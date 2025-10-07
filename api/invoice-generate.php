<?php
error_reporting(E_ERROR | E_PARSE);
require('../fpdf/fpdf.php');
require('../config/db.php');

//$invoiceNo = isset($_GET['invoice_no']) ? intval($_GET['invoice_no']) : 0;
$invoiceNo = isset($_GET['invoice_no']) ? intval($_GET['invoice_no']) : 0;
if ($invoiceNo <= 0) die("Invalid invoice number");

// --- Fetch Invoice Header ---
$stmt = $pdo->prepare("
    SELECT i.id AS invoice_id, i.invoice_no, i.bill_month, i.issue_date, i.due_date,
           h.house_code, s.name AS station,
           t.name AS tenant_name, t.phone, t.email
    FROM invoices i
    JOIN houses h ON i.house_id = h.id
    JOIN stations s ON h.station_id = s.id
    LEFT JOIN tenants t ON i.tenant_id = t.id
    WHERE i.invoice_no = :invoice_no
");
$stmt->execute([':invoice_no' => $invoiceNo]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$invoice) die("Invoice not found");

// --- Fetch Meter Information ---
$stmt = $pdo->prepare("
    SELECT m.type, m.meter_number 
    FROM meters m 
    JOIN houses h ON m.house_id = h.id 
    JOIN invoices i ON i.house_id = h.id 
    WHERE i.invoice_no = :invoice_no
");
$stmt->execute([':invoice_no' => $invoiceNo]);
$meters = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Extract meter numbers
$water_meter = '';
$electricity_meter = '';
foreach ($meters as $meter) {
    if ($meter['type'] == 'water') {
        $water_meter = $meter['meter_number'];
    } elseif ($meter['type'] == 'electricity') {
        $electricity_meter = $meter['meter_number'];
    }
}

// --- Fetch Invoice Lines ---
$stmt = $pdo->prepare("
    SELECT service, description, quantity, unit_price, line_total, metadata
    FROM invoice_lines
    WHERE invoice_id = :invoice_id
    ORDER BY service, id
");
$stmt->execute([':invoice_id' => $invoice['invoice_id']]);
$lines = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- Group Lines ---
$grouped = [];
foreach ($lines as $line) {
    $grouped[$line['service']][] = $line;
}

// --- FPDF Class ---
class PDF extends FPDF {
    function Header() {
        // Company branding - prefer header image, fallback to text
        $imgPath = __DIR__ . '/../assets/header.PNG';
        if (file_exists($imgPath)) {
            // place image across the top with small margins on the left
            // leave room on the right for invoice number
            $this->Image($imgPath, 10, 6, 140);
            // Invoice number on the right side, same row as the header image
            // nudge Y slightly so the text vertically centers next to the image
            $this->SetXY(155, 12);
            $this->SetFont('Arial','B',12);
            $this->Cell(45,6,'Invoice Number',0,2,'R');
            $this->SetFont('Arial','B',16);
            $this->Cell(45,8,$GLOBALS['invoiceNo'],0,0,'R');
            // leave more space below the image/text row to prevent overlap with client info
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
            // Invoice Number (text fallback - placed below header)
            $this->SetFont('Arial','B',12);
            $this->Cell(0,6,'Invoice Number',0,1,'L');
            $this->SetFont('Arial','B',16);
            $this->Cell(0,8,$GLOBALS['invoiceNo'],0,1,'L');
            $this->Ln(2);
        }
    }

    function Footer() {
        // Position 15 mm from bottom
        $this->SetY(-15);
        $this->SetFont('Arial','I',8);
        $this->Cell(0,5,'ISO Standard Compliant Page '.$this->PageNo().'/1',0,0,'C');
    }
    
    // Helper to fetch tariff unit_price by name pattern and effective date
    function getTariffPrice($namePattern, $effectiveDate = null) {
        $pdo = $GLOBALS['pdo'] ?? null;
        if (!$pdo) return 0;
        if (!$effectiveDate) $effectiveDate = date('Y-m-d');
        try {
            $stmt = $pdo->prepare("SELECT unit_price FROM tariff_water_bands WHERE name LIKE :name AND effective_from <= :date AND (effective_to IS NULL OR effective_to >= :date) ORDER BY effective_from DESC LIMIT 1");
            $stmt->execute([':name' => "%" . $namePattern . "%", ':date' => $effectiveDate]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? floatval($row['unit_price']) : 0;
        } catch (Exception $e) {
            return 0;
        }
    }
    
    // Helper to fetch sewage tariff by min_liters and effective date
    function getSewageTariffPrice($minLiters, $effectiveDate = null) {
        $pdo = $GLOBALS['pdo'] ?? null;
        if (!$pdo) return ['unit_price' => 0, 'flat_charge' => 0, 'is_flat' => 0];
        if (!$effectiveDate) $effectiveDate = date('Y-m-d');
        try {
            $stmt = $pdo->prepare("SELECT unit_price, flat_charge, is_flat FROM tariff_sewage_bands WHERE min_liters = :min AND effective_from <= :date AND (effective_to IS NULL OR effective_to >= :date) ORDER BY effective_from DESC LIMIT 1");
            $stmt->execute([':min' => $minLiters, ':date' => $effectiveDate]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? ['unit_price' => floatval($row['unit_price']), 'flat_charge' => floatval($row['flat_charge']), 'is_flat' => intval($row['is_flat'])] : ['unit_price' => 0, 'flat_charge' => 0, 'is_flat' => 0];
        } catch (Exception $e) {
            return ['unit_price' => 0, 'flat_charge' => 0, 'is_flat' => 0];
        }
    }
    
    // Custom function for water service table
    function WaterServiceTable($lines) {
        $this->SetFont('Arial','',9);
        $subtotal = 0;
        
        // Find readings and basic charge from database lines
        $basicChargeLine = null;
        $consumptionLines = [];
        $readings = ['prev_reading' => '', 'current_reading' => '', 'units_billed' => 0];
        
        foreach ($lines ?? [] as $l) {
            $meta = json_decode($l['metadata'], true) ?? [];
            
            // Get basic charge
            if (stripos($l['description'], 'Basic Charge') !== false) {
                $basicChargeLine = $l;
            }
            // Get consumption bands
            elseif (stripos($l['description'], 'B1') !== false || 
                    stripos($l['description'], 'B2') !== false ||
                    stripos($l['description'], 'B3') !== false ||
                    stripos($l['description'], 'B4') !== false) {
                $consumptionLines[] = $l;
            }
            // Get readings from metadata (look for the main consumption line)
            if (isset($meta['prev_reading']) && isset($meta['current_reading']) && !$readings['prev_reading']) {
                $readings['prev_reading'] = $meta['prev_reading'];
                $readings['current_reading'] = $meta['current_reading'];
                $readings['units_billed'] = $l['quantity'] ?? 0;
            }
        }
        
        // Readings Row (showing previous, current, and units billed)



        $this->Cell(20,6,'',1,0,'L');
        $this->Cell(60,6,"",1,0,'L');
        $this->Cell(20,6,$readings['prev_reading'],1,0,'L');
        $this->Cell(20,6,$readings['current_reading'],1,0,'R');
        $this->Cell(20,6,number_format($readings['units_billed'],2),1,0,'R');
        $this->Cell(25,6,'',1,0,'R');
        $this->Cell(25,6,'',1,1,'R');
        
        
    // Charges/Metre Cubic Header
    $this->Cell(20,6,'',1,0,'L');
    $this->SetFont('Arial','B',9);
    $this->Cell(60,6,'Charges/Metre Cubic (Kilolitre)',1,0,'L');
    $this->SetFont('Arial','',9);
    $this->Cell(20,6,'',1,0,'R');
    $this->Cell(20,6,'',1,0,'R');
    $this->Cell(20,6,'',1,0,'R');
    $this->Cell(25,6,'',1,0,'R');
    $this->Cell(25,6,'',1,1,'R');
        
        // Water bands in exact order from screenshot
        $waterBands = [
            "B1(1-10)" => ['description' => 'B1(1-10)', 'quantity' => 0, 'rate' => 0, 'charge' => 0],
            "B2(11-15)" => ['description' => 'B2(11-15)', 'quantity' => 0, 'rate' => 0, 'charge' => 0],
            "B3(16-50)" => ['description' => 'B3(16-50)', 'quantity' => 0, 'rate' => 0, 'charge' => 0],
            "B4(>50)" => ['description' => 'B4(>50)', 'quantity' => 0, 'rate' => 0, 'charge' => 0]
        ];
        
        // Populate band data from database lines (quantities may be present or zero)
        $reportedUnits = 0;
        foreach ($consumptionLines as $line) {
            foreach ($waterBands as $bandKey => $bandData) {
                if (stripos($line['description'], $bandKey) !== false) {
                    $waterBands[$bandKey]['quantity'] = $line['quantity'];
                    $waterBands[$bandKey]['rate'] = $line['unit_price'];
                    $waterBands[$bandKey]['charge'] = $line['line_total'];
                    $reportedUnits += floatval($line['quantity'] ?? 0);
                    break;
                }
            }
        }

        // If readings provided, use units_billed; otherwise use reportedUnits
        $totalUnits = $readings['units_billed'] ?: $reportedUnits;

        // Apply band allocation rules provided by user:
        // Band1 = min(10, units)
        // Band2 = min(5, max(0, units - 10))
        // Band3 = min(35, max(0, units - 15))
        // Band4 = max(0, units - 50)
        $units = max(0, floatval($totalUnits));
        $alloc = [];
        $alloc['B1'] = min(10, $units);
        $alloc['B2'] = min(5, max(0, $units - 10));
        $alloc['B3'] = min(35, max(0, $units - 15));
        $alloc['B4'] = max(0, $units - 50);

        // Force band quantities to use the allocation so we explicitly show the breakdown
        foreach ($waterBands as $bandKey => &$bandData) {
            if (preg_match('/B(\d)/', $bandKey, $m)) {
                $idx = 'B' . $m[1];
                // set quantity to allocated value (or keep existing if allocation missing)
                $bandData['quantity'] = isset($alloc[$idx]) ? $alloc[$idx] : (floatval($bandData['quantity'] ?? 0));
                // Band1 is flat basic charge: rate and charge from tariff
                if ($idx === 'B1') {
                    $bandData['rate'] = $this->getTariffPrice('Band 1', $GLOBALS['invoice']['issue_date'] ?? null);
                    $bandData['charge'] = ($units > 0) ? $bandData['rate'] : 0;
                } else {
                    // ensure rate available (from invoice line or tariff)
                    if (empty($bandData['rate'])) {
                        $bandName = 'Band ' . $m[1];
                        $bandData['rate'] = $this->getTariffPrice($bandName, $GLOBALS['invoice']['issue_date'] ?? null);
                    }
                    // compute charge from quantity*rate
                    $bandData['charge'] = (floatval($bandData['quantity'] ?? 0)) * (floatval($bandData['rate'] ?? 0));
                }
            }
        }
        unset($bandData);
        
        // Display water bands
        foreach ($waterBands as $bandData) {
            $subtotal += $bandData['charge'];

            // leave first Quantity column blank for water bands (we'll show band allocation under 'Units')
            $this->Cell(20,6,'',1,0,'R');
            $this->SetFont('Arial','B',9);
            $this->Cell(60,6,$bandData['description'],1,0,'L');
            $this->SetFont('Arial','',9);
            // previous, current
            $this->Cell(20,6,'',1,0,'R');
            $this->Cell(20,6,'',1,0,'R');
            // Units column - show band quantity allocation here
            $this->Cell(20,6,number_format($bandData['quantity'],2),1,0,'R');
            // rate and charge: hide zeros (Band1 uses flat basicCharge instead)
            $rateText = floatval($bandData['rate']) ? number_format($bandData['rate'],2) : '';
            $chargeText = floatval($bandData['charge']) ? number_format($bandData['charge'],2) : '';
            $this->Cell(25,6,$rateText,1,0,'R');
            $this->Cell(25,6,$chargeText,1,1,'R');
        }
        
        // Subtotal row
        $this->SetFont('Arial','B',9);
        $this->Cell(165,7,'Subtotal',1,0,'R');
        $this->Cell(25,7,number_format($subtotal,2),1,1,'R');
        $this->Ln(3);
        
        return $subtotal;
    }
    
    // Custom function for sewage service table
    function SewageServiceTable($lines) {
        $this->SetFont('Arial','',9);
        $subtotal = 0;
        
        // Find readings from database lines
        $readings = ['prev_reading' => '', 'current_reading' => '', 'units_billed' => 0];
        
        foreach ($lines ?? [] as $l) {
            $meta = json_decode($l['metadata'], true) ?? [];
            // Get readings from metadata
            if (isset($meta['prev_reading']) && isset($meta['current_reading']) && !$readings['prev_reading']) {
                $readings['prev_reading'] = $meta['prev_reading'];
                $readings['current_reading'] = $meta['current_reading'];
                $readings['units_billed'] = $l['quantity'] ?? 0;
            }
        }
        
        // Readings Row
        $calculated_prev = floatval($readings['prev_reading']) - floatval($readings['units_billed']);
        $calculated_curr = floatval($readings['prev_reading']);
        $this->Cell(20,6,'',1,0,'L');
        $this->Cell(60,6,"",1,0,'L');
        $this->Cell(20,6,number_format($calculated_prev, 2),1,0,'R');
        $this->Cell(20,6,number_format($calculated_curr, 2),1,0,'R');
        $this->Cell(20,6,number_format($readings['units_billed'],2),1,0,'R');
        $this->Cell(25,6,'',1,0,'R');
        $this->Cell(25,6,'',1,1,'R');
        
        // Charges/Metre Cubic Header
        $this->Cell(20,6,'',1,0,'L');
        $this->SetFont('Arial','B',9);
        $this->Cell(60,6,'Charges/Metre Cubic (Kilolitre)',1,0,'L');
        $this->SetFont('Arial','',9);
        $this->Cell(20,6,'',1,0,'R');
        $this->Cell(20,6,'',1,0,'R');
        $this->Cell(20,6,'',1,0,'R');
        $this->Cell(25,6,'',1,0,'R');
        $this->Cell(25,6,'',1,1,'R');
        
        // Sewage bands
        $sewageBands = [
            "B1 (0 - 11.12)" => ['description' => 'B1 (0 - 11.12)', 'quantity' => 0, 'rate' => 0, 'charge' => 0],
            "Above 11.12" => ['description' => 'Above 11.12', 'quantity' => 0, 'rate' => 0, 'charge' => 0]
        ];
        
        // Get total units
        $totalUnits = floatval($readings['units_billed']);
        $units = max(0, $totalUnits);
        
        // Allocate units
        $alloc = [];
        $alloc['B1'] = min(11.12, $units);
        $alloc['B2'] = max(0, $units - 11.12);
        
        // Set quantities and compute charges
        foreach ($sewageBands as $bandKey => &$bandData) {
            if ($bandKey === "B1 (0 - 11.12)") {
                $bandData['quantity'] = $alloc['B1'];
                $tariff = $this->getSewageTariffPrice(0, $GLOBALS['invoice']['issue_date'] ?? null);
                if ($tariff['is_flat']) {
                    $bandData['rate'] = $tariff['flat_charge'];
                    $bandData['charge'] = ($units > 0) ? $tariff['flat_charge'] : 0;
                } else {
                    $bandData['rate'] = $tariff['unit_price'];
                    $bandData['charge'] = $bandData['quantity'] * $tariff['unit_price'];
                }
            } elseif ($bandKey === "Above 11.12") {
                $bandData['quantity'] = $alloc['B2'];
                $tariff = $this->getSewageTariffPrice(11, $GLOBALS['invoice']['issue_date'] ?? null);
                $bandData['rate'] = $tariff['unit_price'];
                $bandData['charge'] = $bandData['quantity'] * $tariff['unit_price'];
            }
        }
        unset($bandData);
        
        // Display sewage bands
        foreach ($sewageBands as $bandData) {
            $subtotal += $bandData['charge'];
            
            $calculated_prev = floatval($readings['prev_reading']) - floatval($readings['units_billed']);
            $calculated_curr = floatval($readings['prev_reading']);
            $this->Cell(20,6,'',1,0,'R');
            $this->SetFont('Arial','B',9);
            $this->Cell(60,6,$bandData['description'],1,0,'L');
            $this->SetFont('Arial','',9);
            $this->Cell(20,6,number_format($calculated_prev, 2),1,0,'R');
            $this->Cell(20,6,number_format($calculated_curr, 2),1,0,'R');
            $this->Cell(20,6,number_format($readings['units_billed'],2),1,0,'R');
            $rateText = floatval($bandData['rate']) ? number_format($bandData['rate'],2) : '';
            $chargeText = floatval($bandData['charge']) ? number_format($bandData['charge'],2) : '';
            $this->Cell(25,6,$rateText,1,0,'R');
            $this->Cell(25,6,$chargeText,1,1,'R');
        }
        
        // Subtotal row
        $this->SetFont('Arial','B',9);
        $this->Cell(165,7,'Subtotal',1,0,'R');
        $this->Cell(25,7,number_format($subtotal,2),1,1,'R');
        $this->Ln(3);
        
        return $subtotal;
    }
    
    // Custom function for electricity service table
    function ElectricityServiceTable($lines) {
        $this->SetFont('Arial','',9);
        $subtotal = 0;
        
        foreach ($lines as $line) {
            $meta = json_decode($line['metadata'], true) ?? [];
            $prev = $meta['prev_reading'] ?? '';
            $curr = $meta['current_reading'] ?? '';
            $quantity = $line['quantity'] ?? 0;
            $rate = $line['unit_price'] ?? 0;
            $charge = $line['line_total'] ?? 0;
            $subtotal += $charge;

            $this->Cell(20,6,number_format($quantity,2),1,0,'R');
            $this->Cell(60,6,'Electricity Consumption',1,0,'L');
            $this->Cell(20,6,$prev,1,0,'R');
            $this->Cell(20,6,$curr,1,0,'R');
            $this->Cell(20,6,number_format($quantity,2),1,0,'R');
            $this->Cell(25,6,number_format($rate,2),1,0,'R');
            $this->Cell(25,6,number_format($charge,2),1,1,'R');
        }
        
        // Subtotal row
        $this->SetFont('Arial','B',9);
        $this->Cell(165,7,'Subtotal',1,0,'R');
        $this->Cell(25,7,number_format($subtotal,2),1,1,'R');
        $this->Ln(3);
        
        return $subtotal;
    }
}

// --- Create PDF ---
$pdf = new PDF();
$pdf->AliasNbPages();
$pdf->AddPage();
$pdf->SetFont('Arial','',10);

// --- Client Information Table ---
$pdf->SetFont('Arial','',9);

// remember starting coordinates for the box
$boxX = 10;
$boxY = $pdf->GetY();
$boxW = 190; // full width (left 10 to right 200)
$boxH = 40; // increased height so all client info rows fit inside the box

// draw outer rectangle (border)
$pdf->Rect($boxX, $boxY, $boxW, $boxH);

// vertical separator between left and right columns (two equal columns)
$sepX = $boxX + ($boxW / 2);
$pdf->Line($sepX, $boxY, $sepX, $boxY + $boxH);

// small padding inside the box
$padX = 4;
$currentY = $boxY + 3; // start a little lower to create top padding

$pdf->SetXY($boxX + $padX, $currentY);
$pdf->Cell(85,6,"Client ID: " . $invoice['house_code'],0,0,'L');
$pdf->SetXY($sepX + $padX, $currentY);
$pdf->Cell(85,6,"Region: " . $invoice['station'],0,1,'L');

$currentY += 6;
$pdf->SetXY($boxX + $padX, $currentY);
$pdf->Cell(85,6,"Client Name: " . $invoice['tenant_name'],0,0,'L');
$pdf->SetXY($sepX + $padX, $currentY);
$pdf->Cell(85,6,"Water Meter: " . $water_meter,0,1,'L');

$currentY += 6;
$pdf->SetXY($boxX + $padX, $currentY);
$pdf->Cell(85,6,"House Number: " . $invoice['house_code'],0,0,'L');
$pdf->SetXY($sepX + $padX, $currentY);
$pdf->Cell(85,6,"Electricity Meter: " . $electricity_meter,0,1,'L');

$currentY += 6;
$pdf->SetXY($boxX + $padX, $currentY);
$pdf->Cell(85,6,"Phone: " . $invoice['phone'],0,0,'L');
$pdf->SetXY($sepX + $padX, $currentY);
$pdf->Cell(85,6,"W/Order No:",0,1,'L');

$currentY += 6;
$pdf->SetXY($boxX + $padX, $currentY);
$pdf->Cell(85,6,"Email: " . $invoice['email'],0,0,'L');
$pdf->SetXY($sepX + $padX, $currentY);
$pdf->Cell(85,6,"S/Order No:",0,1,'L');

$currentY += 6;
$pdf->SetXY($boxX + $padX, $currentY);
$pdf->Cell(85,6,"Address: ",0,0,'L');
$pdf->SetXY($sepX + $padX, $currentY);
$pdf->Cell(85,6,"Bill Date: " . $invoice['issue_date'],0,1,'L');

$pdf->SetY($boxY + $boxH + 2);

// --- House/Station Information ---
$pdf->SetFont('Arial','B',9);
$pdf->Cell(40,6,"House No",1,0,'L');
$pdf->Cell(30,6,"Station",1,0,'L');
$pdf->Cell(30,6,"Bill Month",1,0,'L');
$pdf->Cell(30,6,"Payment Due",1,0,'L');
$pdf->Cell(30,6,"Start Date",1,0,'L');
$pdf->Cell(30,6,"End Date",1,1,'L');

$pdf->SetFont('Arial','',9);
$pdf->Cell(40,6,$invoice['house_code'],1,0,'L');
$pdf->Cell(30,6,$invoice['station'],1,0,'L');
$pdf->Cell(30,6,$invoice['bill_month'],1,0,'L');
$pdf->Cell(30,6,$invoice['due_date'],1,0,'L');
// Calculate start and end dates based on bill month (simplified)
$pdf->Cell(30,6,$invoice['bill_month'],1,0,'L');
$pdf->Cell(30,6,$invoice['due_date'],1,1,'L');

$pdf->Ln(5);

// --- Description of Readings Header ---
$pdf->SetFont('Arial','B',9);
$pdf->Cell(20,7,'Quantity',1,0,'C');
$pdf->Cell(60,7,'Description',1,0,'C');
$pdf->Cell(20,7,'Previous',1,0,'C');
$pdf->Cell(20,7,'Current',1,0,'C');
$pdf->Cell(20,7,'Units',1,0,'C');
$pdf->Cell(25,7,'Unit Price',1,0,'C');
$pdf->Cell(25,7,'Total',1,1,'C');

// --- Service Sections ---
$serviceTotals = [];

// Water Section
$water_lines = $grouped['water'] ?? [];
$water_units = 0;
$water_reported = 0;
foreach ($water_lines as $l) {
    $meta = json_decode($l['metadata'], true) ?? [];
    if (isset($meta['prev_reading']) && isset($meta['current_reading'])) {
        $water_units = max($water_units, $l['quantity'] ?? 0);
    }
    if (stripos($l['description'], 'B1') !== false || 
        stripos($l['description'], 'B2') !== false ||
        stripos($l['description'], 'B3') !== false ||
        stripos($l['description'], 'B4') !== false) {
        $water_reported += floatval($l['quantity'] ?? 0);
    }
}
$total_water = $water_units ?: $water_reported;
if ($total_water > 0) {
    $pdf->SetFont('Arial','B',10);
    $pdf->SetFillColor(230,230,230);
    $pdf->Cell(0,7,'Water',1,1,'L',true);
    $serviceTotals['water'] = $pdf->WaterServiceTable($water_lines);
}

// Sewage Section
$sewage_lines = $grouped['sewage'] ?? [];
$sewage_units = 0;
foreach ($sewage_lines as $l) {
    $meta = json_decode($l['metadata'], true) ?? [];
    if (isset($meta['prev_reading']) && isset($meta['current_reading'])) {
        $sewage_units = max($sewage_units, $l['quantity'] ?? 0);
    }
}
if ($sewage_units > 0) {
    $pdf->SetFont('Arial','B',10);
    $pdf->SetFillColor(230,230,230);
    $pdf->Cell(0,7,'Sewage',1,1,'L',true);
    $serviceTotals['sewage'] = $pdf->SewageServiceTable($sewage_lines);
}

// Electricity Section
$electricity_lines = $grouped['electricity'] ?? [];
$total_electricity = array_sum(array_column($electricity_lines, 'quantity'));
if ($total_electricity > 0) {
    $pdf->SetFont('Arial','B',10);
    $pdf->SetFillColor(230,230,230);
    $pdf->Cell(0,7,'Electricity',1,1,'L',true);
    $serviceTotals['electricity'] = $pdf->ElectricityServiceTable($electricity_lines);
}

// --- Final Total Section ---
$pdf->Ln(5);
$total = array_sum($serviceTotals);

// Name and Payment Details
$bill_month = date('F Y', strtotime($invoice['bill_month'] . '-01'));
$pdf->SetFont('Arial','',10);
$pdf->Cell(40,6,'Name',0,0,'L');
$pdf->Cell(50,6,'Eswatini Railway',0,0,'L');
$pdf->Cell(40,6,$bill_month . ' Total',0,0,'R');
$pdf->Cell(40,6,'E '.number_format($total,2),0,1,'R');

$pdf->Cell(40,6,'Payment Details',0,0,'L');
$pdf->Cell(50,6,'Internet Transfer',0,0,'L');
$pdf->Cell(40,6,'Subtotal Total',0,0,'R');
$pdf->Cell(40,6,'E '.number_format($total,2),0,1,'R');

$pdf->Cell(40,6,'CC#',0,0,'L');
$pdf->Cell(50,6,'',0,0,'L');
$pdf->Cell(40,6,'VAT',0,0,'R');
$pdf->Cell(40,6,'',0,1,'R');

$pdf->Cell(40,6,'',0,0,'L');
$pdf->Cell(50,6,'',0,0,'L');
$pdf->Cell(40,6,'Sales Tax',0,0,'R');
$pdf->Cell(40,6,'',0,1,'R');

$pdf->SetFont('Arial','B',12);
$pdf->Cell(40,6,'',0,0,'L');
$pdf->Cell(50,6,'',0,0,'L');
$pdf->Cell(40,6,'Total',0,0,'R');
$pdf->Cell(40,6,'E '.number_format($total,2),0,1,'R');

// --- Output PDF ---
// Generate filename using house code and tenant name for better organization
$house_code = preg_replace('/[^A-Za-z0-9_-]/', '_', $invoice['house_code']);
$tenant_name = preg_replace('/[^A-Za-z0-9_-]/', '_', $invoice['tenant_name'] ?? 'Unknown');
$filename = 'Invoice_' . $house_code . '_' . $tenant_name . '_' . $invoiceNo . '.pdf';
$pdf->Output('I', $filename);