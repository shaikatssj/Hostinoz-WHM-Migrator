<?php
// install.php
session_start();

if (file_exists(__DIR__ . '/config.php') && filesize(__DIR__ . '/config.php') > 50) {
    die("Application is already installed (config.php exists). Please delete it to reinstall.");
}

$errors = [];
$success = '';

// Check required extensions (user request)
$required = ['pdo_mysql', 'curl', 'openssl', 'mbstring', 'json'];
$missing = [];
foreach ($required as $ext) {
    if (!extension_loaded($ext)) {
        $missing[] = $ext;
    }
}

if (!empty($missing)) {
    $errors[] = "The following required PHP extensions are missing: " . implode(', ', $missing) . ". Please enable them to continue.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($missing)) {
    $db_host = trim($_POST['db_host']);
    $db_name = trim($_POST['db_name']);
    $db_user = trim($_POST['db_user']);
    $db_pass = trim($_POST['db_pass']);
    $admin_user = trim($_POST['admin_user'] ?? 'admin');
    $admin_pass = $_POST['admin_pass'];
    
    if (empty($db_host) || empty($db_name) || empty($db_user) || empty($admin_user) || empty($admin_pass)) {
        $errors[] = "All fields except Database Password are required.";
    } else {
        try {
            // Test DB Connection
            $pdo_test = new PDO("mysql:host={$db_host};charset=utf8mb4", $db_user, $db_pass);
            $pdo_test->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Create DB if not exists
            $pdo_test->exec("CREATE DATABASE IF NOT EXISTS `{$db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            
            // Connect to real DB
            $db = new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Write config.php
            $encKey = bin2hex(random_bytes(32)); // 64 chars
            $configContent = "<?php\n" .
                "session_start();\n" .
                "error_reporting(E_ALL);\n" .
                "ini_set('display_errors', '1');\n\n" .
                "define('ENCRYPTION_KEY', '{$encKey}');\n\n" .
                "define('DB_HOST', '{$db_host}');\n" .
                "define('DB_NAME', '{$db_name}');\n" .
                "define('DB_USER', '{$db_user}');\n" .
                "define('DB_PASS', '{$db_pass}');\n\n" .
                "function h(\$s) {\n" .
                "    return htmlspecialchars((string)\$s, ENT_QUOTES, 'UTF-8');\n" .
                "}\n";
            file_put_contents(__DIR__ . '/config.php', $configContent);

            // Import Schema
            $db->exec("CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(255) UNIQUE NOT NULL,
                password VARCHAR(255) NOT NULL,
                email VARCHAR(255) UNIQUE NOT NULL,
                role VARCHAR(50) DEFAULT 'user',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");

            $db->exec("CREATE TABLE IF NOT EXISTS servers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                type VARCHAR(50) NOT NULL,
                host VARCHAR(255) NOT NULL,
                user VARCHAR(255) NOT NULL,
                token TEXT NOT NULL,
                name VARCHAR(255),
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");

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

            $db->exec("CREATE TABLE IF NOT EXISTS settings (
                key_name VARCHAR(255) PRIMARY KEY,
                key_value TEXT
            )");

            $db->exec("CREATE TABLE IF NOT EXISTS notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT,
                message TEXT NOT NULL,
                is_read TINYINT DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");

            // Remove existing admin if reinstalling
            $db->exec("DELETE FROM users WHERE role = 'admin'");
            
            // Insert Admin
            $adminHash = password_hash($admin_pass, PASSWORD_DEFAULT);
            $stmt = $db->prepare("INSERT INTO users (username, password, email, role) VALUES (?, ?, 'admin@domain.com', 'admin')");
            $stmt->execute([$admin_user, $adminHash]);

            $success = "Installation Complete! Please delete install.php for security.";

        } catch (PDOException $e) {
            $errors[] = "Database Error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Installer - WHM Migration Tool</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light" style="display: flex; align-items: center; justify-content: center; min-height: 100vh;">
<div class="container" style="max-width: 600px;">
    <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
        <div class="card-header bg-primary text-white py-3 text-center">
            <h4 class="mb-0 fw-bold">System Installer</h4>
        </div>
        <div class="card-body p-4 p-md-5">
            <?php if (!empty($errors)): ?>
                <?php foreach($errors as $er): ?>
                    <div class="alert alert-danger shadow-sm border-0"><i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($er) ?></div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success shadow-sm border-0"><i class="fas fa-check-circle me-2"></i><?= $success ?></div>
                <a href="index" class="btn btn-primary d-block w-100 py-3 fw-bold rounded-3 mt-4">Go to Application</a>
            <?php else: ?>
                <?php if (empty($missing)): ?>
                    <div class="alert alert-success small shadow-sm border-0">All required PHP extensions are active and running.</div>
                    <form method="POST">
                        <h5 class="mb-3 text-secondary border-bottom pb-2 fw-bold">Database Setup</h5>
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold">MySQL Host</label>
                            <input type="text" name="db_host" class="form-control bg-light border-0" value="localhost" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold">Database Name</label>
                            <input type="text" name="db_name" class="form-control bg-light border-0" value="whmtransfer" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold">Database Username</label>
                            <input type="text" name="db_user" class="form-control bg-light border-0" value="root" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold">Database Password</label>
                            <input type="password" name="db_pass" class="form-control bg-light border-0">
                        </div>
                        
                        <h5 class="mb-3 mt-5 text-secondary border-bottom pb-2 fw-bold">Admin Account</h5>
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold">Admin Username</label>
                            <input type="text" name="admin_user" class="form-control bg-light border-0" value="admin" required>
                        </div>
                        <div class="mb-4">
                            <label class="form-label text-muted small fw-bold">Admin Password</label>
                            <input type="password" name="admin_pass" class="form-control bg-light border-0" required placeholder="Choose a secure password">
                        </div>
                        
                        <button type="submit" class="btn btn-primary w-100 py-3 fw-bold rounded-3 mt-3 shadow-sm">Install System Engine</button>
                    </form>
                <?php else: ?>
                    <div class="alert alert-warning border-0 shadow-sm fw-bold">Warning: Essential modules missing.</div>
                    <p class="text-muted small">Please enable the missing extensions listed above in your <code>php.ini</code> configuration file or control panel, then refresh this page to continue installation.</p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
