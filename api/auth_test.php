<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

echo json_encode([
    'status' => 'test',
    'message' => 'Auth test endpoint working',
    'method' => $_SERVER['REQUEST_METHOD'],
    'input' => file_get_contents('php://input')
]);

try {
    require_once __DIR__ . '/../config/db.php';
    echo json_encode(['db' => 'connected', 'pdo' => isset($pdo) ? 'exists' : 'missing']);
} catch (Exception $e) {
    echo json_encode(['db_error' => $e->getMessage()]);
}
?>