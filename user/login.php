<?php
require_once __DIR__ . '/../includes/db.php';

if (isset($_SESSION['user_logged_in']) && $_SESSION['user_logged_in'] === true) {
    header("Location: index");
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $captcha_response = $_POST['g-recaptcha-response'] ?? '';

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

        if ($user && in_array($user['role'], ['user', 'reseller']) && password_verify($password, $user['password'])) {
            $_SESSION['user_logged_in'] = true;
            $_SESSION['user_id'] = $user['id'];
            header("Location: index");
            exit;
        } else {
            $error = "Invalid username or password.";
        }
    }
}

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
    <title>Client Login - WHM Migration</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <?php if($captchaEnabled): ?>
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <?php endif; ?>
    <style>
        body { background: #f1f5f9; font-family: 'Inter', sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 20px;}
        .login-card { width: 100%; max-width: 420px; padding: 3rem 2.5rem; border-radius: 16px; background: #fff; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1); }
        .form-control:focus { border-color: #3b82f6; box-shadow: 0 0 0 0.25rem rgba(59, 130, 246, 0.25); background: #fff !important; }
        .btn-primary { background: #3b82f6; border: none; padding: 12px; font-weight: 600; font-size: 1.1rem; border-radius: 8px;}
        .btn-primary:hover { background: #2563eb; transform: translateY(-1px); box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.3); }
        .input-group-text { background: transparent; border-right: none; color: #94a3b8; }
        .form-control.with-icon { border-left: none; }
    </style>
</head>
<body>

<div class="login-card border-0">
    <div class="text-center mb-4">
        <div class="bg-primary bg-opacity-10 d-inline-block p-3 rounded-circle mb-3">
            <i class="fas fa-server fa-2x text-primary"></i>
        </div>
        <h3 class="fw-bold text-slate-800">Migration Portal</h3>
        <p class="text-muted small">Sign in to manage your migrations</p>
    </div>
    
    <?php if($error): ?>
        <div class="alert alert-danger border-0 bg-danger bg-opacity-10 text-danger shadow-sm"><i class="fas fa-exclamation-circle me-2"></i><?= h($error) ?></div>
    <?php endif; ?>
    
    <form method="POST">
        <div class="mb-4">
            <label class="form-label fw-semibold text-slate-700 small">Username</label>
            <div class="input-group">
                <span class="input-group-text bg-light border-0"><i class="fas fa-user"></i></span>
                <input type="text" name="username" class="form-control form-control-lg bg-light border-0 with-icon" required placeholder="Enter username">
            </div>
        </div>
        <div class="mb-4">
            <label class="form-label fw-semibold text-slate-700 small">Password</label>
            <div class="input-group">
                <span class="input-group-text bg-light border-0"><i class="fas fa-lock"></i></span>
                <input type="password" name="password" class="form-control form-control-lg bg-light border-0 with-icon" required placeholder="Enter password">
            </div>
        </div>
        <?php if($captchaEnabled && $sitekey): ?>
        <div class="mb-4 d-flex justify-content-center">
            <div class="g-recaptcha" data-sitekey="<?= h($sitekey) ?>"></div>
        </div>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary w-100 transition-all mt-2">Sign In</button>
        
        <div class="text-center mt-4">
            <p class="text-muted small mb-0">Don't have an account? <a href="register" class="text-primary fw-bold text-decoration-none">Register here</a></p>
        </div>
    </form>
</div>

</body>
</html>
