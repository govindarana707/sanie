<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/KarobarTransaction.php';
require_once __DIR__ . '/../models/Account.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Category.php';
require_once __DIR__ . '/../models/Person.php';
require_once __DIR__ . '/../services/NotificationService.php';
require_once __DIR__ . '/../services/AccountingService.php';

class KarobarService {
    private $conn;
    private $karobarModel;
    private $accountModel;
    private $transactionModel;
    private $personModel;
    private $notifService;
    private $accountingService;

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
        $this->karobarModel = new KarobarTransaction();
        $this->accountModel = new Account();
        $this->transactionModel = new Transaction();
        $this->personModel = new Person();
        $this->notifService = new NotificationService();
        $this->accountingService = new AccountingService();
    }

    public function createTransaction($data, $userId) {
        $this->conn->beginTransaction();

        try {
            $karobarData = [
                'user_id' => $userId,
                'person_id' => $data['person_id'],
                'type' => $data['type'],
                'amount' => floatval($data['amount']),
                'account_id' => $data['account_id'] ?? null,
                'description' => $data['description'] ?? '',
                'transaction_date' => $data['transaction_date'],
                'due_date' => $data['due_date'] ?? null,
                'payment_method' => $data['payment_method'] ?? null
            ];

            $karobarId = $this->karobarModel->create($karobarData);

            if (!$karobarId) {
                $this->conn->rollBack();
                return false;
            }

            // Apply proper balance effects based on karobar type
            $this->applyKarobarBalanceEffects($karobarData, $userId);

            $this->sendKarobarNotification($karobarData, $karobarId, $userId);

            $this->conn->commit();
            return $karobarId;
        } catch (\Throwable $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            error_log("KarobarService::createTransaction error: " . $e->getMessage());
            throw $e;
        }
    }

    public function processCreditPurchase($expenseData, $userId) {
        $this->conn->beginTransaction();

        try {
            $amount = floatval($expenseData['amount']);
            $date = $expenseData['date'];
            $description = $expenseData['description'] ?? 'Credit purchase';
            $creditorId = intval($expenseData['creditor_id']);
            $dueDate = $expenseData['due_date'] ?? null;

            // Create karobar transaction only - no expense transaction
            // Credit purchase increases payable, no immediate account impact
            $karobarId = $this->karobarModel->create([
                'user_id' => $userId,
                'person_id' => $creditorId,
                'type' => 'borrowed',
                'amount' => $amount,
                'account_id' => null,
                'description' => $description,
                'transaction_date' => $date,
                'due_date' => $dueDate,
                'payment_method' => 'credit'
            ]);

            if (!$karobarId) {
                $this->conn->rollBack();
                return false;
            }

            $this->conn->commit();

            $this->sendKarobarNotification([
                'type' => 'borrowed',
                'amount' => $amount,
                'person_id' => $creditorId,
                'transaction_date' => $date
            ], $karobarId, $userId);

            return ['karobar_id' => $karobarId];
        } catch (\Throwable $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            error_log("KarobarService::processCreditPurchase error: " . $e->getMessage());
            throw $e;
        }
    }

    public function processRepayment($repayData, $userId) {
        $this->conn->beginTransaction();

        try {
            $accountId = $repayData['account_id'] ?? $this->getDefaultAccountId($userId);
            if (!$accountId) {
                throw new Exception("No account specified for repayment");
            }

            $amount = floatval($repayData['amount']);

            $karobarId = $this->karobarModel->create([
                'user_id' => $userId,
                'person_id' => $repayData['person_id'],
                'type' => 'repaid',
                'amount' => $amount,
                'account_id' => $accountId,
                'description' => $repayData['description'] ?? 'Repayment',
                'transaction_date' => $repayData['transaction_date'],
                'due_date' => null,
                'payment_method' => $repayData['payment_method'] ?? 'cash'
            ]);

            if (!$karobarId) {
                $this->conn->rollBack();
                return false;
            }

            // Repayment: Decrease Cash, Decrease Payable
            $this->accountingService->updateAccountBalance($accountId, -$amount);

            $this->sendKarobarNotification([
                'type' => 'repaid',
                'amount' => $amount,
                'person_id' => $repayData['person_id'],
                'transaction_date' => $repayData['transaction_date']
            ], $karobarId, $userId);

            $this->conn->commit();
            return $karobarId;
        } catch (\Throwable $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            error_log("KarobarService::processRepayment error: " . $e->getMessage());
            throw $e;
        }
    }

    public function processReceiving($receiveData, $userId) {
        $this->conn->beginTransaction();

        try {
            $accountId = $receiveData['account_id'] ?? $this->getDefaultAccountId($userId);
            if (!$accountId) {
                throw new Exception("No account specified for receiving");
            }

            $amount = floatval($receiveData['amount']);

            $karobarId = $this->karobarModel->create([
                'user_id' => $userId,
                'person_id' => $receiveData['person_id'],
                'type' => 'returned',
                'amount' => $amount,
                'account_id' => $accountId,
                'description' => $receiveData['description'] ?? 'Money received',
                'transaction_date' => $receiveData['transaction_date'],
                'due_date' => null,
                'payment_method' => $receiveData['payment_method'] ?? 'cash'
            ]);

            if (!$karobarId) {
                $this->conn->rollBack();
                return false;
            }

            // Receive Back: Increase Cash, Decrease Receivable
            $this->accountingService->updateAccountBalance($accountId, $amount);

            $this->sendKarobarNotification([
                'type' => 'returned',
                'amount' => $amount,
                'person_id' => $receiveData['person_id'],
                'transaction_date' => $receiveData['transaction_date']
            ], $karobarId, $userId);

            $this->conn->commit();
            return $karobarId;
        } catch (\Throwable $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            error_log("KarobarService::processReceiving error: " . $e->getMessage());
            throw $e;
        }
    }

    public function deleteTransaction($id, $userId) {
        $this->conn->beginTransaction();

        try {
            $transaction = $this->karobarModel->findById($id, $userId);
            if (!$transaction) {
                throw new Exception("Transaction not found");
            }

            // Reverse balance effects before deleting
            if ($transaction['account_id']) {
                $reverseAmount = $this->calculateAccountReverse($transaction);
                if ($reverseAmount != 0) {
                    $this->accountingService->updateAccountBalance($transaction['account_id'], $reverseAmount);
                }
            }

            $this->karobarModel->delete($id, $userId);

            $this->conn->commit();
            return true;
        } catch (\Throwable $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            error_log("KarobarService::deleteTransaction error: " . $e->getMessage());
            throw $e;
        }
    }

    private function applyKarobarBalanceEffects($karobarData, $userId) {
        $type = $karobarData['type'];
        $amount = floatval($karobarData['amount']);
        $accountId = $karobarData['account_id'];

        if (!$accountId) {
            return; // No account specified, no balance effect
        }

        switch ($type) {
            case 'lent':
                // Lend Money: Decrease Cash, Increase Receivable
                // Only account balance changes (receivable tracked by karobar system)
                $this->accountingService->updateAccountBalance($accountId, -$amount);
                break;

            case 'borrowed':
                // Borrow Money: Increase Cash, Increase Payable
                // Only account balance changes (payable tracked by karobar system)
                $this->accountingService->updateAccountBalance($accountId, $amount);
                break;

            case 'returned':
                // Receive Back: Increase Cash, Decrease Receivable
                // Only account balance changes (receivable tracked by karobar system)
                $this->accountingService->updateAccountBalance($accountId, $amount);
                break;

            case 'repaid':
                // Repay: Decrease Cash, Decrease Payable
                // Only account balance changes (payable tracked by karobar system)
                $this->accountingService->updateAccountBalance($accountId, -$amount);
                break;
        }
    }

    public function getDefaultAccountId($userId) {
        $query = "SELECT id FROM accounts WHERE user_id = :user_id AND is_active = 1 ORDER BY is_default DESC, id ASC LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['id'] : null;
    }

    private function findOrCreateKarobarCategory($name, $type) {
        $query = "SELECT id FROM categories WHERE name = :name AND type = :type AND is_default = 0 AND user_id IS NULL LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':type', $type);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result) {
            return $result;
        }

        $insertQuery = "INSERT INTO categories (name, type, icon, color, is_default, status) VALUES (:name, :type, 'handshake', '#8B5CF6', 0, 'active')";
        $stmt = $this->conn->prepare($insertQuery);
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':type', $type);

        if ($stmt->execute()) {
            return ['id' => $this->conn->lastInsertId()];
        }

        return null;
    }

    private function calculateAccountReverse($transaction) {
        $type = $transaction['type'];
        $amount = floatval($transaction['amount']);

        switch ($type) {
            case 'lent': return $amount;
            case 'borrowed': return -$amount;
            case 'returned': return -$amount;
            case 'repaid': return $amount;
            default: return 0;
        }
    }

    private function sendKarobarNotification($karobarData, $karobarId, $userId) {
        try {
            $type = $karobarData['type'];
            $amount = number_format(floatval($karobarData['amount']), 0);
            $person = $this->personModel->findById($karobarData['person_id'], $userId);
            $personName = $person ? $person['name'] : 'Unknown';

            switch ($type) {
                case 'lent':
                    $this->notifService->create($userId, 'karobar_paid',
                        'Money Lent',
                        "Rs {$amount} lent to {$personName}.",
                        'karobar', $karobarId);
                    if (floatval($karobarData['amount']) >= 10000) {
                        $this->notifService->create($userId, 'system',
                            'Large Lending Alert',
                            "You lent Rs {$amount} to {$personName}. This is a significant amount.",
                            'karobar', $karobarId);
                    }
                    break;
                case 'borrowed':
                    $this->notifService->create($userId, 'karobar_received',
                        'Money Borrowed',
                        "Rs {$amount} borrowed from {$personName}.",
                        'karobar', $karobarId);
                    if (floatval($karobarData['amount']) >= 10000) {
                        $this->notifService->create($userId, 'system',
                            'Large Borrowing Alert',
                            "You borrowed Rs {$amount} from {$personName}. This is a significant amount.",
                            'karobar', $karobarId);
                    }
                    break;
                case 'returned':
                    $this->notifService->create($userId, 'karobar_received',
                        'Money Received',
                        "Rs {$amount} received from {$personName}.",
                        'karobar', $karobarId);
                    break;
                case 'repaid':
                    $this->notifService->create($userId, 'karobar_paid',
                        'Repayment Completed',
                        "Rs {$amount} repaid to {$personName}.",
                        'karobar', $karobarId);
                    break;
            }
        } catch (\Throwable $e) {
            error_log("KarobarService::sendKarobarNotification error: " . $e->getMessage());
        }
    }

    public function getDashboardData($userId) {
        $dashboard = $this->karobarModel->getDashboard($userId);

        $totalReceivable = 0;
        $totalPayable = 0;
        foreach ($dashboard['people_balances'] as $p) {
            $bal = floatval($p['balance']);
            if ($bal > 0) $totalReceivable += $bal;
            elseif ($bal < 0) $totalPayable += abs($bal);
        }

        $dashboard['total_receivable'] = $totalReceivable;
        $dashboard['total_payable'] = $totalPayable;
        $dashboard['net_karobar'] = $totalReceivable - $totalPayable;

        return $dashboard;
    }

    public function getCreditReports($userId, $filters = []) {
        $reportType = $filters['report_type'] ?? 'all';

        $result = [
            'receivable_report' => [],
            'payable_report' => [],
            'shop_wise_report' => [],
            'person_wise_report' => [],
            'outstanding_report' => [],
            'monthly_credit_report' => []
        ];

        if ($reportType === 'all' || $reportType === 'receivable') {
            $result['receivable_report'] = $this->getReceivableReport($userId, $filters);
        }
        if ($reportType === 'all' || $reportType === 'payable') {
            $result['payable_report'] = $this->getPayableReport($userId, $filters);
        }
        if ($reportType === 'all' || $reportType === 'shop_wise') {
            $result['shop_wise_report'] = $this->getShopWiseReport($userId, $filters);
        }
        if ($reportType === 'all' || $reportType === 'person_wise') {
            $result['person_wise_report'] = $this->getPersonWiseReport($userId, $filters);
        }
        if ($reportType === 'all' || $reportType === 'outstanding') {
            $result['outstanding_report'] = $this->getOutstandingReport($userId, $filters);
        }
        if ($reportType === 'all' || $reportType === 'monthly_credit') {
            $result['monthly_credit_report'] = $this->getMonthlyCreditReport($userId, $filters);
        }

        return $result;
    }

    private function getReceivableReport($userId, $filters) {
        $query = "SELECT kt.*, p.name as person_name, p.type as person_type,
                  DATEDIFF(CURDATE(), kt.transaction_date) as days_since,
                  CASE WHEN kt.due_date IS NOT NULL AND kt.due_date < CURDATE() THEN 1 ELSE 0 END as is_overdue
                  FROM karobar_transactions kt
                  JOIN people p ON kt.person_id = p.id
                  WHERE kt.user_id = :user_id AND kt.type = 'lent'
                  AND (kt.amount - COALESCE((SELECT SUM(amount) FROM karobar_transactions WHERE person_id = kt.person_id AND user_id = :user_id2 AND type = 'returned' AND transaction_date <= kt.transaction_date), 0)) > 0";

        $params = [':user_id' => $userId, ':user_id2' => $userId];

        if (!empty($filters['start_date'])) {
            $query .= " AND kt.transaction_date >= :start_date";
            $params[':start_date'] = $filters['start_date'];
        }
        if (!empty($filters['end_date'])) {
            $query .= " AND kt.transaction_date <= :end_date";
            $params[':end_date'] = $filters['end_date'];
        }

        $query .= " ORDER BY kt.transaction_date DESC";

        $stmt = $this->conn->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getPayableReport($userId, $filters) {
        $query = "SELECT kt.*, p.name as person_name, p.type as person_type,
                  DATEDIFF(CURDATE(), kt.transaction_date) as days_since,
                  CASE WHEN kt.due_date IS NOT NULL AND kt.due_date < CURDATE() THEN 1 ELSE 0 END as is_overdue
                  FROM karobar_transactions kt
                  JOIN people p ON kt.person_id = p.id
                  WHERE kt.user_id = :user_id AND kt.type = 'borrowed'
                  AND (kt.amount - COALESCE((SELECT SUM(amount) FROM karobar_transactions WHERE person_id = kt.person_id AND user_id = :user_id2 AND type = 'repaid' AND transaction_date <= kt.transaction_date), 0)) > 0";

        $params = [':user_id' => $userId, ':user_id2' => $userId];

        if (!empty($filters['start_date'])) {
            $query .= " AND kt.transaction_date >= :start_date";
            $params[':start_date'] = $filters['start_date'];
        }
        if (!empty($filters['end_date'])) {
            $query .= " AND kt.transaction_date <= :end_date";
            $params[':end_date'] = $filters['end_date'];
        }

        $query .= " ORDER BY kt.transaction_date DESC";

        $stmt = $this->conn->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getShopWiseReport($userId, $filters) {
        $query = "SELECT p.id, p.name, p.type, p.phone, p.address,
                  COALESCE(SUM(CASE WHEN kt.type = 'lent' THEN kt.amount ELSE 0 END), 0) as total_lent,
                  COALESCE(SUM(CASE WHEN kt.type = 'borrowed' THEN kt.amount ELSE 0 END), 0) as total_borrowed,
                  COALESCE(SUM(CASE WHEN kt.type = 'returned' THEN kt.amount ELSE 0 END), 0) as total_returned,
                  COALESCE(SUM(CASE WHEN kt.type = 'repaid' THEN kt.amount ELSE 0 END), 0) as total_repaid,
                  COUNT(kt.id) as transaction_count
                  FROM people p
                  LEFT JOIN karobar_transactions kt ON p.id = kt.person_id AND kt.user_id = :user_id
                  WHERE p.user_id = :user_id2 AND p.type IN ('shop', 'vendor', 'business')
                  GROUP BY p.id, p.name, p.type, p.phone, p.address
                  ORDER BY total_lent + total_borrowed DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':user_id2', $userId);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getPersonWiseReport($userId, $filters) {
        $query = "SELECT p.id, p.name, p.type, p.phone,
                  COALESCE(SUM(CASE WHEN kt.type = 'lent' THEN kt.amount ELSE 0 END), 0) as total_lent,
                  COALESCE(SUM(CASE WHEN kt.type = 'borrowed' THEN kt.amount ELSE 0 END), 0) as total_borrowed,
                  COALESCE(SUM(CASE WHEN kt.type = 'returned' THEN kt.amount ELSE 0 END), 0) as total_returned,
                  COALESCE(SUM(CASE WHEN kt.type = 'repaid' THEN kt.amount ELSE 0 END), 0) as total_repaid,
                  COUNT(kt.id) as transaction_count,
                  MAX(kt.transaction_date) as last_transaction_date
                  FROM people p
                  LEFT JOIN karobar_transactions kt ON p.id = kt.person_id AND kt.user_id = :user_id
                  WHERE p.user_id = :user_id2 AND p.status = 'active'
                  GROUP BY p.id, p.name, p.type, p.phone
                  HAVING total_lent + total_borrowed > 0
                  ORDER BY total_lent + total_borrowed DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':user_id2', $userId);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getOutstandingReport($userId, $filters) {
        $query = "SELECT p.id, p.name, p.type, p.phone,
                  SUM(CASE WHEN kt.type = 'lent' THEN kt.amount ELSE 0 END) -
                  SUM(CASE WHEN kt.type = 'returned' THEN kt.amount ELSE 0 END) as receivable_balance,
                  SUM(CASE WHEN kt.type = 'borrowed' THEN kt.amount ELSE 0 END) -
                  SUM(CASE WHEN kt.type = 'repaid' THEN kt.amount ELSE 0 END) as payable_balance,
                  MIN(CASE WHEN kt.due_date IS NOT NULL AND kt.due_date >= CURDATE() THEN kt.due_date END) as next_due_date,
                  MAX(CASE WHEN kt.due_date IS NOT NULL AND kt.due_date < CURDATE() THEN kt.due_date END) as last_overdue_date
                  FROM people p
                  JOIN karobar_transactions kt ON p.id = kt.person_id AND kt.user_id = :user_id
                  WHERE p.user_id = :user_id2 AND p.status = 'active'
                  GROUP BY p.id, p.name, p.type, p.phone
                  HAVING receivable_balance > 0 OR payable_balance > 0
                  ORDER BY GREATEST(receivable_balance, payable_balance) DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':user_id2', $userId);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getMonthlyCreditReport($userId, $filters) {
        $startDate = $filters['start_date'] ?? date('Y-m-01');
        $endDate = $filters['end_date'] ?? date('Y-m-t');

        $query = "SELECT DATE_FORMAT(transaction_date, '%b %Y') as month_label,
                  DATE_FORMAT(transaction_date, '%Y-%m') as month_key,
                  MONTH(transaction_date) as month_num,
                  YEAR(transaction_date) as year_num,
                  SUM(CASE WHEN type = 'lent' THEN amount ELSE 0 END) as total_lent,
                  SUM(CASE WHEN type = 'borrowed' THEN amount ELSE 0 END) as total_borrowed,
                  SUM(CASE WHEN type = 'returned' THEN amount ELSE 0 END) as total_returned,
                  SUM(CASE WHEN type = 'repaid' THEN amount ELSE 0 END) as total_repaid,
                  COUNT(*) as transaction_count
                  FROM karobar_transactions
                  WHERE user_id = :user_id
                  AND transaction_date BETWEEN :start_date AND :end_date
                  GROUP BY YEAR(transaction_date), MONTH(transaction_date), month_label, month_key
                  ORDER BY year_num DESC, month_num DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':start_date', $startDate);
        $stmt->bindParam(':end_date', $endDate);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAIAnalysis($userId) {
        $data = $this->karobarModel->getDashboard($userId);
        $peopleBalances = $data['people_balances'] ?? [];

        $totalReceivable = 0;
        $totalPayable = 0;
        foreach ($peopleBalances as $p) {
            $bal = floatval($p['balance']);
            if ($bal > 0) $totalReceivable += $bal;
            elseif ($bal < 0) $totalPayable += abs($bal);
        }

        $mostBorrowedShop = $this->getMostBorrowedShop($userId);
        $mostOwed = $this->getMostOwedPerson($userId);
        $mostReceivable = $this->getMostReceivablePerson($userId);
        $creditDependency = $this->getCreditDependency($userId);
        $monthlyTrend = $this->getMonthlyDebtTrend($userId);
        $avgRepaymentDays = $this->getAvgRepaymentDays($userId);
        $highestCreditor = $this->getHighestCreditor($userId);
        $highestDebtor = $this->getHighestDebtor($userId);

        $score = 70;
        if ($totalPayable > 0 && $totalReceivable > 0) {
            $ratio = $totalReceivable / $totalPayable;
            $score = min(100, 50 + ($ratio * 20));
        } elseif ($totalReceivable > 0) {
            $score = 85;
        } elseif ($totalPayable > 0) {
            $score = 55;
        } else {
            $score = 80;
        }

        $insights = [];
        if ($totalPayable > 0) {
            $insights[] = "You owe Rs " . number_format($totalPayable) . " across " . count($peopleBalances) . " people.";
        }
        if ($totalReceivable > 0) {
            $insights[] = "Others owe you Rs " . number_format($totalReceivable) . ".";
        }
        if ($avgRepaymentDays > 0) {
            $insights[] = "Average repayment takes {$avgRepaymentDays} days.";
        }
        if ($creditDependency > 50) {
            $insights[] = "High credit dependency detected. Consider reducing credit purchases.";
        }

        $recommendations = [];
        if ($totalPayable > $totalReceivable * 2) {
            $recommendations[] = "Your payables are significantly higher than receivables. Focus on settling debts.";
        }
        if ($avgRepaymentDays > 30) {
            $recommendations[] = "Average repayment period exceeds 30 days. Consider shorter repayment terms.";
        }
        if (empty($recommendations)) {
            $recommendations[] = "Your karobar (credit) management looks healthy. Keep it up!";
        }

        return [
            'health_score' => round($score),
            'health_status' => $score >= 80 ? 'Excellent' : ($score >= 60 ? 'Good' : ($score >= 40 ? 'Average' : 'Needs Improvement')),
            'total_receivable' => $totalReceivable,
            'total_payable' => $totalPayable,
            'net_karobar' => $totalReceivable - $totalPayable,
            'most_borrowed_shop' => $mostBorrowedShop,
            'most_owed_person' => $mostOwed,
            'most_receivable_person' => $mostReceivable,
            'credit_dependency' => $creditDependency,
            'monthly_trend' => $monthlyTrend,
            'avg_repayment_days' => $avgRepaymentDays,
            'highest_creditor' => $highestCreditor,
            'highest_debtor' => $highestDebtor,
            'insights' => $insights,
            'recommendations' => $recommendations,
            'people_count' => count($peopleBalances)
        ];
    }

    private function getMostBorrowedShop($userId) {
        $query = "SELECT p.name, SUM(kt.amount) as amount
                  FROM people p
                  JOIN karobar_transactions kt ON p.id = kt.person_id
                  WHERE kt.user_id = :user_id AND kt.type = 'borrowed' AND p.type IN ('shop','vendor','business')
                  GROUP BY p.id, p.name
                  ORDER BY amount DESC LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function getMostOwedPerson($userId) {
        $query = "SELECT p.name, SUM(kt.amount) as amount
                  FROM people p
                  JOIN karobar_transactions kt ON p.id = kt.person_id
                  WHERE kt.user_id = :user_id AND kt.type = 'borrowed'
                  GROUP BY p.id, p.name
                  ORDER BY amount DESC LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function getMostReceivablePerson($userId) {
        $query = "SELECT p.name, SUM(kt.amount) as amount
                  FROM people p
                  JOIN karobar_transactions kt ON p.id = kt.person_id
                  WHERE kt.user_id = :user_id AND kt.type = 'lent'
                  GROUP BY p.id, p.name
                  ORDER BY amount DESC LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function getCreditDependency($userId) {
        $query = "SELECT
                  COALESCE(SUM(CASE WHEN type IN ('borrowed','lent') THEN amount ELSE 0 END), 0) as credit_volume,
                  (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = :user_id AND type = 'expense' AND payment_method = 'credit') as credit_expenses,
                  (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = :user_id AND type = 'expense') as total_expenses
                  FROM karobar_transactions WHERE user_id = :user_id2";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':user_id2', $userId);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        $totalExpenses = floatval($result['total_expenses'] ?? 0);
        $creditExpenses = floatval($result['credit_expenses'] ?? 0);

        return $totalExpenses > 0 ? round(($creditExpenses / $totalExpenses) * 100, 1) : 0;
    }

    private function getMonthlyDebtTrend($userId) {
        $query = "SELECT DATE_FORMAT(transaction_date, '%b') as month,
                  MONTH(transaction_date) as month_num,
                  SUM(CASE WHEN type = 'borrowed' THEN amount ELSE 0 END) as borrowed,
                  SUM(CASE WHEN type = 'repaid' THEN amount ELSE 0 END) as repaid
                  FROM karobar_transactions
                  WHERE user_id = :user_id AND YEAR(transaction_date) = YEAR(CURDATE())
                  GROUP BY MONTH(transaction_date), DATE_FORMAT(transaction_date, '%b')
                  ORDER BY month_num ASC";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getAvgRepaymentDays($userId) {
        $query = "SELECT AVG(DATEDIFF(r.transaction_date, b.transaction_date)) as avg_days
                  FROM karobar_transactions b
                  JOIN karobar_transactions r ON b.person_id = r.person_id AND b.user_id = r.user_id
                  WHERE b.user_id = :user_id AND b.type = 'borrowed' AND r.type = 'repaid'
                  AND r.transaction_date >= b.transaction_date";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? round(floatval($result['avg_days'] ?? 0)) : 0;
    }

    private function getHighestCreditor($userId) {
        $query = "SELECT p.name,
                  COALESCE(SUM(CASE WHEN kt.type = 'borrowed' THEN kt.amount ELSE 0 END), 0) -
                  COALESCE(SUM(CASE WHEN kt.type = 'repaid' THEN kt.amount ELSE 0 END), 0) as amount
                  FROM people p
                  JOIN karobar_transactions kt ON p.id = kt.person_id
                  WHERE kt.user_id = :user_id
                  GROUP BY p.id, p.name
                  HAVING amount > 0
                  ORDER BY amount DESC LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function getHighestDebtor($userId) {
        $query = "SELECT p.name,
                  COALESCE(SUM(CASE WHEN kt.type = 'lent' THEN kt.amount ELSE 0 END), 0) -
                  COALESCE(SUM(CASE WHEN kt.type = 'returned' THEN kt.amount ELSE 0 END), 0) as amount
                  FROM people p
                  JOIN karobar_transactions kt ON p.id = kt.person_id
                  WHERE kt.user_id = :user_id
                  GROUP BY p.id, p.name
                  HAVING amount > 0
                  ORDER BY amount DESC LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
