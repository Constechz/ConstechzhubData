# Constechzhub Data Bundle & Result Checker Hub

Constechzhub is a high-performance PHP-based automation platform for selling mobile data bundles, airtime, and academic result checker cards. Designed with multi-tier access control, the system enables Admins, Super Admins, Agents, Customers, and VIP clients to seamlessly perform, manage, and audit digital asset purchases.

---

## Key Features

- **Multi-Role Portal**: Tailored dashboards for Admin, Super Admin, Agent, Customer, and VIP users.
- **Bulk Order Processing**: Bulk upload spreadsheet templates or paste structured text to process large batches of data/airtime packages in a single execution flow.
- **Smart Wallet & Transactions**: Automated ledger tracking for wallet deposits, withdrawals, commission liquidations, and API/bundle purchases.
- **Payment Gateways**: Double-gate integration supporting both **Paystack** and **Moolre** payment services.
- **External API Provider Sync**: Custom provider configurations (`includes/api_providers.php`) to automatically route data and airtime requests to external telecom gateways.
- **Transactional Notifications**: SMS gateway integration (supporting **Arkesel** and **mNotify**) alongside custom SMTP emails.
- **PWA (Progressive Web App)**: Full service worker configuration for installing the application directly onto mobile and desktop interfaces.
- **SEO-Optimized Metadata**: Dynamically generated semantic HTML headers for fast page loading and search engine optimization.

---

## Codebase Directory Structure

```text
├── admin/                 # Admin dashboards, users, wallet, and settings managers
├── agent/                 # Agent specific portal, custom pricing tables, and store settings
├── api/                   # API gateway endpoints for developers/external consumers
├── assets/                # CSS styling, custom JS bundles, and vendor libraries (e.g., FontAwesome)
├── config/                # Main site configurations, database connection helper, and routing rules
├── cron/                  # Automated workers for order recovery, status syncing, and email queues
├── customer/              # Customer dashboard and transaction history
├── database/              # SQL schema file backups and schema update migrations
├── images/                # App-wide static assets, logo variations, and icons
├── includes/              # Shared helper modules (SMS adapters, email dispatchers, SEO handlers)
├── store/                 # Public e-commerce shop interface for buying packages without logging in
├── super-admin/           # Configuration tools reserved exclusively for platform owners
├── uploads/               # User-submitted document uploads (e.g. Ghana Card verification)
├── vip/                   # VIP user dashboard and special packages
└── index.php              # Public index router page
```

---

## Local Development Setup

### Prerequisites
- **XAMPP / WAMP / Laragon** (PHP 8.1+ & MySQL/MariaDB)
- **Composer** (Optional, if third-party libraries are updated)
- **Git** (For version control)

### Step-by-Step Installation

1. **Clone the Repository**:
   ```bash
   git clone https://github.com/Constechz/ConstechzhubData.git
   cd ConstechzhubData
   ```

2. **Configure Environment Variables**:
   Copy `.env.example` or create a `.env` file in the root directory:
   ```env
   APP_ENV=local
   SITE_NAME=Constechzhub
   SITE_URL=http://localhost/ConstechzhubData/
   DB_HOST=127.0.0.1
   DB_PORT=3306         # Change to 3308 if your MySQL runs on a custom port
   DB_NAME=constechzhub
   DB_USER=root
   DB_PASS=
   ```

3. **Import Database Schema**:
   - Start MySQL in your local controller panel (e.g., XAMPP).
   - Create a database named `constechzhub` using phpMyAdmin or the mysql CLI:
     ```sql
     CREATE DATABASE constechzhub;
     ```
   - Import the database schema from the backup file:
     ```bash
     mysql -u root -p constechzhub < database/constechzhub.sql
     ```

4. **Run Locally**:
   Move the folder into your local server directory (e.g., `C:/xampp/htdocs/ConstechzhubData`) and navigate to `http://localhost/ConstechzhubData/` in your browser.

---

## Deployment Guide (CloudPanel)

[CloudPanel](https://www.cloudpanel.io/) is a robust, lightweight hosting control panel optimized for PHP applications. Follow these steps to host and run Constechzhub in a production environment:

### Step 1: Create a PHP Site in CloudPanel
1. Log in to your CloudPanel admin panel.
2. In the left navigation, click on **Sites** and select **Add Site**.
3. Choose **Create a PHP Site**.
4. Enter your site details:
   - **Domain Name**: `yourdomain.com` (or `sub.yourdomain.com`)
   - **PHP Version**: `8.1` or `8.2` (recommended)
   - **Site User**: `clp-user` (CloudPanel automatically creates this system user)
5. Keep other settings default and click **Create**.

### Step 2: Set up the MySQL Database
1. Go to the **Databases** tab in your newly created site configuration.
2. Click **Add Database**.
3. Fill in the credentials:
   - **Database Name**: e.g., `constech_db`
   - **Database User**: e.g., `constech_db_user`
   - **Password**: Generate a strong password.
4. Click **Create** and record these credentials.

### Step 3: Deploy the Code via SSH
1. Copy the SSH connection details of your CloudPanel server.
2. Log into the server using your terminal:
   ```bash
   ssh clp-user@your-server-ip
   ```
3. Navigate to the website root directory:
   ```bash
   cd /home/cloudpanel/htdocs/yourdomain.com
   ```
4. If there is a default index page or placeholder folder, remove it:
   ```bash
   rm -rf htdocs/*
   ```
5. Clone the repository into the `/htdocs` folder:
   ```bash
   git clone https://github.com/Constechz/ConstechzhubData.git htdocs
   ```
6. Navigate into the application root:
   ```bash
   cd htdocs
   ```

### Step 4: Import the Database on CloudPanel
Use the CLI to import the local schema backup into your new production database:
```bash
mysql -u constech_db_user -p constech_db < database/constechzhub.sql
```
*(Enter your database password when prompted)*

### Step 5: Configure Environment Variables
1. Inside `/home/cloudpanel/htdocs/yourdomain.com/htdocs`, create a `.env` file:
   ```bash
   nano .env
   ```
2. Paste and update your production environment details:
   ```env
   APP_ENV=production
   SITE_NAME=Constechzhub
   SITE_URL=https://yourdomain.com/
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_NAME=constech_db
   DB_USER=constech_db_user
   DB_PASS=your_strong_database_password
   ADMIN_EMAIL=admin@yourdomain.com
   PAYSTACK_PUBLIC_KEY=pk_live_your_key_here
   PAYSTACK_SECRET_KEY=sk_live_your_key_here
   SMTP_HOST=smtp.gmail.com
   SMTP_PORT=587
   SMTP_USERNAME=your-email@gmail.com
   SMTP_PASSWORD=your-app-password
   ```
3. Press `CTRL+O` and `ENTER` to save, and `CTRL+X` to exit nano.

### Step 6: Set Directory Permissions
Make sure the server user `clp-user` has ownership of all code files, and make the `uploads` directory writeable:
```bash
chown -R clp-user:clp-user /home/cloudpanel/htdocs/yourdomain.com/htdocs
chmod -R 775 /home/cloudpanel/htdocs/yourdomain.com/htdocs/uploads
```

### Step 7: Configure Production Cron Workers
CloudPanel allows adding cron jobs directly via the GUI dashboard. Navigate to **Cron Jobs** inside your site panel and add the following scheduled tasks:

1. **Paystack Order Recovery** (Runs every 10 minutes to verify payment status):
   - **Command**: `/usr/bin/php /home/cloudpanel/htdocs/yourdomain.com/htdocs/cron/paystack_order_recovery.php`
   - **Schedule**: `*/10 * * * *`
   - **User**: `clp-user`

2. **Status Sync Updater** (Runs every minute to keep statuses up to date):
   - **Command**: `/usr/bin/php /home/cloudpanel/htdocs/yourdomain.com/htdocs/cron/status_updater.php`
   - **Schedule**: `* * * * *`
   - **User**: `clp-user`

3. **Email Broadcast Worker** (Runs every 5 minutes to dispatch queued emails):
   - **Command**: `/usr/bin/php /home/cloudpanel/htdocs/yourdomain.com/htdocs/cron/email_broadcast_worker.php`
   - **Schedule**: `*/5 * * * *`
   - **User**: `clp-user`

### Step 8: Install SSL Certificate
1. Go back to your CloudPanel admin interface.
2. Select your site, go to the **SSL/TLS** tab.
3. Click **Actions** and select **New Let's Encrypt Certificate**.
4. Click **Create and Install**.

Your webapp is now deployed and running securely with SSL, database automation, and cron jobs active!
