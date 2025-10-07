<?php
// roles.php - manage role -> screen permissions
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/functions.php';

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
    // Return permissions: if admin, return all roles; otherwise return only current user's role permissions
    $uid = get_current_user_id_or_false();
    if (!$uid) json_response('error', null, 'Unauthorized');
    $rstmt = $pdo->prepare('SELECT role FROM users WHERE id = ?');
    $rstmt->execute([$uid]);
    $urow = $rstmt->fetch();
    $userRole = $urow['role'] ?? null;
    if ($userRole === 'admin') {
        $stmt = $pdo->query('SELECT role, screen, allowed FROM role_permissions ORDER BY role, screen');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $role = $r['role'];
            if (!isset($out[$role])) $out[$role] = [];
            $out[$role][$r['screen']] = (int)$r['allowed'];
        }
        json_response('success', $out);
    } else {
        $stmt = $pdo->prepare('SELECT screen, allowed FROM role_permissions WHERE role = ?');
        $stmt->execute([$userRole]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        $out[$userRole] = [];
        foreach ($rows as $r) {
            $out[$userRole][$r['screen']] = (int)$r['allowed'];
        }
        json_response('success', $out);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    // Update role permissions (admin only)
    require_admin_or_die($pdo);
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || empty($input['role']) || !isset($input['permissions']) || !is_array($input['permissions'])) json_response('error', null, 'role and permissions required');
    $role = $input['role'];
    $perms = $input['permissions']; // associative screen => 0/1

    // Simple approach: delete existing for role then insert new
    $del = $pdo->prepare('DELETE FROM role_permissions WHERE role = ?');
    $del->execute([$role]);
    $ins = $pdo->prepare('INSERT INTO role_permissions (role, screen, allowed) VALUES (?, ?, ?)');
    foreach ($perms as $screen => $allowed) {
        $ins->execute([$role, $screen, (int)$allowed]);
    }
    json_response('success', ['updated' => true]);
}

// Other methods not supported
http_response_code(405);
json_response('error', null, 'Method not allowed');

?>
