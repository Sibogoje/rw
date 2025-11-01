<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

require_once '../config/db.php';

try {
    // Use the $pdo connection from db.php
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo json_encode(array(
        "status" => "success",
        "tables" => $tables
    ));
    
} catch(Exception $e) {
    echo json_encode(array(
        "status" => "error",
        "message" => $e->getMessage()
    ));
}
?>