-- Create meter_readings table for storing captured meter readings
-- This separates reading capture from invoice generation

CREATE TABLE IF NOT EXISTS meter_readings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    house_id INT NOT NULL,
    reading_date DATE NOT NULL,
    reading_month VARCHAR(7) NOT NULL, -- YYYY-MM format for grouping
    
    -- Current readings captured
    water_current DECIMAL(10,2) NULL,
    sewage_current DECIMAL(10,2) NULL,
    electricity_current DECIMAL(10,2) NULL,
    
    -- Previous readings (auto-fetched from last reading)
    water_previous DECIMAL(10,2) NULL DEFAULT 0,
    sewage_previous DECIMAL(10,2) NULL DEFAULT 0,
    electricity_previous DECIMAL(10,2) NULL DEFAULT 0,
    
    -- Calculated units (current - previous)
    water_units DECIMAL(10,2) NULL DEFAULT 0,
    sewage_units DECIMAL(10,2) NULL DEFAULT 0,
    electricity_units DECIMAL(10,2) NULL DEFAULT 0,
    
    -- Faulty meter flags
    water_faulty TINYINT(1) DEFAULT 0,
    sewage_faulty TINYINT(1) DEFAULT 0,
    electricity_faulty TINYINT(1) DEFAULT 0,
    
    -- Tracking
    captured_by INT NOT NULL, -- user_id who captured
    status ENUM('draft', 'captured', 'invoiced') DEFAULT 'captured',
    notes TEXT NULL,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (house_id) REFERENCES houses(id) ON DELETE CASCADE,
    FOREIGN KEY (captured_by) REFERENCES users(id),
    
    -- Ensure one reading per house per month
    UNIQUE KEY unique_house_month (house_id, reading_month),
    
    INDEX idx_reading_date (reading_date),
    INDEX idx_reading_month (reading_month),
    INDEX idx_status (status)
);

-- Add some sample data comments
-- INSERT INTO meter_readings (house_id, reading_date, reading_month, water_current, electricity_current, captured_by) 
-- VALUES (1, '2025-11-01', '2025-11', 1250.5, 2840.2, 1);