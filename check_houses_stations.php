<?php
require_once('config/db.php');

echo "=== Houses and their stations ===\n";
$stmt = $pdo->query('SELECT h.id, h.house_code, s.name as station_name FROM houses h LEFT JOIN stations s ON h.station_id = s.id WHERE h.id IN (1474, 1434, 1412)');
while($row = $stmt->fetch()) {
    echo 'House ' . $row['id'] . ' (' . $row['house_code'] . ') - Station: ' . $row['station_name'] . "\n";
}
?>