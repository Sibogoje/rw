<?php
// CORS and JSON headers for all responses
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/functions.php';
// Simple file logger for this API
function houses_log($level, $message, $data = null) {
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0777, true);
    $fp = fopen($logDir . '/houses.log', 'a');
    if (!$fp) return false;
    $time = date('Y-m-d H:i:s');
    $entry = "[{$time}] [{$level}] " . $message;
    if ($data !== null) {
        $entry .= ' | ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    $entry .= PHP_EOL;
    fwrite($fp, $entry);
    fclose($fp);
    return true;
}
// Helper to cast numeric/boolean values for frontend
function cast_house_row(array $row) {
    $row['id'] = (int)$row['id'];
    $row['station_id'] = isset($row['station_id']) ? (int)$row['station_id'] : null;
    $row['has_water'] = (int)$row['has_water'];
    $row['has_sewage'] = (int)$row['has_sewage'];
    $row['has_electricity'] = (int)$row['has_electricity'];
    return $row;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Pagination parameters
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $per_page = isset($_GET['per_page']) ? max(1, (int)$_GET['per_page']) : 25;
    $offset = ($page - 1) * $per_page;

    // Optional search query
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';

    // Build base from/join
    $baseFrom = "FROM houses h
            LEFT JOIN stations s ON s.id = h.station_id
            LEFT JOIN tenants t ON t.house_id = h.id";

    $where = '';
    $params = [];
    if ($q !== '') {
        // search house_code, address, tenant name, station name
        $where = "WHERE (h.house_code LIKE :q OR h.address LIKE :q OR t.name LIKE :q OR s.name LIKE :q)";
        $params[':q'] = '%' . $q . '%';
    }

    // Count total (filtered)
    $countSql = "SELECT COUNT(DISTINCT h.id) as cnt " . $baseFrom . ' ' . $where;
    $countStmt = $pdo->prepare($countSql);
    foreach ($params as $k => $v) $countStmt->bindValue($k, $v);
    $countStmt->execute();
    $total = (int)$countStmt->fetch()['cnt'];

    // Return houses with tenant and station names if available
    $sql = "SELECT h.id, h.house_code, h.address, h.station_id, s.name AS station_name,
                   t.id AS tenant_id, t.name AS tenant_name,
                   h.has_water, h.has_sewage, h.has_electricity, h.created_at
            " . $baseFrom . ' ' . $where . "
            GROUP BY h.id
            ORDER BY h.id DESC
            LIMIT :lim OFFSET :off";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':lim', $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $houses = $stmt->fetchAll();
    // Cast types
    $houses = array_map('cast_house_row', $houses);
    json_response('success', $houses, null, ['meta' => ['total' => $total, 'page' => $page, 'per_page' => $per_page, 'query' => $q]]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    houses_log('INFO', 'POST payload', $input);
    if (!$input || empty($input['house_code'])) {
        json_response('error', null, 'house_code is required');
    }
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO houses (house_code, address, station_id, has_water, has_sewage, has_electricity, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([
            $input['house_code'],
            $input['address'] ?? null,
            $input['station_id'] ?? null,
            isset($input['has_water']) ? (int)$input['has_water'] : 0,
            isset($input['has_sewage']) ? (int)$input['has_sewage'] : 0,
            isset($input['has_electricity']) ? (int)$input['has_electricity'] : 0,
        ]);
        $newId = (int)$pdo->lastInsertId();
        // Optionally assign tenant to this house
        if (!empty($input['tenant_id'])) {
            $tenantId = (int)$input['tenant_id'];
            // Pre-validate tenant exists
            $check = $pdo->prepare("SELECT id FROM tenants WHERE id = ?");
            $check->execute([$tenantId]);
            if (!$check->fetch()) {
                $pdo->rollBack();
                houses_log('ERROR', 'Tenant not found during POST assign', ['tenant_id' => $tenantId, 'payload' => $input]);
                json_response('error', null, 'tenant not found');
            }
            try {
                // Double-check house exists (should be the one we just created)
                $hcheck = $pdo->prepare("SELECT id FROM houses WHERE id = ?");
                $hcheck->execute([$newId]);
                if (!$hcheck->fetch()) {
                    $pdo->rollBack();
                    houses_log('ERROR', 'Created house not found before tenant assignment', ['house_id' => $newId, 'payload' => $input]);
                    json_response('error', null, 'created house not found');
                }
                // Log current DB state for tenant and house to help debug FK issues
                try {
                    $tRowStmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ?");
                    $tRowStmt->execute([$tenantId]);
                    $tRow = $tRowStmt->fetch(PDO::FETCH_ASSOC);
                    $hRowStmt = $pdo->prepare("SELECT * FROM houses WHERE id = ?");
                    $hRowStmt->execute([$newId]);
                    $hRow = $hRowStmt->fetch(PDO::FETCH_ASSOC);
                    houses_log('DEBUG', 'POST pre-assignment rows', ['tenant' => $tRow, 'house' => $hRow]);
                } catch (PDOException $e) {
                    houses_log('ERROR', 'Failed reading rows before POST assignment', ['message' => $e->getMessage(), 'tenant_id' => $tenantId, 'house_id' => $newId]);
                }
                // Ensure no FK violation by setting to NULL first then assigning
                $pdo->prepare("UPDATE tenants SET house_id = NULL WHERE id = ?")->execute([$tenantId]);
                $pdo->prepare("UPDATE tenants SET house_id = ? WHERE id = ?")->execute([$newId, $tenantId]);
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                houses_log('ERROR', 'Tenant assignment failed on POST', ['message' => $e->getMessage(), 'tenant_id' => $tenantId, 'payload' => $input]);
                json_response('error', null, 'Tenant assignment failed: ' . $e->getMessage());
            }
        }
        $pdo->commit();
        json_response('success', ['id' => $newId]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_response('error', null, 'Create house failed');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $input = json_decode(file_get_contents('php://input'), true);
    houses_log('INFO', 'PUT payload', $input);
    if (!$input || empty($input['id'])) json_response('error', null, 'id is required');
    $id = (int)$input['id'];

    // Verify the house exists before doing anything
    try {
        $hExists = $pdo->prepare("SELECT id FROM houses WHERE id = ?");
        $hExists->execute([$id]);
        if (!$hExists->fetch()) {
            houses_log('ERROR', 'PUT for non-existing house', ['house_id' => $id, 'payload' => $input]);
            json_response('error', null, 'house not found');
        }
    } catch (PDOException $e) {
        houses_log('ERROR', 'Error checking house existence before PUT', ['message' => $e->getMessage(), 'payload' => $input]);
        json_response('error', null, 'Internal error');
    }

    // Build update fields
    $fields = [];
    $params = [];
    if (isset($input['house_code'])) { $fields[] = 'house_code = ?'; $params[] = $input['house_code']; }
    if (array_key_exists('address', $input)) { $fields[] = 'address = ?'; $params[] = $input['address']; }
    if (array_key_exists('station_id', $input)) { $fields[] = 'station_id = ?'; $params[] = $input['station_id']; }
    if (array_key_exists('has_water', $input)) { $fields[] = 'has_water = ?'; $params[] = (int)$input['has_water']; }
    if (array_key_exists('has_sewage', $input)) { $fields[] = 'has_sewage = ?'; $params[] = (int)$input['has_sewage']; }
    if (array_key_exists('has_electricity', $input)) { $fields[] = 'has_electricity = ?'; $params[] = (int)$input['has_electricity']; }

    // If tenant_id provided and not null, verify tenant exists BEFORE touching the house to avoid partial updates
    if (array_key_exists('tenant_id', $input) && $input['tenant_id'] !== null) {
        $tenantIdCheck = (int)$input['tenant_id'];
        $check = $pdo->prepare("SELECT id FROM tenants WHERE id = ?");
        $check->execute([$tenantIdCheck]);
        if (!$check->fetch()) {
            houses_log('ERROR', 'PUT tenant not found', ['tenant_id' => $tenantIdCheck, 'payload' => $input]);
            json_response('error', null, 'tenant not found');
        }
    }

    try {
        $pdo->beginTransaction();

        // Lock the house row to prevent deletes/changes from other transactions
        $lockHouse = $pdo->prepare("SELECT id FROM houses WHERE id = ? FOR UPDATE");
        $lockHouse->execute([$id]);
        if (!$lockHouse->fetch()) {
            // house disappeared after pre-check
            if ($pdo->inTransaction()) $pdo->rollBack();
            houses_log('ERROR', 'House disappeared after lock during PUT', ['house_id' => $id, 'payload' => $input]);
            json_response('error', null, 'house not found');
        }

        if (!empty($fields)) {
            $params[] = $id;
            $sql = 'UPDATE houses SET ' . implode(', ', $fields) . ' WHERE id = ?';
            $pdo->prepare($sql)->execute($params);
        }

        // Handle tenant assignment as part of same transaction
        if (array_key_exists('tenant_id', $input)) {
            if ($input['tenant_id'] === null) {
                // Clear any tenant currently assigned to this house
                try {
                    $pdo->prepare("UPDATE tenants SET house_id = NULL WHERE house_id = ?")->execute([$id]);
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    houses_log('ERROR', 'Failed clearing tenants during PUT', ['message' => $e->getMessage(), 'house_id' => $id, 'payload' => $input, 'trace' => $e->getTraceAsString()]);
                    json_response('error', null, 'Update failed: ' . $e->getMessage());
                }
            } else {
                $tenantId = (int)$input['tenant_id'];
                // Ensure tenant still exists (re-check within transaction)
                $tcheck = $pdo->prepare("SELECT id FROM tenants WHERE id = ? FOR UPDATE");
                $tcheck->execute([$tenantId]);
                if (!$tcheck->fetch()) {
                    $pdo->rollBack();
                    houses_log('ERROR', 'PUT tenant disappeared during transaction', ['tenant_id' => $tenantId, 'payload' => $input]);
                    json_response('error', null, 'tenant not found');
                }
                // Log current DB state for tenant and house to help debug FK issues
                try {
                    $tRowStmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ?");
                    $tRowStmt->execute([$tenantId]);
                    $tRow = $tRowStmt->fetch(PDO::FETCH_ASSOC);
                    $hRowStmt = $pdo->prepare("SELECT * FROM houses WHERE id = ?");
                    $hRowStmt->execute([$id]);
                    $hRow = $hRowStmt->fetch(PDO::FETCH_ASSOC);
                    houses_log('DEBUG', 'PUT pre-assignment rows', ['tenant' => $tRow, 'house' => $hRow, 'payload' => $input]);
                } catch (PDOException $e) {
                    houses_log('ERROR', 'Failed reading rows before PUT assignment', ['message' => $e->getMessage(), 'tenant_id' => $tenantId, 'house_id' => $id, 'payload' => $input]);
                }
                // Unassign any tenant currently occupying this house, but don't touch the tenant we're about to assign
                try {
                    $stmtUnassign = $pdo->prepare("UPDATE tenants SET house_id = NULL WHERE house_id = ? AND id <> ?");
                    $stmtUnassign->execute([$id, $tenantId]);
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    houses_log('ERROR', 'Failed clearing existing tenant during PUT', ['message' => $e->getMessage(), 'house_id' => $id, 'payload' => $input, 'trace' => $e->getTraceAsString()]);
                    json_response('error', null, 'Update failed: ' . $e->getMessage());
                }
                // If the tenant is already assigned to this house, no need to reassign
                if (isset($tRow['house_id']) && (int)$tRow['house_id'] === $id) {
                    houses_log('INFO', 'PUT assignment skipped; tenant already assigned to house', ['tenant_id' => $tenantId, 'house_id' => $id]);
                } else {
                    // Assign the named tenant to this house
                    try {
                        $pdo->prepare("UPDATE tenants SET house_id = ? WHERE id = ?")->execute([$id, $tenantId]);
                    } catch (PDOException $e) {
                        // Rollback and log full context
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        houses_log('ERROR', 'Tenant assignment failed on PUT', ['message' => $e->getMessage(), 'tenant_id' => $tenantId, 'payload' => $input, 'trace' => $e->getTraceAsString()]);
                        json_response('error', null, 'Update failed: ' . $e->getMessage());
                    }
                }
            }
        }

        $pdo->commit();
        json_response('success', ['updated' => true]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        houses_log('ERROR', 'Unhandled exception during PUT', ['message' => $e->getMessage(), 'payload' => $input, 'trace' => method_exists($e, 'getTraceAsString') ? $e->getTraceAsString() : null]);
        json_response('error', null, 'Update failed: ' . $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || empty($input['id'])) json_response('error', null, 'id is required');
    $id = (int)$input['id'];
    // Clear tenant assignment
    $pdo->prepare("UPDATE tenants SET house_id = NULL WHERE house_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM houses WHERE id = ?")->execute([$id]);
    json_response('success', ['deleted' => true]);
}

?>