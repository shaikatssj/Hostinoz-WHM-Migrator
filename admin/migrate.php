<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/MigrationSystem.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login"); exit;
}

$servers = $db->query("SELECT id, name, host FROM servers WHERE type = 'dest' ORDER BY name ASC")->fetchAll();

// Admin Vault file (separate from user vault)
const ADMIN_VAULT_FILE = __DIR__ . '/../data/.admin_migrate_vault.json';
if (!is_dir(__DIR__ . '/../data')) mkdir(__DIR__ . '/../data', 0755, true);

function admin_vault_load(): array {
    if (!file_exists(ADMIN_VAULT_FILE)) return ['accounts' => [], 'dst_reseller' => [], 'ownership' => []];
    $json = json_decode((string)file_get_contents(ADMIN_VAULT_FILE), true);
    if (!is_array($json)) $json = [];
    foreach (['accounts', 'dst_reseller', 'ownership'] as $k) if (!isset($json[$k])) $json[$k] = [];
    return $json;
}
function admin_vault_save(array $vault): bool { return (bool)file_put_contents(ADMIN_VAULT_FILE, json_encode($vault, JSON_PRETTY_PRINT)); }

function admin_json_out(array $data, int $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function admin_normalize_host(string $host): string {
    return preg_replace('#(/.*$|^https?://)#i', '', trim($host));
}
function admin_strong_password(int $len = 16): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#%^&*()-_=+';
    $out = '';
    for ($i = 0; $i < $len; $i++) $out .= $chars[random_int(0, strlen($chars) - 1)];
    return $out;
}
function admin_get_dest_server(PDO $db, $id): ?array {
    $stmt = $db->prepare("SELECT host, user, token FROM servers WHERE id = ?");
    $stmt->execute([(int)$id]);
    $s = $stmt->fetch();
    return $s ? ['host' => $s['host'], 'user' => $s['user'], 'token' => MigrationSystem::dec($s['token'])] : null;
}

// Handle source logout
if (isset($_GET['logout_source'])) {
    unset($_SESSION['admin_src_whm']);
    header("Location: migrate?tab=bulk"); exit;
}

$logged_in_src = isset($_SESSION['admin_src_whm']) && is_array($_SESSION['admin_src_whm']);
$login_error = '';

// Handle source login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'admin_source_login') {
    $host  = trim($_POST['host'] ?? '');
    $user  = trim($_POST['user'] ?? '');
    $token = trim($_POST['token'] ?? '');
    if (!$host || !$user || !$token) {
        $login_error = 'All fields required';
    } else {
        $test = MigrationSystem::whmApiCall($host, $user, $token, 'version');
        if (!($test['_ok'] ?? false)) {
            $login_error = 'Invalid WHM credentials/token – could not connect to source.';
        } else {
            $_SESSION['admin_src_whm'] = ['host' => $host, 'user' => $user, 'token' => $token];
            header("Location: migrate?tab=bulk"); exit;
        }
    }
}

// AJAX actions (admin panel has no source ownership restriction – admin is root)
if (isset($_GET['action']) && $_GET['action'] !== '') {

    $action = $_GET['action'];

    // Fetch list of resellers from destination server (no source needed)
    if ($action === 'get_resellers') {
        $dest = admin_get_dest_server($db, $_POST['dest_server_id'] ?? 0);
        if (!$dest) admin_json_out(['ok' => false, 'error' => 'Invalid server'], 422);

        $res = MigrationSystem::whmApiCall($dest['host'], $dest['user'], $dest['token'], 'listresellers');
        if (!($res['_ok'] ?? false)) {
            admin_json_out(['ok' => false, 'error' => 'listresellers API failed – check root token.'], 500);
        }
        // listresellers returns an array of names in data.reseller
        $resellers = (array)($res['data']['reseller'] ?? []);
        admin_json_out(['ok' => true, 'resellers' => $resellers]);
    }

    // All remaining actions require source login
    if (!$logged_in_src) admin_json_out(['ok' => false, 'error' => 'Not connected to source'], 401);

    $src      = $_SESSION['admin_src_whm'];
    $srcHost  = $src['host'];
    $srcUser  = $src['user'];
    $srcToken = $src['token'];

    // Fetch source accounts (admin can see all, filter by owner if needed)
    $la       = MigrationSystem::whmApiCall($srcHost, $srcUser, $srcToken, 'listaccts');
    $accounts = ($la['_ok'] ?? false) ? (array)($la['data']['acct'] ?? []) : [];
    $valid_users = array_column($accounts, 'user');

    if ($action === 'save_dst_reseller') {
        $res_user = trim($_POST['dst_reseller_user'] ?? '');
        if (!$res_user) admin_json_out(['ok' => false, 'error' => 'Reseller username required'], 422);

        $vault = admin_vault_load();
        $vault['dst_reseller'] = ['user' => MigrationSystem::enc($res_user), 'saved_at' => date('Y-m-d H:i:s')];
        admin_vault_save($vault);
        admin_json_out(['ok' => true]);
    }

    if ($action === 'bulk_reset') {
        $users = array_filter(array_map('trim', explode(',', $_POST['users'] ?? '')));
        if (!$users) admin_json_out(['ok' => false, 'error' => 'No users selected'], 422);

        $vault = admin_vault_load();
        $results = [];
        foreach ($users as $u) {
            if (!in_array($u, $valid_users, true)) {
                $results[] = ['user' => $u, 'ok' => false, 'error' => 'Account not found on source'];
                continue;
            }
            $newPass = admin_strong_password(16);
            $resp = MigrationSystem::whmApiCall($srcHost, $srcUser, $srcToken, 'passwd', ['user' => $u, 'password' => $newPass]);
            if ($resp['_ok'] ?? false) {
                $vault['accounts'][$u] = [
                    'pass'       => MigrationSystem::enc($newPass),
                    'changed_at' => date('Y-m-d H:i:s'),
                    'src_host'   => MigrationSystem::enc(admin_normalize_host($srcHost)),
                ];
                $results[] = ['user' => $u, 'ok' => true, 'password' => $newPass];
            } else {
                $results[] = ['user' => $u, 'ok' => false, 'error' => 'passwd API failed'];
            }
        }
        admin_vault_save($vault);
        admin_json_out(['ok' => true, 'results' => $results]);
    }

    if ($action === 'terminate_dest') {
        $cp_user = trim($_POST['cp_user'] ?? '');
        $dest    = admin_get_dest_server($db, $_POST['dest_server_id'] ?? 0);
        if (!$cp_user || !$dest) admin_json_out(['ok' => false, 'error' => 'Invalid parameters'], 422);

        $res = MigrationSystem::whmApiCall($dest['host'], $dest['user'], $dest['token'], 'removeacct', ['user' => $cp_user, 'keepdns' => '0']);
        if (!($res['_ok'] ?? false)) admin_json_out(['ok' => false, 'error' => 'removeacct failed'], 500);
        admin_json_out(['ok' => true, 'terminated' => true]);
    }

    if ($action === 'finalize_owner') {
        $cp_user = trim($_POST['cp_user'] ?? '');
        $dest    = admin_get_dest_server($db, $_POST['dest_server_id'] ?? 0);
        if (!$cp_user || !$dest) admin_json_out(['ok' => false, 'error' => 'Invalid parameters'], 422);

        $vault            = admin_vault_load();
        $res_user         = MigrationSystem::dec($vault['dst_reseller']['user'] ?? '');
        if (!$res_user) admin_json_out(['ok' => false, 'error' => 'No Target Reseller set. Save one first.'], 422);

        $res = MigrationSystem::whmApiCall($dest['host'], $dest['user'], $dest['token'], 'modifyacct', ['user' => $cp_user, 'owner' => $res_user]);
        if (!($res['_ok'] ?? false)) admin_json_out(['ok' => false, 'error' => 'modifyacct(owner) failed'], 500);

        $vault['ownership'][$cp_user] = ['owner' => MigrationSystem::enc($res_user), 'set_at' => date('Y-m-d H:i:s')];
        admin_vault_save($vault);
        admin_json_out(['ok' => true, 'owner_set' => true, 'owner' => $res_user]);
    }

    if ($action === 'migrate_one') {
        $cp_user = trim($_POST['cp_user'] ?? '');
        $dest    = admin_get_dest_server($db, $_POST['dest_server_id'] ?? 0);
        if (!$cp_user || !$dest) admin_json_out(['ok' => false, 'error' => 'Invalid parameters'], 422);
        if (!in_array($cp_user, $valid_users, true)) admin_json_out(['ok' => false, 'error' => 'Account not found on source'], 422);

        $vault    = admin_vault_load();
        $res_user = MigrationSystem::dec($vault['dst_reseller']['user'] ?? '');
        if (!$res_user) admin_json_out(['ok' => false, 'error' => 'Target Reseller not set. Save one first.'], 422);

        $entry = $vault['accounts'][$cp_user] ?? null;
        if (!$entry || empty($entry['pass'])) admin_json_out(['ok' => false, 'error' => "No stored password for {$cp_user}. Run Prepare Selected first."], 422);

        $cp_pass = MigrationSystem::dec($entry['pass']);

        // Check if exists on destination
        $existsCheck = MigrationSystem::whmApiCall($dest['host'], $dest['user'], $dest['token'], 'accountsummary', ['user' => $cp_user]);
        if ($existsCheck['_ok'] ?? false) admin_json_out(['ok' => false, 'exists' => true, 'error' => "Account '{$cp_user}' already exists on destination."], 409);

        $res1 = MigrationSystem::whmApiCall($dest['host'], $dest['user'], $dest['token'], 'create_remote_user_transfer_session', [
            'host'                 => admin_normalize_host($srcHost),
            'password'             => $cp_pass,
            'unrestricted_restore' => '1',
        ]);
        if (!($res1['_ok'] ?? false)) admin_json_out(['ok' => false, 'error' => 'Source auth failed (step 1)'], 500);

        $sessionId = $res1['data']['transfer_session_id'] ?? null;
        if (!$sessionId) admin_json_out(['ok' => false, 'error' => 'No session ID returned'], 500);

        $res2 = MigrationSystem::whmApiCall($dest['host'], $dest['user'], $dest['token'], 'enqueue_transfer_item', [
            'module'              => 'AccountRemoteUser',
            'transfer_session_id' => $sessionId,
            'user'                => $cp_user,
            'localuser'           => $cp_user,
            'replaceip'           => 'basic',
            'mail_location'       => '.existing',
            'ip'                  => '0',
            'force'               => '1',
        ]);
        if (!($res2['_ok'] ?? false)) admin_json_out(['ok' => false, 'error' => 'Enqueue failed (step 2)'], 500);

        $res3 = MigrationSystem::whmApiCall($dest['host'], $dest['user'], $dest['token'], 'start_transfer_session', ['transfer_session_id' => $sessionId]);
        if (!($res3['_ok'] ?? false)) admin_json_out(['ok' => false, 'error' => 'Start session failed (step 3)'], 500);

        // Log to DB
        $stmt = $db->prepare("INSERT INTO migrations (user_id, domain, cp_user, whm_owner, dest_id, status, session_id) VALUES (?, ?, ?, ?, ?, 'running', ?)");
        $stmt->execute([0, $cp_user, $cp_user, $res_user, $_POST['dest_server_id'], $sessionId]);

        admin_json_out(['ok' => true, 'transfer_session_id' => $sessionId]);
    }

    if ($action === 'status') {
        $sessionId = trim($_POST['transfer_session_id'] ?? '');
        $dest      = admin_get_dest_server($db, $_POST['dest_server_id'] ?? 0);
        if (!$sessionId || !$dest) admin_json_out(['ok' => false, 'error' => 'Params missing'], 422);

        $res = MigrationSystem::whmApiCall($dest['host'], $dest['user'], $dest['token'], 'get_transfer_session_state', ['transfer_session_id' => $sessionId]);
        if (!($res['_ok'] ?? false)) admin_json_out(['ok' => false, 'error' => 'status API failed'], 500);
        admin_json_out(['ok' => true, 'status' => $res]);
    }

    admin_json_out(['ok' => false, 'error' => 'Unknown action'], 404);
}

// --- PAGE DATA ---
require_once __DIR__ . '/header.php';

$vault               = admin_vault_load();
$savedResellerUser   = MigrationSystem::dec($vault['dst_reseller']['user'] ?? '');
$savedResellerSavedAt = $vault['dst_reseller']['saved_at'] ?? '';

$bulkAccounts = [];
$srcHost = $srcUser = '';
if ($logged_in_src) {
    $srcHost  = $_SESSION['admin_src_whm']['host'];
    $srcUser  = $_SESSION['admin_src_whm']['user'];
    $la       = MigrationSystem::whmApiCall($srcHost, $srcUser, $_SESSION['admin_src_whm']['token'], 'listaccts');
    $bulkAccounts = ($la['_ok'] ?? false) ? (array)($la['data']['acct'] ?? []) : [];
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold">Bulk Migration Center <span class="badge bg-primary ms-2 small">Admin</span></h4>
</div>

<?php if (!$logged_in_src): ?>
<div class="row justify-content-center mt-3">
    <div class="col-md-6">
        <div class="card shadow-sm">
            <div class="card-header bg-white border-bottom py-3">
                <h5 class="mb-0 fw-bold"><i class="fas fa-server text-primary me-2"></i> Connect Source WHM</h5>
            </div>
            <div class="card-body p-4">
                <p class="text-muted small mb-4">Connect to the <strong>source server</strong> using any WHM user (root or reseller) and its API token to fetch accounts for migration.</p>
                <?php if ($login_error): ?>
                    <div class="alert alert-danger border-0 shadow-sm small"><i class="fas fa-exclamation-triangle me-2"></i><?= h($login_error) ?></div>
                <?php endif; ?>
                <form method="post">
                    <input type="hidden" name="action_type" value="admin_source_login">
                    <div class="mb-3">
                        <label class="form-label text-muted small fw-bold">Source WHM Host</label>
                        <input name="host" class="form-control bg-light border-0 shadow-sm" placeholder="e.g. old-server.example.com" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-muted small fw-bold">WHM Username</label>
                        <input name="user" class="form-control bg-light border-0 shadow-sm" placeholder="root or reseller username" required>
                    </div>
                    <div class="mb-4">
                        <label class="form-label text-muted small fw-bold">API Token</label>
                        <input type="password" name="token" class="form-control bg-light border-0 shadow-sm" placeholder="Paste API token" required>
                    </div>
                    <button class="btn btn-primary w-100 fw-bold shadow-sm"><i class="fas fa-plug me-2"></i>Connect & Fetch Accounts</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php else: ?>

<div class="row g-4 mb-4">
    <div class="col-12">
        <div class="card shadow-sm">
            <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold"><i class="fas fa-cogs text-secondary me-2"></i> Migration Settings</h6>
                <a href="?logout_source=1" class="btn btn-sm btn-outline-danger"><i class="fas fa-sign-out-alt me-1"></i> Disconnect Source</a>
            </div>
            <div class="card-body p-4">
                <div class="row g-4">
                    <!-- Destination Server -->
                    <div class="col-md-5">
                        <h6 class="fw-bold text-primary mb-2"><i class="fas fa-hdd me-2"></i>1. Target Destination Server</h6>
                        <p class="text-muted small mb-3">Select the destination server to migrate accounts to.</p>
                        <select id="bulk_dest_server_id" class="form-select shadow-sm">
                            <?php foreach ($servers as $s): ?>
                                <option value="<?= $s['id'] ?>"><?= h($s['name']) ?> (<?= h($s['host']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Reseller Owner (fetched dynamically) -->
                    <div class="col-md-7 border-start">
                        <h6 class="fw-bold text-info mb-2"><i class="fas fa-user-shield me-2"></i>2. Select Target Owner (Reseller)</h6>
                        <p class="text-muted small mb-3">
                            Accounts will be assigned to this reseller after migration. 
                            Resellers are fetched live from the destination server via WHM API.
                            <?php if ($savedResellerUser): ?>
                                <br><strong class="text-success">Currently saved: <?= h($savedResellerUser) ?></strong><?= $savedResellerSavedAt ? " (set: {$savedResellerSavedAt})" : '' ?>
                            <?php endif; ?>
                        </p>
                        <div class="input-group shadow-sm">
                            <select id="dst_reseller_user_sel" class="form-select">
                                <option value="">— Select Target Server Above To Load —</option>
                            </select>
                            <button class="btn btn-info text-white fw-bold" id="btnSaveDstReseller"><i class="fas fa-save me-1"></i>Set Owner</button>
                        </div>
                        <div id="dstResellerMsg" class="mt-2 small fw-bold"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h6 class="mb-0 fw-bold"><i class="fas fa-users text-primary me-2"></i>Source Accounts <span class="badge bg-secondary ms-1"><?= count($bulkAccounts) ?></span></h6>
        <div class="d-flex gap-2">
            <button class="btn btn-sm btn-outline-secondary shadow-sm" id="btnResetSelected"><i class="fas fa-key me-1"></i> Prepare Selected</button>
            <button class="btn btn-sm btn-primary shadow-sm" id="btnMigrateSelected"><i class="fas fa-rocket me-1"></i> Bulk Migrate Selected</button>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="bg-light small text-muted">
                <tr>
                    <th style="width:40px"><input type="checkbox" id="chkAll" class="form-check-input"></th>
                    <th>User / Domain</th>
                    <th>Owner</th>
                    <th>Prep</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bulkAccounts as $a):
                    $u       = $a['user'] ?? '';
                    $domain  = $a['domain'] ?? '';
                    $owner   = $a['owner'] ?? '';
                    $susp    = (int)($a['suspended'] ?? 0);
                    $hasPass = isset($vault['accounts'][$u]['pass']);
                    $ownerSet= isset($vault['ownership'][$u]['set_at']);
                ?>
                <tr>
                    <td><input type="checkbox" class="chkUser form-check-input" value="<?= h($u) ?>"></td>
                    <td>
                        <div class="fw-semibold"><?= h($u) ?></div>
                        <div class="text-muted small"><?= h($domain) ?></div>
                    </td>
                    <td><span class="badge bg-light text-dark border small"><?= h($owner ?: '—') ?></span></td>
                    <td>
                        <?php if ($hasPass): ?>
                            <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-pill small"><i class="fas fa-check me-1"></i>Ready</span>
                        <?php else: ?>
                            <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25 rounded-pill small">Pending</span>
                        <?php endif; ?>
                        <?php if ($ownerSet): ?>
                            <div class="text-success small mt-1"><i class="fas fa-link"></i> Linked</div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="small font-monospace text-muted" id="rowmsg-<?= h($u) ?>">
                            <?= $susp ? '<span class="text-warning">Suspended</span>' : 'Active' ?>
                        </div>
                    </td>
                    <td>
                        <button class="btn btn-sm btn-light border shadow-sm me-1" onclick="startAutomatedMigration('<?= h($u) ?>')" title="Migrate"><i class="fas fa-play text-primary"></i></button>
                        <button class="btn btn-sm btn-light border shadow-sm" onclick="terminateDest('<?= h($u) ?>')" title="Terminate on Destination"><i class="fas fa-trash text-danger"></i></button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($bulkAccounts)): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">No accounts found on source.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Migration Logs Terminal -->
<div class="card border-0 overflow-hidden" style="background: #0f172a;">
    <div class="card-header border-bottom border-secondary d-flex justify-content-between align-items-center py-2" style="background:#0f172a;">
        <h6 class="mb-0 text-white small fw-bold"><i class="fas fa-terminal me-2 text-green-400"></i>Migration Logs</h6>
        <button class="btn btn-sm text-secondary" onclick="document.getElementById('out').textContent='System Ready.'"><i class="fas fa-eraser"></i> Clear</button>
    </div>
    <pre id="out" class="m-0 p-4 text-light small font-monospace" style="max-height:280px;overflow-y:auto;background:#0f172a;">System Ready.</pre>
</div>

<script>
function q(sel) { return document.querySelector(sel); }
function qa(sel) { return Array.from(document.querySelectorAll(sel)); }
function out(text) {
    const el = q('#out');
    el.textContent += "\n" + text;
    el.scrollTop = el.scrollHeight;
}
function looksFinished(obj) {
    try {
        const s = JSON.stringify(obj).toLowerCase();
        return s.includes('completed') || s.includes('finished') || s.includes('"state":"done"');
    } catch(e) { return false; }
}
function getServerId() { return q('#bulk_dest_server_id')?.value || ''; }
function getSelectedUsers() { return qa('.chkUser').filter(x => x.checked).map(x => x.value.trim()).filter(Boolean); }

async function postAction(action, data) {
    const fd = new FormData();
    for (const k in data) fd.append(k, data[k]);
    const res = await fetch(`?action=${encodeURIComponent(action)}&tab=bulk`, { method: 'POST', body: fd });
    return await res.json().catch(() => ({ ok: false, error: 'Invalid JSON' }));
}

// ── Load resellers when server changes ────────────────────────────────────────
async function loadResellers() {
    const sel = q('#dst_reseller_user_sel');
    sel.innerHTML = '<option value="">Loading resellers from server...</option>';
    sel.disabled = true;

    const r = await postAction('get_resellers', { dest_server_id: getServerId() });
    sel.innerHTML = '';
    if (!r.ok || !r.resellers?.length) {
        sel.innerHTML = '<option value="">No resellers found or API error</option>';
        sel.disabled = false;
        return;
    }
    r.resellers.forEach(res => {
        const opt = document.createElement('option');
        opt.value = res;
        opt.textContent = res;
        sel.appendChild(opt);
    });
    sel.disabled = false;
}

q('#bulk_dest_server_id')?.addEventListener('change', loadResellers);
window.addEventListener('DOMContentLoaded', () => { if (q('#bulk_dest_server_id')?.value) loadResellers(); });

// ── Save Target Reseller ──────────────────────────────────────────────────────
q('#btnSaveDstReseller')?.addEventListener('click', async (e) => {
    e.preventDefault();
    const sel = q('#dst_reseller_user_sel');
    const resUser = sel?.value;
    if (!resUser) return alert('Please select a reseller from the list.');

    q('#dstResellerMsg').innerHTML = '<span class="text-warning"><i class="fas fa-circle-notch fa-spin"></i> Saving...</span>';
    const r = await postAction('save_dst_reseller', { dst_reseller_user: resUser });
    if (r.ok) q('#dstResellerMsg').innerHTML = `<span class="text-success"><i class="fas fa-check-circle"></i> Owner set to <strong>${resUser}</strong>.</span>`;
    else q('#dstResellerMsg').innerHTML = `<span class="text-danger">Failed: ${r.error || ''}</span>`;
});

// ── Select All ────────────────────────────────────────────────────────────────
q('#chkAll')?.addEventListener('change', (e) => qa('.chkUser').forEach(x => x.checked = e.target.checked));

// ── Prepare selected (bulk reset passwords) ───────────────────────────────────
q('#btnResetSelected')?.addEventListener('click', async (e) => {
    e.preventDefault();
    const users = getSelectedUsers();
    if (!users.length) return alert('Select at least one account.');
    q('#btnResetSelected').disabled = true;
    out('Preparing (password reset) for ' + users.length + ' account(s)...');

    const r = await postAction('bulk_reset', { users: users.join(',') });
    if (!r.ok) {
        out('Preparation failed: ' + (r.error || 'Unknown'));
    } else {
        (r.results || []).forEach(res => {
            out(`  -> ${res.user}: ${res.ok ? 'Password set & vaulted securely' : 'FAIL – ' + res.error}`);
            if (res.ok) {
                const prepCell = q('#rowmsg-' + res.user)?.closest('tr')?.querySelector('td:nth-child(4)');
                if (prepCell) prepCell.innerHTML = '<span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-pill small"><i class="fas fa-check me-1"></i>Ready</span>';
            }
        });
        out('Preparation complete!');
    }
    q('#btnResetSelected').disabled = false;
});

// ── Bulk migrate selected ─────────────────────────────────────────────────────
q('#btnMigrateSelected')?.addEventListener('click', async (e) => {
    e.preventDefault();
    const users = getSelectedUsers();
    if (!users.length) return alert('Select at least one account.');
    if (!getServerId()) return alert('Select a destination server.');
    if (!confirm(`Bulk migrate ${users.length} account(s)? Do NOT close this page until complete.`)) return;

    q('#btnMigrateSelected').disabled = true;
    for (const u of users) await startAutomatedMigration(u);
    q('#btnMigrateSelected').disabled = false;
    out('\n=== All queued migrations finished ===');
});

// ── Terminate on destination ──────────────────────────────────────────────────
async function terminateDest(user) {
    if (!getServerId()) return alert('Select destination server first.');
    if (!confirm(`Permanently DELETE destination account "${user}"? This cannot be undone.`)) return;

    const row = q('#rowmsg-' + user);
    row.innerHTML = '<span class="text-danger fw-bold"><i class="fas fa-circle-notch fa-spin"></i> Terminating...</span>';
    const r = await postAction('terminate_dest', { cp_user: user, dest_server_id: getServerId() });
    if (r.ok) row.innerHTML = '<span class="text-muted fw-bold">Terminated – ready to retry.</span>';
    else { row.innerHTML = '<span class="text-danger fw-bold">Terminate Error</span>'; out('Terminate Error for ' + user + ': ' + r.error); }
}

// ── Full automated pipeline ───────────────────────────────────────────────────
async function startAutomatedMigration(user) {
    const row = q('#rowmsg-' + user);
    row.innerHTML = '<span class="text-primary fw-bold"><i class="fas fa-circle-notch fa-spin"></i> Queuing...</span>';
    out('Starting migration: ' + user);

    const m = await postAction('migrate_one', { cp_user: user, dest_server_id: getServerId() });
    if (!m.ok) {
        if (m.exists) {
            row.innerHTML = '<span class="text-danger fw-bold">Dest Exists</span>';
            out(`  -> ${user}: BLOCKED – account exists on destination. Use trash icon to remove first.`);
        } else {
            row.innerHTML = '<span class="text-danger fw-bold">Failed</span>';
            out(`  -> ${user}: ERROR – ${m.error}`);
        }
        return false;
    }

    row.innerHTML = '<span class="text-warning fw-bold"><i class="fas fa-circle-notch fa-spin"></i> Transferring...</span>';
    out(`  -> ${user}: Transfer queued! Session: ${m.transfer_session_id}. Monitoring...`);

    let done = false;
    for (let i = 0; i < 72; i++) {
        await new Promise(r => setTimeout(r, 5000));
        const st = await postAction('status', { transfer_session_id: m.transfer_session_id, dest_server_id: getServerId() });
        if (st.ok && looksFinished(st.status)) { done = true; break; }
        row.innerHTML = `<span class="text-warning fw-bold"><i class="fas fa-circle-notch fa-spin"></i> Transferring (${i + 1}/72)...</span>`;
    }

    if (!done) {
        row.innerHTML = '<span class="text-danger fw-bold">Timeout – check manually</span>';
        out(`  -> ${user}: Transfer check timed out. Verify manually in WHM.`);
        return false;
    }

    row.innerHTML = '<span class="text-info fw-bold"><i class="fas fa-circle-notch fa-spin"></i> Finalizing owner...</span>';
    out(`  -> ${user}: Transfer complete! Setting owner...`);

    const f = await postAction('finalize_owner', { cp_user: user, dest_server_id: getServerId() });
    if (f.ok) {
        row.innerHTML = `<span class="text-success fw-bold"><i class="fas fa-check-circle"></i> Done! Owner: ${f.owner}</span>`;
        out(`  -> ${user}: Fully migrated & assigned to reseller "${f.owner}"!`);
        return true;
    } else {
        row.innerHTML = '<span class="text-danger fw-bold">Owner Set Failed</span>';
        out(`  -> ${user}: Owner finalize error – ${f.error}`);
        return false;
    }
}
</script>

<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
