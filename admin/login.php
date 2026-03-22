<?php
require_once __DIR__ . '/../includes/db.php';

if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header("Location: index");
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $captcha_response = $_POST['g-recaptcha-response'] ?? '';

    // Check captcha if enabled
    $stmt = $db->query("SELECT key_value FROM settings WHERE key_name = 'captcha_enabled'");
    $captchaEnabled = $stmt->fetchColumn() === '1';

    if ($captchaEnabled) {
        $stmt = $db->query("SELECT key_value FROM settings WHERE key_name = 'captcha_secret'");
        $secret = $stmt->fetchColumn();
        $verify = @file_get_contents("https://www.google.com/recaptcha/api/siteverify?secret={$secret}&response={$captcha_response}");
        $captcha_success = json_decode($verify);
        if (!$captcha_success || !$captcha_success->success) {
            $error = "reCAPTCHA verification failed.";
        }
    }

    if (empty($error)) {
        $stmt = $db->prepare("SELECT id, password, role FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && $user['role'] === 'admin' && password_verify($password, $user['password'])) {
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_id'] = $user['id'];
            header("Location: index");
            exit;
        } else {
            $error = "Invalid credentials.";
        }
    }
}

// Get site key for form
$stmt = $db->query("SELECT key_value FROM settings WHERE key_name = 'captcha_sitekey'");
$sitekey = $stmt->fetchColumn();
$stmt = $db->query("SELECT key_value FROM settings WHERE key_name = 'captcha_enabled'");
$captchaEnabled = $stmt->fetchColumn() === '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - WHM Migration</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <?php if($captchaEnabled): ?>
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <?php endif; ?>
    <style>
        body { background: #0f172a; color: #fff; display: flex; align-items: center; justify-content: center; height: 100vh; font-family: 'Inter', sans-serif; }
        .login-card { width: 100%; max-width: 400px; padding: 2.5rem; border-radius: 12px; border: 1px solid #1e293b; background: #1e293b; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.5); }
        .form-control { background: #0f172a; border: 1px solid #334155; color: #fff; }
        .form-control:focus { background: #0f172a; color: #fff; border-color: #3b82f6; box-shadow: none; }
        .btn-primary { background: #3b82f6; border: none; }
        .btn-primary:hover { background: #2563eb; }
    </style>
</head>
<body>

<div class="login-card">
    <h3 class="text-center mb-4 text-white">Admin Secure Login</h3>
    <?php if($error): ?>
        <div class="alert alert-danger border-0 bg-danger text-white"><?= h($error) ?></div>
    <?php endif; ?>
    <form method="POST">
        <div class="mb-3">
            <label class="form-label text-secondary">Username</label>
            <input type="text" name="username" class="form-control" required>
        </div>
        <div class="mb-3">
            <label class="form-label text-secondary">Password</label>
            <input type="password" name="password" class="form-control" required>
        </div>
        <?php if($captchaEnabled && $sitekey): ?>
        <div class="mb-3 d-flex justify-content-center">
            <div class="g-recaptcha" data-sitekey="<?= h($sitekey) ?>"></div>
        </div>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary w-100 py-2 mt-2">Login to Admin</button>
    </form>
</div>

</body>
</html>
