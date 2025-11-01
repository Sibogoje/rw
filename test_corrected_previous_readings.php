<?php
// Test to check if previous readings are working correctly
require_once __DIR__ . '/config/db.php';

echo "=== Testing Previous Readings after Population ===\n\n";

try {
    // Check last_readings table stats
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_records,
            COUNT(CASE WHEN last_water_reading > 0 THEN 1 END) as houses_with_water,
            COUNT(CASE WHEN last_electricity_reading > 0 THEN 1 END) as houses_with_electricity,
            AVG(CASE WHEN last_water_reading > 0 THEN last_water_reading END) as avg_water,
            AVG(CASE WHEN last_electricity_reading > 0 THEN last_electricity_reading END) as avg_electricity
        FROM last_readings
    ");
    $stmt->execute();
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "1. Last Readings Table Summary:\n";
    echo "   Total records: " . $stats['total_records'] . "\n";
    echo "   Houses with water readings: " . $stats['houses_with_water'] . "\n";
    echo "   Houses with electricity readings: " . $stats['houses_with_electricity'] . "\n";
    echo "   Average water reading: " . number_format($stats['avg_water'], 2) . "\n";
    echo "   Average electricity reading: " . number_format($stats['avg_electricity'], 2) . "\n\n";
    
    // Test SVO-76 specifically
    echo "2. Testing SVO-76 house:\n";
    $stmt = $pdo->prepare("
        SELECT h.house_code, lr.last_water_reading, lr.last_water_date, 
               lr.last_sewage_reading, lr.last_sewage_date,
               lr.last_electricity_reading, lr.last_electricity_date
        FROM houses h
        LEFT JOIN last_readings lr ON h.id = lr.house_id
        WHERE h.house_code LIKE '%SVO-76%'
    ");
    $stmt->execute();
    $svo76_result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($svo76_result) {
        echo "   {$svo76_result['house_code']}:\n";
        echo "     Water: {$svo76_result['last_water_reading']} (from {$svo76_result['last_water_date']})\n";
        echo "     Sewage: {$svo76_result['last_sewage_reading']} (from {$svo76_result['last_sewage_date']})\n";
        echo "     Electricity: {$svo76_result['last_electricity_reading']} (from {$svo76_result['last_electricity_date']})\n";
    } else {
        echo "   SVO-76 house not found\n";
    }
    
    // Test top 5 houses with highest water readings
    echo "\n3. Top 5 houses with highest water readings:\n";
    $stmt = $pdo->prepare("
        SELECT h.house_code, s.name as station, lr.last_water_reading, lr.last_water_date
        FROM last_readings lr
        JOIN houses h ON lr.house_id = h.id
        JOIN stations s ON h.station_id = s.id
        WHERE lr.last_water_reading > 0
        ORDER BY lr.last_water_reading DESC
        LIMIT 5
    ");
    $stmt->execute();
    $top_water = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($top_water as $row) {
        echo "   {$row['house_code']} ({$row['station']}): {$row['last_water_reading']} (from {$row['last_water_date']})\n";
    }
    
    // Test the API format that would be returned for a specific house
    echo "\n4. Simulating API response format for SVO-76:\n";
    $stmt = $pdo->prepare("SELECT id FROM houses WHERE name LIKE '%SVO-76%' LIMIT 1");
    $stmt->execute();
    $house = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($house) {
        $house_id = $house['id'];
        
        // Simulate the previous_readings API endpoint logic
        $stmt = $pdo->prepare("
            SELECT last_water_reading, last_sewage_reading, last_electricity_reading,
                   last_water_date, last_sewage_date, last_electricity_date
            FROM last_readings
            WHERE house_id = ?
        ");
        $stmt->execute([$house_id]);
        $prev = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($prev) {
            $water_previous = $prev['last_water_reading'] ?: 0;
            $sewage_previous = $prev['last_sewage_reading'] ?: 0;
            $electricity_previous = $prev['last_electricity_reading'] ?: 0;
            
            echo "   House ID: $house_id\n";
            echo "   API would return previous readings:\n";
            echo "     Water: $water_previous (from {$prev['last_water_date']})\n";
            echo "     Sewage: $sewage_previous (from {$prev['last_sewage_date']})\n";
            echo "     Electricity: $electricity_previous (from {$prev['last_electricity_date']})\n";
        } else {
            echo "   No previous readings found for house ID: $house_id\n";
        }
    } else {
        echo "   SVO-76 house not found\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\n=== Test Complete ===\n";
?>