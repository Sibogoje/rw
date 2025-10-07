<?php
// CORS headers for development (allow all origins)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stationId = isset($_GET['station_id']) ? (int)$_GET['station_id'] : null;
    $houseId = isset($_GET['house_id']) ? (int)$_GET['house_id'] : null;
    $tenantId = isset($_GET['tenant_id']) ? (int)$_GET['tenant_id'] : null;
    $service = isset($_GET['service']) ? $_GET['service'] : 'all';
    $month = isset($_GET['month']) ? $_GET['month'] : null;
    $year = isset($_GET['year']) ? (int)$_GET['year'] : null;

    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $per_page = isset($_GET['per_page']) ? max(1, (int)$_GET['per_page']) : 25;
    $offset = ($page - 1) * $per_page;

    // Get invoices
    $sql = "SELECT i.id, i.invoice_no, i.house_id, i.tenant_id, i.bill_month, i.issue_date, i.due_date, i.subtotal, i.total, i.status,
                   h.house_code, s.name AS station_name,
                   t.name AS tenant_name
            FROM invoices i
            JOIN houses h ON h.id = i.house_id
            JOIN stations s ON h.station_id = s.id
            LEFT JOIN tenants t ON i.tenant_id = t.id
            WHERE 1=1";
    $params = [];
    if ($stationId) {
        $sql .= " AND h.station_id = ?";
        $params[] = $stationId;
    }
    if ($houseId) {
        $sql .= " AND i.house_id = ?";
        $params[] = $houseId;
    }
    if ($tenantId) {
        $sql .= " AND i.tenant_id = ?";
        $params[] = $tenantId;
    }
    if ($service !== 'all') {
        if ($service === 'water') {
            $sql .= " AND EXISTS (SELECT 1 FROM invoice_lines il WHERE il.invoice_id = i.id AND il.service IN ('water', 'sewage'))";
        } elseif ($service === 'electricity') {
            $sql .= " AND EXISTS (SELECT 1 FROM invoice_lines il WHERE il.invoice_id = i.id AND il.service = 'electricity')";
        }
    }
    if ($month) {
        $sql .= " AND DATE_FORMAT(i.bill_month, '%Y-%m') = ?";
        $params[] = $month;
    }
    if ($year) {
        $sql .= " AND YEAR(i.bill_month) = ?";
        $params[] = $year;
    }
    $sql .= " ORDER BY i.id DESC LIMIT ? OFFSET ?";
    // Prepare and bind positional parameters (filters) then bind LIMIT/OFFSET as integers
    $stmt = $pdo->prepare($sql);
    // bind filter params (positional)
    $pos = 1;
    foreach ($params as $p) {
        $stmt->bindValue($pos, $p);
        $pos++;
    }
    // bind limit and offset as integers to avoid them being quoted
    $stmt->bindValue($pos, (int)$per_page, PDO::PARAM_INT);
    $pos++;
    $stmt->bindValue($pos, (int)$offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // For each invoice, get service totals
    foreach ($rows as &$row) {
        $invoiceId = $row['id'];
        $stmt2 = $pdo->prepare("SELECT service, SUM(line_total) as total FROM invoice_lines WHERE invoice_id = ? GROUP BY service");
        $stmt2->execute([$invoiceId]);
        $totals = $stmt2->fetchAll(PDO::FETCH_KEY_PAIR);
        $row['water_total'] = $totals['water'] ?? 0;
        $row['sewage_total'] = $totals['sewage'] ?? 0;
        $row['electricity_total'] = $totals['electricity'] ?? 0;
    }

    // Count total
    $countSql = "SELECT COUNT(DISTINCT i.id) as cnt FROM invoices i JOIN houses h ON h.id = i.house_id JOIN stations s ON h.station_id = s.id LEFT JOIN tenants t ON i.tenant_id = t.id WHERE 1=1";
    $countParams = [];
    if ($stationId) {
        $countSql .= " AND h.station_id = ?";
        $countParams[] = $stationId;
    }
    if ($houseId) {
        $countSql .= " AND i.house_id = ?";
        $countParams[] = $houseId;
    }
    if ($tenantId) {
        $countSql .= " AND i.tenant_id = ?";
        $countParams[] = $tenantId;
    }
    if ($service !== 'all') {
        if ($service === 'water') {
            $countSql .= " AND EXISTS (SELECT 1 FROM invoice_lines il WHERE il.invoice_id = i.id AND il.service IN ('water', 'sewage'))";
        } elseif ($service === 'electricity') {
            $countSql .= " AND EXISTS (SELECT 1 FROM invoice_lines il WHERE il.invoice_id = i.id AND il.service = 'electricity')";
        }
    }
    if ($month) {
        $countSql .= " AND DATE_FORMAT(i.bill_month, '%Y-%m') = ?";
        $countParams[] = $month;
    }
    if ($year) {
        $countSql .= " AND YEAR(i.bill_month) = ?";
        $countParams[] = $year;
    }
    $countStmt = $pdo->prepare($countSql);
    // bind count params
    $pos = 1;
    foreach ($countParams as $p) {
        $countStmt->bindValue($pos, $p);
        $pos++;
    }
    $countStmt->execute();
    $total = (int)$countStmt->fetch()['cnt'];

    json_response('success', $rows, null, ['meta' => ['total' => $total, 'page' => $page, 'per_page' => $per_page]]);
}
?>