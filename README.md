
# Hostinoz WHM Migrator 🚀

![License](https://img.shields.io/badge/license-MIT-blue.svg)
![PHP Version](https://img.shields.io/badge/php-%3E%3D%207.4-8892BF.svg)
![Bootstrap](https://img.shields.io/badge/bootstrap-5.3-7952B3.svg)

**Hostinoz WHM Migrator** is a powerful, secure, and user-friendly PHP tool designed to automate and streamline cPanel account migrations between WHM servers. With dedicated panels for Administrators and Users/Resellers, live log tracking, and a built-in auto-installer, it takes the hassle out of server-to-server transfers.

Open-sourced with ❤️ by [Hostinoz.com](https://hostinoz.com) - *Premium Web Hosting Solutions*.

---

## ✨ Key Features

- **Automated Web Installer**: Set up the database and initial configuration instantly via [install.php](cci:7://file:///c:/xampp/htdocs/whm/install.php:0:0-0:0).
- **Dual Dashboard System**:
  - **Admin Panel**: Manage all WHM servers, configure API tokens, manage users, and view global migration logs.
  - **User/Reseller Panel**: A clean dashboard for clients or resellers to initiate migrations for their specific accounts.
- **Smart Migration Engine**: Uses WHM API 1 for native, fast, and secure account packaging and transfer.
- **Live Transfer Logs**: Real-time console output in the browser allows users to track migration progress natively (without SSH).
- **Auto-Resume**: If a user refreshes the page or closes the browser mid-migration, the tracker automatically resumes upon return.
- **API Endpoints (`/api/`)**: Readily available API infrastructure to tie this tool into your own billing platforms (like WHMCS or Blesta).

---

## 📂 Directory Structure

```text
hostinoz-whm-migrator/
├── admin/          # Admin Control Panel
├── api/            # API Endpoints (Handling Ajax calls, Log fetching, Status updates)
├── data/           # Storage for persistent logs and session temp data
├── includes/       # Core PHP classes, Functions, and Database configurations
├── user/           # User/Reseller Dashboard
├── index.php       # Main routing / login portal
├── install.php     # 1-Click Installation Script
└── .htaccess       # URL rewriting and security rules
```

---

## 🛠️ System Requirements

Before you install, ensure your environment meets the following:
- **OS**: Linux (CentOS, AlmaLinux, Ubuntu, etc.)
- **Web Server**: Apache, Nginx, or LiteSpeed
- **PHP**: 7.4 or higher (8.1+ recommended)
- **Database**: MySQL 5.7+ or MariaDB 10.3+
- **PHP Extensions**: `curl`, `json`, `mysqli`, `pdo`, `mbstring`

---

## 🚀 Installation Guide

Getting the script up and running takes less than 5 minutes.

1. **Download the Script**
   Clone the repository or download the ZIP file and upload the contents to your web server (e.g., `public_html` or a subdomain like `migrate.yourdomain.com`).
   ```bash
   git clone https://github.com/yourusername/hostinoz-whm-migrator.git
   ```

2. **Create a Database**
   Go into your hosting control panel (cPanel, DirectAdmin, etc.) and create a new **MySQL Database** and a **Database User**. Assign all privileges to the user.

3. **Run the Automated Installer**
   Navigate to the installer script in your browser:
   `https://migrate.yourdomain.com/install.php`

4. **Follow the Setup Wizard**
   - Enter your Database Name, Username, and Password.
   - Create your primary **Admin Account** (Username & Password).
   - Click "Install". The script will automatically build all required tables.

5. **Post-Installation Security (CRITICAL)**
   Once installed successfully, immediately delete or rename the [install.php](cci:7://file:///c:/xampp/htdocs/whm/install.php:0:0-0:0) file to prevent unauthorized access:
   ```bash
   rm install.php
   ```

---

## 📖 How to Use the Tool

### Phase 1: Administrator Setup (`/admin`)

1. **Login**: Navigate to `https://migrate.yourdomain.com/admin` and log in with the admin credentials you created during installation.
2. **Add Destination Server**:
   - Go to **Servers** -> **Add New Server**.
   - You will need the **WHM Hostname / IP**.
   - You will need a **WHM API Token** from the destination server (Create this in WHM -> Manage API Tokens).
   - Enter these details and save. Make sure the server verifies successfully.
3. **Add Source Server** (Optional for global migration, but required if predefined):
   - You can pre-load multiple source WHM servers the exact same way so that users can select them from a dropdown menu.
4. **Create Users** (Optional):
   - If you want Resellers or specific Users to migrate their own accounts, go to **User Management** -> **Add User** and grant them credentials.

### Phase 2: Running a Migration (`/user`)

If you are a User/Reseller, log into the User Panel:

1. **Start a New Transfer**:
   - Click on **New Migration** from the dashboard.
   - Enter the **cPanel Username** and **Domain Name** of the account you want to move.
   - Enter the **Source Server IP**, **WHM Username** (usually `root` or reseller username), and **Source WHM API Token / Password**.
   - Select the **Destination Server** (provided by the Admin).
   - Click **Start Migration**.

2. **Tracking the Progress**:
   - You will be redirected to the tracker screen.
   - A live "Console Log" window will appear. You will see real-time output (e.g., *Packaging account... transferring archive... extracting...*).
   - The status badge will show `Running` until the log confirms the process is `Completed`.

3. **What if I accidentally close the tab?**
   - No problem! The migration runs securely on the backend. When you log back in and go to your **Migration History**, click on the specific migration, and the logs will instantly resume streaming.

---

## 🔌 API Integration Documentation

The `/api/` directory acts as the brain of the backend. If you wish to build this directly into a custom dashboard (like WHMCS), you can ping the endpoints. *(Note: Secure endpoints require auth headers / active sessions).*

- **`POST /api/migrate.php`**: Initiates a new WHM API 1 `create_remote_root_transfer` module.
- **`GET /api/status.php?id={migration_id}`**: Returns JSON string containing `status`, `percent_complete`, and `latest_log_output`.

---

## 🛡️ Best Practices & Security Notes

- **API Tokens over Passwords**: Always generate **WHM API Tokens** rather than using your servers' Root Passwords. API tokens can be scoped and revoked easily.
- **HTTPS Only**: Ensure your domain is using a valid SSL certificate. Passing API tokens over unencrypted HTTP is severely dangerous.
- **Permissions**: Ensure the `/data/` folder is writable by the web server (usually `chmod 755` or `777` depending on your suPHP/FPM setup) so log files can be saved successfully.

---

## 🤝 Contributing

We encourage the open-source community to help improve the Hostinoz WHM Migrator!

1. **Fork** the repository.
2. **Create a branch** for your feature (`git checkout -b feature/EpicNewFeature`).
3. **Commit** your changes (`git commit -m 'Added an EpicNewFeature'`).
4. **Push** to the branch (`git push origin feature/EpicNewFeature`).
5. **Open a Pull Request**.

## 🐛 Bug Reports & Support

If you find a bug, please create an **Issue** on GitHub with steps to reproduce it. 
For enterprise support, managed migrations, or custom server setups, visit [Hostinoz.com](https://hostinoz.com).

## 📜 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

---
*Brought to you by Hostinoz.com - Empowering your digital journey.*
