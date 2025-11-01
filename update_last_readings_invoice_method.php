<?php
require_once('config/db.php');

echo "=== Updating Last Readings Using Invoice Method ===\n";
echo "Using the same logic as the invoice screen to get previous readings from invoice_lines table.\n\n";

try {
    // Clear existing last_readings table
    echo "1. Clearing existing last_readings table...\n";
    $pdo->exec("DELETE FROM last_readings");
    echo "✅ Cleared last_readings table\n\n";
    
    // Get all houses that have been invoiced
    echo "2. Finding all houses with invoice history...\n";
    $stmt = $pdo->query("
        SELECT DISTINCT i.house_id, h.house_code, s.name as station_name
        FROM invoices i
        LEFT JOIN houses h ON i.house_id = h.id
        LEFT JOIN stations s ON h.station_id = s.id
        ORDER BY i.house_id
    ");
    $houses_with_invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "✅ Found " . count($houses_with_invoices) . " houses with invoice history\n\n";
    
    echo "3. Processing each house using invoice method...\n";
    $processed = 0;
    $houses_with_readings = 0;
    
    foreach ($houses_with_invoices as $house) {
        $house_id = $house['house_id'];
        echo "Processing House $house_id ({$house['house_code']}) - {$house['station_name']}:\n";
        
        // Use the exact same method as the invoice screen
        $readings = ['water' => null, 'sewage' => null, 'electricity' => null];
        $reading_dates = ['water' => null, 'sewage' => null, 'electricity' => null];
        
        foreach (['water', 'sewage', 'electricity'] as $service) {
            $stmt = $pdo->prepare("
                SELECT il.metadata, i.issue_date 
                FROM invoice_lines il 
                JOIN invoices i ON il.invoice_id = i.id 
                WHERE i.house_id = ? AND il.service = ? 
                ORDER BY i.issue_date DESC 
                LIMIT 1
            ");
            $stmt->execute([$house_id, $service]);
            $row = $stmt->fetch();
            
            if ($row) {
                $meta = json_decode($row['metadata'], true);
                $current_reading = $meta['current_reading'] ?? null;
                if ($current_reading !== null && $current_reading > 0) {
                    $readings[$service] = $current_reading;
                    $reading_dates[$service] = $row['issue_date'];
                }
            }
        }
        
        // Sewage uses water's previous reading (like invoice screen)
        if ($readings['water'] !== null) {
            $readings['sewage'] = $readings['water'];
            $reading_dates['sewage'] = $reading_dates['water'];
        }
        
        // Check if we have any readings for this house
        $has_any_reading = $readings['water'] !== null || $readings['electricity'] !== null;
        
        if ($has_any_reading) {
            // Insert into last_readings table
            $insert_stmt = $pdo->prepare("
                INSERT INTO last_readings (
                    house_id,
                    last_water_reading,
                    last_water_date,
                    last_sewage_reading,
                    last_sewage_date,
                    last_electricity_reading,
                    last_electricity_date
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            $insert_stmt->execute([
                $house_id,
                $readings['water'] ?? 0.00,
                $reading_dates['water'],
                $readings['sewage'] ?? 0.00,
                $reading_dates['sewage'],
                $readings['electricity'] ?? 0.00,
                $reading_dates['electricity']
            ]);
            
            echo "  ✅ Added to last_readings:\n";
            echo "     Water: " . ($readings['water'] ?? '0.00') . " (from " . ($reading_dates['water'] ?? 'none') . ")\n";
            echo "     Sewage: " . ($readings['sewage'] ?? '0.00') . " (from " . ($reading_dates['sewage'] ?? 'none') . ")\n";
            echo "     Electricity: " . ($readings['electricity'] ?? '0.00') . " (from " . ($reading_dates['electricity'] ?? 'none') . ")\n";
            
            $houses_with_readings++;
        } else {
            echo "  ℹ️  No invoice readings found - not adding to last_readings table\n";
        }
        
        $processed++;
        echo "\n";
        
        // Show progress every 50 houses
        if ($processed % 50 == 0) {
            echo "--- Progress: $processed/$totalHouses houses processed ---\n\n";
        }
    }
    
    echo "4. Summary of processing:\n";
    echo "✅ Processed $processed total houses with invoices\n";
    echo "✅ $houses_with_readings houses had readings in invoice history\n";
    echo "✅ " . ($processed - $houses_with_readings) . " houses had no usable readings\n\n";
    
    // Show final results summary
    echo "5. Final last_readings table summary:\n";
    $stmt = $pdo->query("
        SELECT COUNT(*) as total_houses,
               COUNT(CASE WHEN last_water_reading > 0 THEN 1 END) as houses_with_water,
               COUNT(CASE WHEN last_electricity_reading > 0 THEN 1 END) as houses_with_electricity,
               AVG(last_water_reading) as avg_water,
               AVG(last_electricity_reading) as avg_electricity
        FROM last_readings
    ");
    $summary = $stmt->fetch();
    
    echo "   Total houses in last_readings: {$summary['total_houses']}\n";
    echo "   Houses with water readings: {$summary['houses_with_water']}\n";
    echo "   Houses with electricity readings: {$summary['houses_with_electricity']}\n";
    echo "   Average water reading: " . number_format($summary['avg_water'], 2) . "\n";
    echo "   Average electricity reading: " . number_format($summary['avg_electricity'], 2) . "\n\n";
    
    // Show some examples
    echo "6. Sample last readings (first 10):\n";
    $stmt = $pdo->query("
        SELECT lr.house_id, h.house_code, s.name as station_name,
               lr.last_water_reading, lr.last_electricity_reading,
               lr.last_water_date, lr.last_electricity_date
        FROM last_readings lr
        LEFT JOIN houses h ON lr.house_id = h.id
        LEFT JOIN stations s ON h.station_id = s.id
        WHERE lr.last_water_reading > 0 OR lr.last_electricity_reading > 0
        ORDER BY lr.last_water_reading DESC
        LIMIT 10
    ");
    
    echo "House | Station | Water (Date) | Electricity (Date)\n";
    echo str_repeat('-', 80) . "\n";
    
    while ($row = $stmt->fetch()) {
        printf(
            "%-20s | %-10s | %7.2f (%s) | %7.2f (%s)\n",
            $row['house_code'] ?: 'Unknown',
            $row['station_name'] ?: 'Unknown',
            $row['last_water_reading'],
            $row['last_water_date'] ?: 'none',
            $row['last_electricity_reading'],
            $row['last_electricity_date'] ?: 'none'
        );
    }
    
    echo "\n🎉 Last readings table updated using invoice method!\n";
    echo "The system now uses the same proven method as the invoice screen.\n";
    echo "Previous readings are taken from the most recent invoice for each house.\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
?>