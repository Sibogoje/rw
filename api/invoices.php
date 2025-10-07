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
// Optional Composer autoload (Dompdf). If missing, PDF generation will be skipped.
$vendorAutoload = __DIR__ . '/../vendor/autoload.php';
$hasDompdf = false;
if (file_exists($vendorAutoload)) {
    require_once $vendorAutoload; // For Dompdf
    $hasDompdf = class_exists('Dompdf\\Dompdf');
} else {
    error_log('vendor/autoload.php not found; Dompdf disabled. Run `composer require dompdf/dompdf` to enable PDF generation.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $houseId = $input['house_id'];
    $tenantId = $input['tenant_id'];
    $billMonth = $input['bill_month'];
    $readings = $input['readings'];

    $pdo->beginTransaction();
    try {
        // Get next invoice number
        $seq = $pdo->query("SELECT last_invoice_no FROM invoice_sequence FOR UPDATE")->fetch();
        $nextNo = $seq['last_invoice_no'] + 1;

        // Issue date today, due date last day of next month
        $issueDate = date('Y-m-d');
        $dueDate = date('Y-m-t', strtotime('+1 month', strtotime($billMonth)));

        $lines = [];
        $subtotal = 0;

        $prevWater = null;

        // For each service
        foreach (['water', 'sewage', 'electricity'] as $service) {
            if (!isset($readings[$service]['current']) || $readings[$service]['current'] === null) continue;

            $current = floatval($readings[$service]['current']);
            $faulty = $readings[$service]['faulty'] ?? false;

            // Get previous reading
            $prev = null;
            if ($service == 'water') {
                $stmt = $pdo->prepare("SELECT metadata FROM invoice_lines il JOIN invoices i ON il.invoice_id = i.id WHERE i.house_id = ? AND il.service = ? ORDER BY i.issue_date DESC LIMIT 1");
                $stmt->execute([$houseId, $service]);
                $row = $stmt->fetch();
                if ($row) {
                    $meta = json_decode($row['metadata'], true);
                    $prev = $meta['current_reading'] ?? null;
                }
                $prevWater = $prev;
            } elseif ($service == 'sewage') {
                $prev = $prevWater; // Use water's previous for sewage
            } elseif ($service == 'electricity') {
                $stmt = $pdo->prepare("SELECT metadata FROM invoice_lines il JOIN invoices i ON il.invoice_id = i.id WHERE i.house_id = ? AND il.service = ? ORDER BY i.issue_date DESC LIMIT 1");
                $stmt->execute([$houseId, $service]);
                $row = $stmt->fetch();
                if ($row) {
                    $meta = json_decode($row['metadata'], true);
                    $prev = $meta['current_reading'] ?? null;
                }
            }

            if ($prev === null) $prev = 0; // Assume 0 if no previous

            $units = $current - $prev;
            if ($faulty) {
                $units = 1; // Minimum charge units when faulty
            }

            $serviceLines = [];
            $serviceTotal = 0;

            // Apply tariffs
            if ($service == 'water') {
                // Get bands
                $stmt = $pdo->prepare("SELECT name, min_liters, max_liters, unit_price FROM tariff_water_bands WHERE effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?) ORDER BY effective_from DESC");
                $stmt->execute([$issueDate, $issueDate]);
                $bands = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $remaining = $units;
                foreach ($bands as $band) {
                    $min = floatval($band['min_liters']);
                    $max = $band['max_liters'] ? floatval($band['max_liters']) : INF;
                    $qty = min($remaining, $max - $min + 1); // Adjust
                    if ($qty > 0) {
                        $lineTotal = $qty * floatval($band['unit_price']);
                        $serviceLines[] = $lineTotal;
                        $remaining -= $qty;
                    }
                }
                $serviceTotal = array_sum($serviceLines);
            } elseif ($service == 'sewage') {
                // Basic charge
                $stmt = $pdo->prepare("SELECT flat_charge FROM tariff_sewage_bands WHERE min_liters = 0 AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?) ORDER BY effective_from DESC LIMIT 1");
                $stmt->execute([$issueDate, $issueDate]);
                $basic = $stmt->fetch()['flat_charge'] ?? 87.26;
                $serviceTotal += $basic;
                // Above 11.12
                if ($units > 11.12) {
                    $aboveQty = $units - 11.12;
                    $stmt = $pdo->prepare("SELECT unit_price FROM tariff_sewage_bands WHERE min_liters = 11 AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?) ORDER BY effective_from DESC LIMIT 1");
                    $stmt->execute([$issueDate, $issueDate]);
                    $rate = $stmt->fetch()['unit_price'] ?? 14.28;
                    $serviceTotal += $aboveQty * $rate;
                }
            } elseif ($service == 'electricity') {
                // Assume single tariff
                $stmt = $pdo->prepare("SELECT unit_price FROM tariff_electricity WHERE effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?) ORDER BY effective_from DESC LIMIT 1");
                $stmt->execute([$issueDate, $issueDate]);
                $rate = $stmt->fetch()['unit_price'] ?? 1.0;
                $serviceTotal = $units * $rate;
            }

            // Add one line per service
            $lines[] = [
                'service' => $service,
                'description' => ucfirst($service) . ' Consumption',
                'quantity' => $units,
                'unit_price' => $units > 0 ? $serviceTotal / $units : 0,
                'line_total' => $serviceTotal,
                'metadata' => json_encode(['prev_reading' => $prev, 'current_reading' => $current, 'faulty' => $faulty])
            ];
            $subtotal += $serviceTotal;
        }

        // Insert invoice
        $stmt = $pdo->prepare("INSERT INTO invoices (invoice_no, house_id, tenant_id, bill_month, issue_date, due_date, subtotal, total, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'issued')");
        $stmt->execute([$nextNo, $houseId, $tenantId, $billMonth, $issueDate, $dueDate, $subtotal, $subtotal]);

        $invoiceId = $pdo->lastInsertId();

        // Insert lines
        foreach ($lines as $line) {
            $stmt = $pdo->prepare("INSERT INTO invoice_lines (invoice_id, service, description, quantity, unit_price, line_total, metadata) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$invoiceId, $line['service'], $line['description'], $line['quantity'], $line['unit_price'], $line['line_total'], $line['metadata']]);
        }

        // Update sequence
        $pdo->prepare("UPDATE invoice_sequence SET last_invoice_no = ?")->execute([$nextNo]);

        $pdo->commit();

        json_response('success', ['invoice_no' => $nextNo]);
    } catch (Exception $e) {
        $pdo->rollBack();
        json_response('error', null, $e->getMessage());
    }
}

// Get previous readings for a house
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['house_id'])) {
    $houseId = (int)$_GET['house_id'];
    $readings = ['water' => null, 'sewage' => null, 'electricity' => null];
    foreach (['water', 'sewage', 'electricity'] as $service) {
        $stmt = $pdo->prepare("SELECT metadata FROM invoice_lines il JOIN invoices i ON il.invoice_id = i.id WHERE i.house_id = ? AND il.service = ? ORDER BY i.issue_date DESC LIMIT 1");
        $stmt->execute([$houseId, $service]);
        $row = $stmt->fetch();
        if ($row) {
            $meta = json_decode($row['metadata'], true);
            $readings[$service] = $meta['current_reading'] ?? null;
        }
    }
    // Sewage uses water's previous reading
    $readings['sewage'] = $readings['water'];
    json_response('success', $readings);
    exit;
}

// Implement GET to list invoices (paginated, with lines and joins)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $per_page = isset($_GET['per_page']) ? max(1, (int)$_GET['per_page']) : 25;
    $offset = ($page - 1) * $per_page;
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';

    // Count
    $countSql = "SELECT COUNT(*) as cnt FROM invoices i
        LEFT JOIN houses h ON h.id = i.house_id
        LEFT JOIN tenants t ON t.id = i.tenant_id
        WHERE 1";
    $params = [];
    if ($q !== '') {
        $countSql .= " AND (i.invoice_no LIKE :q OR t.name LIKE :q OR h.house_code LIKE :q)";
        $params[':q'] = '%' . $q . '%';
    }
    $countStmt = $pdo->prepare($countSql);
    foreach ($params as $k => $v) $countStmt->bindValue($k, $v);
    $countStmt->execute();
    $total = (int)$countStmt->fetch()['cnt'];

    // Get invoices
    $sql = "SELECT i.id, i.invoice_no, i.house_id, i.tenant_id, i.bill_month, i.issue_date, i.due_date, i.subtotal, i.total, i.status, i.note, i.created_by, i.created_at,
                   h.house_code, h.address AS house_address, t.name AS tenant_name
            FROM invoices i
            LEFT JOIN houses h ON h.id = i.house_id
            LEFT JOIN tenants t ON t.id = i.tenant_id
            WHERE 1";
    if ($q !== '') {
        $sql .= " AND (i.invoice_no LIKE :q OR t.name LIKE :q OR h.house_code LIKE :q)";
    }
    $sql .= " ORDER BY i.id DESC LIMIT :lim OFFSET :off";
    $stmt = $pdo->prepare($sql);
    if ($q !== '') $stmt->bindValue(':q', '%' . $q . '%');
    $stmt->bindValue(':lim', $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch lines for all invoice ids
    $invoiceIds = array_map(function($r){ return (int)$r['id']; }, $rows);
    $lines = [];
    if (!empty($invoiceIds)) {
        $in = implode(',', array_fill(0, count($invoiceIds), '?'));
        $lstmt = $pdo->prepare("SELECT * FROM invoice_lines WHERE invoice_id IN ($in) ORDER BY id ASC");
        $lstmt->execute($invoiceIds);
        $allLines = $lstmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($allLines as $ln) {
            $iid = (int)$ln['invoice_id'];
            if (!isset($lines[$iid])) $lines[$iid] = [];
            $lines[$iid][] = $ln;
        }
    }

    // build result
    $out = [];
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $out[] = [
            'id' => $id,
            'invoice_no' => (int)$r['invoice_no'],
            'house_id' => isset($r['house_id']) ? (int)$r['house_id'] : null,
            'tenant_id' => isset($r['tenant_id']) ? (int)$r['tenant_id'] : null,
            'bill_month' => $r['bill_month'],
            'issue_date' => $r['issue_date'],
            'due_date' => $r['due_date'],
            'subtotal' => (float)$r['subtotal'],
            'total' => (float)$r['total'],
            'status' => $r['status'],
            'note' => $r['note'],
            'created_by' => $r['created_by'],
            'created_at' => $r['created_at'],
            'house_code' => $r['house_code'],
            'house_address' => $r['house_address'],
            'tenant_name' => $r['tenant_name'],
            'lines' => $lines[$id] ?? []
        ];
    }

    json_response('success', $out, null, ['meta' => ['total' => $total, 'page' => $page, 'per_page' => $per_page]]);
}
?>