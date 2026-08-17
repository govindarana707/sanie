<?php
/**
 * SanIE Financial Data Reset Tool
 * 
 * Usage:   php reset.php                   (CLI mode)
 *          http://.../reset.php            (Web mode - shows confirmation)
 *          http://.../reset.php?confirm=1  (Web mode - executes reset)
 * 
 * Safely clears all financial records while preserving user data,
 * categories, and system configuration.
 */

// ──────────────────────────────────────────────
//  BOOTSTRAP
// ──────────────────────────────────────────────

require_once __DIR__ . '/config/database.php';

$isCli = (php_sapi_name() === 'cli');
$resetEnabled = getenv('ALLOW_FINANCIAL_RESET') === '1';
if (!$isCli || !$resetEnabled) {
    http_response_code(404);
    exit;
}
$confirmed = false;

// ──────────────────────────────────────────────
//  PARSE CONFIRMATION
// ──────────────────────────────────────────────

if ($isCli) {
    $confirmed = in_array('--confirm', $argv ?? []);
} else {
    ob_start();
    $confirmed = isset($_GET['confirm']) && $_GET['confirm'] === '1';
}

// ──────────────────────────────────────────────
//  HELPER: output
// ──────────────────────────────────────────────

function out($msg) {
    global $isCli;
    if ($isCli) {
        echo $msg . PHP_EOL;
    } else {
        echo htmlspecialchars($msg) . "<br>\n";
    }
}

function jsonOut($data, $exit = true) {
    global $isCli, $confirmed;
    if (!$isCli) {
        ob_clean();
        header('Content-Type: application/json');
        echo json_encode($data, JSON_PRETTY_PRINT);
        if ($exit) exit;
    }
}

// ──────────────────────────────────────────────
//  SHOW CONFIRMATION (web mode)
// ──────────────────────────────────────────────

if (!$isCli && !$confirmed) {
    ob_clean();
    ?>
    <!DOCTYPE html>
    <html><head><title>SanIE - Reset Financial Data</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 640px; margin: 40px auto; padding: 20px; }
        .danger { background: #FEF2F2; border: 1px solid #FCA5A5; border-radius: 8px; padding: 16px 20px; }
        h1 { color: #DC2626; }
        .btn { display: inline-block; padding: 10px 24px; border-radius: 6px; text-decoration: none; font-weight: 600; margin: 8px 4px; }
        .btn-danger { background: #DC2626; color: #fff; }
        .btn-secondary { background: #6B7280; color: #fff; }
        .checklist { list-style: none; padding: 0; }
        .checklist li::before { content: "✓ "; color: #10B981; font-weight: bold; }
    </style>
    </head>
    <body>
        <h1>⚠ Reset Financial Data</h1>
        <div class="danger">
            <strong>This will permanently delete ALL financial records.</strong>
            <ul class="checklist">
                <li>All transactions (income, expense, transfer)</li>
                <li>All karobar (borrow/lend) transactions</li>
                <li>All attachments and receipts</li>
                <li>All reports and AI analysis history</li>
                <li>All activity logs and notifications</li>
                <li>Account balances reset to Rs 0.00</li>
                <li>Goal progress reset to Rs 0.00</li>
                <li>Budget spending clears (calculated from transactions)</li>
            </ul>
            <p><strong>Preserved:</strong> Users, Categories, Subcategories, People (contacts), Budgets structure, Goals structure, Recurring templates, Settings.</p>
            <p>A backup JSON will be saved to <code>backend/backups/</code> before reset.</p>
        </div>
        <p>
            <a class="btn btn-danger" href="?confirm=1&t=<?= time() ?>" onclick="return confirm('Are you sure? This cannot be undone!')">Yes, Reset Everything</a>
            <a class="btn btn-secondary" href="#" onclick="history.back();return false">Cancel</a>
        </p>
    </body></html>
    <?php
    exit;
}

// ──────────────────────────────────────────────
//  ESTABLISH CONNECTION
// ──────────────────────────────────────────────

$database = new Database();
$conn = $database->getConnection();
if (!$conn) {
    jsonOut(['success' => false, 'message' => 'Database connection failed']);
    exit(1);
}

// ──────────────────────────────────────────────
//  STEP 1: BACKUP
// ──────────────────────────────────────────────

out("── STEP 1: Backup ──");

$backupDir = __DIR__ . '/backups';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0755, true);
}

$timestamp = date('Y-m-d_H-i-s');
$backupFile = $backupDir . '/sanie_backup_' . $timestamp . '.json';

$backup = [];

// Export tables that will be deleted
$exportTables = [
    'transactions', 'attachments', 'karobar_transactions',
    'activity_logs', 'notifications', 'ai_analysis_history', 'reports'
];
foreach ($exportTables as $table) {
    try {
        $stmt = $conn->query("SELECT * FROM `{$table}`");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($rows)) {
            $backup[$table] = $rows;
        }
    } catch (\Throwable $e) {
        // Table might not exist, skip
    }
}

// Export account balances before reset
try {
    $stmt = $conn->query("SELECT id, user_id, name, balance, opening_balance FROM accounts");
    $backup['accounts'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {}

// Export goal progress
try {
    $stmt = $conn->query("SELECT id, user_id, name, current_amount FROM goals");
    $backup['goals'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {}

$backupWritten = file_put_contents($backupFile, json_encode($backup, JSON_PRETTY_PRINT));

if ($backupWritten) {
    $backupSize = round(filesize($backupFile) / 1024, 2);
    out("  Backup saved: {$backupFile} ({$backupSize} KB)");
} else {
    out("  WARNING: Backup file could not be written!");
    jsonOut(['success' => false, 'message' => 'Backup failed - aborting to protect data']);
    exit(1);
}

// ──────────────────────────────────────────────
//  STEP 2: DISABLE FK CHECKS
// ──────────────────────────────────────────────

out("── STEP 2: Preparing database ──");
$conn->exec("SET FOREIGN_KEY_CHECKS = 0");

// ──────────────────────────────────────────────
//  STEP 3: CLEAR FINANCIAL TABLES
// ──────────────────────────────────────────────

out("── STEP 3: Clearing financial records ──");

$deleteOrder = [
    'attachments',               // FK → transactions CASCADE, → goals CASCADE
    'notifications',             // FK → users CASCADE (contains financial refs)
    'activity_logs',             // FK → users CASCADE
    'ai_analysis_history',       // FK → users CASCADE
    'reports',                   // FK → users CASCADE
    'karobar_transactions',      // FK → transactions SET NULL, → accounts SET NULL
    'transactions',              // FK → accounts RESTRICT, → categories SET NULL, → karobar SET NULL
];

$deletedCounts = [];
foreach ($deleteOrder as $table) {
    try {
        $count = $conn->exec("DELETE FROM `{$table}`");
        $deletedCounts[$table] = $count;
        out("  {$table}: {$count} rows deleted");
    } catch (\Throwable $e) {
        $deletedCounts[$table] = 0;
        out("  {$table}: SKIPPED ({$e->getMessage()})");
    }
}

// ──────────────────────────────────────────────
//  STEP 4: RESET ACCOUNTS
// ──────────────────────────────────────────────

out("── STEP 4: Resetting accounts ──");

try {
    $stmt = $conn->exec("UPDATE accounts SET balance = 0.00, opening_balance = 0.00");
    out("  All account balances reset to Rs 0.00");
} catch (\Throwable $e) {
    out("  ERROR resetting accounts: " . $e->getMessage());
}

// ──────────────────────────────────────────────
//  STEP 5: RESET GOAL PROGRESS
// ──────────────────────────────────────────────

out("── STEP 5: Resetting goal progress ──");

try {
    $stmt = $conn->exec("UPDATE goals SET current_amount = 0.00");
    out("  All goal current_amount reset to Rs 0.00");
} catch (\Throwable $e) {
    out("  ERROR resetting goals: " . $e->getMessage());
}

// ──────────────────────────────────────────────
//  STEP 6: CLEAR ANY SAVED BUDGET SPENDING
//    (Budget spending is calculated dynamically from transactions,
//     so no table needs updating — it will show zero automatically)
// ──────────────────────────────────────────────

out("── STEP 6: Budget & recurring data ──");
out("  Budget spending is calculated dynamically — zero automatically");
out("  Recurring transaction templates preserved");

// ──────────────────────────────────────────────
//  STEP 7: RE-ENABLE FK CHECKS
// ──────────────────────────────────────────────

out("── STEP 7: Finalizing ──");
$conn->exec("SET FOREIGN_KEY_CHECKS = 1");
out("  Foreign key checks re-enabled");

// ──────────────────────────────────────────────
//  COMPLETE
// ──────────────────────────────────────────────

out("── DONE ──");
out("Backup: {$backupFile}");
out("");

// Auto-increment counters are not reset — IDs continue from where they left off.
// This is intentional: existing ID references (e.g. in URLs, caches) won't collide.

jsonOut([
    'success' => true,
    'message' => 'Financial data reset successfully',
    'backup_file' => $backupFile,
    'deleted' => $deletedCounts,
    'timestamp' => $timestamp
], !$isCli);

if ($isCli) {
    echo "Backup: {$backupFile}\n";
    echo "Deleted: " . json_encode($deletedCounts) . "\n";
}
