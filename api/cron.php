<?php
// api/cron.php  -- run every minute via crontab:
//   * * * * * php /path/to/whm/api/cron.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/MigrationSystem.php';

// All states WHM may return
define('WHM_COMPLETED_STATES', ['Completed', 'Completed with Warnings', 'Complete', 'done', 'COMPLETE']);
define('WHM_FAILED_STATES',    ['Failed', 'Aborted', 'Error', 'FAILED', 'ABORTED']);

$running = $db->query(
    "SELECT m.*, s.host, s.user, s.token, s.name as dest_name
     FROM migrations m
     JOIN servers s ON m.dest_id = s.id
     WHERE m.status = 'running'
     ORDER BY m.id ASC"
)->fetchAll();

$processed = 0;
foreach ($running as $m) {
    $rootHost  = $m['host'];
    $rootUser  = $m['user'];
    $rootToken = MigrationSystem::dec($m['token']);

    if (!$m['session_id']) {
        // No session means it was never properly started; mark failed
        $db->prepare("UPDATE migrations SET status='failed', progress='No session ID stored.', updated_at=NOW() WHERE id=?")->execute([$m['id']]);
        continue;
    }

    $res = MigrationSystem::whmApiCall($rootHost, $rootUser, $rootToken, 'get_transfer_session_state', [
        'transfer_session_id' => $m['session_id']
    ]);

    if (!($res['_ok'] ?? false)) {
        // API unreachable; leave running for next tick
        continue;
    }

    // WHM may return state in different locations
    $stateRaw  = $res['data']['state'] ?? $res['data']['status'] ?? '';
    $stateStr  = is_array($stateRaw) ? ($stateRaw[0] ?? '') : (string)$stateRaw;
    $progress  = $res['data']['percent'] ?? $res['data']['progress'] ?? '';

    // Save latest progress
    if ($progress !== '') {
        $db->prepare("UPDATE migrations SET progress=?, updated_at=NOW() WHERE id=?")->execute([(string)$progress, $m['id']]);
    }

    $jsonStr = strtolower(json_encode($res));
    $isCompleted = strpos($jsonStr, 'complet') !== false || strpos($jsonStr, 'success') !== false || strpos($jsonStr, 'done') !== false || strpos($jsonStr, 'finished') !== false || (is_numeric($progress) && $progress >= 100);
    $isFailed    = strpos($jsonStr, 'fail') !== false || strpos($jsonStr, 'abort') !== false || strpos($jsonStr, 'error') !== false;

    if ($isCompleted) {
        // Set owner if known
        if (!empty($m['whm_owner'])) {
            MigrationSystem::whmApiCall($rootHost, $rootUser, $rootToken, 'modifyacct', [
                'user'  => $m['cp_user'],
                'owner' => $m['whm_owner'],
            ]);
        }
        $db->prepare("UPDATE migrations SET status='completed', progress='100', updated_at=NOW() WHERE id=?")->execute([$m['id']]);
        MigrationSystem::notify($db, (int)$m['user_id'], "Migration for {$m['domain']} ({$m['cp_user']}) completed successfully!");
        $processed++;
    } elseif ($isFailed) {
        $db->prepare("UPDATE migrations SET status='failed', progress='0', updated_at=NOW() WHERE id=?")->execute([$m['id']]);
        MigrationSystem::notify($db, (int)$m['user_id'], "Migration for {$m['domain']} ({$m['cp_user']}) failed or was aborted.");
        $processed++;
    }
}

echo json_encode(['ok' => true, 'processed' => $processed, 'checked' => count($running), 'ts' => date('Y-m-d H:i:s')]);
