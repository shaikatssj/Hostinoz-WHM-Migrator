<?php
require_once __DIR__ . '/header.php';

// Fetch stats
$usersCount = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
$serversCount = $db->query("SELECT COUNT(*) FROM servers")->fetchColumn();
$migrationsTotal = $db->query("SELECT COUNT(*) FROM migrations")->fetchColumn();
$migrationsPending = $db->query("SELECT COUNT(*) FROM migrations WHERE status='pending'")->fetchColumn();
$migrationsCompleted = $db->query("SELECT COUNT(*) FROM migrations WHERE status='completed'")->fetchColumn();

// Fetch recent migrations
$recentMigrations = $db->query("SELECT m.*, u.username FROM migrations m LEFT JOIN users u ON m.user_id = u.id ORDER BY m.id DESC LIMIT 5")->fetchAll();
?>

<div class="row mb-4">
    <div class="col-md-3">
        <div class="card text-center py-4">
            <h5 class="text-secondary fw-bold">Total Users</h5>
            <h2 class="fw-bold mb-0"><?= $usersCount ?></h2>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center py-4">
            <h5 class="text-secondary fw-bold">Servers</h5>
            <h2 class="fw-bold mb-0"><?= $serversCount ?></h2>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center py-4">
            <h5 class="text-secondary fw-bold">Total Migrations</h5>
            <h2 class="fw-bold mb-0 text-primary"><?= $migrationsTotal ?></h2>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center py-4">
            <h5 class="text-secondary fw-bold">Completed</h5>
            <h2 class="fw-bold mb-0 text-success"><?= $migrationsCompleted ?></h2>
        </div>
    </div>
</div>

<div class="card p-4">
    <h5 class="fw-bold mb-4">Recent Migrations</h5>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Domain</th>
                    <th>cPanel User</th>
                    <th>Status</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($recentMigrations as $m): ?>
                <tr>
                    <td><?= h($m['username']) ?></td>
                    <td><?= h($m['domain']) ?></td>
                    <td><?= h($m['cp_user']) ?></td>
                    <td>
                        <?php if($m['status'] == 'completed'): ?>
                            <span class="badge bg-success">Completed</span>
                        <?php elseif($m['status'] == 'pending'): ?>
                            <span class="badge bg-warning text-dark">Pending</span>
                        <?php elseif($m['status'] == 'failed'): ?>
                            <span class="badge bg-danger">Failed</span>
                        <?php else: ?>
                            <span class="badge bg-primary">Running</span>
                        <?php endif; ?>
                    </td>
                    <td><?= h($m['created_at']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if(!$recentMigrations): ?>
                <tr><td colspan="5" class="text-center text-muted">No migrations yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
