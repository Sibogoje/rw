<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

$host = 'srv1212.hstgr.io';
$db   = 'u747325399_zenRail';
$user = 'u747325399_zenRail';
$pass = 'u747325399_Zemark';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

try {
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    echo json_encode([
        'status' => 'success', 
        'message' => 'Database connected successfully',
        'server_info' => $pdo->getAttribute(PDO::ATTR_SERVER_INFO)
    ]);
} catch (PDOException $e) {
    echo json_encode([
        'status' => 'error', 
        'message' => 'Database connection failed: ' . $e->getMessage(),
        'code' => $e->getCode()
    ]);
}
?>