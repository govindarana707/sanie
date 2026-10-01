<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Task.php';
require_once __DIR__ . '/../database/SchemaMigrator.php';

function taskAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

$db = (new Database())->getConnection();
if (!$db) throw new RuntimeException('Database unavailable');
$model = new Task($db);
$migration = (new SchemaMigrator($db))->run();
taskAssert($migration['migration_id'] === SchemaMigrator::CURRENT_MIGRATION_ID, 'schema migration is not current');
$summaryType = $db->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tasks' AND COLUMN_NAME='summary_url'")->fetchColumn();
taskAssert(strcasecmp((string)$summaryType, 'varchar(2048)') === 0, 'summary_url schema is missing or has the wrong type');
$statusType = $db->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tasks' AND COLUMN_NAME='status'")->fetchColumn();
taskAssert(stripos((string)$statusType, "'in_progress'") !== false, 'in_progress status is missing from the task schema');
$plan = require __DIR__ . '/../data/board_study_plan.php';
$users = [];

try {
    $makeUser = function(string $label) use ($db, &$users): int {
        $stmt = $db->prepare('INSERT INTO users(email,password,first_name,token_version) VALUES(?,?,?,1)');
        $stmt->execute(['task-test-' . $label . '-' . bin2hex(random_bytes(5)) . '@example.invalid', password_hash('Task-Test-2026!', PASSWORD_DEFAULT), 'Task Test']);
        return $users[] = (int)$db->lastInsertId();
    };
    $owner = $makeUser('owner');
    $other = $makeUser('other');

    taskAssert(count($plan) === 45, 'fixture must contain exactly 45 targets');
    taskAssert(count(array_unique(array_column($plan, 0))) === 15, 'fixture must contain 15 BS dates');
    foreach (['Database Administration','Cloud Computing','Cyber Law & Professional Ethics'] as $subject) {
        taskAssert(count(array_filter($plan, fn($row) => $row[2] === $subject)) === 15, "{$subject} must contain 15 targets");
    }

    taskAssert($model->importBoardPlan($owner, $plan) === 45, 'first import did not create 45 targets');
    taskAssert($model->importBoardPlan($owner, $plan) === 0, 'repeat import was not idempotent');
    $items = $model->findAll($owner, ['task_type'=>'board_study']);
    taskAssert(count($items) === 45, 'owner does not have exactly 45 targets');
    foreach ($plan as $index => $expected) {
        $actual = $items[$index];
        taskAssert([$actual['display_date_bs'],$actual['due_date'],$actual['subject'],$actual['unit_label'],$actual['content']] === $expected, "fixture wording/order changed at target " . ($index + 1));
    }
    taskAssert($model->findAll($other, ['task_type'=>'board_study']) === [], 'foreign user can read owner targets');

    $first = $items[0];
    taskAssert($model->setStatus((int)$first['id'], $owner, 'in_progress'), 'start transition failed');
    $started = $model->findById((int)$first['id'], $owner);
    taskAssert($started['status'] === 'in_progress' && $started['completed_at'] === null, 'in-progress status did not persist safely');
    taskAssert(count($model->findAll($owner, ['status'=>'completed'])) === 0, 'in-progress task inflated completed progress');
    taskAssert($model->setCompletion((int)$first['id'], $owner, true), 'completion update failed');
    $completed = $model->findById((int)$first['id'], $owner);
    taskAssert($completed['status'] === 'completed' && $completed['completed_at'] !== null, 'completion did not persist');
    taskAssert(!$model->setStatus((int)$first['id'], $other, 'in_progress'), 'foreign status update succeeded');
    taskAssert($model->findById((int)$first['id'], $owner)['status'] === 'completed', 'foreign update altered owner task');

    $edit = $items[1];
    $edit['content'] = 'Owner-approved edited content';
    taskAssert($model->update((int)$edit['id'], $owner, $edit), 'edit failed');
    taskAssert($model->findById((int)$edit['id'], $owner)['content'] === 'Owner-approved edited content', 'edit did not persist');
    taskAssert($model->importBoardPlan($owner, $plan) === 0, 'import overwrote or duplicated edited target');
    taskAssert($model->findById((int)$edit['id'], $owner)['content'] === 'Owner-approved edited content', 'edited target was overwritten');

    $delete = $items[2];
    taskAssert($model->delete((int)$delete['id'], $owner), 'delete failed');
    taskAssert($model->importBoardPlan($owner, $plan) === 0, 'deleted seeded target was recreated');
    taskAssert(count($model->findAll($owner, ['task_type'=>'board_study'])) === 44, 'deleted target did not remain deleted');

    $generalId = $model->create($owner, ['title'=>'Normal SanIE task','content'=>'Existing-style general task','due_date'=>'2026-08-22','priority'=>'high']);
    $general = $model->findById($generalId, $owner);
    taskAssert($general['task_type'] === 'general' && $general['priority'] === 'high', 'general Task CRUD foundation failed');
    taskAssert(count($model->findAll($owner, ['due_date'=>'2026-08-22'])) >= 3, 'today filtering failed');

    $remaining = $model->findAll($owner, ['task_type'=>'board_study']);
    $done = count(array_filter($remaining, fn($item) => $item['status'] === 'completed'));
    taskAssert($done === 1 && round($done / count($remaining) * 100) === 2.0, 'dynamic overall progress inputs are incorrect');
    echo "PASS: three-state lifecycle, 45 exact targets, idempotency, CRUD, persistence, completed-only progress, and user isolation\n";
} finally {
    if ($users) {
        $marks = implode(',', array_fill(0, count($users), '?'));
        $stmt = $db->prepare("DELETE FROM users WHERE id IN ({$marks})");
        $stmt->execute($users);
    }
}
