<?php

$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Budget.php';
require_once __DIR__ . '/../models/Category.php';
require_once __DIR__ . '/../models/Subcategory.php';
require_once __DIR__ . '/../database/SchemaMigrator.php';
require_once __DIR__ . '/../services/KarobarService.php';

function bcAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function bcClose(float $expected, float $actual, string $message): void {
    if (abs($expected - $actual) > .001) throw new RuntimeException("{$message}: expected {$expected}, got {$actual}");
}

$db = (new Database())->getConnection();
if (!$db) exit(1);
$users = [];
$passed = 0;
$failed = 0;
$run = function (string $name, callable $test) use (&$passed, &$failed): void {
    try { $test(); $passed++; echo "PASS: {$name}\n"; }
    catch (Throwable $e) { $failed++; fwrite(STDERR, "FAIL: {$name} - {$e->getMessage()}\n"); }
};

try {
    foreach (['owner', 'foreign'] as $label) {
        $stmt = $db->prepare("INSERT INTO users(email,password,first_name) VALUES(?,?,'Budget Phase 9')");
        $stmt->execute(['bc-' . $label . '-' . bin2hex(random_bytes(5)) . '@example.invalid', password_hash('fixture', PASSWORD_DEFAULT)]);
        $users[$label] = (int)$db->lastInsertId();
    }
    $owner = $users['owner'];
    $foreign = $users['foreign'];
    $category = function (int $user, string $name) use ($db): int {
        $stmt = $db->prepare("INSERT INTO categories(user_id,name,type,status,is_default) VALUES(?,?,'expense','active',0)");
        $stmt->execute([$user, $name]);
        return (int)$db->lastInsertId();
    };
    $subcategory = function (int $user, int $parent, string $name) use ($db): int {
        $stmt = $db->prepare("INSERT INTO subcategories(user_id,category_id,name,status) VALUES(?,?,?,'active')");
        $stmt->execute([$user, $parent, $name]);
        return (int)$db->lastInsertId();
    };
    $food = $category($owner, 'BC Food');
    $transport = $category($owner, 'BC Transport');
    $foreignCategory = $category($foreign, 'BC Foreign');
    $restaurant = $subcategory($owner, $food, 'BC Restaurant');
    $groceries = $subcategory($owner, $food, 'BC Groceries');
    $fuel = $subcategory($owner, $transport, 'BC Fuel');
    $foreignSubcategory = $subcategory($foreign, $foreignCategory, 'BC Foreign Child');

    $transaction = function (int $user, string $type, float $amount, string $date, ?int $categoryId, ?int $subcategoryId = null) use ($db): int {
        $stmt = $db->prepare('INSERT INTO transactions(user_id,type,amount,date,category_id,subcategory_id,description) VALUES(?,?,?,?,?,?,?)');
        $stmt->execute([$user, $type, $amount, $date, $categoryId, $subcategoryId, 'Phase 9 classification fixture']);
        return (int)$db->lastInsertId();
    };
    $transaction($owner, 'expense', 100, '2026-08-05', $food);
    $transaction($owner, 'expense', 200, '2026-08-06', $food, $restaurant);
    $transaction($owner, 'expense', 300, '2026-08-07', $food, $groceries);
    $transaction($owner, 'expense', 400, '2026-08-01', $food, $restaurant);
    $transaction($owner, 'expense', 500, '2026-08-31', $food, $groceries);
    $transaction($owner, 'expense', 600, '2026-07-31', $food, $restaurant);
    $transaction($owner, 'income', 700, '2026-08-08', $food, $restaurant);
    $transaction($owner, 'transfer', 800, '2026-08-09', $food, $restaurant);
    $transaction($owner, 'goal_contribution', 900, '2026-08-10', $food, $restaurant);
    $transaction($owner, 'expense', 1000, '2026-08-11', $food, $restaurant); // canonical credit-purchase expense
    $transaction($owner, 'expense', 50, '2026-08-12', $food); // linked transfer-fee classification
    $transaction($owner, 'expense', 1100, '2026-08-13', $transport, $fuel);
    $transaction($foreign, 'expense', 9999, '2026-08-13', $food, $restaurant);

    $model = new Budget();
    $base = ['user_id'=>$owner, 'amount'=>10000, 'period'=>'monthly', 'start_date'=>'2026-08-01', 'end_date'=>'2026-08-31', 'alert_threshold'=>80, 'is_active'=>1];
    $foodBudget = (int)$model->create(array_merge($base, ['name'=>'Food budget', 'category_id'=>$food, 'subcategory_id'=>null]));
    $restaurantBudget = (int)$model->create(array_merge($base, ['name'=>'Restaurant budget', 'amount'=>5000, 'category_id'=>$food, 'subcategory_id'=>$restaurant]));
    $overallBudget = (int)$model->create(array_merge($base, ['name'=>'Overall budget', 'amount'=>20000, 'category_id'=>null, 'subcategory_id'=>null]));

    $run('category scope includes direct and descendant expense rows with inclusive dates', function () use ($model,$foodBudget,$owner): void {
        $p=$model->getBudgetProgress($foodBudget,$owner); bcClose(2550,(float)$p['spent'],'category usage'); bcClose(7450,(float)$p['remaining'],'category remaining'); bcAssert($p['budget']['scope_label']==='BC Food','category label is wrong');
    });
    $run('subcategory scope includes only the exact child', function () use ($model,$restaurantBudget,$owner): void {
        $p=$model->getBudgetProgress($restaurantBudget,$owner); bcClose(1600,(float)$p['spent'],'subcategory usage'); bcClose(3400,(float)$p['remaining'],'subcategory remaining'); bcAssert($p['budget']['scope_label']==='BC Food / BC Restaurant','subcategory label is wrong');
    });
    $run('overall scope includes only owner expense rows in the period', function () use ($model,$overallBudget,$owner): void {
        $p=$model->getBudgetProgress($overallBudget,$owner); bcClose(3650,(float)$p['spent'],'overall usage'); bcClose(16350,(float)$p['remaining'],'overall remaining'); bcAssert($p['budget']['scope_label']==='All expenses','overall label is wrong');
    });
    $run('non-expenses and foreign rows never affect budget usage', function () use ($model,$foodBudget,$owner): void {
        bcClose(2550,(float)$model->getBudgetProgress($foodBudget,$owner)['spent'],'classification or tenant filter failed');
    });
    $run('credit purchase consumes the applicable budget once and repayment does not consume it again', function () use ($db,$model,$base,$owner,$category): void {
        $creditCategory=$category($owner,'BC Credit');
        $s=$db->prepare("INSERT INTO people(user_id,name,type,status)VALUES(?,'BC Vendor','vendor','active')");$s->execute([$owner]);$person=(int)$db->lastInsertId();
        $s=$db->prepare("INSERT INTO accounts(user_id,name,type,balance,opening_balance,is_active,is_default)VALUES(?,'BC Cash','cash',10000,10000,1,0)");$s->execute([$owner]);$account=(int)$db->lastInsertId();
        $budget=(int)$model->create(array_merge($base,['name'=>'Credit budget','amount'=>8000,'category_id'=>$creditCategory,'subcategory_id'=>null]));
        $service=new KarobarService();$service->processCreditPurchase(['amount'=>5000,'creditor_id'=>$person,'category_id'=>$creditCategory,'date'=>'2026-08-14','description'=>'BC linked credit','due_date'=>'2026-08-30','client_request_id'=>'bc-credit-'.bin2hex(random_bytes(5))],$owner);
        bcClose(5000,(float)$model->getBudgetProgress($budget,$owner)['spent'],'credit purchase usage');
        $service->processRepayment(['person_id'=>$person,'account_id'=>$account,'amount'=>2000,'transaction_date'=>'2026-08-15','client_request_id'=>'bc-repay-'.bin2hex(random_bytes(5))],$owner);
        bcClose(5000,(float)$model->getBudgetProgress($budget,$owner)['spent'],'repayment double-counted');
    });
    $run('dashboard period intersection retains a zero-usage budget row', function () use ($model,$foodBudget,$owner): void {
        $rows=$model->getBatchProgress([$foodBudget],$owner,'2026-09-01','2026-09-30');bcAssert(count($rows)===1,'budget disappeared outside the dashboard period');bcClose(0,(float)$rows[0]['spent'],'out-of-range dashboard usage');
    });
    $run('invalid, mismatched, and foreign classifications are rejected', function () use ($model,$base,$owner,$food,$fuel,$foreignCategory,$foreignSubcategory): void {
        $cases=[
            ['name'=>'foreign category','category_id'=>$foreignCategory],
            ['name'=>'mismatch','category_id'=>$food,'subcategory_id'=>$fuel],
            ['name'=>'foreign child','category_id'=>$food,'subcategory_id'=>$foreignSubcategory],
            ['name'=>'child without parent','category_id'=>null,'subcategory_id'=>$fuel],
            ['name'=>'category as child','category_id'=>$food,'subcategory_id'=>$food],
            ['name'=>'bad amount','category_id'=>$food,'amount'=>0],
            ['name'=>'bad dates','category_id'=>$food,'start_date'=>'2026-09-01','end_date'=>'2026-08-01'],
        ];
        foreach($cases as$case){try{$model->create(array_merge($base,$case));throw new RuntimeException('invalid case accepted: '.$case['name']);}catch(BudgetValidationException$e){}}
    });
    $run('editing scope clears stale child and recomputes labels and usage', function () use ($model,$base,$restaurantBudget,$owner,$transport,$food,$restaurant): void {
        bcAssert($model->update($restaurantBudget,$owner,array_merge($base,['name'=>'Moved','amount'=>5000,'category_id'=>$transport,'subcategory_id'=>null])),'category edit failed');
        $moved=$model->getBudgetProgress($restaurantBudget,$owner); bcClose(1100,(float)$moved['spent'],'moved category usage'); bcAssert($moved['budget']['subcategory_id']===null,'stale child was retained'); bcAssert($moved['budget']['scope_label']==='BC Transport','moved label is wrong');
        bcAssert($model->update($restaurantBudget,$owner,array_merge($base,['name'=>'Moved back','amount'=>5000,'category_id'=>$food,'subcategory_id'=>$restaurant])),'subcategory edit failed');
        bcClose(1600,(float)$model->getBudgetProgress($restaurantBudget,$owner)['spent'],'edited subcategory usage');
    });
    $run('archiving classification preserves historical joins and progress', function () use ($db,$model,$foodBudget,$owner,$food): void {
        $db->prepare("UPDATE categories SET status='archived' WHERE id=?")->execute([$food]);
        $p=$model->getBudgetProgress($foodBudget,$owner); bcAssert($p['budget']['scope_label']==='BC Food','archived label disappeared'); bcClose(2550,(float)$p['spent'],'archive changed usage');
        $db->prepare("UPDATE categories SET status='active' WHERE id=?")->execute([$food]);
    });
    $run('category and subcategory hard deletes are restricted while referenced', function () use ($db,$food,$restaurant): void {
        foreach([['categories',$food],['subcategories',$restaurant]] as[$table,$id]){try{$db->prepare("DELETE FROM {$table} WHERE id=?")->execute([$id]);throw new RuntimeException("{$table} delete unexpectedly succeeded");}catch(PDOException$e){bcAssert((string)$e->getCode()==='23000',"{$table} delete failed for wrong reason");}}
    });
    $run('subcategory reads enforce both child and parent ownership', function () use ($foreignCategory,$owner,$foreignSubcategory): void {
        $subs=new Subcategory(); bcAssert($subs->findById($foreignSubcategory,$owner)===false,'foreign child leaked by ID'); bcAssert($subs->getByCategory($foreignCategory,$owner)===[],'foreign parent leaked its children');
    });
    $run('budget delete changes no classifications or financial rows', function () use ($db,$model,$overallBudget,$owner,$food,$restaurant): void {
        $before=(int)$db->query("SELECT COUNT(*) FROM transactions WHERE description='Phase 9 classification fixture'")->fetchColumn(); bcAssert($model->delete($overallBudget,$owner),'budget delete failed'); bcAssert($model->findById($overallBudget,$owner)===false,'budget survived delete'); bcAssert((int)$db->query("SELECT COUNT(*) FROM transactions WHERE description='Phase 9 classification fixture'")->fetchColumn()===$before,'transactions changed'); bcAssert((int)$db->query("SELECT COUNT(*) FROM categories WHERE id={$food}")->fetchColumn()===1,'category changed'); bcAssert((int)$db->query("SELECT COUNT(*) FROM subcategories WHERE id={$restaurant}")->fetchColumn()===1,'subcategory changed');
    });
    $run('schema migration remains current and repeat-safe', function () use ($db): void {
        $a=(new SchemaMigrator($db))->run();$b=(new SchemaMigrator($db))->run();bcAssert(!$a['applied']&&!$b['applied'],'migration was not a no-op');bcAssert($a['migration_id']===SchemaMigrator::PHASE11_MIGRATION_ID,'wrong migration ID');
    });
} finally {
    foreach (array_reverse($users) as $id) {
        try { $db->prepare('DELETE FROM users WHERE id=?')->execute([$id]); }
        catch (Throwable $e) { fwrite(STDERR, "Cleanup warning: {$e->getMessage()}\n"); }
    }
}
echo "RESULT: {$passed} passed, {$failed} failed, 0 skipped\n";
exit($failed ? 1 : 0);
