<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../middleware/auth.php';
use OpenApi\Attributes as OA;

class DatabaseController {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    #[OA\Get(
        path: "/database/backup",
        summary: "Download Full SQL Backup",
        tags: ["System Administration"],
        security: [["bearerAuth" => []]],
        responses: [new OA\Response(response: 200, description: "Stream payload of complete MySQL script")]
    )]
    public function backup() {
        authenticate(['admin']);
        
        $tables = [];
        $query = $this->pdo->query('SHOW TABLES');
        while($row = $query->fetch(PDO::FETCH_NUM)) { 
            $tables[] = $row[0]; 
        }
        
        $sql = "-- Pharma-Stock Database Logical Dump\n";
        $sql .= "-- Time: " . date('Y-m-d H:i:s') . "\n\n";
        
        $sql .= "SET FOREIGN_KEY_CHECKS=0;\n";

        foreach($tables as $table) {
            $sql .= "\nDROP TABLE IF EXISTS `$table`;\n";
            $row2 = $this->pdo->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM);
            $sql .= $row2[1] . ";\n\n";
            
            $result = $this->pdo->query('SELECT * FROM '.$table);
            while($row = $result->fetch(PDO::FETCH_NUM)) {
                $sql .= "INSERT INTO `$table` VALUES(";
                $cols = [];
                foreach($row as $val) {
                    if ($val === null) $cols[] = 'NULL';
                    else $cols[] = $this->pdo->quote($val);
                }
                $sql .= implode(",", $cols) . ");\n";
            }
        }
        
        $sql .= "\nSET FOREIGN_KEY_CHECKS=1;\n";

        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename=pharmastock_backup_' . date('Ymd_His') . '.sql');
        echo $sql;
        exit;
    }

    #[OA\Post(
        path: "/database/restore",
        summary: "Execute SQL Recovery Payload",
        tags: ["System Administration"],
        security: [["bearerAuth" => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(
            mediaType: "multipart/form-data",
            schema: new OA\Schema(properties: [new OA\Property(property: "backup_file", type: "string", format: "binary")])
        )),
        responses: [new OA\Response(response: 200, description: "Database thoroughly overwritten with snapshot logic")]
    )]
    public function restore() {
        authenticate(['admin']);
        if (!isset($_FILES['backup_file'])) {
            response(400, false, null, "No backup file uploaded.");
        }
        
        $sql = file_get_contents($_FILES['backup_file']['tmp_name']);
        if (empty(trim($sql))) {
            response(400, false, null, "Uploaded file is empty.");
        }

        try {
            $this->pdo->exec($sql);
            response(200, true, null, "Database restored successfully.");
        } catch (Exception $e) {
            response(500, false, null, "Restore failed: " . $e->getMessage());
        }
    }
}
