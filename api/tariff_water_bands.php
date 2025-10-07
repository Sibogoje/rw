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
    $sql = 'SELECT * FROM tariff_water_bands ORDER BY id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $tariffs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($tariffs as &$row) {
        if (isset($row['unit_price'])) $row['unit_price'] = (float)$row['unit_price'];
        if (isset($row['min_liters'])) $row['min_liters'] = (int)$row['min_liters'];
        if (isset($row['max_liters'])) $row['max_liters'] = $row['max_liters'] !== null ? (int)$row['max_liters'] : null;
        if (isset($row['priority'])) $row['priority'] = (int)$row['priority'];
    }
    echo json_encode(['success' => true, 'data' => $tariffs]);
    exit;
}

// Create new tariff
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $sql = "INSERT INTO tariff_water_bands (name, min_liters, max_liters, unit_price, priority, effective_from, effective_to) VALUES (:name, :min_liters, :max_liters, :unit_price, :priority, :effective_from, :effective_to)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':name' => $input['name'] ?? null,
        ':min_liters' => $input['min_liters'] ?? 0,
        ':max_liters' => $input['max_liters'] ?? null,
        ':unit_price' => $input['unit_price'] ?? 0,
        ':priority' => $input['priority'] ?? 0,
        ':effective_from' => $input['effective_from'] ?? date('Y-m-d'),
        ':effective_to' => $input['effective_to'] ?? null,
    ]);
    $id = $pdo->lastInsertId();
    echo json_encode(['success' => true, 'data' => ['id' => $id]]);
    exit;
}

// Update existing tariff
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (empty($input['id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing id']);
        exit;
    }
    $sql = "UPDATE tariff_water_bands SET name = :name, min_liters = :min_liters, max_liters = :max_liters, unit_price = :unit_price, priority = :priority, effective_from = :effective_from, effective_to = :effective_to WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':name' => $input['name'] ?? null,
        ':min_liters' => $input['min_liters'] ?? 0,
        ':max_liters' => $input['max_liters'] ?? null,
        ':unit_price' => $input['unit_price'] ?? 0,
        ':priority' => $input['priority'] ?? 0,
        ':effective_from' => $input['effective_from'] ?? date('Y-m-d'),
        ':effective_to' => $input['effective_to'] ?? null,
        ':id' => $input['id'],
    ]);
    echo json_encode(['success' => true]);
    exit;
}

// Delete tariff
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (empty($input['id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing id']);
        exit;
    }
    $sql = "DELETE FROM tariff_water_bands WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $input['id']]);
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
