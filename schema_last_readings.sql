-- Option 1: Last Readings Table
CREATE TABLE last_readings (
    house_id INT PRIMARY KEY,
    last_water_reading DECIMAL(10,2) DEFAULT 0.00,
    last_water_date DATE,
    last_sewage_reading DECIMAL(10,2) DEFAULT 0.00,
    last_sewage_date DATE,
    last_electricity_reading DECIMAL(10,2) DEFAULT 0.00,
    last_electricity_date DATE,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (house_id) REFERENCES houses(id) ON DELETE CASCADE
);

-- Trigger to automatically update last_readings when meter_readings is inserted/updated
DELIMITER $$
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
END$$
DELIMITER ;