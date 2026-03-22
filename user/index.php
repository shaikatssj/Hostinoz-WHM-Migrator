<?php
require_once __DIR__ . '/header.php';

$userId = $_SESSION['user_id'];
$migrationsTotal = $db->query("SELECT COUNT(*) FROM migrations WHERE user_id = $userId")->fetchColumn();
$migrationsPending = $db->query("SELECT COUNT(*) FROM migrations WHERE user_id = $userId AND status='pending'")->fetchColumn();
$migrationsCompleted = $db->query("SELECT COUNT(*) FROM migrations WHERE user_id = $userId AND status='completed'")->fetchColumn();

// Fetch recent migrations for user
$stmt = $db->prepare("SELECT m.*, s.name as dest_name FROM migrations m LEFT JOIN servers s ON m.dest_id = s.id WHERE m.user_id = ? ORDER BY m.id DESC");
$stmt->execute([$userId]);
$migrations = $stmt->fetchAll();
?>

<div class="row g-4 mb-4">
    <div class="col-md-4">
        <div class="card text-center py-4 border-0 shadow-sm h-100">
            <div class="text-primary mb-2"><i class="fas fa-list fa-2x"></i></div>
            <h5 class="text-slate-600 fw-semibold mb-1">Total Migrations</h5>
            <h2 class="fw-bold mb-0 text-slate-800"><?= $migrationsTotal ?></h2>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card text-center py-4 border-0 shadow-sm h-100">
            <div class="text-warning mb-2"><i class="fas fa-clock fa-2x"></i></div>
            <h5 class="text-slate-600 fw-semibold mb-1">In Progress</h5>
            <h2 class="fw-bold mb-0 text-slate-800"><?= $migrationsPending ?></h2>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card text-center py-4 border-0 shadow-sm h-100 bg-primary text-white">
            <div class="text-white-50 mb-2"><i class="fas fa-rocket fa-2x"></i></div>
            <h5 class="fw-semibold mb-1">New Migration</h5>
            <a href="migrate" class="btn btn-light mt-2 fw-bold text-primary px-4 py-2 rounded-pill shadow-sm transition-all hover-transform">Start Now</a>
        </div>
    </div>
</div>

<div class="card p-0 border-0 shadow-sm">
    <div class="card-header bg-white border-bottom py-3">
        <h5 class="fw-bold mb-0 text-slate-800">Your Migration History</h5>
    </div>
    <div class="table-responsive">
        <table class="table align-middle table-hover text-nowrap mb-0">
            <thead class="table-light">
                <tr>
                    <th class="ps-4">Target Domain</th>
                    <th>cPanel User</th>
                    <th>Destination Server</th>
                    <th>Status</th>
                    <th>Requested On</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($migrations as $m): ?>
                <tr>
                    <td class="ps-4 fw-semibold text-slate-800"><i class="fas fa-globe text-muted me-2"></i><?= h($m['domain']) ?></td>
                    <td><span class="badge bg-light text-dark border"><?= h($m['cp_user']) ?></span></td>
                    <td>
                        <?php if($m['dest_name']): ?>
                            <i class="fas fa-server text-secondary me-2"></i><?= h($m['dest_name']) ?>
                        <?php else: ?>
                            <span class="text-muted"><i class="fas fa-question-circle me-1"></i>Unknown</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if($m['status'] == 'completed'): ?>
                            <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-pill px-3"><i class="fas fa-check-circle me-1"></i>Completed</span>
                        <?php elseif($m['status'] == 'failed'): ?>
                            <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 rounded-pill px-3"><i class="fas fa-times-circle me-1"></i>Failed</span>
                        <?php elseif($m['status'] == 'pending'): ?>
                            <span class="badge bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25 rounded-pill px-3"><i class="fas fa-clock me-1"></i>Pending</span>
                        <?php else: ?>
                            <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 rounded-pill px-3"><i class="fas fa-spinner fa-spin me-1"></i><?= h(ucfirst($m['status'])) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted small"><?= h($m['created_at']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if(!$migrations): ?>
                <tr><td colspan="5" class="text-center text-muted py-5">
                    <div class="mb-3"><i class="fas fa-box-open fa-3x text-light"></i></div>
                    <h5>No migrations found</h5>
                    <p class="small text-muted mb-4">You haven't requested any account migrations yet.</p>
                    <a href="migrate" class="btn btn-primary px-4 shadow-sm"><i class="fas fa-plus me-1"></i> Create First Migration</a>
                </td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
