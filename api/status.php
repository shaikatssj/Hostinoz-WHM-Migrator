<?php
// api/status.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/MigrationSystem.php';

header('Content-Type: application/json');

$apiKey = $_GET['api_key'] ?? $_POST['api_key'] ?? '';
$stmt = $db->query("SELECT key_value FROM settings WHERE key_name = 'api_key'");
$validKey = $stmt->fetchColumn();

// If API key is set in settings, enforce it
if ($validKey && $apiKey !== $validKey) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid or missing API Key']);
    exit;
}

$sessionId = $_GET['session_id'] ?? '';
if (!$sessionId) {
    http_response_code(400);
    echo json_encode(['error' => 'session_id required']);
    exit;
}

$stmt = $db->prepare("SELECT m.*, s.host, s.user, s.token FROM migrations m JOIN servers s ON m.dest_id = s.id WHERE m.session_id = ?");
$stmt->execute([$sessionId]);
$m = $stmt->fetch();

if (!$m) {
    http_response_code(404);
    echo json_encode(['error' => 'Migration not found in database']);
    exit;
}

$rootHost = $m['host'];
$rootUser = $m['user'];
$rootToken = MigrationSystem::dec($m['token']);

$res = MigrationSystem::whmApiCall($rootHost, $rootUser, $rootToken, 'get_transfer_session_state', [
    'transfer_session_id' => $sessionId
]);

if ($res['_ok'] ?? false) {
    echo json_encode(['status' => 'success', 'data' => $res['data']]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'WHM API Call failed', 'details' => $res]);
}
