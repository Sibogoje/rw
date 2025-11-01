<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

require_once '../config/db.php';

try {
    // Check tenants table
    $stmt = $pdo->query("DESCRIBE tenants");
    $tenants_columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Sample tenants data
    $stmt = $pdo->query("SELECT * FROM tenants LIMIT 5");
    $tenants_sample = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(array(
        "status" => "success",
        "tenants_columns" => $tenants_columns,
        "tenants_sample" => $tenants_sample
    ));
    
} catch(Exception $e) {
    echo json_encode(array(
        "status" => "error",
        "message" => $e->getMessage()
    ));
}
?>