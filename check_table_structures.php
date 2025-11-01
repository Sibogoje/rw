<?php
require_once __DIR__ . '/config/db.php';

echo "=== Checking table structures ===\n";

echo "Houses table columns:\n";
$stmt = $pdo->query('DESCRIBE houses');
while($row = $stmt->fetch()) {
    echo "  {$row['Field']} ({$row['Type']})\n";
}

echo "\nStations table columns:\n";
$stmt = $pdo->query('DESCRIBE stations');
while($row = $stmt->fetch()) {
    echo "  {$row['Field']} ({$row['Type']})\n";
}

echo "\nSample house data:\n";
$stmt = $pdo->query('SELECT * FROM houses LIMIT 3');
while($row = $stmt->fetch()) {
    print_r($row);
}
?>