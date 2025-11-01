<?php
require_once('config/db.php');

echo "=== Creating last_readings table ===\n";

try {
    // Create the table
    $sql = "CREATE TABLE last_readings (
        house_id INT PRIMARY KEY,
        last_water_reading DECIMAL(10,2) DEFAULT 0.00,
        last_water_date DATE,
        last_sewage_reading DECIMAL(10,2) DEFAULT 0.00,
        last_sewage_date DATE,
        last_electricity_reading DECIMAL(10,2) DEFAULT 0.00,
        last_electricity_date DATE,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (house_id) REFERENCES houses(id) ON DELETE CASCADE
    )";
    
    $pdo->exec($sql);
    echo "✅ last_readings table created successfully\n";
    
    // Create trigger for automatic updates
    $trigger_sql = "
    CREATE TRIGGER update_last_readings 
    AFTER INSERT ON meter_readings
    FOR EACH ROW
    BEGIN
        INSERT INTO last_readings (
            house_id, 
            last_water_reading, last_water_date,
            last_sewage_reading, last_sewage_date,
            last_electricity_reading, last_electricity_date
        ) VALUES (
            NEW.house_id,
            NEW.water_current_reading, NEW.reading_date,
            NEW.sewage_current_reading, NEW.reading_date,
            NEW.electricity_current_reading, NEW.reading_date
        )
        ON DUPLICATE KEY UPDATE
            last_water_reading = NEW.water_current_reading,
            last_water_date = NEW.reading_date,
            last_sewage_reading = NEW.sewage_current_reading,
            last_sewage_date = NEW.reading_date,
            last_electricity_reading = NEW.electricity_current_reading,
            last_electricity_date = NEW.reading_date;
    END";
    
    $pdo->exec($trigger_sql);
    echo "✅ Trigger created successfully\n";
    
    // Populate with existing data
    echo "\n=== Populating with existing meter readings ===\n";
    $populate_sql = "
    INSERT INTO last_readings (house_id, last_water_reading, last_water_date, last_sewage_reading, last_sewage_date, last_electricity_reading, last_electricity_date)
    SELECT 
        house_id,
        water_current_reading,
        reading_date,
        sewage_current_reading,
        reading_date,
        electricity_current_reading,
        reading_date
    FROM meter_readings mr1
    WHERE reading_date = (
        SELECT MAX(reading_date) 
        FROM meter_readings mr2 
        WHERE mr2.house_id = mr1.house_id
    )
    ";
    
    $result = $pdo->exec($populate_sql);
    echo "✅ Populated $result houses with existing readings\n";
    
    // Show the results
    echo "\n=== Last readings summary ===\n";
    $stmt = $pdo->query("SELECT lr.*, h.house_code FROM last_readings lr LEFT JOIN houses h ON lr.house_id = h.id ORDER BY lr.house_id");
    while ($row = $stmt->fetch()) {
        echo "House {$row['house_id']} ({$row['house_code']}): Water={$row['last_water_reading']}, Sewage={$row['last_sewage_reading']}, Electricity={$row['last_electricity_reading']} (Date: {$row['last_water_date']})\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>