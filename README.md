# Hostinoz WHM Migrator 🚀

![License](https://img.shields.io/badge/license-MIT-blue.svg)
![PHP Version](https://img.shields.io/badge/php-%3E%3D%207.4-8892BF.svg)
![Bootstrap](https://img.shields.io/badge/bootstrap-5.3-7952B3.svg)

**Hostinoz WHM Migrator** is a powerful, secure, and user-friendly PHP-based script designed to streamline cPanel/WHM account migrations between servers. Built with modern web standards, it offers a dedicated Admin Panel, a seamless User Panel, and a robust API for remote management.

Open-sourced with ❤️ by [Hostinoz.com](https://hostinoz.com).

## ✨ Features

* **Dual Interface**: Separate, secure panels for Administrators and Users/Resellers.
* **Live Migration Tracking**: Real-time status updates and live persistent logs during the transfer process.
* **Automated Transfers**: Trigger and monitor WHM API-based transfers without touching the command line.
* **Server Management**: Admins can securely add, verify, and manage destination/source WHM servers.
* **API Integration**: Built-in API system allowing remote control and integration with billing systems (e.g., WHMCS).
* **Modern UI**: Clean, responsive, and intuitive interface powered by Bootstrap 5.
* **Secure Architecture**: Implementation of strict validation, secure credential storage, and session management.

## 🛠️ Requirements

- **PHP**: 7.4 or higher (8.1+ recommended)
- **Database**: MySQL/MariaDB
- **Web Server**: Apache, Nginx, or Litespeed
- **PHP Extensions**: `curl`, `json`, `mysqli`, `pdo`
- Required API Tokens/Access to source and destination WHM servers.

## 🚀 Installation

1. **Clone the repository:**
   ```bash
   git clone https://github.com/yourusername/hostinoz-whm-migrator.git
   cd hostinoz-whm-migrator
