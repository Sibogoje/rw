<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

require_once '../config/db.php';

try {
    // Check stations table
    $stmt = $pdo->query("DESCRIBE stations");
    $stations_columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->query("SELECT * FROM stations LIMIT 5");
    $stations_sample = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Check if our custom meter_readings table needs to be created
    // First, let's backup the existing meter_readings
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM meter_readings");
    $existing_readings_count = $stmt->fetch()['count'];
    
    echo json_encode(array(
        "status" => "success",
        "stations_columns" => $stations_columns,
        "stations_sample" => $stations_sample,
        "existing_meter_readings_count" => $existing_readings_count
    ));
    
} catch(Exception $e) {
    echo json_encode(array(
        "status" => "error",
        "message" => $e->getMessage()
    ));
}
?>