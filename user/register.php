<?php
require_once __DIR__ . '/../includes/db.php';

if (isset($_SESSION['user_logged_in']) && $_SESSION['user_logged_in'] === true) {
    header("Location: index");
    exit;
}

$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    // Role selection is forced to 'user' for now, can be changed later. Or we can allow them to select. We'll set 'user'.
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
        if (strlen($password) < 6) {
            $error = "Password must be at least 6 characters.";
        } else {
            $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            if ($stmt->fetchColumn() > 0) {
                $error = "Username or Email already exists.";
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("INSERT INTO users (username, password, email, role) VALUES (?, ?, ?, 'user')");
                if ($stmt->execute([$username, $hash, $email])) {
                    $success = "Registration successful! You can now <a href='login' class='fw-bold'>log in</a>.";
                } else {
                    $error = "Registration failed.";
                }
            }
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
    <title>Register - WHM Migration</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <?php if($captchaEnabled): ?>
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <?php endif; ?>
    <style>
        body { background: #f1f5f9; font-family: 'Inter', sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 20px;}
        .login-card { width: 100%; max-width: 450px; padding: 3rem 2.5rem; border-radius: 16px; background: #fff; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1); }
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
        <div class="bg-success bg-opacity-10 d-inline-block p-3 rounded-circle mb-3">
            <i class="fas fa-user-plus fa-2x text-success"></i>
        </div>
        <h3 class="fw-bold text-slate-800">Create Account</h3>
        <p class="text-muted small">Join the migration portal today</p>
    </div>
    
    <?php if($error): ?>
        <div class="alert alert-danger border-0 bg-danger bg-opacity-10 text-danger shadow-sm"><i class="fas fa-exclamation-circle me-2"></i><?= h($error) ?></div>
    <?php endif; ?>
    <?php if($success): ?>
        <div class="alert alert-success border-0 bg-success bg-opacity-10 text-success shadow-sm"><i class="fas fa-check-circle me-2"></i><?= $success ?></div>
    <?php else: ?>
    
    <form method="POST">
        <div class="mb-3">
            <label class="form-label fw-semibold text-slate-700 small">Username</label>
            <div class="input-group">
                <span class="input-group-text bg-light border-0"><i class="fas fa-user"></i></span>
                <input type="text" name="username" class="form-control form-control-lg bg-light border-0 with-icon" required placeholder="Choose a username">
            </div>
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold text-slate-700 small">Email Address</label>
            <div class="input-group">
                <span class="input-group-text bg-light border-0"><i class="fas fa-envelope"></i></span>
                <input type="email" name="email" class="form-control form-control-lg bg-light border-0 with-icon" required placeholder="name@example.com">
            </div>
        </div>
        <div class="mb-4">
            <label class="form-label fw-semibold text-slate-700 small">Password</label>
            <div class="input-group">
                <span class="input-group-text bg-light border-0"><i class="fas fa-lock"></i></span>
                <input type="password" name="password" class="form-control form-control-lg bg-light border-0 with-icon" required placeholder="Min. 6 characters">
            </div>
        </div>
        <?php if($captchaEnabled && $sitekey): ?>
        <div class="mb-4 d-flex justify-content-center">
            <div class="g-recaptcha" data-sitekey="<?= h($sitekey) ?>"></div>
        </div>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary w-100 transition-all mt-2">Register Account</button>
        
        <div class="text-center mt-4">
            <p class="text-muted small mb-0">Already have an account? <a href="login" class="text-primary fw-bold text-decoration-none">Sign in</a></p>
        </div>
    </form>
    <?php endif; ?>
</div>

</body>
</html>
