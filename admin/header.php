<?php
require_once __DIR__ . '/../includes/db.php';
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - WHM Migration</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f8fafc; font-family: 'Inter', sans-serif; }
        .sidebar { min-height: 100vh; background: #0f172a; color: #fff; padding-top: 20px;}
        .sidebar a { color: #cbd5e1; text-decoration: none; padding: 12px 24px; display: block; font-weight: 500; transition: 0.2s;}
        .sidebar a:hover, .sidebar a.active { background: #1e293b; color: #fff; border-left: 4px solid #3b82f6; }
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
        <h4 class="text-center mb-4 text-white px-3 fw-bold">WHM Migrator</h4>
        <a href="index" class="<?= basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : '' ?>"><i class="fas fa-home"></i> Dashboard</a>
        <a href="servers" class="<?= basename($_SERVER['PHP_SELF']) == 'servers.php' ? 'active' : '' ?>"><i class="fas fa-server"></i> Servers</a>
        <a href="migrations" class="<?= basename($_SERVER['PHP_SELF']) == 'migrations.php' ? 'active' : '' ?>"><i class="fas fa-exchange-alt"></i> Migrations</a>
        <a href="migrate" class="<?= basename($_SERVER['PHP_SELF']) == 'migrate.php' ? 'active' : '' ?>"><i class="fas fa-rocket"></i> Bulk Migrate</a>
        <a href="users" class="<?= basename($_SERVER['PHP_SELF']) == 'users.php' ? 'active' : '' ?>"><i class="fas fa-users"></i> Users</a>
        <a href="settings" class="<?= basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'active' : '' ?>"><i class="fas fa-cog"></i> Settings</a>
        <a href="notifications" class="<?= basename($_SERVER['PHP_SELF']) == 'notifications.php' ? 'active' : '' ?>">
            <i class="fas fa-bell"></i> Notifications
            <?php 
                $nStmt = $db->query("SELECT count(*) FROM notifications WHERE user_id = 0 AND is_read = 0");
                $un = $nStmt->fetchColumn();
                if($un > 0) echo "<span class='badge bg-danger float-end'>$un</span>";
            ?>
        </a>
        <a href="logout" class="text-danger mt-5"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
    <div class="flex-grow-1" style="background: #f8fafc; min-width: 0;">
        <nav class="navbar navbar-expand-lg px-4 py-3">
            <button class="btn btn-dark d-md-none me-3" id="sidebarToggle"><i class="fas fa-bars"></i></button>
            <span class="navbar-brand mb-0 h5 fw-bold text-slate-800">Admin Dashboard</span>
        </nav>
        <div class="content">
