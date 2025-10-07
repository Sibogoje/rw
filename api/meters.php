<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/functions.php';

// $pdo is already created in config/db.php

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
    return $uid;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Get meters for a house
    require_auth_or_die($pdo);
    $house_id = isset($_GET['house_id']) ? (int)$_GET['house_id'] : 0;
    if ($house_id === 0) json_response('error', null, 'house_id required');
    
    try {
        $stmt = $pdo->prepare('SELECT id, house_id, type, meter_number, installed_date, last_manual, notes, created_at FROM meters WHERE house_id = ? ORDER BY type');
        $stmt->execute(array($house_id));
        $meters = $stmt->fetchAll(PDO::FETCH_ASSOC);
        json_response('success', $meters);
    } catch (Exception $e) {
        json_response('error', null, 'GET failed: ' . $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Create meter
    require_auth_or_die($pdo);
    $input = json_decode(file_get_contents('php://input'), true);
    
    $house_id = isset($input['house_id']) ? (int)$input['house_id'] : 0;
    $type = isset($input['type']) ? trim($input['type']) : '';
    
    if ($house_id === 0) json_response('error', null, 'house_id is required');
    if (!in_array($type, array('water', 'electricity'))) json_response('error', null, 'type must be water or electricity');
    
    $meter_number = isset($input['meter_number']) ? trim($input['meter_number']) : '';
    $installed_date = isset($input['installed_date']) ? $input['installed_date'] : null;
    $last_manual = isset($input['last_manual']) ? (int)$input['last_manual'] : 0;
    $notes = isset($input['notes']) ? trim($input['notes']) : '';
    
    try {
        $stmt = $pdo->prepare('INSERT INTO meters (house_id, type, meter_number, installed_date, last_manual, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
        $stmt->execute(array($house_id, $type, $meter_number, $installed_date, $last_manual, $notes));
        $newId = (int)$pdo->lastInsertId();
        json_response('success', array('id' => $newId));
    } catch (Exception $e) {
        json_response('error', null, 'POST failed: ' . $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    // Update meter
    require_auth_or_die($pdo);
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || empty($input['id'])) json_response('error', null, 'id required');
    $id = (int)$input['id'];
    
    $fields = array();
    $params = array();
    if (isset($input['type']) && in_array($input['type'], array('water', 'electricity'))) { 
        $fields[] = 'type = ?'; 
        $params[] = $input['type']; 
    }
    if (isset($input['meter_number'])) { $fields[] = 'meter_number = ?'; $params[] = trim($input['meter_number']); }
    if (array_key_exists('installed_date', $input)) { $fields[] = 'installed_date = ?'; $params[] = $input['installed_date']; }
    if (isset($input['last_manual'])) { $fields[] = 'last_manual = ?'; $params[] = (int)$input['last_manual']; }
    if (isset($input['notes'])) { $fields[] = 'notes = ?'; $params[] = trim($input['notes']); }
    
    if (empty($fields)) json_response('error', null, 'nothing to update');
    $params[] = $id;
    $sql = 'UPDATE meters SET ' . implode(', ', $fields) . ' WHERE id = ?';
    $stmt = $pdo->prepare($sql);
    try {
        $stmt->execute($params);
        json_response('success', array('updated' => true));
    } catch (Exception $e) {
        json_response('error', null, $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    // Delete meter
    require_auth_or_die($pdo);
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id === 0) {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = isset($input['id']) ? (int)$input['id'] : 0;
    }
    if ($id === 0) json_response('error', null, 'id required');
    
    try {
        $stmt = $pdo->prepare('DELETE FROM meters WHERE id = ?');
        $stmt->execute(array($id));
        json_response('success', array('deleted' => true));
    } catch (Exception $e) {
        json_response('error', null, 'Delete failed: ' . $e->getMessage());
    }
}

?>
