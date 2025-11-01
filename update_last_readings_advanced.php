<?php
require_once('config/db.php');

echo "=== Advanced Last Readings Update (12-month lookback per meter type) ===\n";
echo "This script will find the most recent reading for each meter type of each house, looking back up to 12 months.\n\n";

try {
    // Clear existing last_readings table
    echo "1. Clearing existing last_readings table...\n";
    $pdo->exec("DELETE FROM last_readings");
    echo "✅ Cleared last_readings table\n\n";
    
    // Get all houses (not just those with readings)
    echo "2. Getting all houses in the system...\n";
    $stmt = $pdo->query("
        SELECT h.id, h.house_code, s.name as station_name
        FROM houses h
        LEFT JOIN stations s ON h.station_id = s.id
        ORDER BY h.id
    ");
    $all_houses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "✅ Found " . count($all_houses) . " total houses\n\n";
    
    echo "3. Processing each house to find last readings for each meter type (up to 12 months back)...\n";
    $processed = 0;
    $houses_with_readings = 0;
    
    // Calculate 24 months ago (to capture older test data)
    $lookback_months = 24;
    $months_ago = date('Y-m-d', strtotime("-$lookback_months months"));
    echo "Looking for readings from $months_ago onwards (last $lookback_months months)...\n\n";
    
    foreach ($all_houses as $house) {
        $house_id = $house['id'];
        echo "Processing House $house_id ({$house['house_code']}) - {$house['station_name']}:\n";
        
        // Find the most recent reading for each meter type separately
        $water_reading = null;
        $sewage_reading = null;
        $electricity_reading = null;
        
        // Get most recent water reading (up to 24 months back)
        $stmt = $pdo->prepare("
            SELECT water_current_reading, reading_date
            FROM meter_readings 
            WHERE house_id = ? 
            AND water_current_reading IS NOT NULL 
            AND reading_date >= ?
            ORDER BY reading_date DESC, created_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$house_id, $months_ago]);
        $water_reading = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get most recent sewage reading (up to 24 months back)
        $stmt = $pdo->prepare("
            SELECT sewage_current_reading, reading_date
            FROM meter_readings 
            WHERE house_id = ? 
            AND sewage_current_reading IS NOT NULL 
            AND reading_date >= ?
            ORDER BY reading_date DESC, created_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$house_id, $months_ago]);
        $sewage_reading = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get most recent electricity reading (up to 24 months back)
        $stmt = $pdo->prepare("
            SELECT electricity_current_reading, reading_date
            FROM meter_readings 
            WHERE house_id = ? 
            AND electricity_current_reading IS NOT NULL 
            AND reading_date >= ?
            ORDER BY reading_date DESC, created_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$house_id, $months_ago]);
        $electricity_reading = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Determine what to insert
        $has_any_reading = $water_reading || $sewage_reading || $electricity_reading;
        
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
                $water_reading ? $water_reading['water_current_reading'] : 0.00,
                $water_reading ? $water_reading['reading_date'] : null,
                $sewage_reading ? $sewage_reading['sewage_current_reading'] : 0.00,
                $sewage_reading ? $sewage_reading['reading_date'] : null,
                $electricity_reading ? $electricity_reading['electricity_current_reading'] : 0.00,
                $electricity_reading ? $electricity_reading['reading_date'] : null
            ]);
            
            echo "  ✅ Added to last_readings:\n";
            if ($water_reading) {
                echo "     Water: {$water_reading['water_current_reading']} (from {$water_reading['reading_date']})\n";
            } else {
                echo "     Water: 0.00 (no reading in last 24 months)\n";
            }
            
            if ($sewage_reading) {
                echo "     Sewage: {$sewage_reading['sewage_current_reading']} (from {$sewage_reading['reading_date']})\n";
            } else {
                echo "     Sewage: 0.00 (no reading in last 24 months)\n";
            }
            
            if ($electricity_reading) {
                echo "     Electricity: {$electricity_reading['electricity_current_reading']} (from {$electricity_reading['reading_date']})\n";
            } else {
                echo "     Electricity: 0.00 (no reading in last 24 months)\n";
            }
            
            $houses_with_readings++;
        } else {
            echo "  ℹ️  No readings found in last 24 months - not adding to last_readings table\n";
        }
        
        $processed++;
        echo "\n";
    }
    
    echo "4. Summary of processing:\n";
    echo "✅ Processed $processed total houses\n";
    echo "✅ $houses_with_readings houses had readings in last 24 months\n";
    echo "✅ " . ($processed - $houses_with_readings) . " houses had no readings in last 24 months\n\n";
    
    // Show final results with detailed breakdown
    echo "5. Final last_readings table (houses with readings in last 24 months):\n";
    $stmt = $pdo->query("
        SELECT 
            lr.house_id,
            h.house_code,
            s.name as station_name,
            lr.last_water_reading,
            lr.last_water_date,
            lr.last_sewage_reading,
            lr.last_sewage_date,
            lr.last_electricity_reading,
            lr.last_electricity_date,
            CASE 
                WHEN lr.last_water_date IS NOT NULL THEN DATEDIFF(CURDATE(), lr.last_water_date)
                ELSE NULL
            END as water_days_ago,
            CASE 
                WHEN lr.last_sewage_date IS NOT NULL THEN DATEDIFF(CURDATE(), lr.last_sewage_date)
                ELSE NULL
            END as sewage_days_ago,
            CASE 
                WHEN lr.last_electricity_date IS NOT NULL THEN DATEDIFF(CURDATE(), lr.last_electricity_date)
                ELSE NULL
            END as electricity_days_ago
        FROM last_readings lr
        LEFT JOIN houses h ON lr.house_id = h.id
        LEFT JOIN stations s ON h.station_id = s.id
        ORDER BY h.house_code
    ");
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($results)) {
        echo "❌ No houses with readings in last 24 months found\n";
    } else {
        echo "House | Station | Water (Days Ago) | Sewage (Days Ago) | Electricity (Days Ago)\n";
        echo str_repeat('-', 100) . "\n";
        
        foreach ($results as $row) {
            printf(
                "%-20s | %-10s | %7.2f (%s) | %7.2f (%s) | %7.2f (%s)\n",
                $row['house_code'] ?: 'Unknown',
                $row['station_name'] ?: 'Unknown',
                $row['last_water_reading'],
                $row['water_days_ago'] !== null ? $row['water_days_ago'] . ' days' : 'no reading',
                $row['last_sewage_reading'],
                $row['sewage_days_ago'] !== null ? $row['sewage_days_ago'] . ' days' : 'no reading',
                $row['last_electricity_reading'],
                $row['electricity_days_ago'] !== null ? $row['electricity_days_ago'] . ' days' : 'no reading'
            );
        }
        
        echo "\n📊 Statistics:\n";
        echo "Houses with recent readings: " . count($results) . "\n";
        
        // Count readings by age for each meter type
        $water_stats = ['recent' => 0, 'monthly' => 0, 'quarterly' => 0, 'old' => 0, 'none' => 0];
        $sewage_stats = ['recent' => 0, 'monthly' => 0, 'quarterly' => 0, 'old' => 0, 'none' => 0];
        $electricity_stats = ['recent' => 0, 'monthly' => 0, 'quarterly' => 0, 'old' => 0, 'none' => 0];
        
        foreach ($results as $row) {
            // Water stats
            if ($row['water_days_ago'] === null) $water_stats['none']++;
            elseif ($row['water_days_ago'] <= 7) $water_stats['recent']++;
            elseif ($row['water_days_ago'] <= 31) $water_stats['monthly']++;
            elseif ($row['water_days_ago'] <= 93) $water_stats['quarterly']++;
            else $water_stats['old']++;
            
            // Sewage stats
            if ($row['sewage_days_ago'] === null) $sewage_stats['none']++;
            elseif ($row['sewage_days_ago'] <= 7) $sewage_stats['recent']++;
            elseif ($row['sewage_days_ago'] <= 31) $sewage_stats['monthly']++;
            elseif ($row['sewage_days_ago'] <= 93) $sewage_stats['quarterly']++;
            else $sewage_stats['old']++;
            
            // Electricity stats
            if ($row['electricity_days_ago'] === null) $electricity_stats['none']++;
            elseif ($row['electricity_days_ago'] <= 7) $electricity_stats['recent']++;
            elseif ($row['electricity_days_ago'] <= 31) $electricity_stats['monthly']++;
            elseif ($row['electricity_days_ago'] <= 93) $electricity_stats['quarterly']++;
            else $electricity_stats['old']++;
        }
        
        echo "\nWater readings: Recent={$water_stats['recent']}, Monthly={$water_stats['monthly']}, Quarterly={$water_stats['quarterly']}, Old={$water_stats['old']}, None={$water_stats['none']}\n";
        echo "Sewage readings: Recent={$sewage_stats['recent']}, Monthly={$sewage_stats['monthly']}, Quarterly={$sewage_stats['quarterly']}, Old={$sewage_stats['old']}, None={$sewage_stats['none']}\n";
        echo "Electricity readings: Recent={$electricity_stats['recent']}, Monthly={$electricity_stats['monthly']}, Quarterly={$electricity_stats['quarterly']}, Old={$electricity_stats['old']}, None={$electricity_stats['none']}\n";
    }
    
    echo "\n🎉 Advanced last readings table has been updated successfully!\n";
    echo "The system now tracks the most recent reading for each meter type of each house, looking back up to 24 months.\n";
    echo "Houses without ANY readings in 24 months are not included (they would show all zeros when selected).\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
?>