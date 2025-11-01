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
    // Get meter readings - can filter by house_id, reading_month, status
    $uid = require_auth_or_die($pdo);
    
    $house_id = isset($_GET['house_id']) ? (int)$_GET['house_id'] : null;
    $reading_month = isset($_GET['reading_month']) ? $_GET['reading_month'] : null;
    $status = isset($_GET['status']) ? $_GET['status'] : null;
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $per_page = isset($_GET['per_page']) ? max(1, min(100, (int)$_GET['per_page'])) : 50;
    $offset = ($page - 1) * $per_page;
    
    $where = array('1=1');
    $params = array();
    
    if ($house_id) {
        $where[] = 'mr.house_id = ?';
        $params[] = $house_id;
    }
    
    if ($reading_month) {
        $where[] = 'mr.reading_month = ?';
        $params[] = $reading_month;
    }
    
    if ($status) {
        $where[] = 'mr.status = ?';
        $params[] = $status;
    }
    
    $where_str = implode(' AND ', $where);
    
    try {
        // Get total count
        $count_sql = "SELECT COUNT(*) as total FROM meter_readings mr WHERE $where_str";
        $count_stmt = $pdo->prepare($count_sql);
        $count_stmt->execute($params);
        $total = $count_stmt->fetch()['total'];
        
        // Get data with joins
        $sql = "SELECT 
                    mr.*,
                    h.house_code,
                    s.name as station_name,
                    t.name as tenant_name,
                    u.username as captured_by_name
                FROM meter_readings mr
                LEFT JOIN houses h ON mr.house_id = h.id
                LEFT JOIN stations s ON h.station_id = s.id
                LEFT JOIN tenants t ON h.id = t.house_id AND t.active = 1
                LEFT JOIN users u ON mr.captured_by = u.id
                WHERE $where_str
                ORDER BY mr.reading_date DESC, h.house_code ASC
                LIMIT $per_page OFFSET $offset";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $readings = $stmt->fetchAll();
        
        json_response('success', array(
            'data' => $readings,
            'meta' => array(
                'total' => $total,
                'page' => $page,
                'per_page' => $per_page,
                'pages' => ceil($total / $per_page)
            )
        ));
        
    } catch (Exception $e) {
        json_response('error', null, 'Database error: ' . $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Create new meter reading
    $uid = require_auth_or_die($pdo);
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) json_response('error', null, 'Invalid JSON');
    
    $house_id = isset($input['house_id']) ? (int)$input['house_id'] : null;
    $reading_date = isset($input['reading_date']) ? $input['reading_date'] : date('Y-m-d');
    $reading_month = isset($input['reading_month']) ? $input['reading_month'] : date('Y-m');
    
    if (!$house_id) json_response('error', null, 'house_id required');
    
    try {
        // Check if reading already exists for this house/month
        $check_sql = "SELECT id FROM meter_readings WHERE house_id = ? AND reading_month = ?";
        $check_stmt = $pdo->prepare($check_sql);
        $check_stmt->execute(array($house_id, $reading_month));
        if ($check_stmt->fetch()) {
            json_response('error', null, 'Reading already exists for this house and month');
        }
        
        // Get previous readings (last reading for this house)
        $prev_sql = "SELECT water_current, sewage_current, electricity_current 
                     FROM meter_readings 
                     WHERE house_id = ? 
                     ORDER BY reading_date DESC, id DESC 
                     LIMIT 1";
        $prev_stmt = $pdo->prepare($prev_sql);
        $prev_stmt->execute(array($house_id));
        $prev = $prev_stmt->fetch();
        
        $water_previous = $prev ? $prev['water_current'] : 0;
        $sewage_previous = $prev ? $prev['sewage_current'] : 0;
        $electricity_previous = $prev ? $prev['electricity_current'] : 0;
        
        // Current readings from input
        $water_current = isset($input['water_current']) ? (float)$input['water_current'] : null;
        $sewage_current = isset($input['sewage_current']) ? (float)$input['sewage_current'] : null;
        $electricity_current = isset($input['electricity_current']) ? (float)$input['electricity_current'] : null;
        
        // Calculate units
        $water_units = $water_current !== null ? max(0, $water_current - $water_previous) : 0;
        $sewage_units = $sewage_current !== null ? max(0, $sewage_current - $sewage_previous) : 0;
        $electricity_units = $electricity_current !== null ? max(0, $electricity_current - $electricity_previous) : 0;
        
        // Faulty flags
        $water_faulty = isset($input['water_faulty']) ? (int)$input['water_faulty'] : 0;
        $sewage_faulty = isset($input['sewage_faulty']) ? (int)$input['sewage_faulty'] : 0;
        $electricity_faulty = isset($input['electricity_faulty']) ? (int)$input['electricity_faulty'] : 0;
        
        $notes = isset($input['notes']) ? $input['notes'] : null;
        
        $sql = "INSERT INTO meter_readings (
                    house_id, reading_date, reading_month,
                    water_current, sewage_current, electricity_current,
                    water_previous, sewage_previous, electricity_previous,
                    water_units, sewage_units, electricity_units,
                    water_faulty, sewage_faulty, electricity_faulty,
                    captured_by, notes
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array(
            $house_id, $reading_date, $reading_month,
            $water_current, $sewage_current, $electricity_current,
            $water_previous, $sewage_previous, $electricity_previous,
            $water_units, $sewage_units, $electricity_units,
            $water_faulty, $sewage_faulty, $electricity_faulty,
            $uid, $notes
        ));
        
        $new_id = $pdo->lastInsertId();
        json_response('success', array('id' => $new_id), 'Meter reading saved successfully');
        
    } catch (Exception $e) {
        json_response('error', null, 'Database error: ' . $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    // Update meter reading
    $uid = require_auth_or_die($pdo);
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) json_response('error', null, 'Invalid JSON');
    
    $id = isset($input['id']) ? (int)$input['id'] : null;
    if (!$id) json_response('error', null, 'id required');
    
    try {
        // Check if reading exists
        $check_sql = "SELECT house_id, water_previous, sewage_previous, electricity_previous FROM meter_readings WHERE id = ?";
        $check_stmt = $pdo->prepare($check_sql);
        $check_stmt->execute(array($id));
        $existing = $check_stmt->fetch();
        if (!$existing) json_response('error', null, 'Reading not found');
        
        // Build update fields
        $fields = array();
        $params = array();
        
        if (isset($input['water_current'])) {
            $fields[] = 'water_current = ?';
            $params[] = $input['water_current'];
            $fields[] = 'water_units = ?';
            $params[] = max(0, (float)$input['water_current'] - $existing['water_previous']);
        }
        
        if (isset($input['sewage_current'])) {
            $fields[] = 'sewage_current = ?';
            $params[] = $input['sewage_current'];
            $fields[] = 'sewage_units = ?';
            $params[] = max(0, (float)$input['sewage_current'] - $existing['sewage_previous']);
        }
        
        if (isset($input['electricity_current'])) {
            $fields[] = 'electricity_current = ?';
            $params[] = $input['electricity_current'];
            $fields[] = 'electricity_units = ?';
            $params[] = max(0, (float)$input['electricity_current'] - $existing['electricity_previous']);
        }
        
        if (isset($input['water_faulty'])) {
            $fields[] = 'water_faulty = ?';
            $params[] = (int)$input['water_faulty'];
        }
        
        if (isset($input['sewage_faulty'])) {
            $fields[] = 'sewage_faulty = ?';
            $params[] = (int)$input['sewage_faulty'];
        }
        
        if (isset($input['electricity_faulty'])) {
            $fields[] = 'electricity_faulty = ?';
            $params[] = (int)$input['electricity_faulty'];
        }
        
        if (isset($input['notes'])) {
            $fields[] = 'notes = ?';
            $params[] = $input['notes'];
        }
        
        if (isset($input['status'])) {
            $fields[] = 'status = ?';
            $params[] = $input['status'];
        }
        
        if (empty($fields)) json_response('error', null, 'No fields to update');
        
        $params[] = $id;
        $sql = "UPDATE meter_readings SET " . implode(', ', $fields) . " WHERE id = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        json_response('success', null, 'Meter reading updated successfully');
        
    } catch (Exception $e) {
        json_response('error', null, 'Database error: ' . $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    // Delete meter reading
    $uid = require_auth_or_die($pdo);
    
    $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
    if (!$id) json_response('error', null, 'id required');
    
    try {
        $sql = "DELETE FROM meter_readings WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array($id));
        
        if ($stmt->rowCount() > 0) {
            json_response('success', null, 'Meter reading deleted successfully');
        } else {
            json_response('error', null, 'Reading not found');
        }
        
    } catch (Exception $e) {
        json_response('error', null, 'Database error: ' . $e->getMessage());
    }
}

json_response('error', null, 'Method not allowed');
?>