<?php
// Run from project root: php backend/migrations/seed.php

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();
require_once __DIR__ . '/../config/db.php';

echo "Starting Database Seeding...\n";

try {
    $pdo->beginTransaction();

    // 1. Seed Users
    echo "Seeding Users...\n";
    $adminPass = password_hash('admin123', PASSWORD_BCRYPT);
    $managerPass = password_hash('manager123', PASSWORD_BCRYPT);
    $distPass = password_hash('distributor123', PASSWORD_BCRYPT);

    $users = [
        ['Admin', 'admin@pharma.com', $adminPass, 'admin'],
        ['Manager', 'manager@pharma.com', $managerPass, 'manager'],
        ['Distributor', 'distributor@pharma.com', $distPass, 'distributor']
    ];

    $stmt = $pdo->prepare("INSERT IGNORE INTO users (name, email, password, role) VALUES (?, ?, ?, ?)");
    foreach ($users as $u) {
        $stmt->execute($u);
    }
    
    // Fetch user IDs for references
    $managerId = $pdo->query("SELECT id FROM users WHERE role = 'manager' LIMIT 1")->fetchColumn() ?: 1;

    // 2. Seed Medicines
    echo "Seeding Medicines...\n";
    $medicines = [
        ['Paracetamol 500mg', 'Sun Pharma', 'Analgesic', 12.50, 100],
        ['Amoxicillin 250mg', 'Pfizer', 'Antibiotic', 45.00, 50],
        ['Ibuprofen 400mg', 'Cipla', 'NSAID', 22.00, 150],
        ['Cetirizine 10mg', 'GSK', 'Antihistamine', 8.50, 200],
        ['Metformin 500mg', 'Mankind', 'Antidiabetic', 15.00, 100]
    ];

    $stmt = $pdo->prepare("INSERT INTO medicines (name, manufacturer, category, price, reorder_point) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE name=name");
    foreach ($medicines as $m) {
        $stmt->execute($m);
    }

    // 3. Seed Batches (and initial stock entries)
    echo "Seeding Batches...\n";
    
    // Get medicine IDs and prices
    $meds = $pdo->query("SELECT id, name, price FROM medicines")->fetchAll(PDO::FETCH_ASSOC);

    $batches = [];
    foreach ($meds as $med) {
        $id = $med['id'];
        $price = $med['price'];
        // Assume unit purchase cost is 60% of retail price to simulate profit margin
        $unitCost = round($price * 0.60, 2);

        // Create 2 batches for each medicine
        $batches[] = [
            $id,
            'BATCH-' . date('Y') . '-' . rand(1000, 9999),
            date('Y-m-d', strtotime('-1 months')),
            date('Y-m-d', strtotime('+12 months')), // Green
            rand(100, 300),
            $unitCost
        ];
        $batches[] = [
            $id,
            'CRIT-' . date('Y') . '-' . rand(1000, 9999),
            date('Y-m-d', strtotime('-24 months')),
            date('Y-m-d', strtotime('+20 days')), // Red (Critical)
            rand(20, 50),
            $unitCost
        ];
    }

    $batchStmt = $pdo->prepare("INSERT INTO batches (medicine_id, batch_number, mfg_date, expiry_date, quantity, unit_cost) VALUES (?, ?, ?, ?, ?, ?)");
    $txStmt = $pdo->prepare("INSERT INTO transactions (batch_id, type, quantity, reference, created_by) VALUES (?, 'in', ?, 'INITIAL_SEED', ?)");

    foreach ($batches as $b) {
        // Check if batch number already exists (simple safeguard)
        $exists = $pdo->prepare("SELECT id FROM batches WHERE batch_number = ?");
        $exists->execute([$b[1]]);
        if (!$exists->fetchColumn()) {
            $batchStmt->execute($b);
            $batchId = $pdo->lastInsertId();
            
            // Record the transaction for this intake
            $txStmt->execute([$batchId, $b[4], $managerId]);
        }
    }

    $pdo->commit();
    echo "Seeding Complete!\n";

} catch (Exception $e) {
    $pdo->rollBack();
    echo "Error during seeding: " . $e->getMessage() . "\n";
}
