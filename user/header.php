<?php
require_once __DIR__ . '/../includes/db.php';
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header("Location: login");
    exit;
}

$stmt = $db->prepare("SELECT username, email, role FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$currentUser = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Portal - WHM Migration</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f8fafc; font-family: 'Inter', sans-serif; }
        .sidebar { min-height: 100vh; background: #ffffff; border-right: 1px solid #e2e8f0; padding-top: 20px;}
        .sidebar a { color: #64748b; text-decoration: none; padding: 12px 24px; display: block; font-weight: 500; transition: 0.2s;}
        .sidebar a:hover, .sidebar a.active { background: #f1f5f9; color: #0f172a; border-right: 4px solid #3b82f6; }
        .sidebar i { width: 25px; }
        .content { padding: 30px; }
        @media (max-width: 768px) {
            .sidebar { position: fixed; left: -260px; z-index: 1045; transition: 0.3s; height: 100vh; overflow-y: auto; }
            .sidebar.show { left: 0; }
            .content { padding: 15px; width: 100%; }
            .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.5); z-index: 1040; }
            .sidebar-overlay.show { display: block; }
        }
        .card { border: none; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); border-radius: 12px; }
        .navbar { background: #fff; box-shadow: 0 1px 3px 0 rgba(0,0,0,0.05); }
    </style>
</head>
<body>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<div class="d-flex">
    <div class="sidebar" id="sidebar" style="width: 260px; flex-shrink: 0;">
        <div class="text-center mb-4 px-3">
            <h4 class="fw-bold text-primary mb-1">Migration Tool</h4>
            <span class="badge bg-light text-dark border"><?= h(ucfirst($currentUser['role'])) ?> Account</span>
        </div>
        <a href="index" class="<?= basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : '' ?>"><i class="fas fa-home"></i> Dashboard</a>
        <a href="migrate" class="<?= basename($_SERVER['PHP_SELF']) == 'migrate.php' ? 'active' : '' ?>"><i class="fas fa-paper-plane"></i> Request Migration</a>
        <a href="notifications" class="<?= basename($_SERVER['PHP_SELF']) == 'notifications.php' ? 'active' : '' ?>">
            <i class="fas fa-bell"></i> Notifications
            <?php 
                $nStmt = $db->prepare("SELECT count(*) FROM notifications WHERE user_id = ? AND is_read = 0");
                $nStmt->execute([$_SESSION['user_id']]);
                $un = $nStmt->fetchColumn();
                if($un > 0) echo "<span class='badge bg-danger float-end'>$un</span>";
            ?>
        </a>
        <a href="logout" class="text-danger mt-5"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
    <div class="flex-grow-1" style="background: #f8fafc; min-width: 0;">
        <nav class="navbar navbar-expand-lg px-4 py-3 bg-white border-bottom">
            <button class="btn btn-light d-md-none me-3" id="sidebarToggle"><i class="fas fa-bars"></i></button>
            <span class="navbar-brand mb-0 h5 fw-bold text-slate-800">Welcome, <?= h($currentUser['username']) ?></span>
        </nav>
        <div class="content">
