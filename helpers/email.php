<?php
require_once __DIR__ . '/../config/db.php';

function sendLowStockEmail($fallbackEmail, $medicineName, $currentStock, $reorderPoint) {
    global $pdo;
    $targetEmail = $fallbackEmail;
    $enabled = true;

    try {
        $stmt = $pdo->query("SELECT `key`, `value` FROM settings WHERE `key` IN ('admin_email', 'email_alerts_enabled')");
        while($row = $stmt->fetch()) {
            if ($row['key'] === 'admin_email' && !empty($row['value'])) {
                $targetEmail = $row['value'];
            }
            if ($row['key'] === 'email_alerts_enabled') {
                $enabled = filter_var($row['value'], FILTER_VALIDATE_BOOLEAN);
            }
        }
    } catch(Exception $e) {}

    if (!$enabled || !$targetEmail) {
        error_log("Email Aborted: Alerts disabled or missing receiver.");
        return false;
    }

    $subject = "URGENT: Low Stock Alert - $medicineName";
    
    $message = "
    <html>
    <head><title>Low Stock Alert</title></head>
    <body>
        <h2>Pharma-Stock Supply Warning</h2>
        <p>The following medicine has crossed below its required safety threshold:</p>
        <ul>
            <li><b>Medicine:</b> $medicineName</li>
            <li><b>Current Stock:</b> <span style='color:red;'>$currentStock</span></li>
            <li><b>Minimum Reorder Limit:</b> $reorderPoint</li>
        </ul>
        <p>Please log in to the Warehouse Management System to generate a Purchase Order immediately.</p>
    </body>
    </html>
    ";

    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: wms@pharma-stock.local" . "\r\n";

    // Uncomment this line to physically send in production with a configured sendmail server
    // mail($adminEmail, $subject, $message, $headers);
    
    // We log it successfully for demonstration and safety in local environments
    error_log("Simulated Email Sent to $adminEmail: Low stock for $medicineName ($currentStock left)");
    error_log("---------------------------------------");
    
    return true;
}

function sendPasswordResetEmail($toEmail, $token) {
    // In production, configure SMTP. For now, simulate via error_log
    $resetLink = "http://localhost:5173/login?token=" . urlencode($token);
    
    $subject = "PharmaStock - Password Reset Request";
    $message = "You recently requested to reset your password for your PharmaStock account.\n\n";
    $message .= "Click the link below to set a new password. This link will expire in 1 hour.\n";
    $message .= $resetLink . "\n\n";
    $message .= "If you did not request this, please ignore this email.";
    
    $headers = "From: noreply@pharmastock.local\r\n";
    $headers .= "Reply-To: noreply@pharmastock.local\r\n";
    
    // mail($toEmail, $subject, $message, $headers);
    error_log("--- SIMULATED RECOVERY EMAIL SENT ---");
    error_log("To: $toEmail");
    error_log("Subject: $subject");
    error_log("Body: \n$message");
    error_log("---------------------------------------");
    return true;
}
