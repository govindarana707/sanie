<?php
require_once __DIR__ . '/../services/FinancialHealthService.php';

function fhAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: {$message}\n";
}
function fhScore(FinancialHealthService $service, array $metrics): array {
    return $service->calculate($metrics + ['income'=>0,'expense'=>0,'savings'=>0,'net_balance'=>0,'payable'=>0,'receivable'=>0,'income_count'=>0,'expense_count'=>0]);
}

$service = new FinancialHealthService();
$none = fhScore($service, []);
fhAssert($none['score'] === null && $none['status'] === 'Not enough data', 'brand-new users receive a neutral no-data state');

$incomeOnly = fhScore($service, ['income'=>1000,'savings'=>200,'net_balance'=>1000,'income_count'=>1]);
fhAssert($incomeOnly['score'] > 0 && $incomeOnly['breakdown']['income_vs_expense'] === 25, 'income without expenses is scored positively');

$allComponents = ['savings_rate', 'income_vs_expense', 'net_balance', 'debt_payable', 'stability_activity'];
fhAssert(array_sum(array_intersect_key($incomeOnly['breakdown'], array_flip($allComponents))) === $incomeOnly['score'], 'displayed health components sum exactly to the total score');

foreach ([200=>30,150=>24,100=>18,50=>10,10=>5,0=>0] as $savings=>$points) {
    $result = fhScore($service, ['income'=>1000,'savings'=>$savings,'net_balance'=>100,'income_count'=>1]);
    fhAssert($result['breakdown']['savings_rate'] === $points, "savings-rate threshold {$savings} returns {$points} points");
}

$healthy = fhScore($service, ['income'=>1000,'expense'=>600,'savings'=>200,'net_balance'=>800,'income_count'=>1,'expense_count'=>2]);
fhAssert($healthy['score'] === 100 && $healthy['status'] === 'Excellent', 'healthy income, spending, savings, and liquidity receive the full score');

$equal = fhScore($service, ['income'=>1000,'expense'=>1000,'net_balance'=>0,'income_count'=>1,'expense_count'=>2]);
fhAssert($equal['breakdown']['income_vs_expense'] === 8, 'expenses equal to income receive the correct income-expense points');

$over = fhScore($service, ['income'=>1000,'expense'=>1200,'net_balance'=>-200,'income_count'=>1,'expense_count'=>2]);
fhAssert($over['breakdown']['income_vs_expense'] === 0 && $over['status'] === 'Critical', 'expenses above income are penalized');

$negative = fhScore($service, ['income'=>1000,'expense'=>400,'savings'=>100,'net_balance'=>-10,'income_count'=>1,'expense_count'=>1]);
fhAssert($negative['breakdown']['net_balance'] === 0, 'negative net balance receives no liquidity points');

$positive = fhScore($service, ['income'=>1000,'expense'=>400,'savings'=>100,'net_balance'=>500,'income_count'=>1,'expense_count'=>1]);
fhAssert($positive['breakdown']['net_balance'] === 20, 'positive balance relative to average expense receives full liquidity points');
fhAssert($positive['breakdown']['debt_payable'] === 15, 'no payables receive full debt-position points');

$highDebt = fhScore($service, ['income'=>1000,'expense'=>400,'savings'=>100,'net_balance'=>200,'payable'=>1000,'income_count'=>1,'expense_count'=>1]);
fhAssert($highDebt['breakdown']['debt_payable'] === 0, 'high payables receive no debt-position points');

$withReceivable = fhScore($service, ['expense'=>200,'net_balance'=>100,'payable'=>100,'receivable'=>1000,'expense_count'=>1]);
fhAssert($withReceivable['breakdown']['debt_payable'] === 12, 'receivables improve debt capacity without changing the score model');

$zeroIncomeExpense = fhScore($service, ['expense'=>100,'net_balance'=>-100,'expense_count'=>1]);
fhAssert($zeroIncomeExpense['breakdown']['income_vs_expense'] === 0, 'zero income with expenses receives no income-expense points');
fhAssert(fhScore($service, ['income'=>0,'expense'=>0])['score'] === null, 'zero income and zero expenses remain a no-data state');

foreach ([0=>'Critical',24=>'Critical',25=>'Needs Attention',44=>'Needs Attention',45=>'Fair',64=>'Fair',65=>'Good',79=>'Good',80=>'Very Good',89=>'Very Good',90=>'Excellent',100=>'Excellent'] as $score=>$status) {
    fhAssert(FinancialHealthService::statusForScore($score) === $status, "status boundary {$score} is {$status}");
}

foreach ([0=>'critical',24=>'critical',25=>'needs-attention',44=>'needs-attention',45=>'fair',64=>'fair',65=>'good',79=>'good',80=>'very-good',89=>'very-good',90=>'excellent',100=>'excellent'] as $score=>$state) {
    fhAssert(FinancialHealthService::stateForScore($score) === $state, "semantic state boundary {$score} is {$state}");
}

$base = ['income'=>1000,'expense'=>600,'savings'=>100,'net_balance'=>200,'payable'=>0,'receivable'=>0,'income_count'=>1,'expense_count'=>2];
$incomeChanged = fhScore($service, array_replace($base, ['income'=>2000]));
$expenseChanged = fhScore($service, array_replace($base, ['expense'=>900]));
$savingsChanged = fhScore($service, array_replace($base, ['savings'=>200]));
$balanceChanged = fhScore($service, array_replace($base, ['net_balance'=>400]));
$payableChanged = fhScore($service, array_replace($base, ['payable'=>1000]));
$receivableChanged = fhScore($service, ['expense'=>200, 'net_balance'=>100, 'payable'=>100, 'receivable'=>1000, 'expense_count'=>1]);
fhAssert($incomeChanged['breakdown']['savings_rate'] !== fhScore($service, $base)['breakdown']['savings_rate'], 'income changes recalculate savings-rate points');
fhAssert($expenseChanged['breakdown']['income_vs_expense'] !== fhScore($service, $base)['breakdown']['income_vs_expense'], 'expense changes recalculate income-versus-expense points');
fhAssert($savingsChanged['breakdown']['savings_rate'] !== fhScore($service, $base)['breakdown']['savings_rate'], 'savings changes recalculate savings-rate points');
fhAssert($balanceChanged['breakdown']['net_balance'] !== fhScore($service, $base)['breakdown']['net_balance'], 'account-balance changes recalculate liquidity points');
fhAssert($payableChanged['breakdown']['debt_payable'] !== fhScore($service, $base)['breakdown']['debt_payable'], 'payable changes recalculate debt-burden points');
fhAssert($receivableChanged['breakdown']['debt_payable'] > fhScore($service, ['expense'=>200, 'net_balance'=>100, 'payable'=>100, 'receivable'=>0, 'expense_count'=>1])['breakdown']['debt_payable'], 'receivable changes recalculate available debt capacity');
