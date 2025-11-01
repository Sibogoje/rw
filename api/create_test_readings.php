<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

require_once '../config/db.php';
require_once '../lib/auth.php';
require_once '../lib/functions.php';

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

$uid = require_auth_or_die($pdo);

try {
    // Get some house IDs for testing (from Lavumisa station)
    $stmt = $pdo->query("SELECT id, house_code FROM houses WHERE station_id = 3 LIMIT 3");
    $houses = $stmt->fetchAll();
    
    if (empty($houses)) {
        json_response('error', null, 'No houses found for testing');
    }
    
    $inserted = 0;
    
    // Insert sample readings for October 2024 (as previous month)
    foreach ($houses as $house) {
        $sql = "INSERT INTO meter_readings (
            house_id, reading_date, reading_month,
            water_previous_reading, water_current_reading, water_units,
            sewage_previous_reading, sewage_current_reading, sewage_units,
            electricity_previous_reading, electricity_current_reading, electricity_units,
            water_faulty, sewage_faulty, electricity_faulty,
            captured_by, status, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $house['id'],                    // house_id
            '2024-10-15',                   // reading_date
            '2024-10',                      // reading_month
            rand(800, 1000),               // water_previous_reading
            rand(1100, 1300),              // water_current_reading  
            rand(200, 350),                // water_units
            rand(500, 700),                // sewage_previous_reading
            rand(750, 950),                // sewage_current_reading
            rand(200, 300),                // sewage_units
            rand(1500, 2000),              // electricity_previous_reading
            rand(2100, 2500),              // electricity_current_reading
            rand(500, 800),                // electricity_units
            0,                             // water_faulty
            0,                             // sewage_faulty  
            0,                             // electricity_faulty
            $uid,                          // captured_by
            'verified'                     // status
        ]);
        
        $inserted++;
    }
    
    json_response('success', [
        'message' => "Inserted $inserted test readings for October 2024",
        'houses' => $houses
    ]);
    
} catch(Exception $e) {
    json_response('error', null, 'Error: ' . $e->getMessage());
}
?>