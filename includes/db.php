<?php
// includes/db.php
if (!file_exists(__DIR__ . '/../config.php') || filesize(__DIR__ . '/../config.php') < 50) {
    $baseDir = realpath(__DIR__ . '/..');
    $scriptDir = dirname($_SERVER['SCRIPT_FILENAME']);
    $depth = substr_count(str_replace('\\', '/', $scriptDir), '/') - substr_count(str_replace('\\', '/', $baseDir), '/');
    $prefix = str_repeat('../', max(0, $depth));
    header("Location: " . $prefix . "install.php");
    exit;
}

require_once __DIR__ . '/../config.php';

try {
    // Connect to MySQL without dbname first to create it if not exists
    $pdo_setup = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS);
    $pdo_setup->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo_setup->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    
    // Connect to the actual db
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $db = new PDO($dsn, DB_USER, DB_PASS);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Users Table
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(255) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        email VARCHAR(255) UNIQUE NOT NULL,
        role VARCHAR(50) DEFAULT 'user',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Servers Table
    $db->exec("CREATE TABLE IF NOT EXISTS servers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type VARCHAR(50) NOT NULL,
        host VARCHAR(255) NOT NULL,
        user VARCHAR(255) NOT NULL,
        token TEXT NOT NULL,
        name VARCHAR(255),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Migrations Table
    $db->exec("CREATE TABLE IF NOT EXISTS migrations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        domain VARCHAR(255) NOT NULL,
        cp_user VARCHAR(255) NOT NULL,
        whm_owner VARCHAR(255) DEFAULT NULL,
        dest_id INT,
        status VARCHAR(50) DEFAULT 'pending',
        session_id VARCHAR(255),
        progress TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (dest_id) REFERENCES servers(id) ON DELETE SET NULL
    )");

    // Settings Table
    $db->exec("CREATE TABLE IF NOT EXISTS settings (
        key_name VARCHAR(255) PRIMARY KEY,
        key_value TEXT
    )");

    // Notifications Table
    $db->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT,
        message TEXT NOT NULL,
        is_read TINYINT DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Add backwards compatibility for whm_owner if table exists
    try { $db->exec("ALTER TABLE migrations ADD COLUMN whm_owner VARCHAR(255) DEFAULT NULL"); } catch (PDOException $e) { /* ignore */ }
    try { $db->exec("ALTER TABLE migrations ADD COLUMN progress TEXT DEFAULT NULL"); } catch (PDOException $e) { /* ignore */ }
    try { $db->exec("ALTER TABLE migrations ADD COLUMN session_id VARCHAR(255) DEFAULT NULL"); } catch (PDOException $e) { /* ignore */ }
    try { $db->exec("ALTER TABLE migrations ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"); } catch (PDOException $e) { /* ignore */ }

    // Initialize Default Admin
    $stmt = $db->query("SELECT COUNT(*) FROM users");
    if ($stmt->fetchColumn() == 0) {
        $adminPass = password_hash('admin123', PASSWORD_DEFAULT);
        $db->exec("INSERT INTO users (username, password, email, role) VALUES ('admin', '$adminPass', 'admin@admin.com', 'admin')");
    }

} catch (PDOException $e) {
    die("Database Connection failed: " . htmlspecialchars($e->getMessage()));
}
