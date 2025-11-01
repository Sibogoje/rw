<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Minimal auth implementation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $username = isset($input['username']) ? $input['username'] : '';
    $password = isset($input['password']) ? $input['password'] : '';
    
    // Simple hardcoded check for testing
    if ($username === 'admin' && $password === 'admin') {
        echo json_encode(array(
            'status' => 'success',
            'data' => array('token' => 'test-token-123')
        ));
    } else {
        echo json_encode(array(
            'status' => 'error',
            'message' => 'Invalid credentials'
        ));
    }
    exit;
}

echo json_encode(array(
    'status' => 'error',
    'message' => 'Method not allowed'
));
?>