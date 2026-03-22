<?php
require_once __DIR__ . '/header.php';

// ── Live sync: called by JS via ?sync=1 ──────────────────────────────────────
if (isset($_GET['sync'])) {
    header('Content-Type: application/json');
    require_once __DIR__ . '/../includes/MigrationSystem.php';

    $running = $db->query(
        "SELECT m.*, s.host, s.user, s.token FROM migrations m
         JOIN servers s ON m.dest_id = s.id WHERE m.status = 'running'"
    )->fetchAll();

    $updated = 0;
    foreach ($running as $m) {
        $token = MigrationSystem::dec($m['token']);
        $res = MigrationSystem::whmApiCall($m['host'], $m['user'], $token, 'get_transfer_session_state', [
            'transfer_session_id' => $m['session_id']
        ]);
        if (!($res['_ok'] ?? false)) continue;

        $stateRaw = $res['data']['state'] ?? $res['data']['status'] ?? '';
        $state    = is_array($stateRaw) ? ($stateRaw[0] ?? '') : (string)$stateRaw;
        $progress = $res['data']['percent'] ?? $res['data']['progress'] ?? '';

        $jsonStr = strtolower(json_encode($res));
        $isComplete = strpos($jsonStr, 'complet') !== false || strpos($jsonStr, 'success') !== false || strpos($jsonStr, 'done') !== false || strpos($jsonStr, 'finished') !== false || (is_numeric($progress) && $progress >= 100);
        $isFailed   = strpos($jsonStr, 'fail') !== false || strpos($jsonStr, 'abort') !== false || strpos($jsonStr, 'error') !== false;

        if ($isComplete) {
            if (!empty($m['whm_owner'])) {
                MigrationSystem::whmApiCall($m['host'], $m['user'], $token, 'modifyacct', [
                    'user' => $m['cp_user'], 'owner' => $m['whm_owner']
                ]);
            }
            $db->prepare("UPDATE migrations SET status='completed', progress='100', updated_at=NOW() WHERE id=?")->execute([$m['id']]);
            MigrationSystem::notify($db, (int)$m['user_id'], "Migration for {$m['domain']} completed!");
            $updated++;
        } elseif ($isFailed) {
            $db->prepare("UPDATE migrations SET status='failed', progress='0', updated_at=NOW() WHERE id=?")->execute([$m['id']]);
            $updated++;
        } elseif ($progress !== '') {
            $db->prepare("UPDATE migrations SET progress=?, updated_at=NOW() WHERE id=?")->execute([(string)$progress, $m['id']]);
        }
    }

    echo json_encode(['ok' => true, 'updated' => $updated, 'checked' => count($running)]);
    exit;
}

$migrations = $db->query("SELECT m.*, u.username, s.name as dest_name 
                          FROM migrations m 
                          LEFT JOIN users u ON m.user_id = u.id 
                          LEFT JOIN servers s ON m.dest_id = s.id 
                          ORDER BY m.id DESC")->fetchAll();

$hasRunning = false;
foreach ($migrations as $m) { if ($m['status'] === 'running') { $hasRunning = true; break; } }
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold">All Migrations</h4>
    <div class="d-flex align-items-center gap-3">
        <?php if ($hasRunning): ?>
        <span id="syncStatus" class="text-muted small"><i class="fas fa-circle-notch fa-spin me-1 text-primary"></i>Auto-syncing running migrations...</span>
        <?php endif; ?>
        <button class="btn btn-sm btn-outline-primary shadow-sm" id="btnSyncNow"><i class="fas fa-sync-alt me-1"></i>Sync Now</button>
    </div>
</div>

<div class="card p-4 border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table align-middle table-hover text-nowrap">
            <thead class="table-light">
                <tr>
                    <th>ID</th>
                    <th>User</th>
                    <th>Target Domain</th>
                    <th>cPanel User</th>
                    <th>Destination</th>
                    <th>Status</th>
                    <th>Progress</th>
                    <th>Updated</th>
                </tr>
            </thead>
            <tbody id="migrationsTable">
                <?php foreach($migrations as $m): ?>
                <tr id="mrow-<?= $m['id'] ?>">
                    <td class="text-muted fw-bold">#<?= $m['id'] ?></td>
                    <td><i class="fas fa-user text-primary me-2"></i><?= h($m['username'] ?? 'Admin') ?></td>
                    <td class="fw-semibold"><?= h($m['domain']) ?></td>
                    <td><span class="badge bg-light text-dark border"><?= h($m['cp_user']) ?></span></td>
                    <td><i class="fas fa-server text-secondary me-2"></i><?= h($m['dest_name'] ?? '—') ?></td>
                    <td class="status-cell">
                        <?= renderStatusBadge($m['status']) ?>
                    </td>
                    <td class="progress-cell">
                        <?php if ($m['status'] === 'running'): ?>
                            <div class="progress" style="width:80px;height:6px;">
                                <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary" style="width:<?= $m['progress'] ?: 50 ?>%"></div>
                            </div>
                        <?php elseif ($m['status'] === 'completed'): ?>
                            <span class="text-success small fw-bold">100%</span>
                        <?php else: ?>
                            <span class="text-muted small">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted small"><?= h($m['updated_at'] ?? $m['created_at']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if(!$migrations): ?>
                <tr><td colspan="8" class="text-center text-muted py-5">No migrations have been requested yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
function renderStatusBadge(string $status): string {
    return match($status) {
        'completed' => '<span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-pill px-3"><i class="fas fa-check-circle me-1"></i>Completed</span>',
        'failed'    => '<span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 rounded-pill px-3"><i class="fas fa-times-circle me-1"></i>Failed</span>',
        'pending'   => '<span class="badge bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25 rounded-pill px-3"><i class="fas fa-clock me-1"></i>Pending</span>',
        default     => '<span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 rounded-pill px-3"><i class="fas fa-spinner fa-spin me-1"></i>Running</span>',
    };
}
?>

<script>
async function syncMigrations() {
    const res = await fetch('?sync=1');
    const data = await res.json().catch(() => null);
    if (!data) return;

    if (data.updated > 0) {
        // Reload table rows without full page reload
        const page = await fetch(window.location.href);
        const html = await page.text();
        const parser = new DOMParser();
        const doc   = parser.parseFromString(html, 'text/html');
        const newTbody = doc.getElementById('migrationsTable');
        if (newTbody) document.getElementById('migrationsTable').innerHTML = newTbody.innerHTML;
        const statusEl = document.getElementById('syncStatus');
        if (statusEl) statusEl.innerHTML = `<i class="fas fa-check-circle me-1 text-success"></i>Synced ${data.updated} migration(s) at ${new Date().toLocaleTimeString()}`;
    }
}

// Auto-sync every 10 seconds if there are running migrations
<?php if ($hasRunning): ?>
    const autoSyncInterval = setInterval(syncMigrations, 10000);
<?php endif; ?>

document.getElementById('btnSyncNow')?.addEventListener('click', async () => {
    const btn = document.getElementById('btnSyncNow');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-circle-notch fa-spin me-1"></i>Syncing...';
    await syncMigrations();
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-sync-alt me-1"></i>Sync Now';
    // Reload the whole page to see updated list
    setTimeout(() => location.reload(), 500);
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
