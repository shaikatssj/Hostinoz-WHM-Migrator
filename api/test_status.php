<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/MigrationSystem.php';

$db = new PDO('mysql:host=127.0.0.1;dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$running = $db->query(
    "SELECT m.*, s.host, s.user, s.token, s.name as dest_name
     FROM migrations m
     JOIN servers s ON m.dest_id = s.id
     ORDER BY m.id DESC LIMIT 1"
)->fetch();

if (!$running) {
    die("No migrations found.");
}

$rootHost  = $running['host'];
$rootUser  = $running['user'];
$rootToken = MigrationSystem::dec($running['token']);

$res = MigrationSystem::whmApiCall($rootHost, $rootUser, $rootToken, 'get_transfer_session_state', [
    'transfer_session_id' => $running['session_id']
]);

echo "SESSION ID: " . $running['session_id'] . "\n\n";
echo json_encode($res, JSON_PRETTY_PRINT);
