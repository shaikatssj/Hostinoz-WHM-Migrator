<?php
require_once __DIR__ . '/header.php';

if (isset($_GET['mark_read'])) {
    $db->exec("UPDATE notifications SET is_read = 1 WHERE user_id = 0");
    header("Location: notifications");
    exit;
}

$notifications = $db->query("SELECT * FROM notifications WHERE user_id = 0 ORDER BY id DESC LIMIT 50")->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold text-slate-800">System Notifications</h4>
    <a href="?mark_read=1" class="btn btn-outline-primary shadow-sm"><i class="fas fa-check-double me-1"></i> Mark All as Read</a>
</div>

<div class="card p-0 border-0 shadow-sm overflow-hidden">
    <div class="list-group list-group-flush">
        <?php foreach($notifications as $n): ?>
            <div class="list-group-item d-flex justify-content-between align-items-center py-4 px-4 <?= $n['is_read'] ? 'bg-white text-muted' : 'bg-primary bg-opacity-10 fw-semibold text-dark' ?>">
                <div class="d-flex align-items-center">
                    <div class="bg-light p-2 rounded-circle me-3 <?= $n['is_read'] ? 'text-secondary' : 'text-primary' ?>">
                        <i class="fas fa-bell"></i>
                    </div>
                    <?= h($n['message']) ?>
                </div>
                <span class="small <?= $n['is_read'] ? 'text-muted' : 'text-primary fw-bold' ?>"><i class="fas fa-clock me-1"></i><?= h($n['created_at']) ?></span>
            </div>
        <?php endforeach; ?>
        <?php if(!$notifications): ?>
            <div class="text-center text-muted py-5"><i class="fas fa-inbox fa-3x mb-3 text-light"></i><br>No new notifications.</div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
