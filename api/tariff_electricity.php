<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
require_once '../config/db.php';
require_once '../lib/auth.php';
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // JWT auth check (optional, add if needed)
    // $user = authenticateJWT();
    $sql = 'SELECT * FROM tariff_electricity ORDER BY id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $tariffs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($tariffs as &$row) {
        if (isset($row['unit_price'])) $row['unit_price'] = (float)$row['unit_price'];
    }
    echo json_encode(['success' => true, 'data' => $tariffs]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $sql = "INSERT INTO tariff_electricity (name, unit_price, effective_from, effective_to) VALUES (:name, :unit_price, :effective_from, :effective_to)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':name' => $input['name'] ?? null,
        ':unit_price' => $input['unit_price'] ?? 0,
        ':effective_from' => $input['effective_from'] ?? date('Y-m-d'),
        ':effective_to' => $input['effective_to'] ?? null,
    ]);
    $id = $pdo->lastInsertId();
    echo json_encode(['success' => true, 'data' => ['id' => $id]]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (empty($input['id'])) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'Missing id']); exit; }
    $sql = "UPDATE tariff_electricity SET name = :name, unit_price = :unit_price, effective_from = :effective_from, effective_to = :effective_to WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':name' => $input['name'] ?? null,
        ':unit_price' => $input['unit_price'] ?? 0,
        ':effective_from' => $input['effective_from'] ?? date('Y-m-d'),
        ':effective_to' => $input['effective_to'] ?? null,
        ':id' => $input['id'],
    ]);
    echo json_encode(['success' => true]); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (empty($input['id'])) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'Missing id']); exit; }
    $sql = "DELETE FROM tariff_electricity WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $input['id']]);
    echo json_encode(['success' => true]); exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
