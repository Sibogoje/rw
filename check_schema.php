<?php
require_once('config/db.php');

echo "=== Meter Readings Table Schema ===\n";
$stmt = $pdo->query('DESCRIBE meter_readings');
while($row = $stmt->fetch()) {
    echo $row['Field'] . ' - ' . $row['Type'] . "\n";
}

echo "\n=== Sample Record from Our Test Data ===\n";
$stmt = $pdo->query('SELECT * FROM meter_readings WHERE house_id = 1474 LIMIT 1');
$record = $stmt->fetch(PDO::FETCH_ASSOC);
if ($record) {
    foreach ($record as $key => $value) {
        echo "$key: $value\n";
    }
} else {
    echo "No record found\n";
}
?>