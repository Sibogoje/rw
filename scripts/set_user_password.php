<?php
// Usage: php scripts/set_user_password.php username newpassword
// Dev convenience: updates password_hash for a user without changing role
require_once __DIR__ . '/../config/db.php';
if ($argc < 3) { echo "Usage: php set_user_password.php username newpassword\n"; exit(1); }
$username = $argv[1];
$password = $argv[2];
$pwHash = password_hash($password, PASSWORD_DEFAULT);
try {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    if (!$row) { echo "User not found: $username\n"; exit(2); }
    $id = (int)$row['id'];
    $u = $pdo->prepare('UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?');
    $u->execute([$pwHash, $id]);
    echo "Password updated for $username (id=$id)\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n"; exit(3);
}

?>
