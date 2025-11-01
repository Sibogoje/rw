<?php
// CORS headers for development
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/functions.php';

// Get last readings for houses (for meter readings screen)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $house_ids = isset($_GET['house_ids']) ? $_GET['house_ids'] : null;
    
    if (!$house_ids) {
        json_response('error', null, 'house_ids parameter required');
    }
    
    // Parse house_ids (could be comma-separated)
    $ids = array_map('intval', explode(',', $house_ids));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    
    try {
        $sql = "SELECT 
                    lr.house_id,
                    lr.last_water_reading,
                    lr.last_water_date,
                    lr.last_sewage_reading,
                    lr.last_sewage_date,
                    lr.last_electricity_reading,
                    lr.last_electricity_date,
                    h.house_code
                FROM last_readings lr
                LEFT JOIN houses h ON lr.house_id = h.id
                WHERE lr.house_id IN ($placeholders)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);
        $results = $stmt->fetchAll();
        
        // Format for Flutter
        $formatted = [];
        foreach ($results as $row) {
            $formatted[] = [
                'house_id' => (int)$row['house_id'],
                'house_code' => $row['house_code'],
                'water_previous' => (float)$row['last_water_reading'],
                'water_previous_date' => $row['last_water_date'],
                'sewage_previous' => (float)$row['last_sewage_reading'], 
                'sewage_previous_date' => $row['last_sewage_date'],
                'electricity_previous' => (float)$row['last_electricity_reading'],
                'electricity_previous_date' => $row['last_electricity_date']
            ];
        }
        
        json_response('success', $formatted);
        
    } catch (Exception $e) {
        json_response('error', null, 'Database error: ' . $e->getMessage());
    }
}

json_response('error', null, 'Method not allowed');
?>