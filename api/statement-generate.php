<?php
error_reporting(E_ERROR | E_PARSE);
require('../fpdf/fpdf.php');
require('../config/db.php');

$stationId = isset($_GET['station_id']) ? intval($_GET['station_id']) : null;
$houseId = isset($_GET['house_id']) ? intval($_GET['house_id']) : null;
$tenantId = isset($_GET['tenant_id']) ? intval($_GET['tenant_id']) : null;
$service = isset($_GET['service']) ? $_GET['service'] : 'all';
$month = isset($_GET['month']) ? $_GET['month'] : null;
$year = isset($_GET['year']) ? intval($_GET['year']) : null;

// Get station name if provided
$stationName = 'All Stations';
if ($stationId) {
    $stmt = $pdo->prepare("SELECT name FROM stations WHERE id = ?");
    $stmt->execute([$stationId]);
    $station = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($station) $stationName = $station['name'];
}

// Get invoices
$sql = "SELECT i.id, i.invoice_no, i.house_id, i.tenant_id, i.bill_month, i.issue_date, i.due_date, i.subtotal, i.total, i.status,
               h.house_code,
               t.name AS tenant_name
        FROM invoices i
        JOIN houses h ON h.id = i.house_id
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
$sql .= " ORDER BY i.id ASC"; // ASC for cumulative
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// For each invoice, get service totals
foreach ($invoices as &$inv) {
    $invoiceId = $inv['id'];
    $stmt2 = $pdo->prepare("SELECT service, SUM(line_total) as total FROM invoice_lines WHERE invoice_id = ? GROUP BY service");
    $stmt2->execute([$invoiceId]);
    $totals = $stmt2->fetchAll(PDO::FETCH_KEY_PAIR);
    $inv['water_total'] = $totals['water'] ?? 0;
    $inv['sewage_total'] = $totals['sewage'] ?? 0;
    $inv['electricity_total'] = $totals['electricity'] ?? 0;
}

// FPDF Class
class PDF extends FPDF {
    function Header() {
        // Company branding
        $imgPath = __DIR__ . '/../assets/header.PNG';
        if (file_exists($imgPath)) {
            $this->Image($imgPath, 10, 6, 140);
            $this->SetXY(155, 12);
            $this->SetFont('Arial','B',12);
            $this->Cell(45,6,'Statement',0,2,'R');
            $this->SetFont('Arial','B',16);
            $this->Cell(45,8,date('d/m/Y'),0,0,'R');
            $this->Ln(40);
        } else {
            $this->SetFont('Arial','B',14);
            $this->Cell(0,6,'ESWATINI RAILWAY',0,1,'L');
            $this->SetFont('Arial','',9);
            $this->Cell(0,4,'Postal Office Box 475',0,1,'L');
            $this->Cell(0,4,'Eswatini Railway, Building, Dzeliwe Street',0,1,'L');
            $this->Cell(0,4,'Mbabane',0,1,'L');
            $this->Cell(0,4,'00268 2411 7400 Fax 00268 2411 7400',0,1,'L');
            $this->Ln(2);
            $this->SetFont('Arial','B',12);
            $this->Cell(0,6,'Statement',0,1,'L');
            $this->SetFont('Arial','B',16);
            $this->Cell(0,8,date('d/m/Y'),0,1,'L');
            $this->Ln(2);
        }

        // Regional Code and Statement Date
        $this->SetFont('Arial','',10);
        $this->Cell(0,6,'Regional Code: ' . $GLOBALS['stationName'] . '    Statement Date: ' . date('d/m/Y'),0,1,'L');
        $this->Ln(5);

        // Table Header (we will draw actual header per page in the body before outputting rows)
        $this->SetFont('Arial','B',8);
        // Save the header position for drawing later if needed
        $this->Ln(0);
    }

    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial','I',8);
        $this->Cell(0,10,'Page '.$this->PageNo().'/{nb}',0,0,'C');
    }

    // Expose protected margins safely
    public function GetLeftMargin() {
        return $this->lMargin;
    }

    public function GetRightMargin() {
        return $this->rMargin;
    }

    public function GetBottomMargin() {
        return $this->bMargin;
    }
}

// Create PDF
$pdf = new PDF();
$pdf->AliasNbPages();
$pdf->AddPage(); // Portrait
$pdf->SetFont('Arial','',8);

$cumulative = 0;
// Helper: compute printable width and column widths
$leftMargin = $pdf->GetLeftMargin(); // usually 10
$rightMargin = $pdf->GetRightMargin(); // usually 10
$pageWidth = $pdf->GetPageWidth();
$printableWidth = $pageWidth - $leftMargin - $rightMargin;

// Define columns with relative widths that sum to 1.0
// Decide which service columns to show based on requested service
$showWater = ($service === 'water' || $service === 'all');
$showSewage = ($service === 'water' || $service === 'all');
$showElectricity = ($service === 'electricity' || $service === 'all');

$services = [];
if ($showWater) $services[] = 'water';
if ($showSewage) $services[] = 'sewage';
if ($showElectricity) $services[] = 'electricity';

// Base columns and service columns dynamically included
$cols = [
    'house' => 0.13,
    'occupant' => 0.23,
    'invoice' => 0.11,
    'month' => 0.12,
];

// Add service columns with a base relative width
foreach ($services as $s) {
    $cols[$s] = 0.09; // each service column
}

// Add totals columns (give them remaining emphasis)
$cols['total'] = 0.13;
$cols['cumulative'] = 0.11;

// Convert to absolute widths in mm
foreach ($cols as $k => $rel) {
    $colWidths[$k] = max(10, floor($printableWidth * $rel));
}

// Row height and helper functions for wrapped rows
function nb_lines($pdf, $w, $txt) {
    // Simulate FPDF MultiCell wrapping by measuring words and counting lines.
    if ($txt === null) return 1;
    if ($w <= 0) return 1;
    $usable = max(1, $w - 2); // leave tiny padding

    // Normalize newlines and split into words
    $txt = str_replace("\r", '', $txt);
    $words = preg_split('/(\s+)/u', $txt, -1, PREG_SPLIT_DELIM_CAPTURE);

    $lines = 1;
    $currentWidth = 0.0;

    foreach ($words as $token) {
        // token may be whitespace or word
        $tokenWidth = $pdf->GetStringWidth($token);

        if (trim($token) === '') {
            // whitespace: add space width
            $spaceW = $pdf->GetStringWidth(' ');
            if ($currentWidth + $spaceW <= $usable) {
                $currentWidth += $spaceW;
            } else {
                $lines++;
                $currentWidth = 0;
            }
            continue;
        }

        if ($currentWidth + $tokenWidth <= $usable) {
            $currentWidth += $tokenWidth;
            continue;
        }

        // token doesn't fit on current line
        if ($tokenWidth > $usable) {
            // long word: split by characters
            $chars = preg_split('//u', $token, -1, PREG_SPLIT_NO_EMPTY);
            $part = '';
            $partWidth = 0.0;
            foreach ($chars as $ch) {
                $chW = $pdf->GetStringWidth($ch);
                if ($partWidth + $chW > $usable) {
                    $lines++;
                    $part = $ch;
                    $partWidth = $chW;
                } else {
                    $part .= $ch;
                    $partWidth += $chW;
                }
            }
            $currentWidth = $partWidth;
        } else {
            // move token to next line
            $lines++;
            $currentWidth = $tokenWidth;
        }
    }

    return max(1, (int)$lines);
}

// Draw table header function
function draw_table_header($pdf, $colWidths, $services) {
    $pdf->SetFont('Arial','B',8);
    $pdf->SetFillColor(230,230,230);
    $h = 8; // header box height
    $x = $pdf->GetX();
    $y = $pdf->GetY();

    // Draw the fixed columns first
    $fixedHeaders = [
        'house' => 'House No',
        'occupant' => 'Occupant',
        'invoice' => 'Invoice No',
        'month' => 'Bill/month'
    ];
    foreach ($fixedHeaders as $key => $label) {
        $pdf->Rect($x, $y, $colWidths[$key], $h, 'DF');
        $pdf->SetXY($x + 1, $y + 1);
        $pdf->MultiCell($colWidths[$key] - 2, 4, $label, 0, 'L');
        $x += $colWidths[$key];
    }

    // Dynamic service columns
    foreach ($services as $s) {
        $label = ucfirst($s);
        $pdf->Rect($x, $y, $colWidths[$s], $h, 'DF');
        $pdf->SetXY($x + 1, $y + 1);
        $pdf->MultiCell($colWidths[$s] - 2, 4, $label, 0, 'R');
        $x += $colWidths[$s];
    }

    // Totals
    $pdf->Rect($x, $y, $colWidths['total'], $h, 'DF');
    $pdf->SetXY($x + 1, $y + 1);
    $pdf->MultiCell($colWidths['total'] - 2, 4, 'Total', 0, 'R');
    $x += $colWidths['total'];

    // Cumulative Total (wrap)
    $pdf->Rect($x, $y, $colWidths['cumulative'], $h, 'DF');
    $pdf->SetXY($x + 1, $y + 1);
    $pdf->MultiCell($colWidths['cumulative'] - 2, 4, "Cumulative\nTotal", 0, 'R');
    $pdf->Ln($h);
    $pdf->SetFont('Arial','',8);
}

    // Ensure absolute column widths sum to printable width (last column absorbs rounding)
    $colWidthsFinal = [];
    $totalCols = count($colWidths);
    $used = 0;
    $i = 0;
    foreach ($colWidths as $k => $rel) {
        $i++;
        if ($i < $totalCols) {
            $w = max(10, floor($printableWidth * ($rel / array_sum($colWidths))));
            $colWidthsFinal[$k] = $w;
            $used += $w;
        } else {
            // Last column gets the remaining width
            $colWidthsFinal[$k] = max(10, $printableWidth - $used);
        }
    }

    // Draw header for first page
    draw_table_header($pdf, $colWidthsFinal, $services);
foreach ($invoices as $inv) {
    $total = $inv['total'];
    $cumulative += $total;
    $billMonth = date('F', strtotime($inv['bill_month'])); // Month name
    // Prepare cell texts
    $cellHouse = $inv['house_code'];
    $cellOccupant = $inv['tenant_name'] ?? '';
    $cellInvoice = $inv['invoice_no'];
    $cellMonth = $billMonth;
    $cellWater = number_format($inv['water_total'],2);
    $cellSewage = number_format($inv['sewage_total'],2);
    $cellElectricity = number_format($inv['electricity_total'],2);
    $cellTotal = number_format($total,2);
    $cellCumulative = number_format($cumulative,2);

    // Determine number of lines needed for wrapped cells (primarily occupant)
    $lines = max(
        nb_lines($pdf, $colWidthsFinal['house'], $cellHouse),
        nb_lines($pdf, $colWidthsFinal['occupant'], $cellOccupant),
        nb_lines($pdf, $colWidthsFinal['invoice'], $cellInvoice),
        nb_lines($pdf, $colWidthsFinal['month'], $cellMonth)
    );
    $lineHeight = 5; // mm per line
    $rowHeight = $lines * $lineHeight;

    // Check for page break
    if ($pdf->GetY() + $rowHeight + 20 > $pdf->GetPageHeight() - $pdf->GetBottomMargin()) {
        $pdf->AddPage();
        draw_table_header($pdf, $colWidthsFinal, $services);
    }

    // Draw cells using MultiCell to wrap occupant
    $x = $pdf->GetX();
    $y = $pdf->GetY();

    // Draw full-height bordered boxes for text columns and then write wrapped text inside
    // House
    $pdf->Rect($x, $y, $colWidthsFinal['house'], $rowHeight);
    $pdf->SetXY($x + 1, $y + 1);
    $pdf->MultiCell($colWidthsFinal['house'] - 2, $lineHeight, $cellHouse, 0, 'L');
    $pdf->SetXY($x + $colWidthsFinal['house'], $y);

    // Occupant (wrap)
    $pdf->Rect($x + $colWidthsFinal['house'], $y, $colWidthsFinal['occupant'], $rowHeight);
    $pdf->SetXY($x + $colWidthsFinal['house'] + 1, $y + 1);
    $pdf->MultiCell($colWidthsFinal['occupant'] - 2, $lineHeight, $cellOccupant, 0, 'L');
    $pdf->SetXY($x + $colWidthsFinal['house'] + $colWidthsFinal['occupant'], $y);

    // Invoice
    $pdf->Rect($x + $colWidthsFinal['house'] + $colWidthsFinal['occupant'], $y, $colWidthsFinal['invoice'], $rowHeight);
    $pdf->SetXY($x + $colWidthsFinal['house'] + $colWidthsFinal['occupant'] + 1, $y + 1);
    $pdf->MultiCell($colWidthsFinal['invoice'] - 2, $lineHeight, $cellInvoice, 0, 'L');
    $pdf->SetXY($x + $colWidthsFinal['house'] + $colWidthsFinal['occupant'] + $colWidthsFinal['invoice'], $y);

    // Month
    $pdf->Rect($x + $colWidthsFinal['house'] + $colWidthsFinal['occupant'] + $colWidthsFinal['invoice'], $y, $colWidthsFinal['month'], $rowHeight);
    $pdf->SetXY($x + $colWidthsFinal['house'] + $colWidthsFinal['occupant'] + $colWidthsFinal['invoice'] + 1, $y + 1);
    $pdf->MultiCell($colWidthsFinal['month'] - 2, $lineHeight, $cellMonth, 0, 'L');
    $pdf->SetXY($x + $colWidthsFinal['house'] + $colWidthsFinal['occupant'] + $colWidthsFinal['invoice'] + $colWidthsFinal['month'], $y);

    // Right-aligned dynamic service columns
    foreach ($services as $s) {
        $val = '';
        if ($s === 'water') $val = $cellWater;
        elseif ($s === 'sewage') $val = $cellSewage;
        elseif ($s === 'electricity') $val = $cellElectricity;
        $pdf->Cell($colWidthsFinal[$s], $rowHeight, $val, 1, 0, 'R');
    }

    // Totals
    $pdf->Cell($colWidthsFinal['total'], $rowHeight, $cellTotal, 1, 0, 'R');
    $pdf->Cell($colWidthsFinal['cumulative'], $rowHeight, $cellCumulative, 1, 1, 'R');
}

// Generate filename using station name, service, and date for better organization
$filename_station = preg_replace('/[^A-Za-z0-9_-]/', '_', $stationName);
$filename_service = $service !== 'all' ? ucfirst($service) : 'All_Services';
$filename_date = $month && $year ? $year . '_' . str_pad($month, 2, '0', STR_PAD_LEFT) : date('Y_m');
$filename = 'Statement_' . $filename_station . '_' . $filename_service . '_' . $filename_date . '.pdf';
$pdf->Output('I', $filename);
?>