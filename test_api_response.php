<?php
require_once('config/db.php');

// Test the exact API call that Flutter makes
$house_ids = [1474, 1434, 1412];
$selected_month = '2024-11';

echo "=== Testing API Response for Flutter ===\n";
echo "Selected month: $selected_month\n";
echo "Selected houses: " . implode(', ', $house_ids) . "\n\n";

// Simulate the first API call (existing readings for November)
echo "1. Checking existing readings for November 2024:\n";
$stmt = $pdo->prepare("SELECT * FROM meter_readings WHERE reading_month = ?");
$stmt->execute([$selected_month]);
$november_readings = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Found " . count($november_readings) . " readings for November 2024\n\n";

// Simulate the second API call (all readings to find previous)
echo "2. Getting all readings to find previous:\n";
$stmt = $pdo->query("SELECT house_id, reading_date, reading_month, water_current_reading, sewage_current_reading, electricity_current_reading FROM meter_readings ORDER BY reading_date DESC");
$all_readings = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Total readings in database: " . count($all_readings) . "\n";

// Process like Flutter does
$latest_by_house = [];
foreach ($all_readings as $reading) {
    $house_id = (int)$reading['house_id'];
    
    echo "Processing house $house_id, month {$reading['reading_month']}\n";
    
    // Skip if not in selected houses
    if (!in_array($house_id, $house_ids)) {
        echo "  -> Skipped: not in selected houses\n";
        continue;
    }
    
    // Skip if current month
    if ($reading['reading_month'] == $selected_month) {
        echo "  -> Skipped: is current month\n";
        continue;
    }
    
    // Keep latest reading per house
    if (!isset($latest_by_house[$house_id]) ||
        $reading['reading_date'] > $latest_by_house[$house_id]['reading_date']) {
        echo "  -> Kept as latest for house $house_id\n";
        $latest_by_house[$house_id] = $reading;
    } else {
        echo "  -> Skipped: older than existing latest\n";
    }
}

echo "\n3. Final previous readings:\n";
foreach ($house_ids as $house_id) {
    if (isset($latest_by_house[$house_id])) {
        $latest = $latest_by_house[$house_id];
        echo "House $house_id: Water={$latest['water_current_reading']}, Sewage={$latest['sewage_current_reading']}, Electricity={$latest['electricity_current_reading']} (from {$latest['reading_month']})\n";
    } else {
        echo "House $house_id: No previous readings found (would show 0.0)\n";
    }
}

echo "\n4. Testing API endpoint directly:\n";
// Test what the API actually returns
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://zenmark.grinpath.com/api/meter_readings?per_page=1000');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $http_code\n";
if ($response) {
    $data = json_decode($response, true);
    if ($data && isset($data['data'])) {
        $api_readings = $data['data'];
        if (is_array($api_readings)) {
            echo "API returned " . count($api_readings) . " readings\n";
            
            // Check if our test houses are in the API response
            $found_houses = [];
            foreach ($api_readings as $reading) {
                if (in_array($reading['house_id'], $house_ids)) {
                    $found_houses[] = $reading['house_id'];
                }
            }
            echo "Test houses found in API: " . implode(', ', array_unique($found_houses)) . "\n";
        } else {
            echo "API data is not an array\n";
        }
    } else {
        echo "API response structure: " . substr($response, 0, 200) . "\n";
    }
} else {
    echo "No response from API\n";
}
?>