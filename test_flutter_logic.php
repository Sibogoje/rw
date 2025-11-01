<?php
require_once('config/db.php');

echo "=== Testing Flutter Logic Simulation ===\n";

// Simulate what Flutter is doing
$selectedMonth = '2024-11'; // November 2024 (what you should be selecting)
$selectedHouseIds = [1474, 1434, 1412];

echo "Selected month: $selectedMonth\n";
echo "Selected houses: " . implode(', ', $selectedHouseIds) . "\n\n";

// Get all readings (like Flutter does)
$stmt = $pdo->prepare("SELECT house_id, reading_month, reading_date, water_current_reading, sewage_current_reading, electricity_current_reading FROM meter_readings ORDER BY reading_date DESC");
$stmt->execute();
$allReadings = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "=== All readings in database ===\n";
foreach ($allReadings as $reading) {
    echo "House {$reading['house_id']}: {$reading['reading_month']} ({$reading['reading_date']}) - Water={$reading['water_current_reading']}\n";
}

echo "\n=== Processing like Flutter ===\n";
$latestByHouse = [];

foreach ($allReadings as $reading) {
    $houseId = $reading['house_id'];
    
    echo "Processing house $houseId, month {$reading['reading_month']}\n";
    
    // Only process selected houses and skip current month
    if (!in_array($houseId, $selectedHouseIds)) {
        echo "  -> Skipped: not in selected houses\n";
        continue;
    }
    
    if ($reading['reading_month'] == $selectedMonth) {
        echo "  -> Skipped: same as selected month ($selectedMonth)\n";
        continue;
    }
    
    // Keep latest reading per house
    if (!isset($latestByHouse[$houseId]) ||
        $reading['reading_date'] > $latestByHouse[$houseId]['reading_date']) {
        $latestByHouse[$houseId] = $reading;
        echo "  -> Kept as latest for house $houseId\n";
    } else {
        echo "  -> Skipped: older than current latest\n";
    }
}

echo "\n=== Final previous readings ===\n";
foreach ($selectedHouseIds as $houseId) {
    if (isset($latestByHouse[$houseId])) {
        $latest = $latestByHouse[$houseId];
        echo "House $houseId: Water={$latest['water_current_reading']}, Sewage={$latest['sewage_current_reading']}, Electricity={$latest['electricity_current_reading']} (from {$latest['reading_month']})\n";
    } else {
        echo "House $houseId: No previous readings found\n";
    }
}
?>