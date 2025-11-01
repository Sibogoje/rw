<?php
require_once('config/db.php');

// Create test readings for the houses you're actually selecting
$readings = [
    [
        'house_id' => 1474,
        'reading_date' => '2024-10-15',
        'reading_month' => '2024-10',
        'water_previous_reading' => 850.0,
        'water_current_reading' => 1125.0,
        'water_units' => 275.0,
        'sewage_previous_reading' => 420.0,
        'sewage_current_reading' => 670.0,
        'sewage_units' => 250.0,
        'electricity_previous_reading' => 1200.0,
        'electricity_current_reading' => 1850.0,
        'electricity_units' => 650.0,
        'captured_by' => 1,
        'status' => 'verified'
    ],
    [
        'house_id' => 1434,
        'reading_date' => '2024-10-18',
        'reading_month' => '2024-10',
        'water_previous_reading' => 920.0,
        'water_current_reading' => 1180.0,
        'water_units' => 260.0,
        'sewage_previous_reading' => 460.0,
        'sewage_current_reading' => 690.0,
        'sewage_units' => 230.0,
        'electricity_previous_reading' => 1350.0,
        'electricity_current_reading' => 1975.0,
        'electricity_units' => 625.0,
        'captured_by' => 1,
        'status' => 'verified'
    ],
    [
        'house_id' => 1412,
        'reading_date' => '2024-10-20',
        'reading_month' => '2024-10',
        'water_previous_reading' => 780.0,
        'water_current_reading' => 1045.0,
        'water_units' => 265.0,
        'sewage_previous_reading' => 390.0,
        'sewage_current_reading' => 625.0,
        'sewage_units' => 235.0,
        'electricity_previous_reading' => 1100.0,
        'electricity_current_reading' => 1720.0,
        'electricity_units' => 620.0,
        'captured_by' => 1,
        'status' => 'verified'
    ]
];

$sql = "INSERT INTO meter_readings (
    house_id, reading_date, reading_month, 
    water_previous_reading, water_current_reading, water_units,
    sewage_previous_reading, sewage_current_reading, sewage_units,
    electricity_previous_reading, electricity_current_reading, electricity_units,
    captured_by, status, created_at
) VALUES (:house_id, :reading_date, :reading_month, :water_previous_reading, :water_current_reading, :water_units, :sewage_previous_reading, :sewage_current_reading, :sewage_units, :electricity_previous_reading, :electricity_current_reading, :electricity_units, :captured_by, :status, NOW())";

$stmt = $pdo->prepare($sql);

foreach ($readings as $reading) {
    try {
        $stmt->execute($reading);
        echo "✅ Created reading for house {$reading['house_id']}\n";
    } catch (PDOException $e) {
        echo "❌ Error creating reading for house {$reading['house_id']}: " . $e->getMessage() . "\n";
    }
}

echo "\n🎉 Test readings created! Now your meter readings screen should show previous readings.\n";
?>