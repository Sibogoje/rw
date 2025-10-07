<?php
// Convenience script to create/update a default admin user.
// WARNING: This creates a user with password '12345'. Run only in local/dev environments and remove afterwards.

require_once __DIR__ . '/../config/db.php';

$username = 'admin';
$password = '12345';
$full_name = 'Administrator';
$email = null;

$pwHash = password_hash($password, PASSWORD_DEFAULT);
try {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    if ($row) {
        $id = (int)$row['id'];
        $u = $pdo->prepare('UPDATE users SET password_hash = ?, full_name = ?, email = ?, role = ?, active = 1, updated_at = NOW() WHERE id = ?');
        $u->execute([$pwHash, $full_name, $email, 'admin', $id]);
        echo "Updated existing user '$username' to admin with password '12345'.\n";
    } else {
        $ins = $pdo->prepare('INSERT INTO users (username, password_hash, full_name, email, role, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())');
        $ins->execute([$username, $pwHash, $full_name, $email, 'admin']);
        echo "Created admin user '$username' with password '12345'.\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(2);
}

?>
