<?php
require_once __DIR__ . '/header.php';

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'delete') {
        $id = (int)$_POST['id'];
        if ($id !== $_SESSION['admin_id']) {
            $db->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
            $msg = "User deleted.";
        } else {
            $err = "You cannot delete yourself.";
        }
    }
}

$users = $db->query("SELECT * FROM users ORDER BY id DESC")->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold text-slate-800">Users Management</h4>
</div>

<?php if($msg): ?><div class="alert alert-success border-0 shadow-sm"><?= h($msg) ?></div><?php endif; ?>
<?php if($err): ?><div class="alert alert-danger border-0 shadow-sm"><?= h($err) ?></div><?php endif; ?>

<div class="card p-4 border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table align-middle table-hover">
            <thead class="table-light">
                <tr>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Joined</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($users as $u): ?>
                <tr>
                    <td class="fw-semibold text-slate-800"><i class="fas fa-user-circle text-muted me-2"></i><?= h($u['username']) ?></td>
                    <td><a href="mailto:<?= h($u['email']) ?>" class="text-decoration-none"><?= h($u['email']) ?></a></td>
                    <td>
                        <?php if($u['role'] == 'admin'): ?>
                            <span class="badge bg-danger rounded-pill px-3">Admin</span>
                        <?php elseif($u['role'] == 'reseller'): ?>
                            <span class="badge bg-primary rounded-pill px-3">Reseller</span>
                        <?php else: ?>
                            <span class="badge bg-secondary rounded-pill px-3">User</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted small"><?= h($u['created_at']) ?></td>
                    <td>
                        <?php if ($u['id'] !== $_SESSION['admin_id']): ?>
                        <form method="POST" onsubmit="return confirm('Delete this user? Migrations associated with them will also be deleted.');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $u['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                        </form>
                        <?php else: ?>
                            <span class="text-muted small">Current</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
