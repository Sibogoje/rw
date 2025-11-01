<?php
require_once('config/db.php');

echo "=== Checking meter_readings data ===\n";
$stmt = $pdo->query("SELECT house_id, reading_month, water_current_reading, sewage_current_reading, electricity_current_reading FROM meter_readings WHERE house_id IN (1474, 1434, 1412) ORDER BY house_id, reading_month");
$readings = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($readings)) {
    echo "❌ No meter readings found for houses 1474, 1434, 1412\n";
} else {
    echo "✅ Found meter readings:\n";
    foreach ($readings as $reading) {
        echo "House {$reading['house_id']} ({$reading['reading_month']}): Water={$reading['water_current_reading']}, Sewage={$reading['sewage_current_reading']}, Electricity={$reading['electricity_current_reading']}\n";
    }
}

echo "\n=== Checking invoice_lines data ===\n";
$stmt = $pdo->query("SELECT i.house_id, il.service, il.metadata FROM invoice_lines il JOIN invoices i ON il.invoice_id = i.id WHERE i.house_id IN (1474, 1434, 1412) ORDER BY i.house_id, il.service");
$invoiceLines = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($invoiceLines)) {
    echo "❌ No invoice lines found for houses 1474, 1434, 1412\n";
} else {
    echo "✅ Found invoice lines:\n";
    foreach ($invoiceLines as $line) {
        $meta = json_decode($line['metadata'], true);
        $current = $meta['current_reading'] ?? 'null';
        echo "House {$line['house_id']} ({$line['service']}): current_reading={$current}\n";
    }
}

echo "\n=== Testing Previous Readings Logic ===\n";
foreach ([1474, 1434, 1412] as $houseId) {
    echo "\nHouse $houseId:\n";
    
    // Test meter_readings approach (what our meter readings screen uses)
    $stmt = $pdo->prepare("
        SELECT water_current_reading, sewage_current_reading, electricity_current_reading 
        FROM meter_readings 
        WHERE house_id = ? AND reading_month < '2024-11' 
        ORDER BY reading_month DESC 
        LIMIT 1
    ");
    $stmt->execute([$houseId]);
    $meterReading = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($meterReading) {
        echo "  Meter readings approach: Water={$meterReading['water_current_reading']}, Sewage={$meterReading['sewage_current_reading']}, Electricity={$meterReading['electricity_current_reading']}\n";
    } else {
        echo "  Meter readings approach: No previous readings found\n";
    }
    
    // Test invoice_lines approach (what invoice screen uses)
    $readings = ['water' => null, 'sewage' => null, 'electricity' => null];
    foreach (['water', 'sewage', 'electricity'] as $service) {
        $stmt = $pdo->prepare("SELECT metadata FROM invoice_lines il JOIN invoices i ON il.invoice_id = i.id WHERE i.house_id = ? AND il.service = ? ORDER BY i.issue_date DESC LIMIT 1");
        $stmt->execute([$houseId, $service]);
        $row = $stmt->fetch();
        if ($row) {
            $meta = json_decode($row['metadata'], true);
            $readings[$service] = $meta['current_reading'] ?? null;
        }
    }
    echo "  Invoice lines approach: Water={$readings['water']}, Sewage={$readings['sewage']}, Electricity={$readings['electricity']}\n";
}
?>