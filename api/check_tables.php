<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

require_once '../config/db.php';

try {
    // Get database connection
    $conn = Database::getConnection();
    
    // Check if meter_readings table exists
    $stmt = $conn->query("SHOW TABLES LIKE 'meter_readings'");
    $table_exists = $stmt->rowCount() > 0;
    
    $response = array(
        "status" => "success",
        "meter_readings_table_exists" => $table_exists
    );
    
    if ($table_exists) {
        // Get table structure
        $stmt = $conn->query("DESCRIBE meter_readings");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $response["table_structure"] = $columns;
        
        // Get row count
        $stmt = $conn->query("SELECT COUNT(*) as count FROM meter_readings");
        $count = $stmt->fetch(PDO::FETCH_ASSOC);
        $response["row_count"] = $count["count"];
    }
    
    echo json_encode($response);
    
} catch(Exception $e) {
    echo json_encode(array(
        "status" => "error",
        "message" => $e->getMessage()
    ));
}
?>