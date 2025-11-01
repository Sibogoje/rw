<?php
// Simple test to check if previous readings are working
require_once 'config/db.php';

echo "=== Testing Previous Readings after Population ===\n\n";

try {
    $db = getDbConnection();
    
    // Check last_readings table stats
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_records,
            COUNT(DISTINCT house_id) as houses_with_readings,
            AVG(CASE WHEN service = 'Water' AND last_reading > 0 THEN last_reading END) as avg_water,
            AVG(CASE WHEN service = 'Electricity' AND last_reading > 0 THEN last_reading END) as avg_electricity
        FROM last_readings
    ");
    $stmt->execute();
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "1. Last Readings Table Summary:\n";
    echo "   Total records: " . $stats['total_records'] . "\n";
    echo "   Houses with readings: " . $stats['houses_with_readings'] . "\n";
    echo "   Average water reading: " . number_format($stats['avg_water'], 2) . "\n";
    echo "   Average electricity reading: " . number_format($stats['avg_electricity'], 2) . "\n\n";
    
    // Test SVO-76 specifically
    echo "2. Testing SVO-76 house:\n";
    $stmt = $db->prepare("
        SELECT h.name, lr.service, lr.last_reading, lr.last_reading_date
        FROM houses h
        LEFT JOIN last_readings lr ON h.id = lr.house_id
        WHERE h.name LIKE '%SVO-76%'
        ORDER BY lr.service
    ");
    $stmt->execute();
    $svo76_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($svo76_results)) {
        foreach ($svo76_results as $row) {
            $service = $row['service'] ?? 'Unknown';
            $reading = $row['last_reading'] ?? '0.00';
            $date = $row['last_reading_date'] ?? 'none';
            echo "   {$row['name']} - $service: $reading (from $date)\n";
        }
    } else {
        echo "   SVO-76 house not found\n";
    }
    
    // Test top 5 houses with highest water readings
    echo "\n3. Top 5 houses with highest water readings:\n";
    $stmt = $db->prepare("
        SELECT h.name, s.name as station, lr.last_reading, lr.last_reading_date
        FROM last_readings lr
        JOIN houses h ON lr.house_id = h.id
        JOIN stations s ON h.station_id = s.id
        WHERE lr.service = 'Water' AND lr.last_reading > 0
        ORDER BY lr.last_reading DESC
        LIMIT 5
    ");
    $stmt->execute();
    $top_water = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($top_water as $row) {
        echo "   {$row['name']} ({$row['station']}): {$row['last_reading']} (from {$row['last_reading_date']})\n";
    }
    
    // Test meter readings API response for SVO-76
    echo "\n4. Testing API response for SVO-76:\n";
    $stmt = $db->prepare("SELECT id FROM houses WHERE name LIKE '%SVO-76%' LIMIT 1");
    $stmt->execute();
    $house = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($house) {
        $house_id = $house['id'];
        
        // Simulate API call
        $stmt = $db->prepare("
            SELECT service, last_reading, last_reading_date
            FROM last_readings
            WHERE house_id = ?
            ORDER BY service
        ");
        $stmt->execute([$house_id]);
        $api_readings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "   House ID: $house_id\n";
        echo "   API would return:\n";
        if (!empty($api_readings)) {
            foreach ($api_readings as $reading) {
                echo "     - {$reading['service']}: {$reading['last_reading']} (from {$reading['last_reading_date']})\n";
            }
        } else {
            echo "     - No readings found\n";
        }
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\n=== Test Complete ===\n";
?>