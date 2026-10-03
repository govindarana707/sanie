$ErrorActionPreference = 'Stop'
$link = Get-Content (Join-Path $PSScriptRoot '..\.temp\linked-project.json') -Raw | ConvertFrom-Json
if ($link.name -ne 'sanie-dev') { throw 'Cloud smoke requires sanie-dev.' }
$projectRef = $link.ref
$base = "https://$projectRef.supabase.co"
$keys = npx --no-install supabase projects api-keys --project-ref $projectRef --reveal --output json | ConvertFrom-Json
$publishable = ($keys | Where-Object type -eq 'publishable' | Select-Object -First 1).api_key
$service = ($keys | Where-Object name -eq 'service_role' | Select-Object -First 1).api_key
if (-not $publishable -or -not $service) { throw 'Required sanie-dev test keys are unavailable.' }

function Request($method, $path, $key, $token, $body) {
  $headers = @{ apikey = $key; Authorization = "Bearer $token" }
  $args = @{ Uri = "$base$path"; Method = $method; Headers = $headers; SkipHttpErrorCheck = $true }
  if ($null -ne $body) {
    $args.Body = ConvertTo-Json -InputObject $body -Depth 10 -Compress
    $args.ContentType = 'application/json'
  }
  $response = Invoke-WebRequest @args
  $json = if ($response.Content) { $response.Content | ConvertFrom-Json } else { $null }
  return @{ status = [int]$response.StatusCode; data = $json }
}
function RequireSuccess($result, $label) {
  if ($result.status -lt 200 -or $result.status -ge 300) { throw "$label HTTP $($result.status): $($result.data.message)" }
  return $result.data
}
function RequireError($result, $code, $label) {
  if ($result.status -lt 400 -or $result.data.message -ne $code) { throw "$label expected $code; got HTTP $($result.status) $($result.data.message)" }
}
function Rpc($token, $name, $body) {
  return Request 'POST' "/rest/v1/rpc/$name" $publishable $token $body
}
function AdminSql($sql) {
  $output = npx --no-install supabase db query --linked $sql 2>&1
  if ($LASTEXITCODE -ne 0) { throw "Disposable fixture SQL failed: $output" }
}

$firstUser = $null
$secondUser = $null
$systemCategory = [guid]::NewGuid().ToString()
$systemSubcategory = [guid]::NewGuid().ToString()
$category = [guid]::NewGuid().ToString()
$subcategory = [guid]::NewGuid().ToString()
$createdSystem = $false
try {
  $passwordA = [guid]::NewGuid().ToString() + 'aA1!'
  $passwordB = [guid]::NewGuid().ToString() + 'bB1!'
  $emailA = "category-smoke-$([guid]::NewGuid().ToString('N'))@example.test"
  $emailB = "category-smoke-$([guid]::NewGuid().ToString('N'))@example.test"
  $firstUser = RequireSuccess (Request 'POST' '/auth/v1/admin/users' $service $service @{email=$emailA;password=$passwordA;email_confirm=$true}) 'Create disposable user A'
  $secondUser = RequireSuccess (Request 'POST' '/auth/v1/admin/users' $service $service @{email=$emailB;password=$passwordB;email_confirm=$true}) 'Create disposable user B'
  $loginA = RequireSuccess (Request 'POST' '/auth/v1/token?grant_type=password' $publishable $publishable @{email=$emailA;password=$passwordA}) 'Sign in A'
  $loginB = RequireSuccess (Request 'POST' '/auth/v1/token?grant_type=password' $publishable $publishable @{email=$emailB;password=$passwordB}) 'Sign in B'
  $tokenA = $loginA.access_token
  $tokenB = $loginB.access_token
  $profile = RequireSuccess (Request 'GET' "/rest/v1/profiles?id=eq.$($firstUser.id)&select=data_generation" $publishable $tokenA $null) 'Read generation'
  $generation = [int]$profile[0].data_generation

  AdminSql "insert into public.categories(id,user_id,name,category_type,is_system) values('$systemCategory',null,'Smoke system expense','expense',true)"
  $createdSystem = $true
  AdminSql "insert into public.subcategories(id,user_id,category_id,name) values('$systemSubcategory',null,'$systemCategory','Smoke system child')"
  $visible = RequireSuccess (Request 'GET' "/rest/v1/categories?id=eq.$systemCategory&select=id,user_id,is_system" $publishable $tokenA $null) 'Read system fixture'
  if ($visible.Count -ne 1 -or $visible[0].user_id -ne $null) { throw 'System category read failed.' }
  $visibleSub = RequireSuccess (Request 'GET' "/rest/v1/subcategories?id=eq.$systemSubcategory&select=id,user_id,category_id" $publishable $tokenA $null) 'Read system subcategory'
  if ($visibleSub.Count -ne 1 -or $visibleSub[0].user_id -ne $null -or $visibleSub[0].category_id -ne $systemCategory) { throw 'System subcategory read failed.' }

  $requestCreate = [guid]::NewGuid().ToString()
  $createBody = @{p_id=$category;p_name='Smoke category';p_type='expense';p_icon=$null;p_description=$null;p_request=$requestCreate;p_generation=$generation}
  $created = RequireSuccess (Rpc $tokenA 'create_category' $createBody) 'Create category'
  if ($created.entity.user_id -ne $firstUser.id -or $created.replayed) { throw 'Category ownership or create receipt failed.' }
  $replay = RequireSuccess (Rpc $tokenA 'create_category' $createBody) 'Replay category create'
  if (-not $replay.replayed) { throw 'Create replay failed.' }
  $mismatched = $createBody.Clone(); $mismatched.p_name = 'Changed'
  RequireError (Rpc $tokenA 'create_category' $mismatched) 'IDEMPOTENCY_MISMATCH' 'Mismatched replay'
  RequireError (Rpc $tokenA 'create_category' @{p_id=([guid]::NewGuid().ToString());p_name='Stale';p_type='expense';p_icon=$null;p_description=$null;p_request=([guid]::NewGuid().ToString());p_generation=($generation+1)}) 'DATA_GENERATION_MISMATCH' 'Stale generation'
  RequireError (Rpc $tokenA 'update_category' @{p_id=$systemCategory;p_name='Wrong';p_type='expense';p_icon=$null;p_description=$null;p_base_version=1;p_request=([guid]::NewGuid().ToString());p_generation=$generation}) 'NOT_FOUND' 'System category protection'
  RequireError (Rpc $tokenA 'archive_subcategory' @{p_id=$systemSubcategory;p_category=$systemCategory;p_base_version=1;p_request=([guid]::NewGuid().ToString());p_generation=$generation}) 'NOT_FOUND' 'System subcategory protection'

  $subBody = @{p_id=$subcategory;p_category=$category;p_name='Smoke child';p_icon=$null;p_description=$null;p_request=([guid]::NewGuid().ToString());p_generation=$generation}
  $subCreated = RequireSuccess (Rpc $tokenA 'create_subcategory' $subBody) 'Create subcategory'
  if ($subCreated.entity.category_id -ne $category -or $subCreated.entity.user_id -ne $firstUser.id) { throw 'Subcategory parent or ownership failed.' }
  $profileB = RequireSuccess (Request 'GET' "/rest/v1/profiles?id=eq.$($secondUser.id)&select=data_generation" $publishable $tokenB $null) 'Read B generation'
  $genB = [int]$profileB[0].data_generation
  RequireError (Rpc $tokenB 'create_subcategory' @{p_id=([guid]::NewGuid().ToString());p_category=$category;p_name='Foreign';p_icon=$null;p_description=$null;p_request=([guid]::NewGuid().ToString());p_generation=$genB}) 'FORBIDDEN_REFERENCE' 'Cross-user parent'
  RequireError (Rpc $tokenB 'update_category' @{p_id=$category;p_name='Foreign';p_type='expense';p_icon=$null;p_description=$null;p_base_version=1;p_request=([guid]::NewGuid().ToString());p_generation=$genB}) 'NOT_FOUND' 'Cross-user update'

  $editCategory = @{p_id=$category;p_name='Smoke edited';p_type='expense';p_icon=$null;p_description=$null;p_base_version=1;p_request=([guid]::NewGuid().ToString());p_generation=$generation}
  RequireSuccess (Rpc $tokenA 'update_category' $editCategory) 'Update category' | Out-Null
  $staleEdit = $editCategory.Clone(); $staleEdit.p_request = [guid]::NewGuid().ToString()
  RequireError (Rpc $tokenA 'update_category' $staleEdit) 'CONFLICT' 'Stale category version'
  $editSub = @{p_id=$subcategory;p_category=$category;p_name='Smoke child edited';p_icon=$null;p_description=$null;p_base_version=1;p_request=([guid]::NewGuid().ToString());p_generation=$generation}
  RequireSuccess (Rpc $tokenA 'update_subcategory' $editSub) 'Update subcategory' | Out-Null
  $archivedSub = RequireSuccess (Rpc $tokenA 'archive_subcategory' @{p_id=$subcategory;p_category=$category;p_base_version=2;p_request=([guid]::NewGuid().ToString());p_generation=$generation}) 'Archive subcategory'
  if ($archivedSub.entity.status -ne 'archived') { throw 'Subcategory archive failed.' }
  $archivedCategory = RequireSuccess (Rpc $tokenA 'archive_category' @{p_id=$category;p_base_version=2;p_request=([guid]::NewGuid().ToString());p_generation=$generation}) 'Archive category'
  if ($archivedCategory.entity.status -ne 'archived') { throw 'Category archive failed.' }
  $feed = RequireSuccess (Rpc $tokenA 'pull_changes' @{p_after_cursor=0;p_limit=500;p_generation=$generation}) 'Pull changes'
  if (-not ($feed | Where-Object { $_.entity_id -eq $category }) -or -not ($feed | Where-Object { $_.entity_id -eq $subcategory })) { throw 'Category change feed convergence failed.' }
  Write-Output 'CATEGORY_CLOUD_SMOKE_PASS'
} finally {
  if ($createdSystem) {
    AdminSql "delete from public.subcategories where id='$systemSubcategory'"
    AdminSql "delete from public.categories where id='$systemCategory'"
  }
  if ($firstUser) { Request 'DELETE' "/auth/v1/admin/users/$($firstUser.id)" $service $service $null | Out-Null }
  if ($secondUser) { Request 'DELETE' "/auth/v1/admin/users/$($secondUser.id)" $service $service $null | Out-Null }
}
