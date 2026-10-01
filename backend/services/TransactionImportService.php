<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Account.php';
require_once __DIR__ . '/../models/Category.php';
require_once __DIR__ . '/../models/Subcategory.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/AccountingService.php';
require_once __DIR__ . '/NotificationService.php';

class TransactionImportService {
    private const MAX_BYTES = 2097152;
    private const MAX_ROWS = 5000;
    private const SUPPORTED_TYPES = ['income', 'expense', 'transfer'];

    private PDO $conn;
    private Account $accounts;
    private Category $categories;
    private Subcategory $subcategories;
    private Transaction $transactions;
    private AccountingService $accounting;
    private NotificationService $notifications;

    public function __construct() {
        $this->conn = (new Database())->getConnection();
        $this->accounts = new Account();
        $this->categories = new Category();
        $this->subcategories = new Subcategory();
        $this->transactions = new Transaction();
        $this->accounting = new AccountingService();
        $this->notifications = new NotificationService();
    }

    public function preview(int $userId, string $csvContent, string $providedIdentity): array {
        [$identity, $rows] = $this->analyze($userId, $csvContent, $providedIdentity);
        $counts = ['total_rows' => count($rows), 'ready' => 0, 'invalid' => 0, 'unsupported' => 0];
        foreach ($rows as $row) $counts[$row['status']]++;
        return [
            'batch_identity' => $identity,
            'counts' => $counts,
            'rows' => array_map(fn(array $row) => $this->publicRow($row), $rows),
        ];
    }

    public function process(int $userId, string $csvContent, string $providedIdentity): array {
        [$identity, $rows] = $this->analyze($userId, $csvContent, $providedIdentity);
        $counts = [
            'total_rows' => count($rows),
            'imported' => 0,
            'already_imported' => 0,
            'failed' => 0,
            'unsupported' => 0,
        ];
        $results = [];

        foreach ($rows as $row) {
            if ($row['status'] === 'unsupported') {
                $counts['unsupported']++;
                $results[] = $this->executionRow($row, 'unsupported');
                continue;
            }
            if ($row['status'] !== 'ready') {
                $counts['failed']++;
                $results[] = $this->executionRow($row, 'failed');
                continue;
            }

            $requestId = $this->requestId($identity, (int)$row['source_row_number']);
            $payload = $row['_payload'];
            $payload['client_request_id'] = $requestId;

            try {
                $existing = $this->transactions->findByClientRequestId($requestId, $userId);
                if ($existing) {
                    if (!$this->matchesExisting($existing, $payload)) {
                        throw new TransactionConflictException('Import row identity is already used by different transaction data');
                    }
                    $counts['already_imported']++;
                    $results[] = $this->executionRow($row, 'already_imported', (int)$existing['id']);
                    continue;
                }

                $created = $this->accounting->createTransaction($userId, $payload);
                $transactionId = $payload['type'] === 'transfer'
                    ? (int)($created['transfer_id'] ?? 0)
                    : (int)$created;
                if ($transactionId < 1) throw new RuntimeException('Transaction creation did not return an identifier');

                $counts['imported']++;
                $results[] = $this->executionRow($row, 'imported', $transactionId);
            } catch (Throwable $error) {
                $existing = $this->transactions->findByClientRequestId($requestId, $userId);
                if ($existing && $this->matchesExisting($existing, $payload)) {
                    $counts['already_imported']++;
                    $results[] = $this->executionRow($row, 'already_imported', (int)$existing['id']);
                    continue;
                }
                $counts['failed']++;
                [$category, $reason] = $this->safeError($error);
                $failed = $row;
                $failed['error_category'] = $category;
                $failed['reason'] = $reason;
                $results[] = $this->executionRow($failed, 'failed');
            }
        }

        if ($counts['imported'] > 0) {
            try { $this->notifications->syncUserBudgetAlertStates($userId); }
            catch (Throwable $error) { error_log('CSV import budget notification sync failed: ' . $error->getMessage()); }
            $this->notifyBatch($userId, $counts);
        }

        return ['batch_identity' => $identity, 'counts' => $counts, 'rows' => $results];
    }

    private function analyze(int $userId, string $csvContent, string $providedIdentity): array {
        $identity = $this->verifyIdentity($csvContent, $providedIdentity);
        [$headers, $sourceRows] = $this->parseCsv($csvContent);
        $maps = $this->mappingData($userId);
        $rows = [];
        foreach ($sourceRows as $source) $rows[] = $this->analyzeRow($source, $headers, $maps);
        return [$identity, $rows];
    }

    private function verifyIdentity(string $content, string $provided): string {
        $length = strlen($content);
        if ($length < 1) throw new InvalidArgumentException('CSV content is empty');
        if ($length > self::MAX_BYTES) throw new InvalidArgumentException('CSV file exceeds the 2 MB import limit');
        $provided = strtolower(trim($provided));
        if (!preg_match('/^[a-f0-9]{64}$/', $provided)) throw new InvalidArgumentException('Invalid CSV batch identity');
        $actual = hash('sha256', $content);
        if (!hash_equals($actual, $provided)) throw new InvalidArgumentException('CSV batch identity does not match the submitted file');
        return $actual;
    }

    private function parseCsv(string $content): array {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);
        $rawHeaders = fgetcsv($stream);
        if ($rawHeaders === false) throw new InvalidArgumentException('CSV header row is missing');
        $headers = array_map(function ($header): string {
            $value = strtolower(trim((string)$header));
            return preg_replace('/^\x{FEFF}/u', '', $value) ?? $value;
        }, $rawHeaders);
        if (count(array_unique($headers)) !== count($headers)) throw new InvalidArgumentException('CSV contains duplicate column names');
        foreach (['date', 'type', 'amount'] as $required) {
            if (!in_array($required, $headers, true)) throw new InvalidArgumentException('CSV must contain Date, Type and Amount columns');
        }

        $rows = [];
        $recordNumber = 1;
        while (($cells = fgetcsv($stream)) !== false) {
            $recordNumber++;
            if (count($rows) >= self::MAX_ROWS) throw new InvalidArgumentException('CSV exceeds the 5,000 row import limit');
            if (count($cells) === 1 && trim((string)($cells[0] ?? '')) === '') continue;
            $values = [];
            foreach ($headers as $index => $header) $values[$header] = trim((string)($cells[$index] ?? ''));
            $rows[] = [
                'source_row_number' => $recordNumber,
                'values' => $values,
                'structure_error' => count($cells) > count($headers) ? 'Row contains more values than the header defines' : null,
            ];
        }
        fclose($stream);
        if (!$rows) throw new InvalidArgumentException('CSV has no data rows');
        return [$headers, $rows];
    }

    private function mappingData(int $userId): array {
        $accountMap = $this->uniqueMap(
            array_values(array_filter($this->accounts->findAll($userId), fn(array $row) => !empty($row['is_active']))),
            fn(array $row) => $this->key($row['name'])
        );
        $categoryRows = $this->categories->findAll($userId, null, 'active');
        $categoryMap = $this->uniqueMap($categoryRows, fn(array $row) => $row['type'] . ':' . $this->key($row['name']));
        $categoryNames = [];
        foreach ($categoryRows as $row) $categoryNames[$this->key($row['name'])][$row['type']] = true;
        $subcategoryRows = $this->subcategories->findAll($userId, null, 'active');
        $subcategoryMap = $this->uniqueMap($subcategoryRows, fn(array $row) => $row['category_id'] . ':' . $this->key($row['name']));
        $subcategoryNames = [];
        foreach ($subcategoryRows as $row) $subcategoryNames[$this->key($row['name'])][] = $row;
        return compact('accountMap', 'categoryMap', 'categoryNames', 'subcategoryMap', 'subcategoryNames');
    }

    private function uniqueMap(array $rows, callable $keyMaker): array {
        $map = [];
        foreach ($rows as $row) {
            $key = $keyMaker($row);
            if ($key === '') continue;
            $map[$key] = array_key_exists($key, $map) ? null : $row;
        }
        return $map;
    }

    private function analyzeRow(array $source, array $headers, array $maps): array {
        $values = $source['values'];
        $base = ['source_row_number' => $source['source_row_number'], 'original' => $values, 'normalized' => null];
        if ($source['structure_error']) return $this->invalid($base, 'row', '', $source['structure_error']);

        $type = $this->key($values['type'] ?? '');
        if ($type === '') return $this->invalid($base, 'type', '', 'Transaction type is required');
        if (!in_array($type, self::SUPPORTED_TYPES, true)) {
            return array_merge($base, ['status' => 'unsupported', 'error_category' => 'unsupported_type', 'field' => 'type', 'supplied_value' => $values['type'] ?? '', 'reason' => "Transaction type \"{$values['type']}\" is not supported by CSV import"]);
        }

        [$amount, $amountError] = $this->money($values['amount'] ?? '');
        if ($amountError) return $this->invalid($base, 'amount', $values['amount'] ?? '', $amountError);
        $date = (string)($values['date'] ?? '');
        $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) return $this->invalid($base, 'date', $date, 'Date must use a valid YYYY-MM-DD value');
        $description = trim((string)($values['description'] ?? ''));
        $descriptionLength = function_exists('mb_strlen') ? mb_strlen($description) : strlen($description);
        if ($descriptionLength > 255) return $this->invalid($base, 'description', $description, 'Description must be 255 characters or fewer');

        $normalized = ['type' => $type, 'date' => $date, 'amount' => $amount, 'description' => $description];
        if ($type === 'transfer') {
            $feeValue = $values['fee amount'] ?? $values['fee_amount'] ?? '';
            if ($feeValue !== '') {
                return array_merge($base, ['status' => 'unsupported', 'error_category' => 'unsupported_type', 'field' => 'fee amount', 'supplied_value' => $feeValue, 'reason' => 'Transfer fees are not supported by the current CSV import format']);
            }
            $fromName = trim((string)($values['from account'] ?? ''));
            $toName = trim((string)($values['to account'] ?? ''));
            $from = $this->mapped($maps['accountMap'], $fromName);
            if (!$from) return $this->mappingError($base, 'from account', $fromName, $this->mappingReason('Source account', $fromName, $maps['accountMap']));
            $to = $this->mapped($maps['accountMap'], $toName);
            if (!$to) return $this->mappingError($base, 'to account', $toName, $this->mappingReason('Destination account', $toName, $maps['accountMap']));
            if ((int)$from['id'] === (int)$to['id']) return $this->invalid($base, 'to account', $toName, 'Source and destination accounts must be different');
            $normalized += ['from_account' => $from['name'], 'to_account' => $to['name']];
            $payload = [
                'type' => 'transfer', 'amount' => $amount,
                'from_account_id' => (int)$from['id'], 'to_account_id' => (int)$to['id'],
                'account_id' => (int)$from['id'], 'date' => $date,
                'description' => $description, 'payment_method' => 'transfer',
                'fee_amount' => 0, 'fee_category_id' => null,
            ];
        } else {
            $accountName = trim((string)($values['account'] ?? ''));
            $account = $this->mapped($maps['accountMap'], $accountName);
            if (!$account) return $this->mappingError($base, 'account', $accountName, $this->mappingReason('Account', $accountName, $maps['accountMap']));
            $categoryName = trim((string)($values['category'] ?? ''));
            $categoryKey = $type . ':' . $this->key($categoryName);
            $category = $maps['categoryMap'][$categoryKey] ?? false;
            if (!$category) {
                $reason = $categoryName === '' ? 'Category is required' : "Category \"{$categoryName}\" was not found for {$type}";
                if (isset($maps['categoryMap'][$categoryKey]) && $maps['categoryMap'][$categoryKey] === null) $reason = "Category \"{$categoryName}\" is ambiguous for {$type}";
                elseif (!empty($maps['categoryNames'][$this->key($categoryName)]) && empty($maps['categoryNames'][$this->key($categoryName)][$type])) $reason = "Category \"{$categoryName}\" belongs to a different transaction type";
                return $this->mappingError($base, 'category', $categoryName, $reason);
            }
            $subcategory = null;
            $subcategoryName = trim((string)($values['subcategory'] ?? ''));
            if ($subcategoryName !== '') {
                $subKey = $category['id'] . ':' . $this->key($subcategoryName);
                $subcategory = $maps['subcategoryMap'][$subKey] ?? false;
                if (!$subcategory) {
                    $reason = isset($maps['subcategoryMap'][$subKey]) && $maps['subcategoryMap'][$subKey] === null
                        ? "Subcategory \"{$subcategoryName}\" is ambiguous within category \"{$categoryName}\""
                        : "Subcategory \"{$subcategoryName}\" was not found under category \"{$categoryName}\"";
                    if (!empty($maps['subcategoryNames'][$this->key($subcategoryName)])) $reason = "Subcategory \"{$subcategoryName}\" does not belong to category \"{$categoryName}\"";
                    return $this->mappingError($base, 'subcategory', $subcategoryName, $reason);
                }
            }
            $payment = $this->key($values['payment method'] ?? '') ?: 'cash';
            if (!preg_match('/^[a-z0-9_-]{1,30}$/i', $payment)) return $this->invalid($base, 'payment method', $values['payment method'] ?? '', 'Payment method is invalid');
            $normalized += [
                'account' => $account['name'], 'category' => $category['name'],
                'subcategory' => $subcategory['name'] ?? null, 'payment_method' => $payment,
            ];
            $payload = [
                'type' => $type, 'amount' => $amount, 'account_id' => (int)$account['id'],
                'category_id' => (int)$category['id'], 'subcategory_id' => $subcategory ? (int)$subcategory['id'] : null,
                'date' => $date, 'description' => $description, 'payment_method' => $payment,
            ];
        }

        return array_merge($base, [
            'status' => 'ready', 'error_category' => null, 'field' => null,
            'supplied_value' => null, 'reason' => null, 'normalized' => $normalized,
            '_payload' => $payload,
        ]);
    }

    private function money(string $raw): array {
        $text = trim($raw);
        if (str_contains($text, ',')) {
            if (!preg_match('/^[1-9]\d{0,2}(?:,\d{3})+(?:\.\d{1,2})?$/', $text)) return [null, 'Amount contains invalid thousands grouping'];
            $text = str_replace(',', '', $text);
        }
        if (!preg_match('/^(?:0|[1-9]\d{0,11})(?:\.\d{1,2})?$/', $text)) return [null, 'Amount must be positive and use at most two decimal places'];
        $value = (float)$text;
        if (!is_finite($value) || $value <= 0 || $value > 999999999999.99) return [null, 'Amount is outside the supported range'];
        return [number_format($value, 2, '.', ''), null];
    }

    private function mapped(array $map, string $name): ?array {
        $key = $this->key($name);
        return $key !== '' && isset($map[$key]) && is_array($map[$key]) ? $map[$key] : null;
    }

    private function mappingReason(string $label, string $name, array $map): string {
        if ($name === '') return "{$label} is required";
        $key = $this->key($name);
        return array_key_exists($key, $map) && $map[$key] === null
            ? "{$label} \"{$name}\" is ambiguous"
            : "{$label} \"{$name}\" was not found or is inactive";
    }

    private function invalid(array $base, string $field, string $value, string $reason): array {
        return array_merge($base, ['status' => 'invalid', 'error_category' => 'validation_error', 'field' => $field, 'supplied_value' => $value, 'reason' => $reason]);
    }

    private function mappingError(array $base, string $field, string $value, string $reason): array {
        return array_merge($base, ['status' => 'invalid', 'error_category' => 'mapping_error', 'field' => $field, 'supplied_value' => $value, 'reason' => $reason]);
    }

    private function requestId(string $identity, int $rowNumber): string {
        return 'req_csv_' . substr($identity, 0, 40) . '_' . $rowNumber;
    }

    private function matchesExisting(array $existing, array $payload): bool {
        if ((string)$existing['type'] !== (string)$payload['type']) return false;
        if (number_format((float)$existing['amount'], 2, '.', '') !== number_format((float)$payload['amount'], 2, '.', '')) return false;
        if ((string)$existing['date'] !== (string)$payload['date'] || (string)$existing['description'] !== (string)$payload['description']) return false;
        if ($payload['type'] === 'transfer') {
            return (int)$existing['from_account_id'] === (int)$payload['from_account_id']
                && (int)$existing['to_account_id'] === (int)$payload['to_account_id'];
        }
        return (int)$existing['account_id'] === (int)$payload['account_id']
            && (int)$existing['category_id'] === (int)$payload['category_id']
            && (int)($existing['subcategory_id'] ?? 0) === (int)($payload['subcategory_id'] ?? 0)
            && (string)($existing['payment_method'] ?? '') === (string)($payload['payment_method'] ?? '');
    }

    private function safeError(Throwable $error): array {
        $message = trim($error->getMessage());
        if ($error instanceof TransactionConflictException) return ['conflict', $message ?: 'Import row conflicts with an existing request'];
        if ($error instanceof InvalidArgumentException || preg_match('/amount|account|balance|category|subcategory|date|required|positive|not active|access denied|same account/i', $message)) {
            return ['validation_error', $message ?: 'Transaction validation failed'];
        }
        return ['transient_server_error', 'The row could not be processed right now; retry the unchanged file'];
    }

    private function notifyBatch(int $userId, array $counts): void {
        try {
            $message = "{$counts['imported']} new transaction(s) imported";
            if ($counts['failed'] || $counts['unsupported']) $message .= "; " . ($counts['failed'] + $counts['unsupported']) . ' row(s) need attention';
            $this->notifications->create($userId, 'transaction_added', 'CSV Import Processed', $message . '.', null, null);
        } catch (Throwable $error) {
            error_log('CSV import notification failed: ' . $error->getMessage());
        }
    }

    private function publicRow(array $row): array {
        unset($row['_payload']);
        return $row;
    }

    private function executionRow(array $row, string $status, ?int $transactionId = null): array {
        $public = $this->publicRow($row);
        $public['status'] = $status;
        if ($transactionId !== null) $public['transaction_id'] = $transactionId;
        return $public;
    }

    private function key($value): string {
        $text = trim((string)$value);
        return function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);
    }
}
