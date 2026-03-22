<?php
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/MigrationSystem.php';

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add') {
        $name = trim($_POST['name'] ?? '');
        $host = trim($_POST['host'] ?? '');
        $user = trim($_POST['user'] ?? '');
        $token = trim($_POST['token'] ?? '');

        if (!$name || !$host || !$user || !$token) {
            $err = "All fields required.";
        } else {
            $test = MigrationSystem::whmApiCall($host, $user, $token, 'version');
            if (!($test['_ok'] ?? false)) {
                $err = "Could not connect to WHM with provided credentials. Error: " . ($test['error'] ?? 'Unknown');
            } else {
                $encToken = MigrationSystem::enc($token);
                $stmt = $db->prepare("INSERT INTO servers (type, name, host, user, token) VALUES ('dest', ?, ?, ?, ?)");
                if ($stmt->execute([$name, $host, $user, $encToken])) {
                    $msg = "Server added successfully.";
                } else {
                    $err = "Database error.";
                }
            }
        }
    } elseif ($_POST['action'] === 'delete') {
        $id = (int)$_POST['id'];
        $db->prepare("DELETE FROM servers WHERE id = ?")->execute([$id]);
        $msg = "Server deleted.";
    }
}

$servers = $db->query("SELECT * FROM servers ORDER BY id DESC")->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold text-slate-800">Destination Servers</h4>
    <button class="btn btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#addModal"><i class="fas fa-plus me-1"></i> Add Server</button>
</div>

<?php if($msg): ?><div class="alert alert-success border-0 shadow-sm"><?= h($msg) ?></div><?php endif; ?>
<?php if($err): ?><div class="alert alert-danger border-0 shadow-sm"><?= h($err) ?></div><?php endif; ?>

<div class="card p-4">
    <div class="table-responsive">
        <table class="table align-middle table-hover">
            <thead class="table-light">
                <tr>
                    <th>Name</th>
                    <th>Host</th>
                    <th>Username</th>
                    <th>Added On</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($servers as $s): ?>
                <tr>
                    <td class="fw-semibold text-slate-800"><i class="fas fa-server text-muted me-2"></i><?= h($s['name']) ?></td>
                    <td><?= h($s['host']) ?></td>
                    <td><span class="badge bg-secondary"><?= h($s['user']) ?></span></td>
                    <td class="text-muted small"><?= h($s['created_at']) ?></td>
                    <td>
                        <form method="POST" onsubmit="return confirm('Are you sure you want to delete this server?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $s['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(!$servers): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">No servers added yet. Click 'Add Server' to get started.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="addModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <form method="POST">
          <input type="hidden" name="action" value="add">
          <div class="modal-header bg-light border-0">
            <h5 class="modal-title fw-bold">Add Destination Server</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body p-4">
            <div class="alert alert-primary bg-primary bg-opacity-10 border-0 shadow-none small text-primary mb-4">
                <i class="fas fa-info-circle me-1"></i> This is the WHM Root account where user accounts will be migrated to. Ensure port 2087 is accessible.
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold text-slate-700">Friendly Name</label>
                <input type="text" name="name" class="form-control form-control-lg bg-light border-0" required placeholder="e.g. US Server 1">
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold text-slate-700">WHM Hostname or IP</label>
                <input type="text" name="host" class="form-control form-control-lg bg-light border-0" required placeholder="e.g. server.example.com">
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold text-slate-700">WHM Username</label>
                <input type="text" name="user" class="form-control form-control-lg bg-light border-0" value="root" required>
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold text-slate-700">WHM API Token</label>
                <input type="password" name="token" class="form-control form-control-lg bg-light border-0" required placeholder="Paste token here">
            </div>
          </div>
          <div class="modal-footer border-0 bg-light">
            <button type="button" class="btn btn-link text-secondary text-decoration-none" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary px-4 shadow-sm">Save Server</button>
          </div>
      </form>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
