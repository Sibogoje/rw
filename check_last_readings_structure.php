<?php
require_once __DIR__ . '/config/db.php';

echo "=== Checking last_readings table structure ===\n";

try {
    $stmt = $pdo->query('DESCRIBE last_readings');
    while($row = $stmt->fetch()) {
        echo "Column: {$row['Field']}, Type: {$row['Type']}\n";
    }
    
    echo "\n=== Sample data ===\n";
    $stmt = $pdo->query('SELECT * FROM last_readings LIMIT 5');
    while($row = $stmt->fetch()) {
        print_r($row);
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>