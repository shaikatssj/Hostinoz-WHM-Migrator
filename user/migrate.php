<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/MigrationSystem.php';

if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header("Location: login");
    exit;
}

$stmt = $db->prepare("SELECT role FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$userRole = $stmt->fetchColumn();

$msg = '';
$err = '';
$servers = $db->query("SELECT id, name, host FROM servers WHERE type = 'dest' ORDER BY name ASC")->fetchAll();

// Clear dest_cpanel session
if (isset($_GET['clear_dest_cpanel'])) {
    unset($_SESSION['dest_cpanel']);
    header("Location: migrate?tab=single");
    exit;
}

$destCpanel = $_SESSION['dest_cpanel'] ?? null; // null means not connected yet

// Single migration is now entirely AJAX – no form POST handler here.
// See action=start_single and action=poll_single in the AJAX section below.

// ------------------------------------------------------------------
// BULK MIGRATION AJAX & LOGIC
// ------------------------------------------------------------------
const VAULT_FILE = __DIR__ . '/../data/.cp_migrate_vault.json';
if(!is_dir(__DIR__ . '/../data')) mkdir(__DIR__ . '/../data', 0755, true);

function json_out(array $data, int $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function normalize_host(string $host): string {
    $host = trim($host);
    $host = preg_replace('#^https?://#i', '', $host);
    $host = preg_replace('#/.*$#', '', $host);
    return $host;
}
function vault_load(): array {
    if (!file_exists(VAULT_FILE)) return ['accounts' => [], 'dst_reseller' => [], 'ownership' => []];
    $json = json_decode((string)file_get_contents(VAULT_FILE), true);
    if (!is_array($json)) $json = [];
    if (!isset($json['accounts'])) $json['accounts'] = [];
    if (!isset($json['dst_reseller'])) $json['dst_reseller'] = [];
    if (!isset($json['ownership'])) $json['ownership'] = [];
    return $json;
}
function vault_save(array $vault): bool { return file_put_contents(VAULT_FILE, json_encode($vault, JSON_PRETTY_PRINT)); }
function strong_password(int $len = 16): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#%^&*()-_=+';
    $out = '';
    for ($i=0; $i<$len; $i++) $out .= $chars[random_int(0, strlen($chars)-1)];
    return $out;
}
function get_dest_server(PDO $db, $id) {
    $stmt = $db->prepare("SELECT host, user, token FROM servers WHERE id = ?");
    $stmt->execute([(int)$id]);
    $server = $stmt->fetch();
    return $server ? ['host' => $server['host'], 'user' => $server['user'], 'token' => MigrationSystem::dec($server['token'])] : null;
}

if (isset($_GET['logout_source'])) {
    unset($_SESSION['src_whm']);
    header("Location: migrate?tab=bulk");
    exit;
}

$logged_in_src = isset($_SESSION['src_whm']) && is_array($_SESSION['src_whm']);
$login_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'source_login') {
    $host  = trim($_POST['host'] ?? '');
    $user  = trim($_POST['user'] ?? '');
    $token = trim($_POST['token'] ?? '');

    if (!$host || !$user || !$token) {
        $login_error = 'All fields required';
    } else {
        $test = MigrationSystem::whmApiCall($host, $user, $token, 'version');
        if (!($test['_ok'] ?? false)) {
            $login_error = 'Invalid WHM credentials/token (version test failed)';
        } else {
            $_SESSION['src_whm'] = ['host' => $host, 'user' => $user, 'token' => $token];
            header("Location: migrate?tab=bulk");
            exit;
        }
    }
}

if (isset($_GET['action']) && $_GET['action'] !== '') {
    $action = $_GET['action'];

    // verify_cpanel can run without bulk source login
    if ($_GET['action'] === 'verify_cpanel') {
        $cp_host      = trim($_POST['cp_host'] ?? '');
        $cp_user      = trim($_POST['cp_user'] ?? '');
        $cp_pass      = trim($_POST['cp_pass'] ?? '');
        $server_id    = (int)($_POST['dest_server_id'] ?? 0);

        if (!$cp_host || !$cp_user || !$cp_pass || !$server_id) json_out(['ok' => false, 'error' => 'All fields are required.'], 422);

        // Verify cPanel credentials directly against the destination host
        $destSrv = get_dest_server($db, $server_id);
        if (!$destSrv) json_out(['ok' => false, 'error' => 'Invalid destination server.'], 422);

        // Test via cPanel UAPI
        $cpHost = preg_replace('#^https?://#i', '', trim($destSrv['host'], '/'));
        $ch = curl_init("https://{$cpHost}:2083/execute/Mysql/get_server_information");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => ["Authorization: cpanel {$cp_user}:{$cp_pass}"],
        ]);
        curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http < 200 || $http >= 300) {
            // Try Basic (password)
            $ch2 = curl_init("https://{$cpHost}:2083/execute/Mysql/get_server_information");
            curl_setopt_array($ch2, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_HTTPHEADER     => ["Authorization: Basic " . base64_encode("{$cp_user}:{$cp_pass}")],
            ]);
            curl_exec($ch2);
            $http = (int)curl_getinfo($ch2, CURLINFO_HTTP_CODE);
            curl_close($ch2);
        }

        if ($http < 200 || $http >= 300) {
            json_out(['ok' => false, 'error' => 'cPanel verification failed. Check the hostname, username, and password/token.'], 403);
        }

        // Pull the domain from WHM root accountsummary
        $accRes = MigrationSystem::whmApiCall($destSrv['host'], $destSrv['user'], $destSrv['token'], 'accountsummary', ['user' => $cp_user]);
        $domain = $accRes['data']['acct'][0]['domain'] ?? $cp_host;

        $_SESSION['dest_cpanel'] = [
            'user'      => $cp_user,
            'domain'    => $domain,
            'host'      => $cpHost,
            'server_id' => $server_id,
        ];

        json_out(['ok' => true, 'user' => $cp_user, 'domain' => $domain]);
    }

    // ── start_single: AJAX-initiated solo migration ─────────────────────────
    if ($action === 'start_single') {
        if (!$destCpanel) json_out(['ok' => false, 'error' => 'Not connected to cPanel. Refresh and verify first.'], 401);

        $source_host  = trim($_POST['source_host'] ?? '');
        $source_user  = trim($_POST['source_user'] ?? '');
        $source_pass  = trim($_POST['source_pass'] ?? '');
        $force_rename = ($_POST['force_rename'] ?? '') === '1';

        if (!$source_host || !$source_user || !$source_pass)
            json_out(['ok' => false, 'error' => 'All source fields are required.'], 422);

        $dest_user   = $destCpanel['user'];
        $dest_domain = $destCpanel['domain'];
        $srv = get_dest_server($db, $destCpanel['server_id']);
        if (!$srv) json_out(['ok' => false, 'error' => 'Destination server not found.'], 422);

        $rootHost  = $srv['host'];
        $rootUser  = $srv['user'];
        $rootToken = $srv['token'];
        $localuser = $dest_user;

        // Remove old account if exists (ignore errors)
        MigrationSystem::whmApiCall($rootHost, $rootUser, $rootToken, 'removeacct', ['user' => $localuser, 'keepdns' => '0']);
        sleep(1);

        $res1 = MigrationSystem::whmApiCall($rootHost, $rootUser, $rootToken, 'create_remote_user_transfer_session', [
            'host'                 => normalize_host($source_host),
            'password'             => $source_pass,
            'unrestricted_restore' => '1',
        ]);
        if (!($res1['_ok'] ?? false)) json_out(['ok' => false, 'error' => 'Step 1 failed: Cannot authenticate to source server. Check hostname & password.'], 500);

        $sessionId = $res1['data']['transfer_session_id'] ?? null;
        if (!$sessionId) json_out(['ok' => false, 'error' => 'No transfer session ID returned by source.'], 500);

        $res2 = MigrationSystem::whmApiCall($rootHost, $rootUser, $rootToken, 'enqueue_transfer_item', [
            'module'              => 'AccountRemoteUser',
            'transfer_session_id' => $sessionId,
            'user'                => $source_user,
            'localuser'           => $localuser,
            'replaceip'           => 'basic',
            'mail_location'       => '.existing',
            'force'               => '1',
        ]);
        if (!($res2['_ok'] ?? false)) {
            $reason = (string)($res2['metadata']['reason'] ?? $res2['error'] ?? 'unknown');
            json_out(['ok' => false, 'error' => "Step 2 failed: {$reason}"], 500);
        }

        $res3 = MigrationSystem::whmApiCall($rootHost, $rootUser, $rootToken, 'start_transfer_session', [
            'transfer_session_id' => $sessionId
        ]);
        if (!($res3['_ok'] ?? false)) json_out(['ok' => false, 'error' => 'Step 3 failed: Could not start transfer session.'], 500);

        // Save rename intent
        $renameFlag = ($force_rename && $source_user !== $dest_user) ? $source_user : null;

        $stmt = $db->prepare("INSERT INTO migrations (user_id, domain, cp_user, whm_owner, dest_id, status, session_id) VALUES (?, ?, ?, ?, ?, 'running', ?)");
        $stmt->execute([$_SESSION['user_id'], $dest_domain, $localuser, $renameFlag, $destCpanel['server_id'], $sessionId]);
        $migId = $db->lastInsertId();

        MigrationSystem::notify($db, (int)$_SESSION['user_id'], "Solo migration for {$dest_domain} ({$dest_user}) started. Session: {$sessionId}.");

        json_out(['ok' => true, 'session_id' => $sessionId, 'migration_id' => $migId,
                  'dest_user' => $dest_user, 'dest_domain' => $dest_domain,
                  'server_id' => $destCpanel['server_id']]);
    }

    // ── poll_single: check a running solo migration status ──────────────────
    if ($action === 'poll_single') {
        $sessionId = trim($_POST['session_id'] ?? '');
        $serverId  = (int)($_POST['server_id'] ?? 0);
        if (!$sessionId || !$serverId) json_out(['ok' => false, 'error' => 'Missing params'], 422);

        $srv = get_dest_server($db, $serverId);
        if (!$srv) json_out(['ok' => false, 'error' => 'Server not found'], 422);

        $res = MigrationSystem::whmApiCall($srv['host'], $srv['user'], $srv['token'], 'get_transfer_session_state', [
            'transfer_session_id' => $sessionId
        ]);
        if (!($res['_ok'] ?? false)) json_out(['ok' => false, 'error' => 'Status API failed'], 500);

        $stateRaw = $res['data']['state'] ?? $res['data']['status'] ?? '';
        $state    = is_array($stateRaw) ? ($stateRaw[0] ?? '') : (string)$stateRaw;
        $progress = $res['data']['percent'] ?? $res['data']['progress'] ?? '';

        $jsonStr = strtolower(json_encode($res));
        $isCompleted = strpos($jsonStr, 'complet') !== false || strpos($jsonStr, 'success') !== false || strpos($jsonStr, 'done') !== false || strpos($jsonStr, 'finished') !== false || (is_numeric($progress) && $progress >= 100);
        $isFailed    = strpos($jsonStr, 'fail') !== false || strpos($jsonStr, 'abort') !== false || strpos($jsonStr, 'error') !== false;

        // Sync to DB and Notify
        if ($isCompleted) {
            $stmt = $db->prepare("SELECT id, status, cp_user, domain FROM migrations WHERE session_id=?");
            $stmt->execute([$sessionId]);
            if ($mig = $stmt->fetch()) {
                if ($mig['status'] !== 'completed') {
                    $db->prepare("UPDATE migrations SET status='completed', progress='100', updated_at=NOW() WHERE id=?")->execute([$mig['id']]);
                    MigrationSystem::notify($db, (int)$_SESSION['user_id'], "Solo Migration for {$mig['domain']} ({$mig['cp_user']}) completed!");
                }
            }
        } elseif ($isFailed) {
            $db->prepare("UPDATE migrations SET status='failed', updated_at=NOW() WHERE session_id=?")->execute([$sessionId]);
        } elseif ($progress !== '') {
            $db->prepare("UPDATE migrations SET progress=?, updated_at=NOW() WHERE session_id=?")->execute([(string)$progress, $sessionId]);
        }

        json_out(['ok' => true, 'state' => $state, 'progress' => $progress, 'is_completed' => $isCompleted, 'is_failed' => $isFailed]);
    }

    if (!$logged_in_src) json_out(['ok' => false, 'error' => 'Not logged in to source'], 401);


    $src = $_SESSION['src_whm'];
    $srcHost = $src['host'];
    $srcUser = $src['user'];
    $srcToken= $src['token'];
    $action = $_GET['action'];

    $la = MigrationSystem::whmApiCall($srcHost, $srcUser, $srcToken, 'listaccts', ['searchtype' => 'owner', 'search' => $srcUser, 'searchmethod' => 'exact']);
    $accounts = ($la['_ok'] ?? false) ? (array)($la['data']['acct'] ?? []) : [];

    $owns_account = function($user) use ($accounts) {
        foreach($accounts as $a) { if(($a['user'] ?? '') === $user) return true; }
        return false;
    };

    if ($action === 'save_dst_reseller') {
        $res_user = trim($_POST['dst_reseller_user'] ?? '');
        $res_token = trim($_POST['dst_reseller_token'] ?? '');
        $dest = get_dest_server($db, $_POST['dest_server_id'] ?? 0);
        
        if (!$res_user || !$res_token) json_out(['ok' => false, 'error' => 'Reseller username and API token required'], 422);
        if (!$dest) json_out(['ok' => false, 'error' => 'Invalid Destination Server'], 422);

        $test = MigrationSystem::whmApiCall($dest['host'], $res_user, $res_token, 'version');
        if (!($test['_ok'] ?? false)) json_out(['ok' => false, 'error' => 'Security Verification Failed. The API Token is invalid or the Reseller does not exist on the target server.'], 403);

        $vault = vault_load();
        $vault['dst_reseller'] = ['user' => MigrationSystem::enc($res_user), 'token' => MigrationSystem::enc($res_token), 'saved_at' => date('Y-m-d H:i:s')];
        vault_save($vault);
        json_out(['ok' => true]);
    }

    if ($action === 'bulk_reset') {
        $users = array_filter(array_map('trim', explode(',', $_POST['users'] ?? '')));
        if (!$users) json_out(['ok' => false, 'error' => 'No users selected'], 422);

        $vault = vault_load();
        $results = [];
        foreach ($users as $u) {
            if (!$owns_account($u)) {
                $results[] = ['user' => $u, 'ok' => false, 'error' => 'Unauthorized source account (Security Block)'];
                continue;
            }
            $newPass = strong_password(16);
            $resp = MigrationSystem::whmApiCall($srcHost, $srcUser, $srcToken, 'passwd', ['user' => $u, 'password' => $newPass]);
            if ($resp['_ok'] ?? false) {
                $vault['accounts'][$u] = [
                    'pass' => MigrationSystem::enc($newPass),
                    'changed_at' => date('Y-m-d H:i:s'),
                    'src_host' => MigrationSystem::enc(normalize_host($srcHost))
                ];
                $results[] = ['user' => $u, 'ok' => true, 'password' => $newPass];
            } else {
                $results[] = ['user' => $u, 'ok' => false, 'error' => 'Failed'];
            }
        }
        vault_save($vault);
        // Send password summary email to user + admin
        MigrationSystem::sendBulkPasswordEmail($db, (int)$_SESSION['user_id'], $results);
        json_out(['ok' => true, 'results' => $results, 'show_modal' => true]);
    }

    if ($action === 'terminate_dest') {
        $cp_user = trim($_POST['cp_user'] ?? '');
        $dest = get_dest_server($db, $_POST['dest_server_id'] ?? 0);
        if (!$cp_user || !$dest) json_out(['ok' => false, 'error' => 'Invalid parameters'], 422);
        if (!$owns_account($cp_user)) json_out(['ok' => false, 'error' => 'Unauthorized source account (Security Block)'], 403);

        $res = MigrationSystem::whmApiCall($dest['host'], $dest['user'], $dest['token'], 'removeacct', ['user' => $cp_user, 'keepdns' => '0']);
        if (!($res['_ok'] ?? false)) json_out(['ok' => false, 'error' => 'removeacct failed'], 500);
        json_out(['ok' => true, 'terminated' => true]);
    }

    if ($action === 'finalize_owner') {
        $cp_user = trim($_POST['cp_user'] ?? '');
        $dest = get_dest_server($db, $_POST['dest_server_id'] ?? 0);
        if (!$cp_user || !$dest) json_out(['ok' => false, 'error' => 'Invalid parameters'], 422);
        if (!$owns_account($cp_user)) json_out(['ok' => false, 'error' => 'Unauthorized source account (Security Block)'], 403);

        $vault = vault_load();
        $dst_reseller_user = MigrationSystem::dec($vault['dst_reseller']['user'] ?? '');
        if (!$dst_reseller_user) json_out(['ok' => false, 'error' => 'Destination Reseller owner not set'], 422);

        $res = MigrationSystem::whmApiCall($dest['host'], $dest['user'], $dest['token'], 'modifyacct', ['user' => $cp_user, 'owner' => $dst_reseller_user]);
        if (!($res['_ok'] ?? false)) json_out(['ok' => false, 'error' => 'modifyacct failed'], 500);

        $vault['ownership'][$cp_user] = ['owner' => MigrationSystem::enc($dst_reseller_user), 'set_at' => date('Y-m-d H:i:s')];
        vault_save($vault);
        
        // Mark as completed in DB
        $db->prepare("UPDATE migrations SET status='completed', progress='100', updated_at=NOW() WHERE cp_user=? AND dest_id=? AND status='running'")
           ->execute([$cp_user, $_POST['dest_server_id']]);

        // Send completion notification
        MigrationSystem::notify($db, (int)$_SESSION['user_id'], "Bulk Migration for {$cp_user} completed successfully!");

        json_out(['ok' => true, 'owner_set' => true, 'owner' => $dst_reseller_user]);
    }

    if ($action === 'migrate_one') {
        $cp_user = trim($_POST['cp_user'] ?? '');
        $dest = get_dest_server($db, $_POST['dest_server_id'] ?? 0);
        if (!$cp_user || !$dest) json_out(['ok' => false, 'error' => 'Invalid parameters'], 422);
        if (!$owns_account($cp_user)) json_out(['ok' => false, 'error' => 'Unauthorized source account (Security Block)'], 403);

        $vault = vault_load();
        $dst_reseller_user = MigrationSystem::dec($vault['dst_reseller']['user'] ?? '');
        if (!$dst_reseller_user) json_out(['ok' => false, 'error' => 'Destination Target Reseller not set.'], 422);

        $existsCheck = MigrationSystem::whmApiCall($dest['host'], $dest['user'], $dest['token'], 'accountsummary', ['user' => $cp_user]);
        if (($existsCheck['_ok'] ?? false)) json_out(['ok' => false, 'exists' => true, 'error' => "Account exists on destination."], 409);

        $entry = $vault['accounts'][$cp_user] ?? null;
        if (!$entry || empty($entry['pass'])) json_out(['ok' => false, 'error' => "No stored password. Bulk reset first."], 422);
        
        $cp_pass = MigrationSystem::dec($entry['pass']);

        $res1 = MigrationSystem::whmApiCall($dest['host'], $dest['user'], $dest['token'], 'create_remote_user_transfer_session', [
            'host' => normalize_host($srcHost),
            'password' => $cp_pass,
            'unrestricted_restore' => '1',
        ]);
        if (!($res1['_ok'] ?? false)) json_out(['ok' => false, 'error' => 'Source auth failed'], 500);
        
        $sessionId = $res1['data']['transfer_session_id'] ?? null;

        $res2 = MigrationSystem::whmApiCall($dest['host'], $dest['user'], $dest['token'], 'enqueue_transfer_item', [
            'module' => 'AccountRemoteUser',
            'transfer_session_id' => $sessionId,
            'user' => $cp_user,
            'localuser' => $cp_user,
            'replaceip' => 'basic',
            'mail_location' => '.existing',
            'ip' => '0',
            'force' => '1'
        ]);
        if (!($res2['_ok'] ?? false)) json_out(['ok' => false, 'error' => 'enqueue failed'], 500);

        $res3 = MigrationSystem::whmApiCall($dest['host'], $dest['user'], $dest['token'], 'start_transfer_session', [
            'transfer_session_id' => $sessionId
        ]);
        if (!($res3['_ok'] ?? false)) json_out(['ok' => false, 'error' => 'start failed'], 500);

        $stmt = $db->prepare("INSERT INTO migrations (user_id, domain, cp_user, whm_owner, dest_id, status, session_id) VALUES (?, ?, ?, ?, ?, 'running', ?)");
        $stmt->execute([$_SESSION['user_id'], $cp_user, $cp_user, $dst_reseller_user, $_POST['dest_server_id'], $sessionId]);

        json_out(['ok' => true, 'transfer_session_id' => $sessionId]);
    }

    if ($action === 'status') {
        $sessionId = trim($_POST['transfer_session_id'] ?? '');
        $dest = get_dest_server($db, $_POST['dest_server_id'] ?? 0);
        if (!$sessionId || !$dest) json_out(['ok' => false, 'error' => 'Params missing'], 422);

        $res = MigrationSystem::whmApiCall($dest['host'], $dest['user'], $dest['token'], 'get_transfer_session_state', [
            'transfer_session_id' => $sessionId
        ]);
        if (!($res['_ok'] ?? false)) json_out(['ok' => false, 'error' => 'status failed'], 500);
        json_out(['ok' => true, 'status' => $res]);
    }
}

// ------------------------------------------------------------------
// UI PRESENTATION
// ------------------------------------------------------------------
require_once __DIR__ . '/header.php';

$srcHost = '';
$srcUser = '';
$vault = vault_load();
$bulkAccounts = [];

$dstSavedResellerUser = MigrationSystem::dec($vault['dst_reseller']['user'] ?? '');
$dstOwnerSavedAt = $vault['dst_reseller']['saved_at'] ?? '';

if ($logged_in_src) {
    $srcHost = $_SESSION['src_whm']['host'];
    $srcUser = $_SESSION['src_whm']['user'];
    $la = MigrationSystem::whmApiCall($srcHost, $srcUser, $_SESSION['src_whm']['token'], 'listaccts', ['searchtype' => 'owner', 'search' => $srcUser, 'searchmethod' => 'exact']);
    $bulkAccounts = ($la['_ok'] ?? false) ? (array)($la['data']['acct'] ?? []) : [];
}

$activeTab = $_GET['tab'] ?? 'single';
$isBulkAllowed = true;
if ($activeTab === 'bulk' && !$isBulkAllowed) $activeTab = 'single';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold text-slate-800">Secure Migration Center</h4>
</div>

<?php if($msg): ?>
<div class="alert alert-success border-0 bg-success bg-opacity-10 text-success shadow-sm">
    <i class="fas fa-check-circle me-2"></i><?= h($msg) ?>
</div>
<?php endif; ?>
<?php if($err): ?>
<div class="alert alert-danger border-0 bg-danger bg-opacity-10 text-danger shadow-sm">
    <i class="fas fa-exclamation-triangle me-2"></i><?= h($err) ?>
</div>
<?php endif; ?>

<!-- Tabs -->
<?php if ($isBulkAllowed): ?>
<ul class="nav nav-tabs mb-4 border-bottom-0">
  <li class="nav-item">
    <a class="nav-link <?= $activeTab === 'single' ? 'active fw-bold text-primary border-primary border-bottom-0' : 'text-slate-600' ?>" href="?tab=single"><i class="fas fa-user border-0 me-2"></i>Single Account Migration</a>
  </li>
  <li class="nav-item ms-2">
    <a class="nav-link <?= $activeTab === 'bulk' ? 'active fw-bold text-primary border-primary border-bottom-0' : 'text-slate-600' ?>" href="?tab=bulk"><i class="fas fa-layer-group me-2"></i>Bulk Reseller Migration</a>
  </li>
</ul>
<?php endif; ?>

<!-- SINGLE MIGRATION TAB -->
<div style="display: <?= $activeTab === 'single' ? 'block' : 'none' ?>;">
    <?php if(empty($servers)): ?>
        <div class="alert alert-warning border-0">No destination servers available. Please contact support.</div>
    <?php else: ?>

    <!-- STEP 1: cPanel Connect -->
    <?php if (!$destCpanel): ?>
    <div class="row justify-content-center mb-4">
        <div class="col-md-7">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-bottom py-3">
                    <h5 class="fw-bold mb-0"><i class="fas fa-plug text-primary me-2"></i>Step 1: Verify Your New cPanel Account</h5>
                </div>
                <div class="card-body p-4">
                    <div class="alert alert-primary bg-primary bg-opacity-10 border-0 small text-primary mb-4">
                        <i class="fas fa-info-circle me-2"></i>Login with your <strong>newly purchased cPanel account</strong> to verify ownership and unlock the migration form.
                    </div>
                    <div id="cpanelConnectErr" class="alert alert-danger border-0 d-none small"></div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-muted small">Destination Server</label>
                        <select id="single_dest_server_id" class="form-select bg-light border-0 shadow-sm">
                            <?php foreach($servers as $s): ?>
                                <option value="<?= $s['id'] ?>"><?= h($s['name']) ?> (<?= h($s['host']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-muted small">New cPanel Hostname</label>
                        <input id="cp_host" class="form-control bg-light border-0 shadow-sm" placeholder="e.g. newserver.example.com" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-muted small">New cPanel Username</label>
                        <input id="cp_user" class="form-control bg-light border-0 shadow-sm" required>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold text-muted small">New cPanel Password <span class="text-muted fw-normal">(or API Token)</span></label>
                        <input type="password" id="cp_pass" class="form-control bg-light border-0 shadow-sm" required>
                    </div>
                    <button id="btnVerifyCpanel" class="btn btn-primary w-100 py-2 fw-bold shadow-sm">
                        <i class="fas fa-shield-alt me-2"></i>Verify & Connect
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php else: ?>
    <!-- Step 1 verified, show migration form -->
    <div class="alert alert-success bg-success bg-opacity-10 border-0 shadow-sm d-flex justify-content-between align-items-center mb-4">
        <div>
            <i class="fas fa-check-circle text-success me-2"></i>
            <strong>Connected:</strong> <code><?= h($destCpanel['user']) ?></code> &mdash; <strong><?= h($destCpanel['domain']) ?></strong>
        </div>
        <a href="?clear_dest_cpanel=1&tab=single" class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-times me-1"></i>Disconnect
        </a>
    </div>

    <form method="POST" id="singleMigrateForm">
        <input type="hidden" name="action_type" value="single_migrate">
        <input type="hidden" name="force_rename" id="forceRenameInput" value="">
        <div class="row g-4 mb-4">
            <!-- Source Details -->
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white border-bottom py-3">
                        <h5 class="fw-bold mb-0"><i class="fas fa-server text-secondary me-2"></i>Step 2: Source Details <span class="text-muted fw-normal small">(old server)</span></h5>
                    </div>
                    <div class="card-body p-4">
                        <div class="alert alert-secondary bg-light border-0 small mb-4">
                            Enter the credentials of the old host where you are migrating <strong>FROM</strong>.
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold text-muted small">Old Server Hostname (or IP)</label>
                            <input type="text" name="source_host" id="sourceHostInput" class="form-control form-control-lg bg-light border-0" required placeholder="e.g. 192.168.1.5">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold text-muted small">Old cPanel Username</label>
                            <input type="text" name="source_user" id="sourceUserInput" class="form-control form-control-lg bg-light border-0" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold text-muted small">Old cPanel Password</label>
                            <input type="password" name="source_pass" class="form-control form-control-lg bg-light border-0" required>
                        </div>
                        <div class="mt-4 bg-warning bg-opacity-10 p-3 rounded border border-warning border-opacity-25 small text-dark">
                            <i class="fas fa-exclamation-triangle text-warning me-2"></i><strong>Warning:</strong> The migration will restore your old data onto the newly purchased account.
                        </div>
                    </div>
                </div>
            </div>

            <!-- Mismatch Warning Panel -->
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white border-bottom py-3">
                        <h5 class="fw-bold mb-0"><i class="fas fa-info-circle text-info me-2"></i>Migration Details</h5>
                    </div>
                    <div class="card-body p-4">
                        <p class="text-muted small">Migrating from your old account to:</p>
                        <ul class="list-group list-group-flush mb-3">
                            <li class="list-group-item px-0"><span class="text-muted small">Username</span><div class="fw-bold"><?= h($destCpanel['user']) ?></div></li>
                            <li class="list-group-item px-0"><span class="text-muted small">Domain</span><div class="fw-bold"><?= h($destCpanel['domain']) ?></div></li>
                        </ul>

                        <!-- Rename warning – shown by JS when source_user !== dest_user -->
                        <div id="renameMismatchWarning" class="alert border border-warning bg-warning bg-opacity-10 d-none">
                            <div class="d-flex align-items-start">
                                <i class="fas fa-exclamation-triangle text-warning mt-1 me-2"></i>
                                <div class="small">
                                    <strong>Username Mismatch Detected</strong><br>
                                    Your source username (<code id="srcUserDisplay"></code>) differs from your new account (<code><?= h($destCpanel['user']) ?></code>).<br><br>
                                    If you proceed, your cPanel username will be <strong>renamed to <code><?= h($destCpanel['user']) ?></code></strong> and the domain will be set to <strong><?= h($destCpanel['domain']) ?></strong> after import.<br><br>
                                    <span class="text-danger fw-bold"><i class="fas fa-radiation me-1"></i>This may cause temporary instability. Only proceed if you understand the risks.</span>
                                </div>
                            </div>
                            <div class="mt-3 d-flex gap-2">
                                <button type="button" id="btnConfirmRename" class="btn btn-warning btn-sm fw-bold"><i class="fas fa-check me-1"></i>Yes, Proceed with Rename</button>
                                <button type="button" id="btnCancelRename" class="btn btn-outline-secondary btn-sm">Cancel</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="text-end">
            <button type="submit" id="btnSingleMigrate" class="btn btn-primary px-5 py-3 fw-bold shadow-sm">
                <i class="fas fa-rocket me-2"></i>Begin Migration
            </button>
        </div>
    </form>
    <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Solo Migration Live Log Terminal (hidden until migration starts) -->
<div id="singleLog" class="d-none mt-4 card border-0 overflow-hidden" style="background:#0f172a;">
    <div class="card-header border-bottom border-secondary py-2 d-flex justify-content-between align-items-center" style="background:#0f172a;">
        <h6 class="mb-0 text-white small fw-bold"><i class="fas fa-terminal me-2 text-info"></i>Migration Live Log</h6>
        <span class="text-muted small">Do not close this page until complete</span>
    </div>
    <pre id="singleOut" class="m-0 p-4 text-light small font-monospace" style="max-height:280px;overflow-y:auto;background:#0f172a;">Initializing...</pre>
</div>

<script>
// === cPanel Connect (Step 1) ===
document.getElementById('btnVerifyCpanel')?.addEventListener('click', async function() {
    const errBox = document.getElementById('cpanelConnectErr');
    errBox.classList.add('d-none');
    this.disabled = true;
    this.innerHTML = '<i class="fas fa-circle-notch fa-spin me-2"></i>Verifying...';
    const fd = new FormData();
    fd.append('cp_host', document.getElementById('cp_host').value);
    fd.append('cp_user', document.getElementById('cp_user').value);
    fd.append('cp_pass', document.getElementById('cp_pass').value);
    fd.append('dest_server_id', document.getElementById('single_dest_server_id').value);
    const res = await fetch('?action=verify_cpanel&tab=single', { method: 'POST', body: fd });
    const data = await res.json().catch(() => ({ ok: false, error: 'Server error' }));
    if (data.ok) {
        window.location.href = '?tab=single'; // reload to show session-driven form
    } else {
        errBox.textContent = data.error || 'Verification failed.';
        errBox.classList.remove('d-none');
        this.disabled = false;
        this.innerHTML = '<i class="fas fa-shield-alt me-2"></i>Verify & Connect';
    }
});

// === Username mismatch warning (Step 2) ===
const srcUserInput   = document.getElementById('sourceUserInput');
const destCpUser     = '<?= h($destCpanel['user'] ?? '') ?>';
const mismatchBox    = document.getElementById('renameMismatchWarning');
const srcUserDisplay = document.getElementById('srcUserDisplay');
const forceRename    = document.getElementById('forceRenameInput');

if (srcUserInput && mismatchBox) {
    srcUserInput.addEventListener('input', function() {
        const sDiff = this.value.trim() && this.value.trim() !== destCpUser;
        mismatchBox.classList.toggle('d-none', !sDiff);
        if (srcUserDisplay) srcUserDisplay.textContent = this.value.trim();
        forceRename.value = '';
    });
    document.getElementById('btnConfirmRename')?.addEventListener('click', function() {
        forceRename.value = '1';
        mismatchBox.innerHTML = '<div class="text-success small fw-bold"><i class="fas fa-check-circle me-1"></i>Rename confirmed. Click "Begin Migration" to proceed.</div>';
    });
    document.getElementById('btnCancelRename')?.addEventListener('click', function() {
        srcUserInput.value = '';
        mismatchBox.classList.add('d-none');
        forceRename.value = '';
    });
}

// === AJAX Solo Migration with Live Terminal ===
const singleForm = document.getElementById('singleMigrateForm');
const singleLog  = document.getElementById('singleLog');
const singleOut  = document.getElementById('singleOut');

function sLog(msg) {
    if (!singleOut) return;
    singleOut.textContent += '\n' + msg;
    singleOut.scrollTop = singleOut.scrollHeight;
    sessionStorage.setItem('singleLogData', singleOut.textContent);
}

document.addEventListener('DOMContentLoaded', () => {
    const savedSingleLog = sessionStorage.getItem('singleLogData');
    if (savedSingleLog && singleOut) {
        singleOut.textContent = savedSingleLog;
        singleLog.classList.remove('d-none');
        singleOut.scrollTop = singleOut.scrollHeight;
    }
});

singleForm?.addEventListener('submit', async function(e) {
    e.preventDefault();

    // Check rename confirmation
    const hasMismatch = srcUserInput?.value.trim() && srcUserInput?.value.trim() !== destCpUser;
    if (hasMismatch && forceRename?.value !== '1') {
        mismatchBox?.classList.remove('d-none');
        mismatchBox?.scrollIntoView({ behavior: 'smooth' });
        alert('Please confirm the username rename warning before proceeding.');
        return;
    }

    const btn = document.getElementById('btnSingleMigrate');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-circle-notch fa-spin me-2"></i>Starting...';

    singleLog?.classList.remove('d-none');
    singleOut.textContent = 'Connecting to source server...';
    sessionStorage.setItem('singleLogData', singleOut.textContent);

    const fd = new FormData(singleForm);
    fd.append('force_rename', forceRename?.value || '');

    const r = await fetch('?action=start_single', { method: 'POST', body: fd });
    const data = await r.json().catch(() => ({ ok: false, error: 'Server error' }));

    if (!data.ok) {
        sLog('ERROR: ' + data.error);
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-rocket me-2"></i>Begin Migration';
        return;
    }

    sLog('✓ Migration queued! Session ID: ' + data.session_id);
    sLog('Migrating ' + data.dest_user + ' @ ' + data.dest_domain);
    sLog('Polling transfer status every 5 seconds...');
    btn.innerHTML = '<i class="fas fa-circle-notch fa-spin me-2"></i>Transferring...';

    await startPollingSingle(data.session_id, data.server_id);
});

async function startPollingSingle(sessionId, serverId) {
    const btn = document.getElementById('btnSingleMigrate');
    let done = false;
    for (let i = 1; i <= 120; i++) {
        await new Promise(res => setTimeout(res, 5000));

        const pr = await fetch('?action=poll_single', {
            method: 'POST',
            body: new URLSearchParams({ session_id: sessionId, server_id: serverId })
        });
        const pd = await pr.json().catch(() => null);
        if (!pd?.ok) { sLog(`  [${i}] Status check failed – retrying...`); continue; }

        const prog  = pd.progress ? ` (${pd.progress}%)` : '';
        sLog(`  [${i}] State: ${pd.state || 'running'}${prog}`);

        if (pd.is_completed) {
            sLog('\n✅ Transfer COMPLETE! Your data has been migrated successfully.');
            if (btn) {
                btn.innerHTML = '<i class="fas fa-check-circle me-2"></i>Migration Complete!';
                btn.classList.replace('btn-primary', 'btn-success');
            }
            done = true;
            break;
        }
        if (pd.is_failed) {
            sLog('\n❌ Transfer FAILED or was aborted. Please contact support with the session ID: ' + sessionId);
            if (btn) {
                btn.innerHTML = '<i class="fas fa-times-circle me-2"></i>Migration Failed';
                btn.classList.replace('btn-primary', 'btn-danger');
            }
            done = true;
            break;
        }
    }

    if (!done) {
        sLog('\n⚠️ Timeout (10 minutes). Migration may still be running in the background. Check Migrations tab for updates.');
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-rocket me-2"></i>Begin Migration';
        }
    }
}
</script>

<?php
// Look for a running solo migration to auto-resume the frontend log
$runSoloStmt = $db->prepare("SELECT session_id, dest_id FROM migrations WHERE user_id = ? AND status = 'running' AND session_id IS NOT NULL ORDER BY id DESC LIMIT 1");
$runSoloStmt->execute([$_SESSION['user_id']]);
$resumeSolo = $runSoloStmt->fetch();
if ($resumeSolo):
?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const sl = document.getElementById('singleLog');
    const btn = document.getElementById('btnSingleMigrate');
    if (sl) sl.classList.remove('d-none');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-circle-notch fa-spin me-2"></i>Transferring...';
    }
    sLog('--- Resumed active tracking from previous session ---');
    sLog('Polling transfer status every 5 seconds...');
    startPollingSingle('<?= h($resumeSolo['session_id']) ?>', <?= (int)$resumeSolo['dest_id'] ?>);
});
</script>
<?php endif; ?>

<!-- BULK MIGRATION TAB -->
<?php if ($isBulkAllowed): ?>
<div style="display: <?= $activeTab === 'bulk' ? 'block' : 'none' ?>;">

    <?php if (!$logged_in_src): ?>
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card shadow-sm border-0 mt-3">
                <div class="card-header bg-white py-3 border-bottom">
                    <h5 class="mb-0 fw-bold text-slate-800"><i class="fas fa-server text-primary me-2"></i> Connect Source Reseller Server</h5>
                </div>
                <div class="card-body p-4">
                    <p class="text-muted small mb-4">Login to your <strong>old server</strong> using your reseller username and API token to select and migrate your accounts in bulk.</p>
                    <?php if ($login_error): ?>
                        <div class="alert alert-danger shadow-sm border-0"><i class="fas fa-exclamation-triangle me-2"></i> <?= h($login_error) ?></div>
                    <?php endif; ?>
                    <form method="post">
                        <input type="hidden" name="action_type" value="source_login">
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold">Source WHM Host</label>
                            <input name="host" class="form-control bg-light border-0" placeholder="e.g. old-server.example.com" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold">Source Reseller Username</label>
                            <input name="user" class="form-control bg-light border-0" placeholder="e.g. reseller_old" required>
                        </div>
                        <div class="mb-4">
                            <label class="form-label text-muted small fw-bold">Source API Token</label>
                            <input type="password" name="token" class="form-control bg-light border-0" placeholder="Paste your API token here" required>
                        </div>
                        <button class="btn btn-primary w-100 py-2 fw-bold shadow-sm">Connect & Fetch Accounts</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php else: ?>

    <div class="row g-4 mb-4">
        <div class="col-md-12">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-bold text-slate-800"><i class="fas fa-cogs text-secondary me-2"></i> Bulk Migration Settings</h5>
                    <a href="?logout_source=1" class="btn btn-sm btn-outline-danger shadow-sm"><i class="fas fa-sign-out-alt"></i> Disconnect Source</a>
                </div>
                <div class="card-body p-4 bg-light bg-opacity-50">
                    <div class="row">
                        <!-- Server Selection simplifies root token management securely -->
                        <div class="col-md-6 border-end">
                            <h6 class="fw-bold mb-3 text-primary">1. Select Target Server</h6>
                            <p class="small text-muted mb-3">Choose the server where these accounts will be migrated to.</p>
                            <div class="mb-3">
                                <select id="bulk_dest_server_id" class="form-select shadow-sm">
                                    <?php foreach($servers as $s): ?>
                                        <option value="<?= $s['id'] ?>"><?= h($s['name']) ?> (<?= h($s['host']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="col-md-6 ps-md-4">
                            <h6 class="fw-bold mb-3 text-info">2. Target Reseller Configuration</h6>
                            <p class="small text-muted mb-3">Enter your reseller username and API Token on the <strong>new target server</strong> so ownership is securely verified and properly assigned.</p>
                            <div class="mb-2">
                                <input id="dst_reseller_user" class="form-control mb-2 shadow-sm" value="<?= h($dstSavedResellerUser) ?>" placeholder="New target Reseller Username">
                                <div class="input-group shadow-sm">
                                    <input type="password" id="dst_reseller_token" class="form-control" placeholder="New target Reseller API Token">
                                    <button class="btn btn-info text-white fw-bold" id="btnSaveDstReseller">Save & Verify</button>
                                </div>
                            </div>
                            <div id="dstResellerMsg" class="mt-2 text-success small fw-bold"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white py-3 border-bottom d-flex flex-column flex-md-row justify-content-between align-items-center gap-3">
            <h5 class="mb-0 fw-bold text-slate-800"><i class="fas fa-users text-primary me-2"></i> Source Accounts (<?= count($bulkAccounts) ?>)</h5>
            <div>
                <button class="btn btn-sm btn-outline-secondary me-2" id="btnResetSelected"><i class="fas fa-key"></i> Prepare Selected</button>
                <button class="btn btn-sm btn-primary shadow-sm" id="btnMigrateSelected"><i class="fas fa-rocket"></i> Bulk Migrate Selected</button>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light text-slate-600 font-monospace small">
                    <tr>
                        <th style="width: 40px;"><input type="checkbox" id="chkAll" class="form-check-input"></th>
                        <th>User / Domain</th>
                        <th>Prep Status</th>
                        <th>Migration Process</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bulkAccounts as $a): 
                        $u = $a['user'] ?? '';
                        $domain = $a['domain'] ?? '';
                        $hasPass = isset($vault['accounts'][$u]['pass']);
                        $ownerSetAt = isset($vault['ownership'][$u]['set_at']);
                    ?>
                    <tr>
                        <td><input type="checkbox" class="chkUser form-check-input" value="<?= h($u) ?>"></td>
                        <td>
                            <div class="fw-bold text-slate-800"><?= h($u) ?></div>
                            <div class="text-muted small"><?= h($domain) ?></div>
                        </td>
                        <td>
                            <?php if ($hasPass): ?>
                                <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-pill"><i class="fas fa-check me-1"></i>Ready</span>
                            <?php else: ?>
                                <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25 rounded-pill">Needs Prep</span>
                            <?php endif; ?>
                            
                            <?php if ($ownerSetAt): ?>
                                <div class="small text-success mt-1"><i class="fas fa-check-circle"></i> Linked</div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="small font-monospace text-muted" id="rowmsg-<?= h($u) ?>">Idle</div>
                        </td>
                        <td>
                            <button class="btn btn-sm btn-light border shadow-sm" onclick="startAutomatedMigration('<?= h($u) ?>')" title="Migrate this Account"><i class="fas fa-play text-primary"></i></button>
                            <button class="btn btn-sm btn-light border shadow-sm" onclick="terminateDest('<?= h($u) ?>')" title="Delete from Destination"><i class="fas fa-trash text-danger"></i></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($bulkAccounts)): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">No accounts found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Output Terminal -->
    <div class="card shadow-sm border-0 bg-dark text-light overflow-hidden">
        <div class="card-header bg-dark border-bottom border-secondary py-2 d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-bold small"><i class="fas fa-terminal me-2"></i> Migration Logs</h6>
            <button class="btn btn-sm btn-dark text-secondary" onclick="document.getElementById('out').innerHTML = ''; sessionStorage.removeItem('bulkLogData');"><i class="fas fa-eraser"></i> Clear</button>
        </div>
        <div class="card-body p-0">
            <pre id="out" class="m-0 p-3 small font-monospace" style="max-height: 250px; overflow-y: auto;">System Ready.</pre>
        </div>
    </div>

    <script>
    function q(sel){ return document.querySelector(sel); }
    function qa(sel){ return Array.from(document.querySelectorAll(sel)); }
    function out(text){ 
        const el = q('#out');
        el.textContent += "\n" + text; 
        el.scrollTop = el.scrollHeight;
        sessionStorage.setItem('bulkLogData', el.textContent);
    }
    
    document.addEventListener('DOMContentLoaded', () => {
        const savedBulkLog = sessionStorage.getItem('bulkLogData');
        if (savedBulkLog && q('#out')) {
            q('#out').textContent = savedBulkLog;
            q('#out').scrollTop = q('#out').scrollHeight;
        }
    });
    
    function looksFinished(statusObj) {
        try {
            const s = JSON.stringify(statusObj).toLowerCase();
            return s.includes('completed') || s.includes('complete') || s.includes('finished') || s.includes('"state":"done"') || s.includes('"state":"completed"');
        } catch (e) { return false; }
    }

    function getSelectedUsers() { return qa('.chkUser').filter(x => x.checked).map(x => x.value.trim()).filter(Boolean); }
    function getServerId() { return q('#bulk_dest_server_id').value; }

    async function postAction(action, data) {
        const fd = new FormData();
        for (const k in data) fd.append(k, data[k]);
        const res = await fetch(`?action=${encodeURIComponent(action)}&tab=bulk`, { method:'POST', body: fd });
        return await res.json().catch(()=>({ok:false,error:'Invalid JSON response'}));
    }

    q('#chkAll')?.addEventListener('change', (e)=>{ qa('.chkUser').forEach(x => x.checked = e.target.checked); });

    q('#btnSaveDstReseller')?.addEventListener('click', async (e)=>{
        e.preventDefault();
        q('#dstResellerMsg').innerHTML = '<span class="text-warning"><i class="fas fa-circle-notch fa-spin"></i> Connecting to Target Server to Verify...</span>';
        const json = await postAction('save_dst_reseller', { 
            dst_reseller_user: q('#dst_reseller_user').value,
            dst_reseller_token: q('#dst_reseller_token').value,
            dest_server_id: getServerId()
        });
        if (json.ok) q('#dstResellerMsg').innerHTML = '<span class="text-success"><i class="fas fa-check-circle"></i> Target Reseller Verified & Saved!</span>';
        else q('#dstResellerMsg').innerHTML = '<span class="text-danger">Failed: '+(json.error||'')+'</span>';
    });

    q('#btnResetSelected')?.addEventListener('click', async (e)=>{
        e.preventDefault();
        const users = getSelectedUsers();
        if (!users.length) return alert('No users selected.');
        
        out('Preparing (Resetting Passwords) for ' + users.length + ' accounts...');
        q('#btnResetSelected').disabled = true;
        
        const r = await postAction('bulk_reset', { users: users.join(',') });
        if (!r.ok) {
            out('Preparation Failed: ' + (r.error || 'Unknown Error'));
        } else {
            out('Preparation Complete! Password summary emailed to you and admin.');
            for(let res of r.results) {
                out(`  -> ${res.user}: ${res.ok ? 'Password Generated & Vaulted securely' : 'FAIL - ' + res.error}`);
                if (res.ok) {
                    q('#rowmsg-' + res.user)?.closest('tr')?.querySelector('td:nth-child(4)')?.insertAdjacentHTML('beforeend',
                        '<div class="mt-1"><span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-pill"><i class="fas fa-check me-1"></i>Ready</span></div>'
                    );
                }
            }
            // Show password summary modal
            if (r.show_modal) showPasswordModal(r.results);
        }
        q('#btnResetSelected').disabled = false;
    });

    function showPasswordModal(results) {
        const successful = results.filter(r => r.ok);
        if (!successful.length) return;

        let tableRows = successful.map(r =>
            `<tr><td class="fw-semibold font-monospace">${r.user}</td><td class="font-monospace text-success fw-bold">${r.password}</td></tr>`
        ).join('');

        let plainText = 'Bulk Password Reset Summary\n' + '='.repeat(40) + '\n' +
            successful.map(r => `${r.user.padEnd(20)} => ${r.password}`).join('\n');

        q('#pwdModalBody').innerHTML = `
            <div class="alert alert-info border-0 small"><i class="fas fa-envelope me-2"></i>A copy of these passwords has been <strong>emailed to you and the admin</strong>. Save this now – it will not be shown again.</div>
            <div class="table-responsive">
                <table class="table table-sm align-middle font-monospace">
                    <thead class="table-dark small"><tr><th>Username</th><th>New Password</th></tr></thead>
                    <tbody>${tableRows}</tbody>
                </table>
            </div>`;
        q('#pwdPlainText').value = plainText;

        new bootstrap.Modal(document.getElementById('passwordModal')).show();
    }

    function copyPasswords() {
        const ta = q('#pwdPlainText');
        ta.select();
        document.execCommand('copy');
        const btn = q('#btnCopyPasswords');
        btn.innerHTML = '<i class="fas fa-check me-1"></i>Copied!';
        btn.classList.replace('btn-outline-secondary', 'btn-success');
        setTimeout(() => { btn.innerHTML = '<i class="fas fa-copy me-1"></i>Copy All'; btn.classList.replace('btn-success', 'btn-outline-secondary'); }, 2000);
    }

    function downloadPasswords() {
        const text = q('#pwdPlainText').value;
        const blob = new Blob([text], { type: 'text/plain' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'bulk_passwords_' + new Date().toISOString().slice(0,10) + '.txt';
        a.click();
    }

    q('#btnMigrateSelected')?.addEventListener('click', async (e)=>{
        e.preventDefault();
        const users = getSelectedUsers();
        if (!users.length) return alert('No users selected.');
        if(!getServerId()) return alert('Please select a Destination Server.');
        
        if(!confirm('Are you sure you want to begin bulk migrating ' + users.length + ' accounts? Do not close this page until finished!')) return;
        
        q('#btnMigrateSelected').disabled = true;
        for (let u of users) {
            await startAutomatedMigration(u);
        }
        q('#btnMigrateSelected').disabled = false;
        alert('Bulk Migration Queue Finished!');
    });

    async function terminateDest(user) {
        if(!getServerId()) return alert('Select destination server first.');
        if(!confirm('Delete destination account "'+user+'"?')) return;
        const row = q('#rowmsg-' + user);
        row.innerHTML = '<span class="text-danger fw-bold">Terminating...</span>';
        const r = await postAction('terminate_dest', { cp_user: user, dest_server_id: getServerId() });
        if(r.ok) row.innerHTML = '<span class="text-secondary fw-bold">Terminated. Ready to retry.</span>';
        else {
            row.innerHTML = '<span class="text-danger fw-bold">Term Error!</span>';
            out('Terminate Error: ' + r.error);
        }
    }

    async function startAutomatedMigration(user) {
        const row = q('#rowmsg-' + user);
        row.innerHTML = '<span class="text-primary fw-bold"><i class="fas fa-circle-notch fa-spin"></i> Queuing...</span>';
        out('Starting Migration Process for: ' + user);
        
        const m = await postAction('migrate_one', { cp_user: user, dest_server_id: getServerId() });
        if (!m.ok) {
            if (m.exists) {
                row.innerHTML = '<span class="text-danger fw-bold">Err: Dest Exists</span>';
                out(`  -> ${user} blocked: Already exists on destination. Use Trash icon to remove it first.`);
            } else {
                row.innerHTML = '<span class="text-danger fw-bold">Migration Failed</span>';
                out(`  -> ${user} Error: ` + m.error);
            }
            return false;
        }
        
        row.innerHTML = '<span class="text-warning text-dark fw-bold"><i class="fas fa-circle-notch fa-spin"></i> Transferring...</span>';
        out(`  -> ${user} Queued! Session: ${m.transfer_session_id}. Waiting...`);
        
        let isComplete = false;
        for(let i=0; i<60; i++) {
            await new Promise(r => setTimeout(r, 5000));
            const st = await postAction('status', { transfer_session_id: m.transfer_session_id, dest_server_id: getServerId() });
            if(st.ok && looksFinished(st.status)) {
                isComplete = true;
                break;
            }
            row.innerHTML = `<span class="text-warning text-dark fw-bold"><i class="fas fa-circle-notch fa-spin"></i> Transferring (${i}/60)...</span>`;
        }
        
        if(!isComplete) {
            row.innerHTML = '<span class="text-danger fw-bold">Timeout!</span>';
            out(`  -> ${user} Transfer Check Timeout! Skipping to next.`);
            return false;
        }
        
        row.innerHTML = '<span class="text-primary fw-bold"><i class="fas fa-circle-notch fa-spin"></i> Finalizing config...</span>';
        out(`  -> ${user} Transfer Complete! Finalizing ownership...`);
        
        const f = await postAction('finalize_owner', { cp_user: user, dest_server_id: getServerId() });
        if(f.ok) {
            row.innerHTML = '<span class="text-success fw-bold"><i class="fas fa-check-circle"></i> Completed!</span>';
            out(`  -> ${user} Fully Setup & Linked to Reseller!`);
            return true;
        } else {
            row.innerHTML = '<span class="text-danger fw-bold">Owner Error</span>';
            out(`  -> ${user} Finalize Owner Failed: ` + f.error);
            return false;
        }
    }
    </script>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>

<!-- Password Summary Modal -->
<div class="modal fade" id="passwordModal" tabindex="-1" aria-labelledby="passwordModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header border-bottom bg-dark text-white">
                <h5 class="modal-title" id="passwordModalLabel"><i class="fas fa-key me-2 text-warning"></i>Bulk Password Reset Summary</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4" id="pwdModalBody"></div>
            <div class="modal-footer border-top bg-light">
                <textarea id="pwdPlainText" class="d-none"></textarea>
                <button class="btn btn-outline-secondary btn-sm" id="btnCopyPasswords" onclick="copyPasswords()">
                    <i class="fas fa-copy me-1"></i>Copy All
                </button>
                <button class="btn btn-outline-primary btn-sm" onclick="downloadPasswords()">
                    <i class="fas fa-download me-1"></i>Download .txt
                </button>
                <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>Close
                </button>
            </div>
        </div>
    </div>
</div>

