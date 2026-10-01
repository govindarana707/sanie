<?php

$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/KarobarService.php';

function kaAssert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: {$message}\n";
}
function kaClose(float $expected, float $actual, string $message): void {
    kaAssert(abs($expected - $actual) < .001, "{$message}: expected {$expected}, got {$actual}");
}

$db = (new Database())->getConnection();
if (!$db) { fwrite(STDERR, "FAIL: database unavailable\n"); exit(1); }
$users = [];
$service = new KarobarService();
$previousMonth = (new DateTimeImmutable('first day of this month'))->modify('-1 month')->format('Y-m-10');
$currentMonth = (new DateTimeImmutable('first day of this month'))->format('Y-m-10');
$pastDue = (new DateTimeImmutable('today'))->modify('-10 days')->format('Y-m-d');

$createUser = function(string $label) use ($db, &$users): int {
    $db->prepare("INSERT INTO users(email,password,first_name) VALUES(?,?,?)")
        ->execute(['karobar-ai-' . $label . '-' . bin2hex(random_bytes(4)) . '@example.invalid', password_hash('fixture', PASSWORD_DEFAULT), 'Karobar AI']);
    $id = (int)$db->lastInsertId();
    $users[] = $id;
    return $id;
};
$createPerson = function(int $userId, string $name) use ($db): int {
    $db->prepare("INSERT INTO people(user_id,name,type) VALUES(?,?,'person')")->execute([$userId, $name]);
    return (int)$db->lastInsertId();
};
$tx = function(int $userId, int $personId, string $type, float $amount, string $date, ?string $due = null) use ($db): int {
    $db->prepare("INSERT INTO karobar_transactions(user_id,person_id,type,amount,transaction_date,due_date,description) VALUES(?,?,?,?,?,?,?)")
        ->execute([$userId, $personId, $type, $amount, $date, $due, 'AI integrity fixture']);
    return (int)$db->lastInsertId();
};

try {
    $empty = $createUser('empty');
    $analysis = $service->getAIAnalysis($empty);
    kaAssert($analysis['health_score'] === null && $analysis['health_status'] === 'Not enough data', 'empty activity does not manufacture a health score');
    kaAssert($analysis['credit_dependency'] === null && $analysis['avg_repayment_days'] === null, 'empty activity uses N/A-compatible null metrics');
    kaAssert(!$analysis['monthly_trend_has_activity'] && count($analysis['monthly_trend']) === 12, 'empty chart supplies a zero-filled 12-month window and empty-state flag');

    $adjustmentUser = $createUser('adjustment');
    $adjustmentPerson = $createPerson($adjustmentUser, 'Adjustment Only');
    $tx($adjustmentUser, $adjustmentPerson, 'adjustment', 250, $currentMonth);
    $analysis = $service->getAIAnalysis($adjustmentUser);
    kaAssert($analysis['health_score'] === null, 'an adjustment without debt activity does not manufacture a health score');

    $payableUser = $createUser('payable');
    $creditor = $createPerson($payableUser, 'Actual Creditor');
    $borrow = $tx($payableUser, $creditor, 'borrowed', 3000, $previousMonth, $pastDue);
    $analysis = $service->getAIAnalysis($payableUser);
    kaClose(3000, $analysis['total_payable'], 'payable-only total');
    kaClose(-3000, $analysis['net_position'], 'payable-only net position');
    kaAssert($analysis['active_people_count'] === 1 && $analysis['people_you_owe'] === 1 && $analysis['people_who_owe_you'] === 0, 'active creditor counts are directional');
    kaAssert($analysis['most_borrowed_person']['name'] === 'Actual Creditor', 'most borrowed includes every person type');
    kaAssert(str_contains(implode(' ', $analysis['recommendations']), 'overdue'), 'overdue payable creates a prioritized recommendation');

    $db->prepare('UPDATE karobar_transactions SET amount=4500 WHERE id=?')->execute([$borrow]);
    kaClose(4500, $service->getAIAnalysis($payableUser)['total_payable'], 'edited borrowing immediately updates analysis');
    $db->prepare('DELETE FROM karobar_transactions WHERE id=?')->execute([$borrow]);
    kaClose(0, $service->getAIAnalysis($payableUser)['total_payable'], 'deleted borrowing immediately updates analysis');

    $receivableUser = $createUser('receivable');
    $debtor = $createPerson($receivableUser, 'Debtor');
    $tx($receivableUser, $debtor, 'lent', 5000, $currentMonth);
    $analysis = $service->getAIAnalysis($receivableUser);
    kaClose(5000, $analysis['total_receivable'], 'receivable-only total');
    kaClose(5000, $analysis['net_position'], 'receivable-only net position');
    kaAssert($analysis['people_who_owe_you'] === 1 && $analysis['people_you_owe'] === 0, 'active debtor counts are directional');

    $mixedUser = $createUser('mixed');
    $mixedCreditor = $createPerson($mixedUser, 'Mixed Creditor');
    $mixedDebtor = $createPerson($mixedUser, 'Mixed Debtor');
    $tx($mixedUser, $mixedCreditor, 'borrowed', 5000, $previousMonth);
    $tx($mixedUser, $mixedCreditor, 'repaid', 2000, $currentMonth);
    $tx($mixedUser, $mixedDebtor, 'lent', 4000, $currentMonth);
    $analysis = $service->getAIAnalysis($mixedUser);
    kaClose(3000, $analysis['total_payable'], 'partial repayment leaves the correct payable');
    kaClose(4000, $analysis['total_receivable'], 'mixed position retains receivable independently');
    kaClose(1000, $analysis['net_position'], 'mixed position net formula is receivable minus payable');
    kaAssert($analysis['people_you_owe'] === 1 && $analysis['people_who_owe_you'] === 1, 'mixed directional people counts remain separate');
    kaAssert(isset($analysis['monthly_trend'][11]['outstanding_balance']), 'monthly trend includes running outstanding balance');

    $sameDayUser = $createUser('same-day');
    $sameDayPerson = $createPerson($sameDayUser, 'Same Day');
    $tx($sameDayUser, $sameDayPerson, 'borrowed', 1000, $currentMonth);
    $tx($sameDayUser, $sameDayPerson, 'repaid', 1000, $currentMonth);
    $analysis = $service->getAIAnalysis($sameDayUser);
    kaAssert($analysis['avg_repayment_days'] === 0, 'a proven same-day completed repayment reports zero days');
    kaClose(0, $analysis['total_payable'], 'fully repaid debt has no outstanding payable');

    $increasingUser = $createUser('increasing');
    $increasingPerson = $createPerson($increasingUser, 'Increasing Debt');
    $tx($increasingUser, $increasingPerson, 'borrowed', 1000, $previousMonth);
    $tx($increasingUser, $increasingPerson, 'borrowed', 500, $currentMonth);
    $analysis = $service->getAIAnalysis($increasingUser);
    kaAssert(str_contains(implode(' ', $analysis['recommendations']), 'increased by 50%'), 'month-over-month debt increase is calculated from running balances');

    $decreasingUser = $createUser('decreasing');
    $decreasingPerson = $createPerson($decreasingUser, 'Decreasing Debt');
    $tx($decreasingUser, $decreasingPerson, 'borrowed', 1000, $previousMonth);
    $tx($decreasingUser, $decreasingPerson, 'repaid', 400, $currentMonth);
    $analysis = $service->getAIAnalysis($decreasingUser);
    kaAssert(str_contains(implode(' ', $analysis['insights']), 'decreased by 40%'), 'month-over-month debt decrease is calculated from running balances');

    kaAssert($analysis['health_score'] >= 0 && $analysis['health_score'] <= 100, 'dynamic health score remains normalized to 0-100');
    kaAssert(in_array($analysis['health_status'], ['Excellent','Good','Average','Poor','Critical'], true), 'health status uses documented bands');
} finally {
    foreach (array_reverse($users) as $userId) $db->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);
}
