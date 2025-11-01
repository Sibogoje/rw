<?php
require_once('config/db.php');

echo "=== Updating Last Readings Table ===\n";
echo "This script will find the most recent reading for each house, regardless of when it was taken.\n\n";

try {
    // Clear existing last_readings table
    echo "1. Clearing existing last_readings table...\n";
    $pdo->exec("DELETE FROM last_readings");
    echo "✅ Cleared last_readings table\n\n";
    
    // Get all houses that have meter readings
    echo "2. Finding all houses with meter readings...\n";
    $stmt = $pdo->query("
        SELECT DISTINCT house_id 
        FROM meter_readings 
        ORDER BY house_id
    ");
    $houses_with_readings = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "✅ Found " . count($houses_with_readings) . " houses with meter readings\n\n";
    
    echo "3. Processing each house to find their most recent readings...\n";
    $processed = 0;
    
    foreach ($houses_with_readings as $house_id) {
        echo "Processing House $house_id:\n";
        
        // Find the most recent reading for this house
        $stmt = $pdo->prepare("
            SELECT 
                house_id,
                reading_date,
                reading_month,
                water_current_reading,
                sewage_current_reading,
                electricity_current_reading,
                created_at
            FROM meter_readings 
            WHERE house_id = ? 
            ORDER BY reading_date DESC, created_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$house_id]);
        $latest_reading = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($latest_reading) {
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
                $latest_reading['water_current_reading'],
                $latest_reading['reading_date'],
                $latest_reading['sewage_current_reading'],
                $latest_reading['reading_date'],
                $latest_reading['electricity_current_reading'],
                $latest_reading['reading_date']
            ]);
            
            echo "  ✅ Latest reading from {$latest_reading['reading_date']} ({$latest_reading['reading_month']})\n";
            echo "     Water: {$latest_reading['water_current_reading']}\n";
            echo "     Sewage: {$latest_reading['sewage_current_reading']}\n";
            echo "     Electricity: {$latest_reading['electricity_current_reading']}\n";
            
            $processed++;
        } else {
            echo "  ❌ No readings found for this house\n";
        }
        echo "\n";
    }
    
    echo "4. Summary of processing:\n";
    echo "✅ Processed $processed houses successfully\n\n";
    
    // Show final results with house codes for verification
    echo "5. Final last_readings table (with house codes):\n";
    $stmt = $pdo->query("
        SELECT 
            lr.house_id,
            h.house_code,
            s.name as station_name,
            lr.last_water_reading,
            lr.last_sewage_reading,
            lr.last_electricity_reading,
            lr.last_water_date,
            DATEDIFF(CURDATE(), lr.last_water_date) as days_ago
        FROM last_readings lr
        LEFT JOIN houses h ON lr.house_id = h.id
        LEFT JOIN stations s ON h.station_id = s.id
        ORDER BY lr.last_water_date DESC, lr.house_id
    ");
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($results)) {
        echo "❌ No results found\n";
    } else {
        echo "House ID | House Code | Station | Water | Sewage | Electricity | Last Reading Date | Days Ago\n";
        echo str_repeat('-', 120) . "\n";
        
        foreach ($results as $row) {
            printf(
                "%-8s | %-20s | %-10s | %7.2f | %7.2f | %7.2f | %-15s | %s days ago\n",
                $row['house_id'],
                $row['house_code'] ?: 'Unknown',
                $row['station_name'] ?: 'Unknown',
                $row['last_water_reading'],
                $row['last_sewage_reading'],
                $row['last_electricity_reading'],
                $row['last_water_date'],
                $row['days_ago']
            );
        }
        
        echo "\n📊 Statistics:\n";
        echo "Total houses: " . count($results) . "\n";
        
        // Show age distribution
        $recent = 0; $monthly = 0; $quarterly = 0; $old = 0;
        foreach ($results as $row) {
            $days = (int)$row['days_ago'];
            if ($days <= 7) $recent++;
            elseif ($days <= 31) $monthly++;
            elseif ($days <= 93) $quarterly++;
            else $old++;
        }
        
        echo "Recent (≤7 days): $recent houses\n";
        echo "Monthly (≤31 days): $monthly houses\n";
        echo "Quarterly (≤93 days): $quarterly houses\n";
        echo "Older (>93 days): $old houses\n";
    }
    
    echo "\n🎉 Last readings table has been updated successfully!\n";
    echo "The system now tracks the most recent reading for each house, regardless of when it was taken.\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
?>