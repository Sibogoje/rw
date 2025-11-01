<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

require_once '../config/db.php';

try {
    // Describe houses table
    $stmt = $pdo->query("DESCRIBE houses");
    $houses_columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Describe meter_readings table 
    $stmt = $pdo->query("DESCRIBE meter_readings");
    $meter_readings_columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(array(
        "status" => "success",
        "houses_columns" => $houses_columns,
        "meter_readings_columns" => $meter_readings_columns
    ));
    
} catch(Exception $e) {
    echo json_encode(array(
        "status" => "error",
        "message" => $e->getMessage()
    ));
}
?>