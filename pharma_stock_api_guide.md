# Pharma-Stock WMS — PHP API Developer Guide

> **Role:** Backend (PHP API developer)
> **Stack:** PHP, MySQL, XAMPP | Frontend handled separately
> **Note:** "Antigravity" is assumed to refer to a PHP micro-framework/router. Adapt route registration syntax as needed.

---

## 1. Project Structure

```
pharma-stock-api/
├── config/
│   └── db.php               # DB connection
├── middleware/
│   └── auth.php             # JWT / session auth guard
├── controllers/
│   ├── AuthController.php
│   ├── MedicineController.php
│   ├── BatchController.php
│   ├── ExpiryController.php
│   ├── OrderController.php
│   └── ReportController.php
├── models/
│   ├── User.php
│   ├── Medicine.php
│   ├── Batch.php
│   └── Order.php
├── routes/
│   └── api.php              # All route definitions
├── helpers/
│   └── response.php         # Standardised JSON response helper
├── migrations/
│   ├── 001_create_migrations_table.sql
│   ├── 002_create_users.sql
│   ├── 003_create_medicines.sql
│   ├── 004_create_batches.sql
│   ├── 005_create_transactions.sql
│   ├── 006_create_purchase_orders.sql
│   └── migrate.php          # Migration runner
├── .env                     # DB creds, JWT secret (never commit)
└── index.php                # Entry point
```

---

## 2. Database Schema

### `users`
```sql
CREATE TABLE users (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    email       VARCHAR(150) UNIQUE NOT NULL,
    password    VARCHAR(255) NOT NULL,         -- bcrypt hash
    role        ENUM('admin','manager','distributor') NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### `medicines`
```sql
CREATE TABLE medicines (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(200) NOT NULL,
    manufacturer VARCHAR(200),
    category     VARCHAR(100),
    price        DECIMAL(10,2),
    reorder_point INT DEFAULT 50,              -- FR5: low stock threshold
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### `batches`
```sql
CREATE TABLE batches (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    medicine_id  INT NOT NULL,
    batch_number VARCHAR(100) UNIQUE NOT NULL,
    mfg_date     DATE NOT NULL,
    expiry_date  DATE NOT NULL,
    quantity     INT NOT NULL DEFAULT 0,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (medicine_id) REFERENCES medicines(id) ON DELETE CASCADE
);
```

### `transactions`
```sql
CREATE TABLE transactions (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    batch_id     INT NOT NULL,
    type         ENUM('in','out') NOT NULL,    -- stock in / stock out (sale)
    quantity     INT NOT NULL,
    reference    VARCHAR(200),                 -- invoice / PO number
    created_by   INT,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (batch_id) REFERENCES batches(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);
```

### `purchase_orders`
```sql
CREATE TABLE purchase_orders (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    medicine_id  INT NOT NULL,
    quantity     INT NOT NULL,
    status       ENUM('pending','approved','received') DEFAULT 'pending',
    created_by   INT,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (medicine_id) REFERENCES medicines(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);
```

---

## 3. API Endpoints

All responses follow the format:
```json
{ "success": true, "data": {}, "message": "" }
```

### 3.1 Auth — `FR1`

| Method | Endpoint | Description | Role |
|--------|----------|-------------|------|
| POST | `/api/auth/login` | Login, returns token | Public |
| POST | `/api/auth/logout` | Invalidate session | Any |

**POST /api/auth/login — Request:**
```json
{ "email": "manager@pharma.com", "password": "secret" }
```
**Response:**
```json
{ "success": true, "data": { "token": "jwt...", "role": "manager" } }
```

---

### 3.2 Medicine Inventory — `FR2`

| Method | Endpoint | Description | Role |
|--------|----------|-------------|------|
| GET | `/api/medicines` | List all medicines + current stock | Any |
| GET | `/api/medicines/{id}` | Single medicine detail | Any |
| POST | `/api/medicines` | Add new medicine | Admin |
| PUT | `/api/medicines/{id}` | Update medicine | Admin, Manager |
| DELETE | `/api/medicines/{id}` | Delete medicine | Admin |

**POST /api/medicines — Request:**
```json
{
    "name": "Paracetamol 500mg",
    "manufacturer": "Sun Pharma",
    "category": "Analgesic",
    "price": 12.50,
    "reorder_point": 100
}
```

---

### 3.3 Batch Tracking — `FR3`, `FR6`

| Method | Endpoint | Description | Role |
|--------|----------|-------------|------|
| GET | `/api/batches` | List all batches | Any |
| GET | `/api/batches/{id}` | Single batch detail + transactions | Any |
| GET | `/api/batches/search?batch_number=XYZ` | Search by batch number | Any |
| POST | `/api/batches` | Add new batch (stock in) | Manager |
| POST | `/api/batches/{id}/sell` | Record sale from batch (stock out) | Manager |

**POST /api/batches — Request:**
```json
{
    "medicine_id": 1,
    "batch_number": "BATCH-2025-001",
    "mfg_date": "2025-01-01",
    "expiry_date": "2027-01-01",
    "quantity": 500
}
```

**POST /api/batches/{id}/sell — Request:**
```json
{ "quantity": 50, "reference": "INV-1023" }
```
> **Note:** Use FEFO logic in the sell endpoint — always consume the batch expiring soonest first.

---

### 3.4 Expiry Monitoring — `FR4`

| Method | Endpoint | Description | Role |
|--------|----------|-------------|------|
| GET | `/api/expiry/dashboard` | All batches with expiry status | Any |
| GET | `/api/expiry/critical` | Only red + yellow batches | Any |

**GET /api/expiry/dashboard — Response shape:**
```json
{
    "success": true,
    "data": [
        {
            "batch_number": "BATCH-2025-001",
            "medicine_name": "Paracetamol 500mg",
            "expiry_date": "2025-05-15",
            "days_to_expiry": 18,
            "status": "red",
            "quantity": 200
        }
    ]
}
```

**Status logic to implement in SQL/PHP:**
```php
// Inside ExpiryController
$days = (int) $row['days_to_expiry'];
if ($days <= 30)      $status = 'red';
elseif ($days <= 60)  $status = 'yellow';
else                  $status = 'green';
```

---

### 3.5 Stock & Reorder Alerts — `FR5`

| Method | Endpoint | Description | Role |
|--------|----------|-------------|------|
| GET | `/api/stock/low` | Medicines below reorder point | Any |
| POST | `/api/orders` | Generate purchase order | Manager |
| GET | `/api/orders` | List all purchase orders | Manager, Admin |
| PUT | `/api/orders/{id}/status` | Update PO status | Admin |

**GET /api/stock/low — Response:**
```json
{
    "success": true,
    "data": [
        {
            "medicine_id": 3,
            "name": "Amoxicillin 250mg",
            "current_stock": 30,
            "reorder_point": 100,
            "shortage": 70
        }
    ]
}
```

---

### 3.6 Reports — `FR7`

| Method | Endpoint | Description | Role |
|--------|----------|-------------|------|
| GET | `/api/reports/inventory` | Full inventory snapshot | Manager, Admin |
| GET | `/api/reports/expiry-summary` | Count of red/yellow/green batches | Any |
| GET | `/api/reports/batch-sales?batch_number=XYZ` | Sales trail for a batch | Manager, Admin |
| GET | `/api/reports/transactions?from=&to=` | Transactions within date range | Manager, Admin |

---

## 4. Auth Middleware

Use JWT tokens. Attach to every protected route.

```php
// middleware/auth.php
function authenticate(array $allowed_roles = []) {
    $headers = getallheaders();
    $token   = $headers['Authorization'] ?? '';
    $token   = str_replace('Bearer ', '', $token);

    try {
        $decoded = JWT::decode($token, new Key(JWT_SECRET, 'HS256'));
        if (!empty($allowed_roles) && !in_array($decoded->role, $allowed_roles)) {
            response(403, false, null, 'Forbidden');
            exit;
        }
        return $decoded;
    } catch (Exception $e) {
        response(401, false, null, 'Unauthorised: ' . $e->getMessage());
        exit;
    }
}
```

**Usage in a controller:**
```php
$user = authenticate(['admin', 'manager']);
```

---

## 5. Standard Response Helper

```php
// helpers/response.php
function response(int $code, bool $success, $data = null, string $message = '') {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => $success, 'data' => $data, 'message' => $message]);
}
```

---

## 6. DB Connection

```php
// config/db.php
$host   = getenv('DB_HOST') ?: 'localhost';
$dbname = getenv('DB_NAME') ?: 'pharma_stock';
$user   = getenv('DB_USER') ?: 'root';
$pass   = getenv('DB_PASS') ?: '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    response(500, false, null, 'DB connection failed');
    exit;
}
```

---

## 7. Key Business Logic Notes

### FEFO on Sell
When a sale is recorded against a medicine (not a specific batch), the API must auto-select the batch expiring soonest with available stock:
```sql
SELECT id, quantity FROM batches
WHERE medicine_id = ? AND quantity > 0 AND expiry_date >= CURDATE()
ORDER BY expiry_date ASC
LIMIT 1;
```

### Auto Stock Deduction
After every `out` transaction, update `batches.quantity`:
```sql
UPDATE batches SET quantity = quantity - ? WHERE id = ?;
```

### Low Stock Check
After deduction, check against `medicines.reorder_point`:
```sql
SELECT SUM(b.quantity) as total_stock, m.reorder_point
FROM batches b JOIN medicines m ON b.medicine_id = m.id
WHERE m.id = ?
GROUP BY m.id;
```
If `total_stock < reorder_point`, include an `alert: true` flag in the response.

---

## 8. Security Checklist

- [ ] All inputs validated and sanitised (use PDO prepared statements only — no raw `$_POST` in SQL)
- [ ] Passwords hashed with `password_hash($pw, PASSWORD_BCRYPT)`
- [ ] JWT secret stored in `.env`, not in code
- [ ] Role-based access enforced on every protected endpoint
- [ ] CORS headers set correctly for frontend origin
- [ ] `.env` and `config/` excluded in `.gitignore`

---

## 9. Functional Requirements Traceability

| Requirement | Endpoint(s) |
|-------------|-------------|
| FR1 — User login | `POST /api/auth/login` |
| FR2 — Add medicines | `POST /api/medicines` |
| FR3 — Store batch details | `POST /api/batches` |
| FR4 — Monitor expiry dates | `GET /api/expiry/dashboard` |
| FR5 — Notify low stock | `GET /api/stock/low` |
| FR6 — Track batch sales | `POST /api/batches/{id}/sell`, `GET /api/reports/batch-sales` |
| FR7 — Generate reports | `GET /api/reports/*` |

---

## 10. Database Migrations (SQL Files + PHP Runner)

Instead of manually running SQL, use numbered `.sql` files and a PHP runner script. The runner tracks which migrations have already been applied so it's safe to run multiple times.

### How It Works

1. Each `.sql` file is numbered and contains one schema change.
2. `migrate.php` reads them in order, skips already-applied ones, and logs each run in a `migrations` table.
3. To set up the DB on any machine: just run `migrate.php` once.

---

### `migrations/001_create_migrations_table.sql`
```sql
-- Tracks which migrations have been run
CREATE TABLE IF NOT EXISTS migrations (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    filename   VARCHAR(255) NOT NULL UNIQUE,
    ran_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### `migrations/002_create_users.sql`
```sql
CREATE TABLE IF NOT EXISTS users (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    email       VARCHAR(150) UNIQUE NOT NULL,
    password    VARCHAR(255) NOT NULL,
    role        ENUM('admin','manager','distributor') NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### `migrations/003_create_medicines.sql`
```sql
CREATE TABLE IF NOT EXISTS medicines (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(200) NOT NULL,
    manufacturer  VARCHAR(200),
    category      VARCHAR(100),
    price         DECIMAL(10,2),
    reorder_point INT DEFAULT 50,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### `migrations/004_create_batches.sql`
```sql
CREATE TABLE IF NOT EXISTS batches (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    medicine_id  INT NOT NULL,
    batch_number VARCHAR(100) UNIQUE NOT NULL,
    mfg_date     DATE NOT NULL,
    expiry_date  DATE NOT NULL,
    quantity     INT NOT NULL DEFAULT 0,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (medicine_id) REFERENCES medicines(id) ON DELETE CASCADE
);
```

### `migrations/005_create_transactions.sql`
```sql
CREATE TABLE IF NOT EXISTS transactions (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    batch_id    INT NOT NULL,
    type        ENUM('in','out') NOT NULL,
    quantity    INT NOT NULL,
    reference   VARCHAR(200),
    created_by  INT,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (batch_id)   REFERENCES batches(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);
```

### `migrations/006_create_purchase_orders.sql`
```sql
CREATE TABLE IF NOT EXISTS purchase_orders (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    medicine_id INT NOT NULL,
    quantity    INT NOT NULL,
    status      ENUM('pending','approved','received') DEFAULT 'pending',
    created_by  INT,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (medicine_id) REFERENCES medicines(id),
    FOREIGN KEY (created_by)  REFERENCES users(id)
);
```

---

### `migrations/migrate.php`

```php
<?php
// Run from project root: php migrations/migrate.php
// Or visit in browser: http://localhost/pharma-stock-api/migrations/migrate.php

require_once __DIR__ . '/../config/db.php';  // provides $pdo

$migrationsDir = __DIR__;

// Step 1: Ensure the migrations tracking table exists first
$pdo->exec("
    CREATE TABLE IF NOT EXISTS migrations (
        id       INT AUTO_INCREMENT PRIMARY KEY,
        filename VARCHAR(255) NOT NULL UNIQUE,
        ran_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");

// Step 2: Get list of already-applied migrations
$applied = $pdo->query("SELECT filename FROM migrations")
               ->fetchAll(PDO::FETCH_COLUMN);

// Step 3: Find all .sql files, sorted by filename (numeric order)
$files = glob($migrationsDir . '/*.sql');
sort($files);

$ran = 0;
foreach ($files as $file) {
    $filename = basename($file);

    if (in_array($filename, $applied)) {
        echo "[SKIP]  $filename (already applied)\n";
        continue;
    }

    $sql = file_get_contents($file);

    try {
        $pdo->exec($sql);
        $stmt = $pdo->prepare("INSERT INTO migrations (filename) VALUES (?)");
        $stmt->execute([$filename]);
        echo "[OK]    $filename\n";
        $ran++;
    } catch (PDOException $e) {
        echo "[ERROR] $filename — " . $e->getMessage() . "\n";
        exit(1); // Stop on first failure
    }
}

echo $ran > 0
    ? "\nDone. $ran migration(s) applied.\n"
    : "\nNothing to migrate. Database is up to date.\n";
```

---

### Running the Migrations

**From the terminal (recommended):**
```bash
php migrations/migrate.php
```

**From the browser (XAMPP):**
```
http://localhost/pharma-stock-api/migrations/migrate.php
```

**Expected output on first run:**
```
[OK]    001_create_migrations_table.sql
[OK]    002_create_users.sql
[OK]    003_create_medicines.sql
[OK]    004_create_batches.sql
[OK]    005_create_transactions.sql
[OK]    006_create_purchase_orders.sql

Done. 6 migration(s) applied.
```

**On subsequent runs (nothing new):**
```
[SKIP]  001_create_migrations_table.sql (already applied)
...
Nothing to migrate. Database is up to date.
```

---

### Adding a New Migration Later

When you need to alter the schema (e.g., add a column), never edit existing files. Create a new numbered file:

```
migrations/007_add_notes_to_medicines.sql
```
```sql
ALTER TABLE medicines ADD COLUMN notes TEXT NULL AFTER category;
```

Then run `migrate.php` again — it will skip the first 6 and apply only the new one.

---

## 11. Development Checklist

- [ ] Set up XAMPP, create `pharma_stock` database (empty)
- [ ] Add all `.sql` files to `migrations/` folder
- [ ] Run `php migrations/migrate.php` to build all tables
- [ ] Configure `.env` with DB credentials and JWT secret
- [ ] Implement `config/db.php` and `helpers/response.php`
- [ ] Build `AuthController` (login + JWT issue)
- [ ] Build `MedicineController` (CRUD)
- [ ] Build `BatchController` (add batch, search, FEFO sell)
- [ ] Build `ExpiryController` (dashboard with colour status)
- [ ] Build `OrderController` (low stock list, PO generation)
- [ ] Build `ReportController` (inventory, batch sales, transactions)
- [ ] Test all endpoints with Postman
- [ ] Share base URL + request/response examples with frontend developer
