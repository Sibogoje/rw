<?php
require_once('config/db.php');

echo "=== Searching for All Possible Meter Reading Data ===\n";

// Check all tables that might contain meter readings
echo "1. Checking all tables in database:\n";
$stmt = $pdo->query("SHOW TABLES");
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $table) {
    echo "   Table: $table\n";
}

echo "\n2. Looking for tables with 'reading' or 'meter' in name:\n";
foreach ($tables as $table) {
    if (stripos($table, 'reading') !== false || stripos($table, 'meter') !== false) {
        echo "   Found: $table\n";
        
        // Check structure and count
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM `$table`");
        $count = $stmt->fetch()['count'];
        echo "     Records: $count\n";
        
        if ($count > 0 && $count < 20) {
            echo "     Sample data:\n";
            $stmt = $pdo->query("SELECT * FROM `$table` LIMIT 3");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                echo "       " . json_encode($row) . "\n";
            }
        }
    }
}

echo "\n3. Checking invoice_lines table (might contain meter readings):\n";
$stmt = $pdo->query("SELECT COUNT(*) as count FROM invoice_lines");
$count = $stmt->fetch()['count'];
echo "   Invoice lines count: $count\n";

if ($count > 0) {
    echo "   Sample invoice lines with metadata:\n";
    $stmt = $pdo->query("
        SELECT il.service, il.metadata, i.house_id, h.house_code, i.bill_month
        FROM invoice_lines il
        JOIN invoices i ON il.invoice_id = i.id
        LEFT JOIN houses h ON i.house_id = h.id
        WHERE il.metadata IS NOT NULL
        ORDER BY i.bill_month DESC
        LIMIT 10
    ");
    
    while ($row = $stmt->fetch()) {
        $metadata = json_decode($row['metadata'], true);
        $current = $metadata['current_reading'] ?? 'null';
        $previous = $metadata['previous_reading'] ?? 'null';
        echo "   House {$row['house_id']} ({$row['house_code']}) - {$row['bill_month']} - {$row['service']}: current=$current, previous=$previous\n";
    }
}

echo "\n4. Checking for houses that have been billed (might indicate they have readings):\n";
$stmt = $pdo->query("
    SELECT COUNT(DISTINCT i.house_id) as billed_houses
    FROM invoices i
    WHERE i.bill_month >= '2024-01-01'
");
$billed = $stmt->fetch()['billed_houses'];
echo "   Houses billed in 2024: $billed\n";

if ($billed > 0) {
    echo "   Sample billed houses:\n";
    $stmt = $pdo->query("
        SELECT DISTINCT i.house_id, h.house_code, COUNT(i.id) as invoice_count
        FROM invoices i
        LEFT JOIN houses h ON i.house_id = h.id
        WHERE i.bill_month >= '2024-01-01'
        GROUP BY i.house_id, h.house_code
        ORDER BY invoice_count DESC
        LIMIT 10
    ");
    
    while ($row = $stmt->fetch()) {
        echo "   House {$row['house_id']} ({$row['house_code']}): {$row['invoice_count']} invoices\n";
    }
}

echo "\n5. Checking if there might be readings in a different date format:\n";
$stmt = $pdo->query("
    SELECT reading_date, reading_month, created_at 
    FROM meter_readings 
    ORDER BY created_at DESC
    LIMIT 5
");
echo "   All meter_readings dates:\n";
while ($row = $stmt->fetch()) {
    echo "   reading_date: {$row['reading_date']}, reading_month: {$row['reading_month']}, created_at: {$row['created_at']}\n";
}

echo "\n6. Quick test - what if we remove date restrictions entirely?\n";
$stmt = $pdo->query("
    SELECT house_id, reading_date, water_current_reading, sewage_current_reading, electricity_current_reading
    FROM meter_readings
    ORDER BY reading_date DESC
");
$all_readings = $stmt->fetchAll();
echo "   Total readings without date filter: " . count($all_readings) . "\n";

foreach ($all_readings as $reading) {
    echo "   House {$reading['house_id']}: {$reading['reading_date']} - W:{$reading['water_current_reading']}, S:{$reading['sewage_current_reading']}, E:{$reading['electricity_current_reading']}\n";
}
?>