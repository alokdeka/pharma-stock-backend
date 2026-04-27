# PharmaStock WMS - Core API

This is the central network routing Core for the **PharmaStock** project. It is built strictly on hyper-fast native PHP and decoupled completely from any heavy abstraction frameworks (like Laravel). All responses strictly output `application/json`.

## Core Technologies
- **Engine**: Pure PHP 8.x
- **Database**: Native MariaDB / MySQL via `PDO`.
- **Security**: Stateless JWT (`firebase/php-jwt`) authentication pipeline.
- **Architecture**: Classic MVC variant mapped strictly around custom central controller objects routing through `api.php`.

## Directory Structure
```
backend/
├── config/       # PDO Database setup and global PHP Settings overrides.
├── controllers/  # Logic executors bridging Models and Routes.
├── helpers/      # Extracted native tasks (CORS validation, simulated Mailer logic).
├── migrations/   # Procedural `.sql` schemas mapping database deployments.
├── routes/       # The entrypoint! Maps inbound Restful POST/GET to custom Controllers.
└── cron/         # Standalone daemon scripts designed expressly for OS crontab hooks.
```

## Setup & Deployment
To construct the API local server, simply wire the root map to your Apache/Nginx controller. 

### 1. Configure the Core
Copy the `config/cors.php` or `config/db.php` schemas based on your local machine configuration:
Ensure the database credentials (`pharma_stock`) correctly map to your unmapped DB.

### 2. Generate the Tables
We do not use an ORM. We deliberately built a procedural SQL migrator mapping strict database relationships manually. You **must** run this before boot:
```bash
php backend/migrations/migrate.php
```

### 3. Native Mailer Pipeline
Tokens relating to `Purchase Order Transitions` or `Password Reset` signals rely natively on the `helpers/email.php` string. 
> [!NOTE]
> If you are deploying locally without a SendMail config, the PHP engine will intelligently simulate the HTTP links to your `error_log` cleanly! If deploying to true production Nginx servers, simply un-comment the `mail()` hook.

### 4. Headless CRON Invocations
To automate stock checking daily independent of Web Requests:
Hook `cron/daily_checks.php` directly into your OS `crontab -e`:
```bash
0 2 * * * php /var/www/pharma-stock/backend/cron/daily_checks.php
```
