<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

require_once '../config/db.php';

try {
    // Rename existing meter_readings table to meter_readings_old
    $pdo->exec("RENAME TABLE meter_readings TO meter_readings_old");
    
    // Create our custom meter_readings table
    $create_sql = "
    CREATE TABLE meter_readings (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        house_id INT NOT NULL,
        reading_date DATE NOT NULL,
        reading_month VARCHAR(7) NOT NULL COMMENT 'Format: YYYY-MM',
        
        -- Water readings
        water_previous_reading DECIMAL(10,2) DEFAULT 0,
        water_current_reading DECIMAL(10,2) DEFAULT 0,
        water_units DECIMAL(10,2) DEFAULT 0,
        water_faulty TINYINT(1) DEFAULT 0,
        
        -- Sewage readings  
        sewage_previous_reading DECIMAL(10,2) DEFAULT 0,
        sewage_current_reading DECIMAL(10,2) DEFAULT 0,
        sewage_units DECIMAL(10,2) DEFAULT 0,
        sewage_faulty TINYINT(1) DEFAULT 0,
        
        -- Electricity readings
        electricity_previous_reading DECIMAL(10,2) DEFAULT 0,
        electricity_current_reading DECIMAL(10,2) DEFAULT 0,
        electricity_units DECIMAL(10,2) DEFAULT 0,
        electricity_faulty TINYINT(1) DEFAULT 0,
        
        -- Metadata
        captured_by INT NOT NULL COMMENT 'User ID who captured the reading',
        status ENUM('pending', 'verified', 'billed') DEFAULT 'pending',
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        
        -- Indexes
        INDEX idx_house_month (house_id, reading_month),
        INDEX idx_reading_date (reading_date),
        INDEX idx_status (status),
        INDEX idx_captured_by (captured_by),
        
        -- Foreign keys
        FOREIGN KEY (house_id) REFERENCES houses(id) ON DELETE CASCADE,
        FOREIGN KEY (captured_by) REFERENCES users(id) ON DELETE RESTRICT,
        
        -- Ensure one reading per house per month
        UNIQUE KEY unique_house_month (house_id, reading_month)
    )";
    
    $pdo->exec($create_sql);
    
    echo json_encode(array(
        "status" => "success",
        "message" => "Custom meter_readings table created successfully",
        "actions" => [
            "Renamed existing table to meter_readings_old",
            "Created new meter_readings table with custom schema"
        ]
    ));
    
} catch(Exception $e) {
    echo json_encode(array(
        "status" => "error",
        "message" => $e->getMessage()
    ));
}
?>