-- Option 2: Optimized queries with indexes and views
-- Add composite index for faster lookups
ALTER TABLE meter_readings ADD INDEX idx_house_date (house_id, reading_date DESC);

-- Create view for latest readings per house
CREATE VIEW latest_readings_per_house AS
SELECT 
    mr1.house_id,
    mr1.water_current_reading as last_water_reading,
    mr1.sewage_current_reading as last_sewage_reading,
    mr1.electricity_current_reading as last_electricity_reading,
    mr1.reading_date as last_reading_date,
    mr1.reading_month as last_reading_month
FROM meter_readings mr1
WHERE mr1.reading_date = (
    SELECT MAX(mr2.reading_date) 
    FROM meter_readings mr2 
    WHERE mr2.house_id = mr1.house_id
);