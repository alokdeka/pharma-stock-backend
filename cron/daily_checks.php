<?php
/**
 * PHARMA-STOCK CRON ENGINE
 * 
 * Target this script in your OS crontab.
 * Example for daily 8:00 AM check:
 * 0 8 * * * /usr/bin/php /var/www/pharma-stock/backend/cron/daily_checks.php >> /var/log/pharma_cron.log
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/email.php';

echo "--- Pharma-Stock CRON Execution initiated: " . date('Y-m-d H:i:s') . " ---\n";

global $pdo;

try {
    $stockStmt = $pdo->query("
        SELECT m.name, m.reorder_point, COALESCE(SUM(b.quantity), 0) as total_stock
        FROM medicines m LEFT JOIN batches b ON m.id = b.medicine_id
        GROUP BY m.id HAVING total_stock <= m.reorder_point
    ");

    $count = 0;
    while ($med = $stockStmt->fetch()) {
        sendLowStockEmail(null, $med['name'], $med['total_stock'], $med['reorder_point']);
        echo "[CRON ALERT] Triggered low-stock memo for {$med['name']}\n";
        $count++;
    }

    echo "--- CRON Task Complete. Executed $count alerts globally. ---\n";
} catch (Exception $e) {
    echo "--- CRON Failed: " . $e->getMessage() . " ---\n";
}
