<?php
// tenants.php - CRUD for tenants
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

function require_auth_or_die($pdo) {
    $uid = get_current_user_id_or_false();
    if (!$uid) json_response('error', null, 'Unauthorized');
    $stmt = $pdo->prepare('SELECT role FROM users WHERE id = ?');
    $stmt->execute([$uid]);
    $row = $stmt->fetch();
    if (!$row) json_response('error', null, 'User not found');
    return $row;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // List tenants (requires authentication)
    require_auth_or_die($pdo);

    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $per_page = isset($_GET['per_page']) ? max(1, (int)$_GET['per_page']) : 100;
    $offset = ($page - 1) * $per_page;
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';

    $where = 'WHERE 1=1';
    $params = [];
    if ($q !== '') {
        $where .= " AND (t.name LIKE :q OR t.phone LIKE :q OR t.email LIKE :q OR h.house_code LIKE :q)";
        $params[':q'] = '%' . $q . '%';
    }

    $countSql = "SELECT COUNT(*) as cnt FROM tenants t LEFT JOIN houses h ON t.house_id = h.id " . $where;
    $countStmt = $pdo->prepare($countSql);
    foreach ($params as $k => $v) $countStmt->bindValue($k, $v);
    $countStmt->execute();
    $total = (int)$countStmt->fetch()['cnt'];

    $sql = "SELECT t.id, t.house_id, t.name, t.is_company, t.is_government, t.phone, t.email, t.start_date, t.end_date, t.active, t.created_at, h.house_code 
            FROM tenants t 
            LEFT JOIN houses h ON t.house_id = h.id 
            " . $where . " ORDER BY t.name ASC LIMIT :lim OFFSET :off";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':lim', $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    json_response('success', $rows, null, ['meta' => ['total' => $total, 'page' => $page, 'per_page' => $per_page]]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Create tenant (requires authentication)
    require_auth_or_die($pdo);
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) json_response('error', null, 'Invalid payload');
    
    $house_id = (int)($input['house_id'] ?? 0);
    $name = trim($input['name'] ?? '');
    if ($house_id <= 0) json_response('error', null, 'house_id is required');
    if ($name === '') json_response('error', null, 'name is required');
    
    $is_company = isset($input['is_company']) ? (int)$input['is_company'] : 0;
    $is_government = isset($input['is_government']) ? (int)$input['is_government'] : 0;
    $phone = trim($input['phone'] ?? '');
    $email = trim($input['email'] ?? '');
    $start_date = $input['start_date'] ?? null;
    $end_date = $input['end_date'] ?? null;
    $active = isset($input['active']) ? (int)$input['active'] : 1;

    $stmt = $pdo->prepare('INSERT INTO tenants (house_id, name, is_company, is_government, phone, email, start_date, end_date, active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
    $stmt->execute([$house_id, $name, $is_company, $is_government, $phone, $email, $start_date, $end_date, $active]);
    $newId = (int)$pdo->lastInsertId();
    json_response('success', ['id' => $newId]);
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    // Update tenant (requires authentication)
    require_auth_or_die($pdo);
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || empty($input['id'])) json_response('error', null, 'id required');
    $id = (int)$input['id'];

    $fields = [];
    $params = [];
    if (isset($input['house_id'])) { $fields[] = 'house_id = ?'; $params[] = (int)$input['house_id']; }
    if (isset($input['name'])) { $fields[] = 'name = ?'; $params[] = trim($input['name']); }
    if (isset($input['is_company'])) { $fields[] = 'is_company = ?'; $params[] = (int)$input['is_company']; }
    if (isset($input['is_government'])) { $fields[] = 'is_government = ?'; $params[] = (int)$input['is_government']; }
    if (isset($input['phone'])) { $fields[] = 'phone = ?'; $params[] = trim($input['phone']); }
    if (isset($input['email'])) { $fields[] = 'email = ?'; $params[] = trim($input['email']); }
    if (array_key_exists('start_date', $input)) { $fields[] = 'start_date = ?'; $params[] = $input['start_date']; }
    if (array_key_exists('end_date', $input)) { $fields[] = 'end_date = ?'; $params[] = $input['end_date']; }
    if (isset($input['active'])) { $fields[] = 'active = ?'; $params[] = (int)$input['active']; }
    
    if (empty($fields)) json_response('error', null, 'nothing to update');
    $params[] = $id;
    $sql = 'UPDATE tenants SET ' . implode(', ', $fields) . ' WHERE id = ?';
    $stmt = $pdo->prepare($sql);
    try { 
        $stmt->execute($params); 
        json_response('success', ['updated' => true]); 
    } catch (Exception $e) { 
        json_response('error', null, $e->getMessage()); 
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    // Delete tenant (requires authentication)
    require_auth_or_die($pdo);
    // Accept id from query string or body
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id === 0) {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = isset($input['id']) ? (int)$input['id'] : 0;
    }
    if ($id === 0) json_response('error', null, 'id required');
    
    try {
        // Simply delete the tenant - cascade will handle foreign key constraints if any
        $stmt = $pdo->prepare('DELETE FROM tenants WHERE id = ?');
        $stmt->execute([$id]);
        json_response('success', ['deleted' => true]);
    } catch (Exception $e) {
        json_response('error', null, 'Delete failed: ' . $e->getMessage());
    }
}

?>
