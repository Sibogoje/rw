<?php
// Usage: php scripts/test_password_verify.php username password
require_once __DIR__ . '/../config/db.php';
if ($argc < 3) { echo "Usage: php test_password_verify.php username password\n"; exit(1); }
$username = $argv[1];
$password = $argv[2];
$stmt = $pdo->prepare('SELECT id, username, password_hash FROM users WHERE username = ?');
$stmt->execute([$username]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) { echo "User not found: $username\n"; exit(2); }
$ok = password_verify($password, $user['password_hash']);
echo "User: {$user['username']} (id={$user['id']})\n";
echo "Provided password correct? " . ($ok ? 'YES' : 'NO') . "\n";
if (!$ok) echo "Stored hash: {$user['password_hash']}\n";
?>
