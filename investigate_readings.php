<?php
require_once('config/db.php');

echo "=== Investigating Existing Meter Readings ===\n";

// Check what readings actually exist
echo "1. Total meter readings in database:\n";
$stmt = $pdo->query("SELECT COUNT(*) as total FROM meter_readings");
$total = $stmt->fetch()['total'];
echo "   Total readings: $total\n\n";

// Check date range of existing readings
echo "2. Date range of existing readings:\n";
$stmt = $pdo->query("SELECT MIN(reading_date) as oldest, MAX(reading_date) as newest FROM meter_readings");
$range = $stmt->fetch();
echo "   Oldest reading: {$range['oldest']}\n";
echo "   Newest reading: {$range['newest']}\n\n";

// Check how many unique houses have readings
echo "3. Houses with readings:\n";
$stmt = $pdo->query("SELECT COUNT(DISTINCT house_id) as houses_with_readings FROM meter_readings");
$houses_with_readings = $stmt->fetch()['houses_with_readings'];
echo "   Houses with readings: $houses_with_readings\n\n";

// Show sample of readings with house codes
echo "4. Sample readings (first 10):\n";
$stmt = $pdo->query("
    SELECT mr.house_id, h.house_code, mr.reading_date, mr.reading_month,
           mr.water_current_reading, mr.sewage_current_reading, mr.electricity_current_reading
    FROM meter_readings mr
    LEFT JOIN houses h ON mr.house_id = h.id
    ORDER BY mr.reading_date DESC
    LIMIT 10
");
while ($row = $stmt->fetch()) {
    echo "   House {$row['house_id']} ({$row['house_code']}) - {$row['reading_date']} ({$row['reading_month']}) - Water:{$row['water_current_reading']}, Sewage:{$row['sewage_current_reading']}, Electricity:{$row['electricity_current_reading']}\n";
}

echo "\n5. Checking if SVO-76 house exists and has readings:\n";
$stmt = $pdo->query("SELECT id, house_code FROM houses WHERE house_code LIKE '%76%' OR house_code LIKE '%SVO%76%'");
$svo_houses = $stmt->fetchAll();
if (empty($svo_houses)) {
    echo "   No SVO-76 house found\n";
} else {
    foreach ($svo_houses as $house) {
        echo "   Found house: {$house['id']} ({$house['house_code']})\n";
        
        // Check readings for this house
        $stmt = $pdo->prepare("SELECT reading_date, reading_month, water_current_reading, sewage_current_reading, electricity_current_reading FROM meter_readings WHERE house_id = ? ORDER BY reading_date DESC LIMIT 3");
        $stmt->execute([$house['id']]);
        $readings = $stmt->fetchAll();
        
        if (empty($readings)) {
            echo "     No readings found for this house\n";
        } else {
            echo "     Readings for this house:\n";
            foreach ($readings as $reading) {
                echo "       {$reading['reading_date']} ({$reading['reading_month']}) - Water:{$reading['water_current_reading']}, Sewage:{$reading['sewage_current_reading']}, Electricity:{$reading['electricity_current_reading']}\n";
            }
        }
    }
}

echo "\n6. Let's check what's wrong with our date filtering:\n";
$lookback_date = date('Y-m-d', strtotime('-24 months'));
echo "   Looking for readings >= $lookback_date\n";

$stmt = $pdo->prepare("
    SELECT COUNT(*) as count 
    FROM meter_readings 
    WHERE reading_date >= ?
");
$stmt->execute([$lookback_date]);
$recent_count = $stmt->fetch()['count'];
echo "   Readings found >= $lookback_date: $recent_count\n";

// Check specific test houses
echo "\n7. Checking our test houses (1059, 1060, 1061, 1412, 1434, 1474):\n";
$test_houses = [1059, 1060, 1061, 1412, 1434, 1474];
foreach ($test_houses as $house_id) {
    $stmt = $pdo->prepare("
        SELECT h.house_code, mr.reading_date, mr.reading_month,
               mr.water_current_reading, mr.sewage_current_reading, mr.electricity_current_reading
        FROM meter_readings mr
        LEFT JOIN houses h ON mr.house_id = h.id
        WHERE mr.house_id = ?
        ORDER BY mr.reading_date DESC
        LIMIT 1
    ");
    $stmt->execute([$house_id]);
    $reading = $stmt->fetch();
    
    if ($reading) {
        echo "   House $house_id ({$reading['house_code']}): {$reading['reading_date']} - Water:{$reading['water_current_reading']}, Sewage:{$reading['sewage_current_reading']}, Electricity:{$reading['electricity_current_reading']}\n";
        
        // Check if this reading is within our date range
        if ($reading['reading_date'] >= $lookback_date) {
            echo "     ✅ This reading should be captured (within 24 months)\n";
        } else {
            echo "     ❌ This reading is older than 24 months\n";
        }
    } else {
        echo "   House $house_id: No readings found\n";
    }
}
?>