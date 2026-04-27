<?php
// Run from project root: php migrations/migrate.php

// Ensure autoload and Dotenv is called to load env variables first
require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

// Then connect DB
require_once __DIR__ . '/../config/db.php';

$migrationsDir = __DIR__;

$pdo->exec("
    CREATE TABLE IF NOT EXISTS migrations (
        id       INT AUTO_INCREMENT PRIMARY KEY,
        filename VARCHAR(255) NOT NULL UNIQUE,
        ran_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");

$applied = $pdo->query("SELECT filename FROM migrations")->fetchAll(PDO::FETCH_COLUMN);

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
        exit(1);
    }
}

echo $ran > 0
    ? "\nDone. $ran migration(s) applied.\n"
    : "\nNothing to migrate. Database is up to date.\n";
