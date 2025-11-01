<?php
// Test the meter readings API with populated last_readings data
header('Content-Type: application/json');
require_once 'config/db.php';

echo "=== Testing Meter Readings API with Populated Data ===\n\n";

// Test 1: Get all stations
echo "1. Testing stations endpoint:\n";
$response = file_get_contents('http://localhost/railway/api/meter_readings.php?action=stations');
$stations = json_decode($response, true);
echo "Found " . count($stations) . " stations\n";
foreach ($stations as $station) {
    echo "   - {$station['name']}\n";
}

echo "\n2. Testing houses for Sidvokodvo station:\n";
$response = file_get_contents('http://localhost/railway/api/meter_readings.php?action=houses&station_id=1');
$houses = json_decode($response, true);
echo "Found " . count($houses) . " houses in Sidvokodvo\n";

echo "\n3. Testing previous readings for first 5 houses:\n";
$count = 0;
foreach ($houses as $house) {
    if ($count >= 5) break;
    
    $house_id = $house['id'];
    $house_name = $house['name'];
    
    // Get previous readings
    $response = file_get_contents("http://localhost/railway/api/meter_readings.php?action=previous_readings&house_id=$house_id");
    $readings = json_decode($response, true);
    
    echo "   House: $house_name (ID: $house_id)\n";
    if (!empty($readings)) {
        foreach ($readings as $reading) {
            $value = $reading['last_reading'] ?? '0.00';
            $date = $reading['last_reading_date'] ?? 'none';
            echo "     - {$reading['service']}: $value (from $date)\n";
        }
    } else {
        echo "     - No previous readings found\n";
    }
    
    $count++;
}

echo "\n4. Testing specific house SVO-76:\n";
try {
    $db = getDbConnection();
    $stmt = $db->prepare("SELECT id FROM houses WHERE name LIKE '%SVO-76%' LIMIT 1");
    $stmt->execute();
    $house = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($house) {
        $house_id = $house['id'];
        $response = file_get_contents("http://localhost/railway/api/meter_readings.php?action=previous_readings&house_id=$house_id");
        $readings = json_decode($response, true);
        
        echo "   SVO-76 (ID: $house_id):\n";
        if (!empty($readings)) {
            foreach ($readings as $reading) {
                $value = $reading['last_reading'] ?? '0.00';
                $date = $reading['last_reading_date'] ?? 'none';
                echo "     - {$reading['service']}: $value (from $date)\n";
            }
        } else {
            echo "     - No previous readings found\n";
        }
    } else {
        echo "   SVO-76 house not found\n";
    }
} catch (Exception $e) {
    echo "   Error: " . $e->getMessage() . "\n";
}

echo "\n5. Sample of last_readings table data:\n";
try {
    $db = getDbConnection();
    $stmt = $db->prepare("
        SELECT h.name, s.name as station, lr.service, lr.last_reading, lr.last_reading_date 
        FROM last_readings lr
        JOIN houses h ON lr.house_id = h.id
        JOIN stations s ON h.station_id = s.id
        WHERE lr.last_reading > 0
        ORDER BY lr.last_reading DESC
        LIMIT 10
    ");
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($results as $row) {
        echo "   {$row['name']} ({$row['station']}) - {$row['service']}: {$row['last_reading']} (from {$row['last_reading_date']})\n";
    }
} catch (Exception $e) {
    echo "   Error: " . $e->getMessage() . "\n";
}

echo "\n=== Test Complete ===\n";
?>