<?php
// CORS headers for development (allow all origins)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    require_once __DIR__ . '/../config/db.php';
    require_once __DIR__ . '/../lib/auth.php';
    require_once __DIR__ . '/../lib/functions.php';
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(array('status' => 'error', 'message' => 'Include error: ' . $e->getMessage()));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        $username = isset($input['username']) ? $input['username'] : '';
        $password = isset($input['password']) ? $input['password'] : '';

        $stmt = $pdo->prepare("SELECT id, password_hash FROM users WHERE username = ?");
        $stmt->execute(array($username));
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            $token = generate_jwt($user['id']);
            json_response('success', array('token' => $token));
        } else {
            json_response('error', null, 'Invalid credentials');
        }
    } catch (Exception $e) {
        json_response('error', null, 'Server error: ' . $e->getMessage());
    }
}
?>