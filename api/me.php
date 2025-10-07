<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/functions.php';

// Get token from Authorization header
$hdr = null;
if (isset($_SERVER['HTTP_AUTHORIZATION'])) $hdr = $_SERVER['HTTP_AUTHORIZATION'];
elseif (function_exists('apache_request_headers')) {
    $all = apache_request_headers();
    if (isset($all['Authorization'])) $hdr = $all['Authorization'];
}
if (!$hdr) json_response('error', null, 'Unauthorized');
if (stripos($hdr, 'Bearer ') === 0) $token = trim(substr($hdr, 7)); else $token = $hdr;

$uid = verify_jwt($token);
if (!$uid) json_response('error', null, 'Unauthorized');

$stmt = $pdo->prepare('SELECT id, username, full_name, email, phone, role, active, created_at, updated_at FROM users WHERE id = ?');
$stmt->execute([$uid]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) json_response('error', null, 'User not found');

json_response('success', $row);

?>
