param([string[]]$TestFiles = @('security-and-rebuild.sql', 'category-mutations.sql', 'account-settings.sql', 'change-feed-scale.sql', 'recurring-calendar.sql', 'karobar-parity.sql', 'budget-ranges.sql', 'integration.sql'))
$docker = 'C:\Users\govin\AppData\Local\Programs\DockerDesktop\resources\bin\docker.exe'
if (-not (Test-Path -LiteralPath $docker)) { throw 'Docker CLI not found.' }
foreach ($testFile in $TestFiles) {
    Write-Output "Running $testFile"
    Get-Content -Raw (Join-Path $PSScriptRoot $testFile) | & $docker exec -i supabase_db_sanie psql -U postgres -d postgres -v ON_ERROR_STOP=1 -X -q -t
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}
