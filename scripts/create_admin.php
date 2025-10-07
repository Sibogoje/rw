<?php
// Usage: php scripts/create_admin.php username password "Full Name" [email]
require_once __DIR__ . '/../config/db.php';
if ($argc < 4) {
    echo "Usage: php create_admin.php username password \"Full Name\" [email]\n";
    exit(1);
}
$username = $argv[1];
$password = $argv[2];
$full_name = $argv[3];
$email = isset($argv[4]) ? $argv[4] : null;

$pwHash = password_hash($password, PASSWORD_DEFAULT);
try {
    // Insert or update existing user to admin
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    if ($row) {
        $id = (int)$row['id'];
        $u = $pdo->prepare('UPDATE users SET password_hash = ?, full_name = ?, email = ?, role = ?, active = 1, updated_at = NOW() WHERE id = ?');
        $u->execute([$pwHash, $full_name, $email, 'admin', $id]);
        echo "Updated existing user '$username' to admin.\n";
    } else {
        $ins = $pdo->prepare('INSERT INTO users (username, password_hash, full_name, email, role, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())');
        $ins->execute([$username, $pwHash, $full_name, $email, 'admin']);
        echo "Created admin user '$username'.\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(2);
}

?>
