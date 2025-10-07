<?php
// users.php - CRUD for users with role checks
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/functions.php';

// Helper to get bearer token and verify user id
function get_current_user_id_or_false() {
    $hdr = null;
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) $hdr = $_SERVER['HTTP_AUTHORIZATION'];
    elseif (function_exists('apache_request_headers')) {
        $all = apache_request_headers();
        if (isset($all['Authorization'])) $hdr = $all['Authorization'];
    }
    if (!$hdr) return false;
    if (stripos($hdr, 'Bearer ') === 0) $token = trim(substr($hdr, 7)); else $token = $hdr;
    return verify_jwt($token);
}

function require_admin_or_die($pdo) {
    $uid = get_current_user_id_or_false();
    if (!$uid) json_response('error', null, 'Unauthorized');
    $stmt = $pdo->prepare('SELECT role FROM users WHERE id = ?');
    $stmt->execute([$uid]);
    $row = $stmt->fetch();
    if (!$row || ($row['role'] ?? '') !== 'admin') json_response('error', null, 'Forbidden');
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Admin-only: listing users
    require_admin_or_die($pdo);

    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $per_page = isset($_GET['per_page']) ? max(1, (int)$_GET['per_page']) : 25;
    $offset = ($page - 1) * $per_page;
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';

    $where = '';
    $params = [];
    if ($q !== '') {
        $where = "WHERE (username LIKE :q OR full_name LIKE :q OR email LIKE :q)";
        $params[':q'] = '%' . $q . '%';
    }

    $countSql = "SELECT COUNT(*) as cnt FROM users " . $where;
    $countStmt = $pdo->prepare($countSql);
    foreach ($params as $k => $v) $countStmt->bindValue($k, $v);
    $countStmt->execute();
    $total = (int)$countStmt->fetch()['cnt'];

    $sql = "SELECT id, username, full_name, email, phone, role, active, created_at, updated_at FROM users " . $where . " ORDER BY id DESC LIMIT :lim OFFSET :off";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':lim', $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    json_response('success', $rows, null, ['meta' => ['total' => $total, 'page' => $page, 'per_page' => $per_page]]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Create user (admin only)
    require_admin_or_die($pdo);
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) json_response('error', null, 'Invalid payload');
    $username = trim($input['username'] ?? '');
    $password = $input['password'] ?? '';
    $full_name = trim($input['full_name'] ?? '');
    if ($username === '' || $password === '' || $full_name === '') json_response('error', null, 'username, password and full_name are required');
    $email = $input['email'] ?? null;
    $phone = $input['phone'] ?? null;
    $role = in_array($input['role'] ?? 'clerk', ['clerk','admin']) ? $input['role'] : 'clerk';
    $active = isset($input['active']) ? (int)$input['active'] : 1;

    // Unique username check
    $chk = $pdo->prepare('SELECT id FROM users WHERE username = ?');
    $chk->execute([$username]);
    if ($chk->fetch()) json_response('error', null, 'username already exists');

    $pwHash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, full_name, email, phone, role, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
    $stmt->execute([$username, $pwHash, $full_name, $email, $phone, $role, $active]);
    $newId = (int)$pdo->lastInsertId();
    json_response('success', ['id' => $newId]);
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    // Update user (admin only)
    require_admin_or_die($pdo);
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || empty($input['id'])) json_response('error', null, 'id required');
    $id = (int)$input['id'];

    $fields = [];
    $params = [];
    if (isset($input['username'])) { $fields[] = 'username = ?'; $params[] = $input['username']; }
    if (isset($input['full_name'])) { $fields[] = 'full_name = ?'; $params[] = $input['full_name']; }
    if (array_key_exists('email', $input)) { $fields[] = 'email = ?'; $params[] = $input['email']; }
    if (array_key_exists('phone', $input)) { $fields[] = 'phone = ?'; $params[] = $input['phone']; }
    if (isset($input['role']) && in_array($input['role'], ['clerk','admin'])) { $fields[] = 'role = ?'; $params[] = $input['role']; }
    if (array_key_exists('active', $input)) { $fields[] = 'active = ?'; $params[] = (int)$input['active']; }
    if (!empty($input['password'])) {
        $fields[] = 'password_hash = ?';
        $params[] = password_hash($input['password'], PASSWORD_DEFAULT);
    }
    if (empty($fields)) json_response('error', null, 'nothing to update');
    $params[] = $id;
    $sql = 'UPDATE users SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = ?';
    $stmt = $pdo->prepare($sql);
    try { $stmt->execute($params); json_response('success', ['updated' => true]); } catch (Exception $e) { json_response('error', null, $e->getMessage()); }
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    // Delete user -- admin only
    require_admin_or_die($pdo);
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || empty($input['id'])) json_response('error', null, 'id required');
    $id = (int)$input['id'];
    $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
    $stmt->execute([$id]);
    json_response('success', ['deleted' => true]);
}

?>
