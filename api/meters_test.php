<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

echo json_encode(array('status' => 'test', 'message' => 'Basic test works'));

// Now try loading config
require_once __DIR__ . '/../config/db.php';
echo json_encode(array('status' => 'test', 'message' => 'Config loaded, PDO exists: ' . (isset($pdo) ? 'yes' : 'no')));
?>
