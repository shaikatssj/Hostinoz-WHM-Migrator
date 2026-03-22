<?php
require_once __DIR__ . '/header.php';

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $settings = [
        'smtp_enabled' => isset($_POST['smtp_enabled']) ? '1' : '0',
        'smtp_from' => trim($_POST['smtp_from'] ?? ''),
        'admin_email' => trim($_POST['admin_email'] ?? ''),
        'captcha_enabled' => isset($_POST['captcha_enabled']) ? '1' : '0',
        'captcha_sitekey' => trim($_POST['captcha_sitekey'] ?? ''),
        'captcha_secret' => trim($_POST['captcha_secret'] ?? ''),
    ];

    $stmt = $db->prepare("REPLACE INTO settings (key_name, key_value) VALUES (?, ?)");
    foreach ($settings as $k => $v) {
        $stmt->execute([$k, $v]);
    }
    $msg = "Settings saved successfully.";
}

$stmt = $db->query("SELECT * FROM settings");
$current = [];
while($row = $stmt->fetch()) $current[$row['key_name']] = $row['key_value'];
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold text-slate-800">System Settings</h4>
</div>

<?php if($msg): ?><div class="alert alert-success border-0 shadow-sm"><?= h($msg) ?></div><?php endif; ?>

<form method="POST">
    <div class="row g-4">
        <div class="col-md-6">
            <div class="card p-4 h-100 border-0 shadow-sm">
                <div class="d-flex align-items-center mb-4">
                    <div class="bg-primary bg-opacity-10 p-3 rounded-circle me-3">
                        <i class="fas fa-envelope text-primary fa-lg"></i>
                    </div>
                    <h5 class="fw-bold mb-0 text-slate-800">Email Notifications</h5>
                </div>
                
                <div class="form-check form-switch mb-4 fs-5">
                    <input class="form-check-input" type="checkbox" name="smtp_enabled" id="smtp_enabled" value="1" <?= ($current['smtp_enabled']??'0')=='1' ? 'checked' : '' ?>>
                    <label class="form-check-label ms-2 mt-1 fs-6" for="smtp_enabled">Enable Email Notifications</label>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold text-slate-700">From Email Address</label>
                    <input type="email" name="smtp_from" class="form-control form-control-lg bg-light border-0" value="<?= h($current['smtp_from']??'') ?>" placeholder="noreply@yourdomain.com">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold text-slate-700">Admin Notification Email</label>
                    <input type="email" name="admin_email" class="form-control form-control-lg bg-light border-0" value="<?= h($current['admin_email']??'') ?>" placeholder="Enter email to receive alerts">
                    <div class="form-text mt-1 small text-muted">System completions & password dumps will be emailed here.</div>
                </div>
                
                <div class="alert alert-secondary bg-light border-0 small mt-auto mb-0">
                    <i class="fas fa-info-circle me-1"></i> Currently using PHP's built-in <code>mail()</code> function for simplified deployment. Ensure your server is configured to send emails.
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card p-4 h-100 border-0 shadow-sm">
                <div class="d-flex align-items-center mb-4">
                    <div class="bg-success bg-opacity-10 p-3 rounded-circle me-3">
                        <i class="fas fa-shield-alt text-success fa-lg"></i>
                    </div>
                    <h5 class="fw-bold mb-0 text-slate-800">Google reCAPTCHA v2</h5>
                </div>
                
                <div class="form-check form-switch mb-4 fs-5">
                    <input class="form-check-input" type="checkbox" name="captcha_enabled" id="captcha_enabled" value="1" <?= ($current['captcha_enabled']??'0')=='1' ? 'checked' : '' ?>>
                    <label class="form-check-label ms-2 mt-1 fs-6" for="captcha_enabled">Enable reCAPTCHA</label>
                    <div class="form-text mt-2 small text-muted">If enabled, reCAPTCHA applies to Admin Login, User Login, and Registration to prevent bots.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold text-slate-700">Site Key</label>
                    <input type="text" name="captcha_sitekey" class="form-control form-control-lg bg-light border-0" value="<?= h($current['captcha_sitekey']??'') ?>" placeholder="Paste Site Key here">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold text-slate-700">Secret Key</label>
                    <input type="password" name="captcha_secret" class="form-control form-control-lg bg-light border-0" value="<?= h($current['captcha_secret']??'') ?>" placeholder="Paste Secret Key here">
                </div>
            </div>
        </div>
    </div>
    
    <div class="text-end mt-4">
        <button type="submit" class="btn btn-primary px-5 py-2 fw-bold shadow-sm"><i class="fas fa-save me-2"></i> Save Changes</button>
    </div>
</form>

<?php require_once __DIR__ . '/footer.php'; ?>
