<?php
error_reporting(0);
ini_set('display_errors', 0);
date_default_timezone_set('Asia/Kolkata');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
/**
 * API Endpoints (PHP Version)
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';

if (!function_exists('json_response')) {
    function json_response($data, $status = 200) {
        if (ob_get_level()) {
            @ob_end_clean();
        }
        header('Content-Type: application/json');
        http_response_code($status);
        echo json_encode($data);
        exit;
    }
}

function enforce_api_auth($allowed_roles = []) {
    if (!isset($_SESSION['user_id'])) {
        if (function_exists('json_response')) {
            json_response(['success' => false, 'error' => 'Unauthorized access (Not logged in)'], 401);
            exit;
        } else {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Unauthorized access (Not logged in)']);
            exit;
        }
    }
    if ($_SESSION['role'] === 'management') return;
    if (!empty($allowed_roles) && !in_array($_SESSION['role'], $allowed_roles)) {
        if (function_exists('json_response')) {
            json_response(['success' => false, 'error' => 'Unauthorized access (Role not permitted)'], 403);
            exit;
        } else {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Unauthorized access (Role not permitted)']);
            exit;
        }
    }
}

function enforce_admin() {
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'management') {
        if (function_exists('json_response')) {
            json_response(['success' => false, 'error' => 'Unauthorized access'], 403);
            exit;
        } else {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
            exit;
        }
    }
}
// Auto-detect medicine category from item name using short-form codes
function detect_medicine_category($item_name) {
    $name = strtoupper(trim($item_name));
    
    $mapping = [
        'SPRAY' => ['SPRAY', 'SPRAYS', 'SPRARY'],
        'OINT' => ['OINT', 'OINTMENT', 'OINTMENTS'],
        'DROP' => ['DROP', 'DROPS', 'DRP', 'DP'],
        'TAB' => ['TAB', 'TABLET', 'TABLETS'],
        'CAP' => ['CAP', 'CAPSULE', 'CAPSULES'],
        'SYP' => ['SYP', 'SYRUP', 'SYRUPS'],
        'INJ' => ['INJ', 'INJECTION', 'INJECTIONS'],
        'CRM' => ['CRM', 'CREAM', 'CREAMS'],
        'GEL' => ['GEL', 'GELS'],
        'POW' => ['POW', 'POWDER', 'POWDERS'],
        'LOT' => ['LOT', 'LOTION', 'LOTIONS']
    ];
    
    foreach ($mapping as $category => $patterns) {
        foreach ($patterns as $pattern) {
            if (preg_match('/\b' . preg_quote($pattern, '/') . '\b/', $name)) {
                return $category;
            }
        }
    }
    return '';
}

function get_mapped_generic_name($conn, $brand_name) {
    $brand_name = trim($brand_name);
    if ($brand_name === '' || strtolower($brand_name) === '(unmapped brand)') return '';
    
    // Look up in agency_items first
    $stmt = $conn->prepare("SELECT DISTINCT generic_name FROM agency_items WHERE TRIM(LOWER(item_name)) = TRIM(LOWER(?)) AND generic_name IS NOT NULL AND TRIM(generic_name) != '' LIMIT 1");
    $stmt->execute([$brand_name]);
    $res = $stmt->fetchColumn();
    if ($res) return $res;
    
    // Look up in inventory
    $stmt = $conn->prepare("SELECT DISTINCT generic_name FROM inventory WHERE TRIM(LOWER(name)) = TRIM(LOWER(?)) AND generic_name IS NOT NULL AND TRIM(generic_name) != '' LIMIT 1");
    $stmt->execute([$brand_name]);
    $res = $stmt->fetchColumn();
    if ($res) return $res;
    
    return '';
}

// Bidirectional synchronization between agency_items and inventory tables
function sync_stock_item($conn, $item_name, $batch_number, $source) {
    $batch_number = $batch_number ?? '';
    if (empty($item_name)) {
        return;
    }
    
    if ($source === 'agency') {
        // Fetch from agency_items
        $stmt = $conn->prepare("SELECT * FROM agency_items WHERE item_name = ? AND batch_number = ?");
        $stmt->execute([$item_name, $batch_number]);
        $a_item = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($a_item) {
            // Fetch agency/supplier name
            $agency_name = '';
            if (!empty($a_item['supplier_id'])) {
                $supp_stmt = $conn->prepare("SELECT name FROM agency_suppliers WHERE id = ?");
                $supp_stmt->execute([$a_item['supplier_id']]);
                $agency_name = $supp_stmt->fetchColumn() ?: '';
            }

            // Check if exists in pharmacy inventory
            $chk = $conn->prepare("SELECT id, tablets_per_strip FROM inventory WHERE name = ? AND batch_number = ?");
            $chk->execute([$item_name, $batch_number]);
            $p_item = $chk->fetch(PDO::FETCH_ASSOC);
            
            if ($p_item) {
                // Update existing record
                $upd = $conn->prepare("UPDATE inventory SET 
                    item_code = ?, category = ?, hsn_code = ?, mfg_date = ?, 
                    expiry_date = ?, mrp = ?, purchase_price = ?, selling_price = ?, 
                    stock = ?, min_stock = ?, supplier_id = ?,
                    generic_name = ?, brand_name = ?, agency_name = COALESCE(NULLIF(?, ''), agency_name),
                    row_location = ?, col_location = ?
                    WHERE id = ?");
                $upd->execute([
                    $a_item['item_code'] ?? '',
                    $a_item['category'] ?? 'Tablet',
                    $a_item['hsn_code'] ?? '',
                    $a_item['mfg_date'] ?? '',
                    $a_item['expiry_date'] ?? '',
                    (float)($a_item['mrp'] ?? 0),
                    (float)($a_item['purchase_price'] ?? 0),
                    (float)($a_item['selling_price'] ?? 0),
                    (in_array(strtolower($a_item['category'] ?? 'Tablet'), ['tablet', 'tablets', 'tab']) && ($p_item['tablets_per_strip'] ?? 0) > 0) ? (int)($a_item['stock'] ?? 0) * (int)$p_item['tablets_per_strip'] : (int)($a_item['stock'] ?? 0),
                    (int)($a_item['min_stock'] ?? 0),
                    $a_item['supplier_id'] ?? null,
                    $a_item['generic_name'] ?? '',
                    $a_item['brand_name'] ?? '',
                    $agency_name,
                    $a_item['row_location'] ?? '',
                    $a_item['col_location'] ?? '',
                    $p_item['id']
                ]);
            } else {
                // Determine tablets_per_strip by looking at other batches of same medicine
                $chk_tps = $conn->prepare("SELECT tablets_per_strip FROM inventory WHERE name = ? ORDER BY id DESC LIMIT 1");
                $chk_tps->execute([$item_name]);
                $existing_tps = $chk_tps->fetchColumn();
                $tps = ($existing_tps !== false) ? max(1, (int)$existing_tps) : 1;
                
                // Insert new record
                $ins = $conn->prepare("INSERT INTO inventory (
                    item_code, name, category, hsn_code, batch_number, mfg_date, 
                    expiry_date, mrp, purchase_price, selling_price, opening_stock, 
                    stock, min_stock, tablets_per_strip, supplier_id,
                    generic_name, brand_name, agency_name, row_location, col_location
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $ins->execute([
                    $a_item['item_code'] ?? '',
                    $item_name,
                    $a_item['category'] ?? 'Tablet',
                    $a_item['hsn_code'] ?? '',
                    $batch_number,
                    $a_item['mfg_date'] ?? '',
                    $a_item['expiry_date'] ?? '',
                    (float)($a_item['mrp'] ?? 0),
                    (float)($a_item['purchase_price'] ?? 0),
                    (float)($a_item['selling_price'] ?? 0),
                    0,
                    (in_array(strtolower($a_item['category'] ?? 'Tablet'), ['tablet', 'tablets', 'tab']) && $tps > 0) ? (int)($a_item['stock'] ?? 0) * $tps : (int)($a_item['stock'] ?? 0),
                    (int)($a_item['min_stock'] ?? 0),
                    $tps,
                    $a_item['supplier_id'] ?? null,
                    $a_item['generic_name'] ?? '',
                    $a_item['brand_name'] ?? '',
                    $agency_name,
                    $a_item['row_location'] ?? '',
                    $a_item['col_location'] ?? ''
                ]);
            }
        } else {
            // Deleted from agency_items: delete from inventory
            $del = $conn->prepare("DELETE FROM inventory WHERE name = ? AND batch_number = ?");
            $del->execute([$item_name, $batch_number]);
        }
    } else if ($source === 'pharmacy') {
        // Fetch from inventory
        $stmt = $conn->prepare("SELECT * FROM inventory WHERE name = ? AND batch_number = ?");
        $stmt->execute([$item_name, $batch_number]);
        $p_item = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($p_item) {
            // Check if exists in agency_items
            $chk = $conn->prepare("SELECT id FROM agency_items WHERE item_name = ? AND batch_number = ?");
            $chk->execute([$item_name, $batch_number]);
            $a_item = $chk->fetch(PDO::FETCH_ASSOC);
            
            if ($a_item) {
                // Update existing record (stock and common details only, preserving brand, manufacturer, unit, barcode etc.)
                $upd = $conn->prepare("UPDATE agency_items SET 
                    item_code = ?, category = ?, hsn_code = ?, mfg_date = ?, 
                    expiry_date = ?, mrp = ?, purchase_price = ?, selling_price = ?, 
                    stock = ?, min_stock = ?,
                    generic_name = ?, brand_name = ?, supplier_id = ?,
                    row_location = ?, col_location = ?
                    WHERE id = ?");
                $upd->execute([
                    $p_item['item_code'] ?? '',
                    $p_item['category'] ?? 'Tablet',
                    $p_item['hsn_code'] ?? '',
                    $p_item['mfg_date'] ?? '',
                    $p_item['expiry_date'] ?? '',
                    (float)($p_item['mrp'] ?? 0),
                    (float)($p_item['purchase_price'] ?? 0),
                    (float)($p_item['selling_price'] ?? 0),
                    (in_array(strtolower($p_item['category'] ?? 'Tablet'), ['tablet', 'tablets', 'tab']) && ($p_item['tablets_per_strip'] ?? 0) > 0) ? floor((int)($p_item['stock'] ?? 0) / (int)$p_item['tablets_per_strip']) : (int)($p_item['stock'] ?? 0),
                    (int)($p_item['min_stock'] ?? 0),
                    $p_item['generic_name'] ?? '',
                    $p_item['brand_name'] ?? '',
                    $p_item['supplier_id'] ?? $a_item['supplier_id'],
                    $p_item['row_location'] ?? '',
                    $p_item['col_location'] ?? '',
                    $a_item['id']
                ]);
            } else {
                // Insert new record
                $ins = $conn->prepare("INSERT INTO agency_items (
                    item_code, item_name, category, hsn_code, batch_number, mfg_date, 
                    expiry_date, mrp, purchase_price, selling_price, opening_stock, 
                    stock, min_stock, generic_name, brand_name, supplier_id,
                    row_location, col_location
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $ins->execute([
                    $p_item['item_code'] ?? '',
                    $item_name,
                    $p_item['category'] ?? 'Tablet',
                    $p_item['hsn_code'] ?? '',
                    $batch_number,
                    $p_item['mfg_date'] ?? '',
                    $p_item['expiry_date'] ?? '',
                    (float)($p_item['mrp'] ?? 0),
                    (float)($p_item['purchase_price'] ?? 0),
                    (float)($p_item['selling_price'] ?? 0),
                    (int)($p_item['opening_stock'] ?? 0),
                    (in_array(strtolower($p_item['category'] ?? 'Tablet'), ['tablet', 'tablets', 'tab']) && ($p_item['tablets_per_strip'] ?? 0) > 0) ? floor((int)($p_item['stock'] ?? 0) / (int)$p_item['tablets_per_strip']) : (int)($p_item['stock'] ?? 0),
                    (int)($p_item['min_stock'] ?? 0),
                    $p_item['generic_name'] ?? '',
                    $p_item['brand_name'] ?? '',
                    $p_item['supplier_id'] ?? null,
                    $p_item['row_location'] ?? '',
                    $p_item['col_location'] ?? ''
                ]);
            }
        } else {
            // Deleted from inventory: delete from agency_items
            $del = $conn->prepare("DELETE FROM agency_items WHERE item_name = ? AND batch_number = ?");
            $del->execute([$item_name, $batch_number]);
        }
    }
}

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

// Self-healing: Ensure required columns exist for Master Control
try {
    $conn = get_db();
    $stmt = $conn->query("SHOW COLUMNS FROM users");
    $cols = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    if (!in_array('details', $cols)) $conn->exec("ALTER TABLE users ADD COLUMN details TEXT");
    if (!in_array('photo_path', $cols)) $conn->exec("ALTER TABLE users ADD COLUMN photo_path VARCHAR(255)");
    if (!in_array('specialization', $cols)) $conn->exec("ALTER TABLE users ADD COLUMN specialization VARCHAR(255)");
    if (!in_array('admin_security_password', $cols)) $conn->exec("ALTER TABLE users ADD COLUMN admin_security_password VARCHAR(255) DEFAULT '123'");
    if (!in_array('token_prefix', $cols)) $conn->exec("ALTER TABLE users ADD COLUMN token_prefix VARCHAR(50)");
    if (!in_array('is_active', $cols)) $conn->exec("ALTER TABLE users ADD COLUMN is_active TINYINT DEFAULT 1");
    if (!in_array('doctor_registration_number', $cols)) $conn->exec("ALTER TABLE users ADD COLUMN doctor_registration_number VARCHAR(255)");

    $conn->exec("CREATE TABLE IF NOT EXISTS staff_records (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        phone VARCHAR(50) NOT NULL,
        education VARCHAR(255),
        role VARCHAR(100),
        salary DECIMAL(10,2) DEFAULT 0.00,
        status VARCHAR(50) DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->exec("CREATE TABLE IF NOT EXISTS medicine_returns (
        id INT AUTO_INCREMENT PRIMARY KEY,
        return_date VARCHAR(100),
        return_time VARCHAR(100),
        patient_name VARCHAR(255),
        bill_number VARCHAR(100),
        medicine_name VARCHAR(255),
        returned_qty DECIMAL(10,2),
        processed_by VARCHAR(255),
        reason TEXT,
        sale_type VARCHAR(100),
        sale_id INT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Self-healing: Ensure new columns exist in agency_purchases
    $stmt_ap = $conn->query("SHOW COLUMNS FROM agency_purchases");
    $cols_ap = $stmt_ap->fetchAll(PDO::FETCH_COLUMN, 0);
    if (!in_array('phonepe_amount', $cols_ap)) $conn->exec("ALTER TABLE agency_purchases ADD COLUMN phonepe_amount DECIMAL(10,2) DEFAULT 0.00");
    if (!in_array('bank_amount', $cols_ap)) $conn->exec("ALTER TABLE agency_purchases ADD COLUMN bank_amount DECIMAL(10,2) DEFAULT 0.00");
    if (!in_array('upi_account', $cols_ap)) $conn->exec("ALTER TABLE agency_purchases ADD COLUMN upi_account VARCHAR(100)");
    if (!in_array('account_id', $cols_ap)) $conn->exec("ALTER TABLE agency_purchases ADD COLUMN account_id INT");

    // Self-healing: Ensure new columns exist in agency_suppliers
    $stmt_as = $conn->query("SHOW COLUMNS FROM agency_suppliers");
    $cols_as = $stmt_as->fetchAll(PDO::FETCH_COLUMN, 0);
    if (!in_array('phonepe_amount', $cols_as)) $conn->exec("ALTER TABLE agency_suppliers ADD COLUMN phonepe_amount DECIMAL(10,2) DEFAULT 0.00");
    if (!in_array('bank_amount', $cols_as)) $conn->exec("ALTER TABLE agency_suppliers ADD COLUMN bank_amount DECIMAL(10,2) DEFAULT 0.00");
    if (!in_array('upi_account', $cols_as)) $conn->exec("ALTER TABLE agency_suppliers ADD COLUMN upi_account VARCHAR(100)");
    if (!in_array('account_id', $cols_as)) $conn->exec("ALTER TABLE agency_suppliers ADD COLUMN account_id INT");

    // Self-healing: Ensure new columns exist in direct_sales
    $stmt_ds = $conn->query("SHOW COLUMNS FROM direct_sales");
    $cols_ds = $stmt_ds->fetchAll(PDO::FETCH_COLUMN, 0);
    if (!in_array('phonepe_amount', $cols_ds)) $conn->exec("ALTER TABLE direct_sales ADD COLUMN phonepe_amount DECIMAL(10,2) DEFAULT 0.00");
    if (!in_array('bank_amount', $cols_ds)) $conn->exec("ALTER TABLE direct_sales ADD COLUMN bank_amount DECIMAL(10,2) DEFAULT 0.00");
    if (!in_array('payment_history', $cols_ds)) $conn->exec("ALTER TABLE direct_sales ADD COLUMN payment_history TEXT");
    if (!in_array('upi_account', $cols_ds)) $conn->exec("ALTER TABLE direct_sales ADD COLUMN upi_account VARCHAR(100)");
    if (!in_array('account_id', $cols_ds)) $conn->exec("ALTER TABLE direct_sales ADD COLUMN account_id INT");

    // Self-healing: Ensure new columns exist in prescriptions
    $stmt_pr = $conn->query("SHOW COLUMNS FROM prescriptions");
    $cols_pr = $stmt_pr->fetchAll(PDO::FETCH_COLUMN, 0);
    if (!in_array('phonepe_amount', $cols_pr)) $conn->exec("ALTER TABLE prescriptions ADD COLUMN phonepe_amount DECIMAL(10,2) DEFAULT 0.00");
    if (!in_array('bank_amount', $cols_pr)) $conn->exec("ALTER TABLE prescriptions ADD COLUMN bank_amount DECIMAL(10,2) DEFAULT 0.00");
    if (!in_array('discount_percent', $cols_pr)) $conn->exec("ALTER TABLE prescriptions ADD COLUMN discount_percent DECIMAL(5,2) DEFAULT 0.00");
    if (!in_array('scan_type', $cols_pr)) $conn->exec("ALTER TABLE prescriptions ADD COLUMN scan_type VARCHAR(100)");
    if (!in_array('scan_notes', $cols_pr)) $conn->exec("ALTER TABLE prescriptions ADD COLUMN scan_notes TEXT");
    if (!in_array('upi_account', $cols_pr)) $conn->exec("ALTER TABLE prescriptions ADD COLUMN upi_account VARCHAR(100)");
    if (!in_array('account_id', $cols_pr)) $conn->exec("ALTER TABLE prescriptions ADD COLUMN account_id INT");

    // Self-healing: Ensure supplier_id exists in inventory
    $stmt_inv = $conn->query("SHOW COLUMNS FROM inventory");
    $cols_inv = $stmt_inv->fetchAll(PDO::FETCH_COLUMN, 0);
    if (!in_array('supplier_id', $cols_inv)) $conn->exec("ALTER TABLE inventory ADD COLUMN supplier_id INT");

    // Self-healing: Ensure generic_name, brand_name, row_location, col_location exist in agency_items
    $stmt_ai = $conn->query("SHOW COLUMNS FROM agency_items");
    $cols_ai = $stmt_ai->fetchAll(PDO::FETCH_COLUMN, 0);
    if (!in_array('generic_name', $cols_ai)) $conn->exec("ALTER TABLE agency_items ADD COLUMN generic_name VARCHAR(255) DEFAULT NULL");
    if (!in_array('brand_name', $cols_ai)) $conn->exec("ALTER TABLE agency_items ADD COLUMN brand_name VARCHAR(255) DEFAULT NULL");
    if (!in_array('row_location', $cols_ai)) $conn->exec("ALTER TABLE agency_items ADD COLUMN row_location VARCHAR(100) DEFAULT NULL");
    if (!in_array('col_location', $cols_ai)) $conn->exec("ALTER TABLE agency_items ADD COLUMN col_location VARCHAR(100) DEFAULT NULL");

    // Self-healing: Ensure generic_name exists in agency_purchase_items
    $stmt_api = $conn->query("SHOW COLUMNS FROM agency_purchase_items");
    $cols_api = $stmt_api->fetchAll(PDO::FETCH_COLUMN, 0);
    if (!in_array('generic_name', $cols_api)) $conn->exec("ALTER TABLE agency_purchase_items ADD COLUMN generic_name VARCHAR(255) DEFAULT NULL");

} catch (Exception $e) {}

function get_prev_balance_info($conn, $patient_id, $current_presc_id) {
    // Get the sum of remaining balance for older prescriptions
    $stmt = $conn->prepare("SELECT SUM(balance_amount) as rem, MAX(created_at) as last_date FROM prescriptions WHERE patient_id=? AND id < ? AND balance_amount > 0");
    $stmt->execute([$patient_id, $current_presc_id]);
    $row = $stmt->fetch();
    $rem = (float)($row['rem'] ?? 0);
    $date = $row['last_date'] ? date('d/m/Y', strtotime($row['last_date'])) : null;
    
    // Get the cleared amount from the current prescription
    $stmt = $conn->prepare("SELECT prev_balance_cleared FROM prescriptions WHERE id=?");
    $stmt->execute([$current_presc_id]);
    $cleared = (float)($stmt->fetchColumn() ?: 0);
    
    $orig = $rem + $cleared;
    
    if ($orig > 0) {
        return [
            'original' => $orig,
            'cleared' => $cleared,
            'remaining' => $rem,
            'date' => $date
        ];
    }
    return null;
}

// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
// API â€” RECEPTIONIST
// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•

if ($uri === '/api/doctors' && $method === 'GET') {
    try {
        json_response(get_all_doctors());
    } catch (Throwable $e) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode([
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
        exit;
    }
}

if ($uri === '/api/register_patient' && $method === 'POST') {
    enforce_api_auth(['receptionist']);
    $doctor_id = $input['doctor_id'];
    $doctor_name = get_doctor_name($doctor_id);
    
    // Get doctor_type and token_prefix
    $conn = get_db();
    $stmt = $conn->prepare("SELECT doctor_type, token_prefix FROM users WHERE id = ?");
    $stmt->execute([$doctor_id]);
    $doc_info = $stmt->fetch();
    $doctor_type = $doc_info ? ($doc_info['doctor_type'] ?: 'D') : 'D';
    $prefix = ($doc_info && !empty($doc_info['token_prefix'])) 
                ? strtoupper($doc_info['token_prefix']) 
                : strtoupper(substr($doctor_type, 0, 1));
    
    $manual_token_num = $input['manual_token'] ?? null;
    
    if ($manual_token_num) {
        $num = (int)$manual_token_num;
        $token = sprintf("%s-%03d", $prefix, $num);
        
        $stmt = $conn->prepare("SELECT 1 FROM patients WHERE token = ? AND DATE(created_at) = CURDATE()");
        $stmt->execute([$token]);
        if ($stmt->fetch()) {
            json_response(['success' => false, 'message' => 'Token already assigned.'], 400);
        }
    } else {
        $token = generate_token($doctor_id);
    }

    $now = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("INSERT INTO patients 
        (name, age, gender, phone, address, doctor_id, doctor_type, doctor_name, complaint, bp, temp, pulse, weight, height, token, spo2, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $input['name'], $input['age'], $input['gender'], $input['phone'],
        $input['address'] ?? '', $doctor_id, $doctor_type, $doctor_name,
        $input['complaint'] ?? '', $input['bp'] ?? '', $input['temp'] ?? '',
        $input['pulse'] ?? '', $input['weight'] ?? '', $input['height'] ?? '',
        $token, $input['spo2'] ?? null, $now
    ]);
    
    $db_id = $conn->lastInsertId();
    
    // Permanent ID Logic
    $stmt = $conn->prepare("SELECT patient_id FROM patients WHERE name=? AND phone=? AND id != ? AND patient_id IS NOT NULL AND patient_id != '' LIMIT 1");
    $stmt->execute([$input['name'], $input['phone'], $db_id]);
    $existing = $stmt->fetch();
    
    if ($existing && !empty($existing['patient_id'])) {
        $ccs_id = $existing['patient_id'];
    } else {
        $stmt = $conn->query("SELECT MAX(CAST(SUBSTRING(patient_id, 4) AS UNSIGNED)) AS max_num FROM patients WHERE patient_id LIKE 'CCS%'");
        $max_row = $stmt->fetch();
        $next_num = ($max_row && $max_row['max_num'] !== null) ? ((int)$max_row['max_num'] + 1) : 1;
        $ccs_id = "CCS" . $next_num;
    }
    
    $stmt = $conn->prepare("UPDATE patients SET patient_id = ? WHERE id = ?");
    $stmt->execute([$ccs_id, $db_id]);
    
    json_response([
        'success' => true,
        'patient_db_id' => (int)$db_id,
        'patient_id' => $ccs_id,
        'token' => $token,
        'doctor_name' => $doctor_name
    ]);
}

if (preg_match('/^\/api\/fetch_patient\/(.*)$/', $uri, $matches)) {
    enforce_api_auth(['receptionist', 'doctor', 'pharmacist']);
    $query = urldecode($matches[1]);
    $conn = get_db();
    $search_col = (strpos($query, 'CCS') === 0) ? "patient_id" : "phone";

    $stmt = $conn->prepare("SELECT p1.name, p1.age, p1.gender, p1.address, p1.complaint, p1.bp, p1.temp, p1.pulse, p1.weight, p1.height, p1.spo2, p1.created_at, p1.patient_id, p1.phone 
               FROM patients p1
               INNER JOIN (
                   SELECT MAX(id) as max_id 
                   FROM patients 
                   WHERE $search_col = ?
                   GROUP BY name, phone
               ) p2 ON p1.id = p2.max_id
               ORDER BY p1.created_at DESC");
    $stmt->execute([$query]);
    $rows = $stmt->fetchAll();
    
    if ($rows) {
        json_response(['found' => true, 'patients' => $rows]);
    } else {
        json_response(['found' => false]);
    }
}

// ═══════════════════════════════════════════
// API — PATIENTS LIST
// ═══════════════════════════════════════════

if ($uri === '/api/patients' && $method === 'GET') {
    enforce_api_auth(['receptionist', 'doctor', 'pharmacist', 'monitor']);
    $role = $_SESSION['role'] ?? null;
    if ($role === 'management' && isset($_GET['as_role'])) {
        $role = $_GET['as_role'];
    }
    $conn = get_db();
    $rows = [];

    if ($role === 'receptionist') {
        $stmt = $conn->query("SELECT * FROM patients WHERE DATE(created_at) = CURDATE() ORDER BY created_at DESC");
        $rows = $stmt->fetchAll();
    } elseif ($role === 'doctor') {
        $doctor_id = $_SESSION['doctor_id'];
        $stmt = $conn->prepare("SELECT * FROM patients WHERE doctor_id=? AND DATE(created_at) = CURDATE() ORDER BY token ASC");
        $stmt->execute([$doctor_id]);
        $rows = $stmt->fetchAll();
    } elseif ($role === 'pharmacist') {
        $stmt = $conn->query("SELECT p.*, pr.id as presc_id, pr.diagnosis, pr.prescription_text, 
            pr.medicines, pr.total_amount, pr.consultation_fee, pr.scan_fee, pr.scan_type, pr.scan_notes, pr.paid_amount, pr.balance_amount, 
            pr.cash_amount, pr.gpay_amount, pr.injection_cost, pr.iv_cost, pr.upt_cost, pr.discount_percent,
            pr.injection_details, pr.iv_details, pr.doctor_type as presc_doctor_type, 
            pr.status as presc_status, pr.upt_card 
            FROM patients p JOIN prescriptions pr ON p.id=pr.patient_id 
            WHERE p.status IN ('prescribed','completed') 
            AND DATE(p.created_at) = CURDATE() 
            ORDER BY pr.created_at DESC");
        $rows = $stmt->fetchAll();
    } elseif ($role === 'monitor') {
        $stmt = $conn->query("SELECT id, name, token, doctor_id, doctor_type, status FROM patients WHERE DATE(created_at) = CURDATE() AND status IN ('waiting', 'prescribed') ORDER BY created_at ASC");
        $rows = $stmt->fetchAll();
    }

    $result = [];
    foreach ($rows as $row) {
        if (isset($row['medicines']) && is_string($row['medicines'])) {
            $row['medicines'] = json_decode($row['medicines'], true) ?: [];
        }
        if (isset($row['total_amount'])) {
            $row['total_amount'] = (float)$row['total_amount'];
        }
        $result[] = $row;
    }
    json_response($result);
}

if (preg_match('/^\/api\/patient\/(\d+)$/', $uri, $matches)) {
    enforce_api_auth(['receptionist', 'doctor', 'pharmacist']);
    $pid = $matches[1];
    $conn = get_db();
    $stmt = $conn->prepare("SELECT p.*, pr.consultation_fee, pr.scan_fee, pr.prescription_text, pr.upt_card, pr.injection_details, pr.iv_details 
        FROM patients p 
        LEFT JOIN prescriptions pr ON p.id = pr.patient_id 
        WHERE p.id=?");
    $stmt->execute([$pid]);
    $row = $stmt->fetch();
    if ($row) {
        json_response($row);
    } else {
        json_response(['error' => 'Not found'], 404);
    }
}

// ═══════════════════════════════════════════
// API — DOCTOR PRESCRIBE
// ═══════════════════════════════════════════

if ($uri === '/api/prescribe' && $method === 'POST') {
    enforce_api_auth(['doctor']);
    $patient_id = $_POST['patient_id'];
    $doctor_id = $_SESSION['doctor_id'];
    $doctor_name = get_doctor_name($doctor_id);
    $consultation_fee = (float)($_POST['consultation_fee'] ?? 0);
    $scan_fee = (float)($_POST['scan_fee'] ?? 0);
    $scan_type = $_POST['scan_type'] ?? null;
    $scan_notes = $_POST['scan_notes'] ?? null;
    $diagnosis = $_POST['diagnosis'] ?? '';
    $prescription_text = $_POST['prescription_text'] ?? '';
    $upt_card = (($_POST['upt_card'] ?? '') === '1') ? 1 : 0;
    $injection_details = $_POST['injection_details'] ?? '';
    $iv_details = $_POST['iv_details'] ?? '';
    $injection_cost = (float)($_POST['injection_cost'] ?? 0);
    $iv_cost = (float)($_POST['iv_cost'] ?? 0);

    if ($scan_fee < 0) {
        json_response(['success' => false, 'message' => 'Scan fee cannot be negative'], 400);
    }

    $diag_photo_path = null;
    $presc_photo_path = null;

    if (isset($_FILES['diagnosis_photo']) && $_FILES['diagnosis_photo']['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($_FILES['diagnosis_photo']['name'], PATHINFO_EXTENSION);
        $filename = uniqid('diag_') . '.' . $ext;
        $mime_type = mime_content_type($_FILES['diagnosis_photo']['tmp_name']);
        upload_to_supabase($_FILES['diagnosis_photo']['tmp_name'], 'medical_records', $filename, $mime_type);
        $diag_photo_path = 'medical_records/' . $filename;
    }

    if (isset($_FILES['prescription_photo']) && $_FILES['prescription_photo']['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($_FILES['prescription_photo']['name'], PATHINFO_EXTENSION);
        $filename = uniqid('rx_') . '.' . $ext;
        $mime_type = mime_content_type($_FILES['prescription_photo']['tmp_name']);
        upload_to_supabase($_FILES['prescription_photo']['tmp_name'], 'medical_records', $filename, $mime_type);
        $presc_photo_path = 'medical_records/' . $filename;
    }

    $conn = get_db();
    $stmt = $conn->prepare("INSERT INTO prescriptions (
            patient_id, doctor_id, doctor_name, doctor_type, consultation_fee, scan_fee, scan_type, scan_notes,
            diagnosis, diagnosis_photo, prescription_text, prescription_photo, upt_card,
            injection_details, iv_details, injection_cost, iv_cost
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $patient_id, $doctor_id, $doctor_name, $_SESSION['doctor_type'], $consultation_fee, $scan_fee, $scan_type, $scan_notes,
        $diagnosis, $diag_photo_path, $prescription_text, $presc_photo_path, $upt_card,
        $injection_details, $iv_details, $injection_cost, $iv_cost
    ]);

    $now = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("UPDATE patients SET status='prescribed' WHERE id=?");
    $stmt->execute([$patient_id]);
    
    json_response(['success' => true]);
}

// ═══════════════════════════════════════════
// API — DOCTOR STATS
// ═══════════════════════════════════════════

if ($uri === '/api/doctor_stats' && $method === 'GET') {
    enforce_api_auth(['doctor']);
    $doctor_id = $_SESSION['doctor_id'];
    $today = date('Y-m-d');
    $conn = get_db();
    
    $stmt = $conn->prepare("SELECT COUNT(*) FROM patients WHERE doctor_id=? AND DATE(created_at)=?");
    $stmt->execute([$doctor_id, $today]);
    $total = $stmt->fetchColumn();
    
    $stmt = $conn->prepare("SELECT COUNT(*) FROM patients WHERE doctor_id=? AND DATE(created_at)=? AND status='waiting'");
    $stmt->execute([$doctor_id, $today]);
    $waiting = $stmt->fetchColumn();
    
    $stmt = $conn->prepare("SELECT COUNT(*) FROM patients WHERE doctor_id=? AND DATE(created_at)=? AND status IN ('prescribed','completed')");
    $stmt->execute([$doctor_id, $today]);
    $consulted = $stmt->fetchColumn();
    
    $stmt = $conn->prepare("SELECT COALESCE(SUM(consultation_fee),0) FROM prescriptions WHERE doctor_id=? AND DATE(created_at)=?");
    $stmt->execute([$doctor_id, $today]);
    $fees = $stmt->fetchColumn();
    
    json_response([
        'total' => (int)$total,
        'waiting' => (int)$waiting,
        'consulted' => (int)$consulted,
        'fees_collected' => (float)$fees
    ]);
}

// ═══════════════════════════════════════════
// API — PHARMACY STATS
// ═══════════════════════════════════════════

if ($uri === '/api/pharmacy_stats' && $method === 'GET') {
    enforce_api_auth(['pharmacist']);
    $today = date('Y-m-d');
    $conn = get_db();
    $stats = [];
    $doctors = get_all_doctors();
    
    $total_fees = 0;
    $total_scans = 0;
    $total_medicine = 0;
    
    foreach ($doctors as $doc) {
        $did = $doc['id'];
        $dt = $doc['doctor_type'] ?: 'D';
        
        $stmt = $conn->prepare("SELECT COUNT(*) FROM patients WHERE doctor_id=? AND DATE(created_at)=?");
        $stmt->execute([$did, $today]);
        $patients = $stmt->fetchColumn();
        
        $stmt = $conn->prepare("SELECT COALESCE(SUM(consultation_fee),0) FROM prescriptions WHERE doctor_id=? AND DATE(created_at)=?");
        $stmt->execute([$did, $today]);
        $fees = $stmt->fetchColumn();
        
        $stmt = $conn->prepare("SELECT COALESCE(SUM(scan_fee),0) FROM prescriptions WHERE doctor_id=? AND DATE(created_at)=?");
        $stmt->execute([$did, $today]);
        $scans = $stmt->fetchColumn();
        
        $stmt = $conn->prepare("SELECT COALESCE(SUM(total_amount),0) FROM prescriptions WHERE doctor_id=? AND DATE(created_at)=? AND status='dispensed'");
        $stmt->execute([$did, $today]);
        $medicine_total = $stmt->fetchColumn();
        
        $stats[$did] = [
            'patients' => (int)$patients,
            'fees' => (float)$fees,
            'scans' => (float)$scans,
            'medicine_total' => (float)$medicine_total,
            'doctor_name' => format_doctor_name($doc['display_name'], $dt),
            'doctor_type' => $dt
        ];
        
        $total_fees += $stats[$did]['fees'];
        $total_scans += $stats[$did]['scans'];
        $total_medicine += $stats[$did]['medicine_total'];
    }
    
    $total_revenue = $total_fees + $total_scans + $total_medicine;
    
    json_response([
        'doctor_stats' => $stats,
        'total_fees' => $total_fees,
        'total_scans' => $total_scans,
        'total_medicine' => $total_medicine,
        'total_revenue' => $total_revenue
    ]);
}

// ═══════════════════════════════════════════
// API — PHARMACY ADD MEDICINES
// ═══════════════════════════════════════════

if (($uri === '/api/add_medicines' || $uri === '/api/direct_pharmacy') && $method === 'POST') {
    $conn = get_db();
    try {
        $conn->beginTransaction();
        
        if ($uri === '/api/direct_pharmacy') {
    enforce_api_auth(['pharmacist']);
            $pat_name = $input['patient_name'] ?? 'Direct Sale';
            $pat_phone = $input['patient_phone'] ?? '';
            $token = 'W' . rand(100, 999);
            
            $stmt = $conn->prepare("INSERT INTO patients (name, phone, age, gender, doctor_name, doctor_type, token, status, created_at, completed_at) 
                                    VALUES (?, ?, 0, 'Other', 'Direct Pharmacy', 'Pharmacy', ?, 'completed', NOW(), NOW())");
            $stmt->execute([$pat_name, $pat_phone, $token]);
            $patient_id = $conn->lastInsertId();
            
            $stmt = $conn->prepare("INSERT INTO prescriptions (patient_id, doctor_name, doctor_type, diagnosis, prescription_text, status, created_at) 
                                    VALUES (?, 'Direct Pharmacy', 'Pharmacy', '-', '-', 'pending', NOW())");
            $stmt->execute([$patient_id]);
            $presc_id = $conn->lastInsertId();
            $input['prescription_id'] = $presc_id;
        }

        $presc_id = $input['prescription_id'];
        $medicines = $input['medicines'];
        $consultation_fee = (float)($input['consultation_fee'] ?? 0);
        $scan_fee = (float)($input['scan_fee'] ?? 0);
        $scan_type = $input['scan_type'] ?? null;
        $scan_notes = $input['scan_notes'] ?? null;
        $cash_amount = (float)($input['cash_amount'] ?? 0);
        $gpay_amount = (float)($input['gpay_amount'] ?? 0);
        $phonepe_amount = (float)($input['phonepe_amount'] ?? 0);
        $paid_amount = (float)($input['paid_amount'] ?? 0);
        $balance_amount = (float)($input['balance_amount'] ?? 0);
        $upi_account = $input['upi_account'] ?? null;
        
        $total_med_amount = 0;
        foreach ($medicines as $m) $total_med_amount += (float)($m['amount'] ?? 0);

        $injection_cost = (float)($input['injection_cost'] ?? 0);
        $injection_details = $input['injection_details'] ?? null;
        $iv_cost = (float)($input['iv_cost'] ?? 0);
        $iv_details = $input['iv_details'] ?? null;
        $upt_cost = (float)($input['upt_cost'] ?? 0);
        if ($scan_fee < 0) {
            throw new Exception("Scan fee cannot be negative.");
        }

        $total_cost = 0.0;
        
        foreach ($medicines as $m) {
            $name = $m['name'] ?? null;
            $qty = (int)($m['qty'] ?? 0);
            $batch_id = $m['batch_id'] ?? '';
            
            if ($name && $qty > 0) {
                if ($batch_id) {
                    $stmt = $conn->prepare("SELECT name, batch_number, purchase_price, tablets_per_strip FROM inventory WHERE id=?");
                    $stmt->execute([$batch_id]);
                    $row = $stmt->fetch();
                    if ($row) {
                        $tps = max(1, (int)($row['tablets_per_strip'] ?? 1));
                        $cost_per_unit = (float)$row['purchase_price'] / $tps;
                        $total_cost += $cost_per_unit * $qty;
                        $stmt = $conn->prepare("UPDATE inventory SET stock = GREATEST(stock - ?, 0) WHERE id=?");
                        $stmt->execute([$qty, $batch_id]);
                        
                        // Sync stock to agency inventory
                        sync_stock_item($conn, $row['name'], $row['batch_number'], 'pharmacy');
                    }
                } else {
                    $stmt = $conn->prepare("SELECT name, batch_number, purchase_price, tablets_per_strip, id FROM inventory WHERE name=? AND stock > 0 ORDER BY expiry_date ASC LIMIT 1");
                    $stmt->execute([$name]);
                    $row = $stmt->fetch();
                    if ($row) {
                        $tps = max(1, (int)($row['tablets_per_strip'] ?? 1));
                        $cost_per_unit = (float)$row['purchase_price'] / $tps;
                        $total_cost += $cost_per_unit * $qty;
                        $stmt = $conn->prepare("UPDATE inventory SET stock = GREATEST(stock - ?, 0) WHERE id=?");
                        $stmt->execute([$qty, $row['id']]);
                        
                        // Sync stock to agency inventory
                        sync_stock_item($conn, $row['name'], $row['batch_number'], 'pharmacy');
                    }
                }
            }
        }

        $deduct_stock_by_name = function($item_name) use ($conn, &$total_cost) {
            if (!$item_name || trim($item_name) === '') return;
            $stmt = $conn->prepare("SELECT name, batch_number, purchase_price, id FROM inventory WHERE name=? AND stock > 0 ORDER BY expiry_date ASC LIMIT 1");
            $stmt->execute([trim($item_name)]);
            $row = $stmt->fetch();
            if ($row) {
                $total_cost += (float)$row['purchase_price'];
                $stmt = $conn->prepare("UPDATE inventory SET stock = GREATEST(stock - 1, 0) WHERE id=?");
                $stmt->execute([$row['id']]);
                
                // Sync stock to agency inventory
                sync_stock_item($conn, $row['name'], $row['batch_number'], 'pharmacy');
            }
        };

        if ($injection_cost > 0 && $injection_details) {
            $deduct_stock_by_name($injection_details);
        }
        if ($iv_cost > 0 && $iv_details) {
            $deduct_stock_by_name($iv_details);
        }
        if ($upt_cost > 0) {
            $stmt = $conn->query("SELECT name, batch_number, purchase_price, id FROM inventory WHERE category='UPT Card' AND stock > 0 ORDER BY expiry_date ASC LIMIT 1");
            $row = $stmt->fetch();
            if ($row) {
                $total_cost += (float)$row['purchase_price'];
                $stmt = $conn->prepare("UPDATE inventory SET stock = GREATEST(stock - 1, 0) WHERE id=?");
                $stmt->execute([$row['id']]);
                
                // Sync stock to agency inventory
                sync_stock_item($conn, $row['name'], $row['batch_number'], 'pharmacy');
            }
        }

        $discount_percent = (float)($input['discount_percent'] ?? 0);

        $stmt = $conn->prepare("UPDATE prescriptions SET 
            medicines=?, total_amount=?, cost_amount=?, consultation_fee=?, scan_fee=?, scan_type=COALESCE(?, scan_type), scan_notes=COALESCE(?, scan_notes), 
            injection_cost=?, injection_details=?, iv_cost=?, upt_cost=?, 
            cash_amount=?, gpay_amount=?, phonepe_amount=?, paid_amount=?, balance_amount=?, discount_percent=?, upi_account=?, status='dispensed' 
            WHERE id=?");
        $stmt->execute([
            json_encode($medicines), $total_med_amount, $total_cost, $consultation_fee, $scan_fee, $scan_type, $scan_notes,
            $injection_cost, $injection_details, $iv_cost, $upt_cost,
            $cash_amount, $gpay_amount, $phonepe_amount, $paid_amount, $balance_amount, $discount_percent, $upi_account, $presc_id
        ]);

        // Mark patient status as completed and record checkout time
        $stmt = $conn->prepare("UPDATE patients SET status='completed', completed_at=NOW() WHERE id=(SELECT patient_id FROM prescriptions WHERE id=?)");
        $stmt->execute([$presc_id]);

        $stmt = $conn->prepare("SELECT p.*, pr.id as presc_id, pr.diagnosis, pr.prescription_text, pr.medicines,
                   pr.total_amount, pr.consultation_fee, pr.scan_fee,
                   pr.paid_amount, pr.balance_amount, pr.injection_details, pr.iv_details,
                   pr.injection_cost, pr.iv_cost, pr.upt_cost, pr.cash_amount, pr.gpay_amount, pr.phonepe_amount,
                   pr.discount_percent,
                   pr.doctor_name as presc_doctor, pr.doctor_type as presc_doctor_type, p.completed_at
            FROM prescriptions pr JOIN patients p ON pr.patient_id=p.id
            WHERE pr.id=?");
        $stmt->execute([$presc_id]);
        $rec = $stmt->fetch();
        if ($rec) {
            $rec['doctor_name'] = $rec['presc_doctor'];
            $rec['doctor_type'] = $rec['presc_doctor_type'];
            $rec['medicines'] = json_decode($rec['medicines'], true) ?: [];
            $rec['prev_balance_info'] = get_prev_balance_info($conn, $rec['patient_id'], $presc_id);
        }



        $conn->commit();
        json_response(['success' => true, 'total' => $total_med_amount, 'data' => $rec]);
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        json_response(['success' => false, 'error' => $e->getMessage()], 400);
    }
}

// ═══════════════════════════════════════════
// API – DIRECT MEDICINE SALES
// ═══════════════════════════════════════════

if ($uri === '/api/direct_sales/add' && $method === 'POST') {
    enforce_api_auth(['pharmacist']);
    $conn = get_db();
    try {
        $conn->beginTransaction();

        $customer_name = trim($input['customer_name'] ?? '');
        $mobile_number = trim($input['mobile_number'] ?? '');

        if (!$customer_name) {
            throw new Exception('Customer name is required');
        }

        $medicines       = $input['medicines'] ?? [];
        $injection_cost  = (float)($input['injection_cost'] ?? 0);
        $injection_details = $input['injection_details'] ?? '';
        $iv_cost         = (float)($input['iv_cost'] ?? 0);
        $iv_details      = $input['iv_details'] ?? '';
        $upt_cost        = (float)($input['upt_cost'] ?? 0);
        $upt_card        = $upt_cost > 0 ? 1 : 0;
        $cash_amount     = (float)($input['cash_amount'] ?? 0);
        $gpay_amount     = (float)($input['gpay_amount'] ?? 0);
        $phonepe_amount  = (float)($input['phonepe_amount'] ?? 0);
        $paid_amount     = (float)($input['paid_amount'] ?? 0);
        $balance_amount  = (float)($input['balance_amount'] ?? 0);
        $discount_percent = (float)($input['discount_percent'] ?? 0);

        $total_med_amount = 0;
        foreach ($medicines as $m) $total_med_amount += (float)($m['amount'] ?? 0);

        $total_cost = 0.0;

        foreach ($medicines as $m) {
            $name     = $m['name'] ?? null;
            $qty      = (int)($m['qty'] ?? 0);
            $batch_id = $m['batch_id'] ?? '';

            if ($name && $qty > 0) {
                if ($batch_id) {
                    $stmt = $conn->prepare("SELECT name, batch_number, purchase_price, tablets_per_strip FROM inventory WHERE id=?");
                    $stmt->execute([$batch_id]);
                    $row = $stmt->fetch();
                    if ($row) {
                        $tps = max(1, (int)($row['tablets_per_strip'] ?? 1));
                        $total_cost += ((float)$row['purchase_price'] / $tps) * $qty;
                        $conn->prepare("UPDATE inventory SET stock = GREATEST(stock - ?, 0) WHERE id=?")->execute([$qty, $batch_id]);
                        sync_stock_item($conn, $row['name'], $row['batch_number'], 'pharmacy');
                    }
                } else {
                    $stmt = $conn->prepare("SELECT name, batch_number, purchase_price, tablets_per_strip, id FROM inventory WHERE name=? AND stock > 0 ORDER BY expiry_date ASC LIMIT 1");
                    $stmt->execute([$name]);
                    $row = $stmt->fetch();
                    if ($row) {
                        $tps = max(1, (int)($row['tablets_per_strip'] ?? 1));
                        $total_cost += ((float)$row['purchase_price'] / $tps) * $qty;
                        $conn->prepare("UPDATE inventory SET stock = GREATEST(stock - ?, 0) WHERE id=?")->execute([$qty, $row['id']]);
                        sync_stock_item($conn, $row['name'], $row['batch_number'], 'pharmacy');
                    }
                }
            }
        }

        $deduct_single = function($item_name) use ($conn, &$total_cost) {
            if (!$item_name || trim($item_name) === '') return;
            $stmt = $conn->prepare("SELECT name, batch_number, purchase_price, id FROM inventory WHERE name=? AND stock > 0 ORDER BY expiry_date ASC LIMIT 1");
            $stmt->execute([trim($item_name)]);
            $row = $stmt->fetch();
            if ($row) {
                $total_cost += (float)$row['purchase_price'];
                $conn->prepare("UPDATE inventory SET stock = GREATEST(stock - 1, 0) WHERE id=?")->execute([$row['id']]);
                sync_stock_item($conn, $row['name'], $row['batch_number'], 'pharmacy');
            }
        };

        if ($injection_cost > 0 && $injection_details) $deduct_single($injection_details);
        if ($iv_cost > 0 && $iv_details)                 $deduct_single($iv_details);
        if ($upt_cost > 0) {
            $stmt = $conn->query("SELECT name, batch_number, purchase_price, id FROM inventory WHERE category='UPT Card' AND stock > 0 ORDER BY expiry_date ASC LIMIT 1");
            $row  = $stmt->fetch();
            if ($row) {
                $total_cost += (float)$row['purchase_price'];
                $conn->prepare("UPDATE inventory SET stock = GREATEST(stock - 1, 0) WHERE id=?")->execute([$row['id']]);
                sync_stock_item($conn, $row['name'], $row['batch_number'], 'pharmacy');
            }
        }

        $status = ($balance_amount > 0) ? 'pending' : 'completed';

        $payment_history = [];
        if ($paid_amount > 0) {
            $methods = [];
            if ($cash_amount > 0) $methods[] = 'Cash';
            if ($gpay_amount > 0) $methods[] = 'GPay';
            if ($phonepe_amount > 0) $methods[] = 'PhonePe';
            
            $payment_history[] = [
                'date' => date('Y-m-d'),
                'time' => date('H:i:s'),
                'method' => implode(', ', $methods),
                'total_cleared' => $paid_amount,
                'details' => [
                    'Cash' => $cash_amount,
                    'GPay' => $gpay_amount,
                    'PhonePe' => $phonepe_amount,
                    'Bank Transfer' => 0
                ]
            ];
        }
        $payment_history_json = json_encode($payment_history);

        $upi_account = $input['upi_account'] ?? null;

        $stmt = $conn->prepare("INSERT INTO direct_sales (
            customer_name, mobile_number, medicines, injection_details, iv_details,
            injection_cost, iv_cost, upt_card, upt_cost, total_amount, discount_percent,
            cash_amount, gpay_amount, phonepe_amount, paid_amount, balance_amount, cost_amount, status, payment_history, upi_account
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $customer_name, $mobile_number, json_encode($medicines),
            $injection_details ?: null, $iv_details ?: null,
            $injection_cost, $iv_cost, $upt_card, $upt_cost,
            $total_med_amount, $discount_percent,
            $cash_amount, $gpay_amount, $phonepe_amount, $paid_amount, $balance_amount, $total_cost, $status, $payment_history_json, $upi_account
        ]);
        $sale_id = $conn->lastInsertId();

        $stmt = $conn->prepare("SELECT * FROM direct_sales WHERE id=?");
        $stmt->execute([$sale_id]);
        $rec = $stmt->fetch();
        if ($rec) $rec['medicines'] = json_decode($rec['medicines'], true) ?: [];



        $conn->commit();
        json_response(['success' => true, 'data' => $rec]);
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        json_response(['success' => false, 'error' => $e->getMessage()], 400);
    }
}

// API – DIRECT MEDICINE SALES PENDING PAYMENT
// ═══════════════════════════════════════════
if ($uri === '/api/direct_sales/pay_pending' && $method === 'POST') {
    enforce_api_auth(['pharmacist']);
    $conn = get_db();
    
    $sale_id = (int)($input['sale_id'] ?? 0);
    if (!$sale_id) {
        json_response(['success' => false, 'error' => 'Sale ID is required'], 400);
    }
    
    $cash_amount = (float)($input['cash_amount'] ?? 0);
    $gpay_amount = (float)($input['gpay_amount'] ?? 0);
    $phonepe_amount = (float)($input['phonepe_amount'] ?? 0);
    $bank_amount = (float)($input['bank_amount'] ?? 0);
    $pay_amount = $cash_amount + $gpay_amount + $phonepe_amount + $bank_amount;
    
    if ($pay_amount <= 0) {
        json_response(['success' => false, 'error' => 'Invalid payment amount'], 400);
    }
    
    $stmt = $conn->prepare("SELECT * FROM direct_sales WHERE id = ?");
    $stmt->execute([$sale_id]);
    $sale = $stmt->fetch();
    
    if (!$sale) {
        json_response(['success' => false, 'error' => 'Sale not found'], 404);
    }
    if ($sale['status'] !== 'pending') {
        json_response(['success' => false, 'error' => 'Sale is already completed'], 400);
    }
    
    $methods = [];
    if ($cash_amount > 0) $methods[] = 'Cash';
    if ($gpay_amount > 0) $methods[] = 'GPay';
    if ($phonepe_amount > 0) $methods[] = 'PhonePe';
    if ($bank_amount > 0) $methods[] = 'Bank Transfer';
    
    $payment_history = json_decode($sale['payment_history'] ?? '[]', true) ?: [];
    $payment_history[] = [
        'date' => date('Y-m-d'),
        'time' => date('H:i:s'),
        'method' => implode(', ', $methods),
        'total_cleared' => $pay_amount,
        'details' => [
            'Cash' => $cash_amount,
            'GPay' => $gpay_amount,
            'PhonePe' => $phonepe_amount,
            'Bank Transfer' => $bank_amount
        ]
    ];
    
    $new_paid = (float)$sale['paid_amount'] + $pay_amount;
    $new_balance = max(0, (float)$sale['balance_amount'] - $pay_amount);
    $new_status = ($new_balance > 0) ? 'pending' : 'completed';
    
    $upd = $conn->prepare("UPDATE direct_sales SET 
        cash_amount = cash_amount + ?, 
        gpay_amount = gpay_amount + ?, 
        phonepe_amount = phonepe_amount + ?, 
        bank_amount = bank_amount + ?, 
        paid_amount = ?, 
        balance_amount = ?, 
        status = ?, 
        payment_history = ? 
        WHERE id = ?");
        
    $upd->execute([
        $cash_amount, $gpay_amount, $phonepe_amount, $bank_amount,
        $new_paid, $new_balance, $new_status, json_encode($payment_history), $sale_id
    ]);
    
    json_response(['success' => true]);
}

if ($uri === '/api/direct_sales/list' && $method === 'GET') {
    enforce_api_auth(['pharmacist']);
    $conn    = get_db();
    $filter  = $_GET['filter'] ?? 'today';
    $date_from = $_GET['date_from'] ?? '';
    $date_to   = $_GET['date_to'] ?? '';
    $today     = date('Y-m-d');

    $where  = '';
    $params = [];

    if ($filter === 'today') {
        $where = "WHERE date(created_at) = ?";
        $params[] = $today;
    } elseif ($filter === 'yesterday') {
        $where = "WHERE date(created_at) = ?";
        $params[] = date('Y-m-d', strtotime('-1 day'));
    } elseif ($filter === 'week') {
        $where = "WHERE date(created_at) >= ?";
        $params[] = date('Y-m-d', strtotime('monday this week'));
    } elseif ($filter === 'month') {
        $where = "WHERE DATE_FORMAT(created_at, '%Y-%m') = ?";
        $params[] = date('Y-m');
    } elseif ($filter === 'custom' && $date_from && $date_to) {
        $where = "WHERE DATE(created_at) BETWEEN ? AND ?";
        $params[] = $date_from;
        $params[] = $date_to;
    }

    $stmt = $conn->query("SELECT id, name, purchase_price, tablets_per_strip FROM inventory");
    $inv_rows = $stmt->fetchAll();
    $inv_costs = [];
    $inv_tps = [];
    $inv_costs_by_id = [];
    $inv_tps_by_id = [];
    foreach ($inv_rows as $i) {
        $id = $i['id'];
        $name = trim($i['name']);
        
        $inv_costs_by_id[$id] = (float)$i['purchase_price'];
        $inv_tps_by_id[$id] = max(1, (int)$i['tablets_per_strip']);

        if (!isset($inv_costs[$name])) {
            $inv_costs[$name] = (float)$i['purchase_price'];
            $inv_tps[$name] = max(1, (int)$i['tablets_per_strip']);
        }
    }

    $stmt = $conn->prepare("SELECT * FROM direct_sales $where ORDER BY created_at DESC");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $meds = json_decode($r['medicines'], true) ?: [];
        foreach ($meds as &$m) {
            $name = $m['name'] ?? '';
            $batch_id = $m['batch_id'] ?? '';
            $qty = (float)($m['qty'] ?? 0);
            $ret = (float)($m['returned_qty'] ?? 0);
            $net_qty = $qty - $ret;

            if ($batch_id && isset($inv_costs_by_id[$batch_id])) {
                $unit_cost = $inv_costs_by_id[$batch_id];
                $tps = $inv_tps_by_id[$batch_id];
            } else {
                $unit_cost = $inv_costs[$name] ?? 0;
                $tps = max(1, (int)$inv_tps[$name] ?? 1);
            }
            
            $actual_unit_cost = $unit_cost / $tps;

            $m['net_qty'] = $net_qty;
            $m['cost'] = $net_qty * $actual_unit_cost;
            $m['revenue'] = (float)($m['amount'] ?? 0) - (float)($m['returned_amount'] ?? 0);
            $m['profit'] = $m['revenue'] - $m['cost'];
        }
        $r['medicines'] = $meds;
    }
    json_response(['success' => true, 'data' => $rows]);
}

if ($uri === '/api/direct_sales/delete' && $method === 'POST') {
    enforce_api_auth(['pharmacist']);
    $conn    = get_db();
    $sale_id = (int)($input['id'] ?? 0);
    if (!$sale_id) json_response(['success' => false, 'error' => 'Sale ID required'], 400);

    $stmt = $conn->prepare("SELECT * FROM direct_sales WHERE id=?");
    $stmt->execute([$sale_id]);
    $sale = $stmt->fetch();
    if (!$sale) json_response(['success' => false, 'error' => 'Sale not found'], 404);

    $medicines = json_decode($sale['medicines'], true) ?: [];

    foreach ($medicines as $m) {
        $qty      = (int)($m['qty'] ?? 0);
        $batch_id = $m['batch_id'] ?? '';
        $name     = $m['name'] ?? '';
        if ($qty <= 0) continue;

        if ($batch_id) {
            $conn->prepare("UPDATE inventory SET stock = stock + ? WHERE id=?")->execute([$qty, $batch_id]);
            $inv = $conn->prepare("SELECT name, batch_number FROM inventory WHERE id=?");
            $inv->execute([$batch_id]);
            $row = $inv->fetch();
            if ($row) sync_stock_item($conn, $row['name'], $row['batch_number'], 'pharmacy');
        } elseif ($name) {
            $stmt2 = $conn->prepare("SELECT id, name, batch_number FROM inventory WHERE name=? ORDER BY id LIMIT 1");
            $stmt2->execute([$name]);
            $row = $stmt2->fetch();
            if ($row) {
                $conn->prepare("UPDATE inventory SET stock = stock + ? WHERE id=?")->execute([$qty, $row['id']]);
                sync_stock_item($conn, $row['name'], $row['batch_number'], 'pharmacy');
            }
        }
    }

    $conn->prepare("DELETE FROM direct_sales WHERE id=?")->execute([$sale_id]);
    json_response(['success' => true]);
}

// ═══════════════════════════════════════════
// API — INVENTORY
// ═══════════════════════════════════════════


if ($uri === '/api/inventory/bulk_tps' && $method === 'POST') {
    enforce_api_auth(['pharmacist']);
    $input = json_decode(file_get_contents('php://input'), true);
    $names = $input['names'] ?? [];
    if (empty($names)) {
        echo json_encode([]); exit;
    }
    $conn = get_db();
    $placeholders = implode(',', array_fill(0, count($names), '?'));
    $stmt = $conn->prepare("SELECT name, MAX(tablets_per_strip) as tps FROM inventory WHERE name IN ($placeholders) GROUP BY name");
    $stmt->execute($names);
    $result = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $result[$row['name']] = (int)$row['tps'];
    }
    echo json_encode($result);
    exit;
}

if ($uri === '/api/generics/search' && $method === 'GET') {
    enforce_api_auth(['pharmacist', 'doctor', 'receptionist']);
    $q = trim($_GET['q'] ?? '');
    $conn = get_db();
    
    try {
        $stmt = $conn->prepare("
            SELECT DISTINCT generic_name FROM (
                SELECT generic_name FROM inventory WHERE generic_name LIKE ? AND generic_name != '' AND generic_name IS NOT NULL
                UNION
                SELECT generic_name FROM agency_items WHERE generic_name LIKE ? AND generic_name != '' AND generic_name IS NOT NULL
            ) AS combined ORDER BY generic_name ASC LIMIT 15
        ");
        $stmt->execute(["%$q%", "%$q%"]);
        $results = $stmt->fetchAll(PDO::FETCH_COLUMN);
        json_response($results);
    } catch (Exception $e) {
        json_response([], 500);
    }
}

if ($uri === '/api/inventory/search' && $method === 'GET') {
    enforce_api_auth(['pharmacist', 'doctor', 'receptionist']);
    $q = trim($_GET['q'] ?? '');
    $include_all = ($_GET['all'] ?? '0') === '1';
    $category = $_GET['category'] ?? ''; // NEW
    $conn = get_db();
    
    $query = "SELECT i.*, COALESCE(NULLIF(i.agency_name,''), s.name) as agency_name FROM inventory i LEFT JOIN agency_suppliers s ON i.supplier_id = s.id";
    $params = [];
    $conditions = [];
    
    if ($q) {
        $conditions[] = "(i.name LIKE ? OR i.generic_name LIKE ? OR i.item_code LIKE ? OR i.batch_number LIKE ?)";
        $params[] = "%$q%";
        $params[] = "%$q%";
        $params[] = "%$q%";
        $params[] = "%$q%";
    }
    if (!$include_all) {
        $conditions[] = "i.stock > 0";
    }
    
    if ($category === 'medicine') {
        $conditions[] = "i.category NOT IN ('Injection', 'INJ', 'IV')";
    } elseif ($category === 'Injection' || $category === 'INJ') {
        $conditions[] = "i.category IN ('Injection', 'INJ')";
    } elseif ($category) {
        $conditions[] = "i.category = ?";
        $params[] = $category;
    }
    
    if ($conditions) {
        $query .= " WHERE " . implode(" AND ", $conditions);
    }
    $query .= " ORDER BY i.name ASC";
    
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    json_response($stmt->fetchAll());
}

if ($uri === '/api/inventory/add' && $method === 'POST') {
    enforce_api_auth(['pharmacist']);
    $conn = get_db();
    $name = trim($input['name'] ?? '');
    $batch_number = trim($input['batch_number'] ?? 'BATCH-01');
    $stock = (int)($input['stock'] ?? 0);
    $purchase_price = (float)($input['purchase_price'] ?? $input['cost_price'] ?? 0);
    $selling_price = (float)($input['selling_price'] ?? 0);
    $mrp = (float)($input['mrp'] ?? $selling_price);
    $mfg_date = $input['mfg_date'] ?? '';
    $expiry_date = $input['expiry_date'] ?? '';
    $item_code = $input['item_code'] ?? '';
    $category = $input['category'] ?? 'Tablet';
    $hsn_code = $input['hsn_code'] ?? '';
    $tablets_per_strip = (int)($input['tablets_per_strip'] ?? 0);
    $min_stock = (int)($input['min_stock'] ?? 0);
    $row_location = trim($input['row_location'] ?? '');
    $col_location = trim($input['col_location'] ?? '');
    $generic_name = trim($input['generic_name'] ?? '');
    if ($generic_name === '') {
        $generic_name = get_mapped_generic_name($conn, $name);
    }
    $brand_name = trim($input['brand_name'] ?? $name);
    $agency_name = trim($input['agency_name'] ?? '');

    // MySQL-compatible duplicate key update
    $sql = "INSERT INTO inventory (item_code, name, generic_name, brand_name, agency_name, category, hsn_code, batch_number, mfg_date, expiry_date, mrp, purchase_price, selling_price, opening_stock, stock, min_stock, tablets_per_strip, row_location, col_location)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                stock = stock + VALUES(stock),
                opening_stock = opening_stock + VALUES(opening_stock),
                purchase_price = VALUES(purchase_price),
                selling_price = VALUES(selling_price),
                mrp = VALUES(mrp),
                expiry_date = VALUES(expiry_date),
                item_code = VALUES(item_code),
                mfg_date = VALUES(mfg_date),
                category = VALUES(category),
                hsn_code = VALUES(hsn_code),
                min_stock = VALUES(min_stock),
                tablets_per_strip = VALUES(tablets_per_strip),
                row_location = VALUES(row_location),
                col_location = VALUES(col_location),
                generic_name = VALUES(generic_name),
                brand_name = VALUES(brand_name),
                agency_name = VALUES(agency_name)";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$item_code, $name, $generic_name, $brand_name, $agency_name, $category, $hsn_code, $batch_number, $mfg_date, $expiry_date, $mrp, $purchase_price, $selling_price, $stock, $stock, $min_stock, $tablets_per_strip, $row_location, $col_location]);
    
    // Auto-save new category to agency_categories
    if (!empty($category)) {
        $cat = trim($category);
        $checkCat = $conn->prepare("SELECT id FROM agency_categories WHERE name = ?");
        $checkCat->execute([$cat]);
        if (!$checkCat->fetch()) {
            $conn->prepare("INSERT INTO agency_categories (name) VALUES (?)")->execute([$cat]);
        }
    }

    // Sync stock to agency items
    sync_stock_item($conn, $name, $batch_number, 'pharmacy');
    
    json_response(['success' => true]);
}

if ($uri === '/api/inventory/update' && $method === 'POST') {
    enforce_api_auth(['pharmacist']);
    $conn = get_db();
    $id = (int)($input['id'] ?? 0);
    if (!$id) {
        json_response(['success' => false, 'error' => 'Item ID is required for update'], 400);
    }
    $name = trim($input['name'] ?? '');
    $batch_number = trim($input['batch_number'] ?? '');
    $stock = (int)($input['stock'] ?? 0);
    $purchase_price = (float)($input['purchase_price'] ?? 0);
    $selling_price = (float)($input['selling_price'] ?? 0);
    $mrp = (float)($input['mrp'] ?? $selling_price);
    $mfg_date = $input['mfg_date'] ?? '';
    $expiry_date = $input['expiry_date'] ?? '';
    $item_code = $input['item_code'] ?? '';
    $category = $input['category'] ?? 'Tablet';
    $hsn_code = $input['hsn_code'] ?? '';
    $tablets_per_strip = (int)($input['tablets_per_strip'] ?? 0);
    $min_stock = (int)($input['min_stock'] ?? 0);
    $row_location = trim($input['row_location'] ?? '');
    $col_location = trim($input['col_location'] ?? '');
    $generic_name = trim($input['generic_name'] ?? '');
    if ($generic_name === '') {
        $generic_name = get_mapped_generic_name($conn, $name);
    }
    $brand_name = trim($input['brand_name'] ?? '');
    $agency_name = trim($input['agency_name'] ?? '');

    $stmt = $conn->prepare("UPDATE inventory SET 
        item_code = ?, name = ?, generic_name = ?, brand_name = ?, agency_name = ?,
        category = ?, hsn_code = ?, batch_number = ?,
        mfg_date = ?, expiry_date = ?, mrp = ?, purchase_price = ?, selling_price = ?,
        stock = ?, tablets_per_strip = ?, min_stock = ?, row_location = ?, col_location = ?
        WHERE id = ?");
    $stmt->execute([
        $item_code, $name, $generic_name, $brand_name, $agency_name,
        $category, $hsn_code, $batch_number,
        $mfg_date, $expiry_date, $mrp, $purchase_price, $selling_price,
        $stock, $tablets_per_strip, $min_stock, $row_location, $col_location, $id
    ]);

    // Auto-save new category to agency_categories
    if (!empty($category)) {
        $cat = trim($category);
        $checkCat = $conn->prepare("SELECT id FROM agency_categories WHERE name = ?");
        $checkCat->execute([$cat]);
        if (!$checkCat->fetch()) {
            $conn->prepare("INSERT INTO agency_categories (name) VALUES (?)")->execute([$cat]);
        }
    }

    // Sync stock to agency items
    sync_stock_item($conn, $name, $batch_number, 'pharmacy');

    json_response(['success' => true]);
}

if (preg_match('/^\/api\/inventory\/delete\/(\d+)$/', $uri, $matches)) {
    enforce_api_auth(['pharmacist']);
    $item_id = $matches[1];
    $conn = get_db();
    
    // Fetch item details before deleting
    $stmt_name = $conn->prepare("SELECT name, batch_number FROM inventory WHERE id = ?");
    $stmt_name->execute([$item_id]);
    $item_info = $stmt_name->fetch(PDO::FETCH_ASSOC);
    
    $stmt = $conn->prepare("DELETE FROM inventory WHERE id = ?");
    $stmt->execute([$item_id]);
    
    if ($item_info) {
        sync_stock_item($conn, $item_info['name'], $item_info['batch_number'], 'pharmacy');
    }
    
    json_response(['success' => true]);
}

// ═══════════════════════════════════════════
// API — MANAGEMENT / BALANCES
// ═══════════════════════════════════════════

if ($uri === '/api/update_balance' && $method === 'POST') {
    enforce_api_auth(['receptionist', 'pharmacist']);
    $conn = get_db();
    $amount_cleared = (float)($input['amount_cleared'] ?? 0);
    $payment_method = $input['payment_method'] ?? 'Cash';
    $upi_account = ($payment_method === 'UPI') ? ($input['upi_account'] ?? null) : null;
    
    $stmt = $conn->prepare("SELECT cash_amount, gpay_amount, paid_amount, balance_amount FROM prescriptions WHERE id=?");
    $stmt->execute([$input['presc_id']]);
    $presc = $stmt->fetch();
    
    if ($presc) {
        $new_paid = (float)$presc['paid_amount'] + $amount_cleared;
        $new_balance = max(0.0, (float)$presc['balance_amount'] - $amount_cleared);
        
        $new_cash = (float)$presc['cash_amount'];
        $new_gpay = (float)$presc['gpay_amount'];
        
        if ($payment_method === 'UPI') {
            $new_gpay += $amount_cleared;
            $stmt_update = $conn->prepare("UPDATE prescriptions SET paid_amount=?, balance_amount=?, gpay_amount=?, upi_account=? WHERE id=?");
            $stmt_update->execute([$new_paid, $new_balance, $new_gpay, $upi_account, $input['presc_id']]);
        } else {
            $new_cash += $amount_cleared;
            $stmt_update = $conn->prepare("UPDATE prescriptions SET paid_amount=?, balance_amount=?, cash_amount=? WHERE id=?");
            $stmt_update->execute([$new_paid, $new_balance, $new_cash, $input['presc_id']]);
        }
    } else {
        $stmt = $conn->prepare("UPDATE prescriptions SET paid_amount=?, balance_amount=? WHERE id=?");
        $stmt->execute([$input['paid_amount'] ?? 0, $input['balance_amount'] ?? 0, $input['presc_id']]);
    }
    
    json_response(['success' => true]);
}

if (preg_match('/^\/api\/clear_balances\/(.*)$/', $uri, $matches)) {
    enforce_api_auth(['receptionist', 'pharmacist']);
    $phone = urldecode($matches[1]);
    $conn = get_db();
    // $input is already parsed at the top of api.php (line 12)
    $payment_method = $input['payment_method'] ?? 'Cash';
    $upi_account = ($payment_method === 'UPI') ? ($input['upi_account'] ?? null) : null;
    $amount_to_clear = isset($input['amount']) ? (float)$input['amount'] : null;

    if ($amount_to_clear !== null && $amount_to_clear > 0) {
        $stmt = $conn->prepare("SELECT id, paid_amount, balance_amount, cash_amount, gpay_amount 
            FROM prescriptions 
            WHERE patient_id IN (SELECT id FROM patients WHERE phone = ?) 
            AND balance_amount > 0 
            ORDER BY id ASC");
        $stmt->execute([$phone]);
        $prescriptions = $stmt->fetchAll();

        $remaining = $amount_to_clear;
        $conn->beginTransaction();
        try {
            foreach ($prescriptions as $presc) {
                if ($remaining <= 0) break;
                $deduct = min($remaining, (float)$presc['balance_amount']);
                $new_balance = (float)$presc['balance_amount'] - $deduct;
                $new_paid = (float)$presc['paid_amount'] + $deduct;

                $new_cash = (float)$presc['cash_amount'];
                $new_gpay = (float)$presc['gpay_amount'];

                if ($payment_method === 'UPI') {
                    $new_gpay += $deduct;
                    $update = $conn->prepare("UPDATE prescriptions SET paid_amount = ?, balance_amount = ?, gpay_amount = ?, upi_account = ? WHERE id = ?");
                    $update->execute([$new_paid, $new_balance, $new_gpay, $upi_account, $presc['id']]);
                } else {
                    $new_cash += $deduct;
                    $update = $conn->prepare("UPDATE prescriptions SET paid_amount = ?, balance_amount = ?, cash_amount = ? WHERE id = ?");
                    $update->execute([$new_paid, $new_balance, $new_cash, $presc['id']]);
                }
                $remaining -= $deduct;
            }
            
            $current_presc_id = isset($input['current_presc_id']) ? $input['current_presc_id'] : null;
            if ($current_presc_id) {
                $conn->prepare("UPDATE prescriptions SET prev_balance_cleared = prev_balance_cleared + ? WHERE id = ?")
                     ->execute([$amount_to_clear, $current_presc_id]);
            }
            
            $conn->commit();
        } catch (\Exception $e) {
            $conn->rollBack();
            json_response(['success' => false, 'error' => $e->getMessage()], 500);
        }
    } else {
        $stmt = $conn->prepare("SELECT id, paid_amount, balance_amount, cash_amount, gpay_amount 
            FROM prescriptions 
            WHERE patient_id IN (SELECT id FROM patients WHERE phone = ?) 
            AND balance_amount > 0 
            ORDER BY id ASC");
        $stmt->execute([$phone]);
        $prescriptions = $stmt->fetchAll();

        $conn->beginTransaction();
        try {
            foreach ($prescriptions as $presc) {
                $deduct = (float)$presc['balance_amount'];
                $new_paid = (float)$presc['paid_amount'] + $deduct;
                
                $new_cash = (float)$presc['cash_amount'];
                $new_gpay = (float)$presc['gpay_amount'];

                if ($payment_method === 'UPI') {
                    $new_gpay += $deduct;
                    $update = $conn->prepare("UPDATE prescriptions SET paid_amount = ?, balance_amount = 0, gpay_amount = ?, upi_account = ? WHERE id = ?");
                    $update->execute([$new_paid, $new_gpay, $upi_account, $presc['id']]);
                } else {
                    $new_cash += $deduct;
                    $update = $conn->prepare("UPDATE prescriptions SET paid_amount = ?, balance_amount = 0, cash_amount = ? WHERE id = ?");
                    $update->execute([$new_paid, $new_cash, $presc['id']]);
                }
            }
            $conn->commit();
        } catch (\Exception $e) {
            $conn->rollBack();
            json_response(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
    json_response(['success' => true]);
}

if (preg_match('/^\/api\/patient_total_balance\/(.*)$/', $uri, $matches)) {
    enforce_api_auth(['receptionist', 'pharmacist']);
    $phone = urldecode($matches[1]);
    $conn = get_db();
    $stmt = $conn->prepare("SELECT SUM(balance_amount) as total_balance 
        FROM prescriptions 
        WHERE patient_id IN (SELECT id FROM patients WHERE phone = ?)");
    $stmt->execute([$phone]);
    $row = $stmt->fetch();
    json_response(['total_balance' => (float)($row['total_balance'] ?? 0)]);
}

if ($uri === '/api/scans/all' && $method === 'GET') {
    enforce_api_auth(['doctor']);
    $conn = get_db();
    $stmt = $conn->query("SELECT p.name as patient_name, p.patient_id, p.phone,
               pr.id as presc_id, pr.scan_fee, pr.scan_type, pr.scan_notes, 
               pr.balance_amount, pr.paid_amount, pr.created_at as scan_date
        FROM prescriptions pr
        JOIN patients p ON pr.patient_id = p.id
        WHERE pr.scan_fee > 0
        ORDER BY pr.created_at DESC");
    json_response($stmt->fetchAll());
}

if ($uri === '/api/patients/all' && $method === 'GET') {
    enforce_api_auth(['receptionist']);
    $conn = get_db();
    $stmt = $conn->query("SELECT p.name, p.phone, MAX(p.age) as age, MAX(p.gender) as gender, MAX(p.address) as address,
               COUNT(pr.id) as total_visits, 
               SUM(pr.paid_amount) as total_paid,
               SUM(pr.balance_amount) as total_balance,
               SUM(pr.paid_amount + pr.balance_amount) as total_bill,
               MAX(p.id) as id,
               MAX(p.patient_id) as patient_id,
               COALESCE(
                   (SELECT doctor_id FROM prescriptions WHERE patient_id IN (SELECT id FROM patients WHERE name=p.name AND phone=p.phone) ORDER BY created_at DESC LIMIT 1),
                   MAX(p.doctor_id)
               ) as doctor_id,
               COALESCE(
                   (SELECT doctor_name FROM prescriptions WHERE patient_id IN (SELECT id FROM patients WHERE name=p.name AND phone=p.phone) ORDER BY created_at DESC LIMIT 1),
                   MAX(p.doctor_name)
               ) as last_doctor_name,
               COALESCE(
                   (SELECT created_at FROM prescriptions WHERE patient_id IN (SELECT id FROM patients WHERE name=p.name AND phone=p.phone) ORDER BY created_at DESC LIMIT 1),
                   MAX(p.created_at)
               ) as created_at
        FROM patients p
        LEFT JOIN prescriptions pr ON p.id = pr.patient_id
        GROUP BY p.name, p.phone
        ORDER BY MAX(p.id) DESC");
    json_response($stmt->fetchAll());
}

if ($uri === '/api/patients/lookup_by_phone' && $method === 'GET') {
    enforce_api_auth(['receptionist', 'pharmacist']);
    $phone = $_GET['phone'] ?? '';
    if (!$phone) {
        json_response(['success' => false, 'error' => 'Phone number required']);
        exit;
    }
    $conn = get_db();
    
    // First try patients table
    $stmt = $conn->prepare("SELECT name FROM patients WHERE phone = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$phone]);
    $row = $stmt->fetch();
    if ($row) {
        json_response(['success' => true, 'name' => $row['name']]);
        exit;
    }
    
    // Fallback to direct_sales table
    $stmt = $conn->prepare("SELECT customer_name as name FROM direct_sales WHERE mobile_number = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$phone]);
    $row = $stmt->fetch();
    if ($row) {
        json_response(['success' => true, 'name' => $row['name']]);
        exit;
    }
    
    json_response(['success' => false, 'error' => 'Not found']);
    exit;
}

if (preg_match('/^\/api\/patient_history\/(.*)$/', $uri, $matches)) {
    enforce_api_auth(['receptionist', 'doctor', 'pharmacist']);
    $phone = urldecode($matches[1]);
    $name = $_GET['name'] ?? null;
    $conn = get_db();
    
    // Fetch from patients + prescriptions
    $query_presc = "SELECT p.*, 
               pr.id as presc_id, pr.doctor_name, pr.doctor_type, 
               pr.consultation_fee, pr.scan_fee, pr.injection_cost, pr.iv_cost, pr.upt_cost,
               pr.total_amount as med_amount, pr.paid_amount, pr.balance_amount,
               pr.cash_amount, pr.gpay_amount, pr.medicines, pr.diagnosis, pr.prescription_text,
               pr.injection_details, pr.iv_details, pr.discount_percent, pr.created_at as visit_date,
               pr.diagnosis_photo, pr.prescription_photo
        FROM patients p
        LEFT JOIN prescriptions pr ON p.id = pr.patient_id
        WHERE p.phone = ?";
    $params_presc = [$phone];
    if ($name) {
        $query_presc .= " AND p.name = ?";
        $params_presc[] = $name;
    }
    $query_presc .= " ORDER BY pr.created_at DESC";
    $stmt_presc = $conn->prepare($query_presc);
    $stmt_presc->execute($params_presc);
    $presc_rows = $stmt_presc->fetchAll();
    
    // Fetch from direct_sales
    $query_ds = "SELECT ds.*, 
               ds.id as presc_id, 'Pharmacy' as doctor_name, 'Direct Sale' as doctor_type, 
               0 as consultation_fee, 0 as scan_fee, ds.injection_cost, ds.iv_cost, ds.upt_cost,
               ds.total_amount as med_amount, ds.paid_amount, ds.balance_amount,
               ds.cash_amount, ds.gpay_amount, ds.medicines, '' as diagnosis, '' as prescription_text,
               ds.injection_details, ds.iv_details, ds.discount_percent, ds.created_at as visit_date,
               ds.customer_name as name, ds.mobile_number as phone
        FROM direct_sales ds
        WHERE ds.mobile_number = ?";
    $params_ds = [$phone];
    if ($name) {
        $query_ds .= " AND ds.customer_name = ?";
        $params_ds[] = $name;
    }
    $query_ds .= " ORDER BY ds.created_at DESC";
    $stmt_ds = $conn->prepare($query_ds);
    $stmt_ds->execute($params_ds);
    $ds_rows = $stmt_ds->fetchAll();

    $history = [];
    foreach ($presc_rows as $row) {
        if (!$row['presc_id']) continue; // Filter out empty left joins
        if (isset($row['medicines']) && is_string($row['medicines'])) {
            $row['medicines'] = json_decode($row['medicines'], true) ?: [];
        }
        if (!empty($row['diagnosis_photo']) && strpos($row['diagnosis_photo'], '/static/') !== 0) {
            $row['diagnosis_photo'] = get_supabase_signed_url('medical_records', basename($row['diagnosis_photo']));
        }
        if (!empty($row['prescription_photo']) && strpos($row['prescription_photo'], '/static/') !== 0) {
            $row['prescription_photo'] = get_supabase_signed_url('medical_records', basename($row['prescription_photo']));
        }
        $row['sale_type'] = 'prescription';
        $history[] = $row;
    }
    
    foreach ($ds_rows as $row) {
        if (isset($row['medicines']) && is_string($row['medicines'])) {
            $row['medicines'] = json_decode($row['medicines'], true) ?: [];
        }
        $row['sale_type'] = 'direct_sale';
        $history[] = $row;
    }
    
    // Sort combined history by date descending
    usort($history, function($a, $b) {
        return strtotime($b['visit_date']) - strtotime($a['visit_date']);
    });
    
    json_response($history);
}

// ═══════════════════════════════════════════
// API — MANAGEMENT ANALYTICS
// ═══════════════════════════════════════════

if ($uri === '/api/management/analytics' && $method === 'GET') {
    $period = $_GET['period'] ?? 'today';
    $start_date = $_GET['start_date'] ?? null;
    $end_date = $_GET['end_date'] ?? null;
    
    $date_filter = "DATE(created_at) = CURDATE()";
    if ($period === 'yesterday') {
        $date_filter = "DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
    } elseif ($period === 'weekly') {
        $date_filter = "DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
    } elseif ($period === 'monthly') {
        $date_filter = "DATE(created_at) >= DATE_FORMAT(CURDATE(), '%Y-%m-01')";
    } elseif ($period === 'custom' && $start_date && $end_date) {
        $date_filter = "date(created_at) >= '$start_date' AND date(created_at) <= '$end_date'";
    }

    $doctor_type = $_GET['doctor_type'] ?? 'all';
    $doc_filter = "";
    if ($doctor_type !== 'all' && in_array($doctor_type, ['Gent', 'Lady'])) {
        $doc_filter = " AND doctor_type = '$doctor_type' ";
    }

    $conn = get_db();
    
    // Total Patients
    $stmt = $conn->query("SELECT COUNT(*) FROM patients WHERE $date_filter $doc_filter");
    $total_patients = (int)$stmt->fetchColumn();
    
    // Financials
    $stmt = $conn->query("SELECT 
            SUM(total_amount) as med_revenue,
            SUM(cost_amount) as med_cost,
            SUM(consultation_fee) as doc_fee,
            SUM(scan_fee) as scan_fee,
            SUM(injection_cost) as inj_fee,
            SUM(iv_cost) as iv_fee,
            SUM(upt_cost) as upt_fee,
            SUM(cash_amount) as cash_total,
            SUM(gpay_amount) as gpay_total,
            SUM(phonepe_amount) as phonepe_total,
            SUM(paid_amount) as paid_amount,
            SUM(balance_amount) as total_balance,
            SUM(consultation_fee + scan_fee + injection_cost + iv_cost + upt_cost) as total_fees_all,
            SUM((total_amount + consultation_fee + scan_fee + injection_cost + iv_cost + upt_cost) * (IFNULL(discount_percent, 0) / 100.0)) as total_discount
        FROM prescriptions 
        WHERE status='dispensed' AND $date_filter $doc_filter");
    $pr = $stmt->fetch();
    
    $med_rev = (float)($pr['med_revenue'] ?? 0);
    $med_cost = (float)($pr['med_cost'] ?? 0);
    $med_profit = $med_rev - $med_cost;
    $doc_fee = (float)($pr['doc_fee'] ?? 0);
    $scan_fee = (float)($pr['scan_fee'] ?? 0);
    $other_fees = (float)($pr['inj_fee'] ?? 0) + (float)($pr['iv_fee'] ?? 0) + (float)($pr['upt_fee'] ?? 0);
    $total_discount = (float)($pr['total_discount'] ?? 0);
    
    // Direct Sales Stats
    $ds_rev = 0; $ds_cost = 0; $ds_profit = 0;
    $ds_pr = [];
    if ($doctor_type === 'all') {
        $ds_stmt = $conn->query("SELECT 
                SUM(total_amount) as ds_revenue,
                SUM(cost_amount) as ds_cost,
                SUM(cash_amount) as ds_cash,
                SUM(gpay_amount) as ds_gpay,
                SUM(phonepe_amount) as ds_phonepe,
                SUM(bank_amount) as ds_bank,
                SUM(paid_amount) as ds_paid,
                SUM(balance_amount) as ds_balance
            FROM direct_sales
            WHERE $date_filter");
        $ds_pr = $ds_stmt->fetch() ?: [];
        $ds_rev = (float)($ds_pr['ds_revenue'] ?? 0);
        $ds_cost = (float)($ds_pr['ds_cost'] ?? 0);
        $ds_profit = $ds_rev - $ds_cost;
    }

    $total_income = ($med_rev + $doc_fee + $scan_fee + $other_fees) - $total_discount + $ds_rev;
    $total_profit = $total_income - ($med_cost + $ds_cost);

    // Doctor Stats
    $stmt = $conn->query("SELECT 
            doctor_id,
            MAX(doctor_type) as doctor_type,
            MAX(doctor_name) as doctor_name,
            COUNT(id) as patients,
            SUM(consultation_fee) as doc_fee,
            SUM(total_amount) as med_revenue,
            SUM(scan_fee) as scan_fee,
            SUM(injection_cost + iv_cost + upt_cost) as other_fees,
            SUM(injection_cost) as inj_fee,
            SUM(iv_cost) as iv_fee,
            SUM(upt_cost) as upt_fee,
            SUM((total_amount + consultation_fee + scan_fee + injection_cost + iv_cost + upt_cost) * (IFNULL(discount_percent, 0) / 100.0)) as doc_discount
        FROM prescriptions 
        WHERE status='dispensed' AND $date_filter $doc_filter
        GROUP BY doctor_id");
    $doc_rows = $stmt->fetchAll();
    $doctor_stats = [];
    foreach ($doc_rows as $dr) {
        $did = $dr['doctor_id'] ?: 'Unknown';
        $dname = $dr['doctor_name'] ?: 'Unknown';
        $doc_total_rev = (float)$dr['doc_fee'] + (float)$dr['med_revenue'] + (float)$dr['scan_fee'] + (float)$dr['other_fees'] - (float)$dr['doc_discount'];
        
        $doctor_stats[$did] = [
            'doctor_name' => $dname,
            'doctor_type' => $dr['doctor_type'] ?: '',
            'patients' => (int)$dr['patients'],
            'doc_fee' => (float)$dr['doc_fee'],
            'med_revenue' => (float)$dr['med_revenue'],
            'scan_fee' => (float)$dr['scan_fee'],
            'other_fees' => (float)$dr['other_fees'],
            'inj_fee' => (float)$dr['inj_fee'],
            'iv_fee' => (float)$dr['iv_fee'],
            'upt_fee' => (float)$dr['upt_fee'],
            'total_revenue' => $doc_total_rev
        ];
    }

    // Fetch average purchase costs and tablets_per_strip from inventory
    $inv_stmt = $conn->query("SELECT name, MAX(tablets_per_strip) as tablets_per_strip, AVG(purchase_price) as avg_cost FROM inventory GROUP BY name");
    $inv_costs = [];
    $inv_tps = [];
    foreach ($inv_stmt->fetchAll() as $row) {
        $inv_costs[$row['name']] = (float)$row['avg_cost'];
        $inv_tps[$row['name']] = (int)$row['tablets_per_strip'];
    }

    $inv_batch_stmt = $conn->query("SELECT id, purchase_price, tablets_per_strip FROM inventory");
    $inv_batches = [];
    foreach ($inv_batch_stmt->fetchAll() as $row) {
        $inv_batches[$row['id']] = [
            'purchase_price' => (float)$row['purchase_price'],
            'tablets_per_strip' => (int)$row['tablets_per_strip']
        ];
    }

    // Average UPT cost
    $stmt = $conn->query("SELECT AVG(purchase_price) FROM inventory WHERE category='UPT Card'");
    $upt_avg_cost = (float)$stmt->fetchColumn();

    $inj_cost_calc = 0;
    $iv_cost_calc = 0;
    $upt_cost_calc = 0;

    // Top Medicines (now all medicines)
    $stmt = $conn->query("SELECT doctor_id, medicines, injection_details, iv_details, upt_cost, injection_cost, iv_cost FROM prescriptions WHERE status='dispensed' AND $date_filter $doc_filter");
    $med_rows = $stmt->fetchAll();
    $med_stats = [];
    $doc_med_stats = [];
    
    foreach ($med_rows as $row) {
        $did = $row['doctor_id'] ?: 'Unknown';
        if (!isset($doc_med_stats[$did])) $doc_med_stats[$did] = [];

        if ($row['injection_details']) {
            $name = trim($row['injection_details']);
            $cost = $inv_costs[$name] ?? 0;
            $inj_cost_calc += $cost;
            $rev = (float)$row['injection_cost'];
            
            if (!isset($med_stats[$name])) $med_stats[$name] = ['qty' => 0, 'purchased_qty' => 0, 'returned_qty' => 0, 'cost' => 0, 'revenue' => 0, 'profit' => 0];
            $med_stats[$name]['qty'] += 1;
            $med_stats[$name]['purchased_qty'] += 1;
            $med_stats[$name]['cost'] += $cost;
            $med_stats[$name]['revenue'] += $rev;
            $med_stats[$name]['profit'] += ($rev - $cost);
            
            if (!isset($doc_med_stats[$did][$name])) $doc_med_stats[$did][$name] = ['qty' => 0, 'purchased_qty' => 0, 'returned_qty' => 0, 'cost' => 0, 'revenue' => 0, 'profit' => 0];
            $doc_med_stats[$did][$name]['qty'] += 1;
            $doc_med_stats[$did][$name]['purchased_qty'] += 1;
            $doc_med_stats[$did][$name]['cost'] += $cost;
            $doc_med_stats[$did][$name]['revenue'] += $rev;
            $doc_med_stats[$did][$name]['profit'] += ($rev - $cost);
        }
        if ($row['iv_details']) {
            $name = trim($row['iv_details']);
            $cost = $inv_costs[$name] ?? 0;
            $iv_cost_calc += $cost;
            $rev = (float)$row['iv_cost'];
            
            if (!isset($med_stats[$name])) $med_stats[$name] = ['qty' => 0, 'purchased_qty' => 0, 'returned_qty' => 0, 'cost' => 0, 'revenue' => 0, 'profit' => 0];
            $med_stats[$name]['qty'] += 1;
            $med_stats[$name]['purchased_qty'] += 1;
            $med_stats[$name]['cost'] += $cost;
            $med_stats[$name]['revenue'] += $rev;
            $med_stats[$name]['profit'] += ($rev - $cost);
            
            if (!isset($doc_med_stats[$did][$name])) $doc_med_stats[$did][$name] = ['qty' => 0, 'purchased_qty' => 0, 'returned_qty' => 0, 'cost' => 0, 'revenue' => 0, 'profit' => 0];
            $doc_med_stats[$did][$name]['qty'] += 1;
            $doc_med_stats[$did][$name]['purchased_qty'] += 1;
            $doc_med_stats[$did][$name]['cost'] += $cost;
            $doc_med_stats[$did][$name]['revenue'] += $rev;
            $doc_med_stats[$did][$name]['profit'] += ($rev - $cost);
        }
        if ((float)$row['upt_cost'] > 0) {
            $cost = $upt_avg_cost;
            $upt_cost_calc += $cost;
            $rev = (float)$row['upt_cost'];
            $name = "UPT Card";
            
            if (!isset($med_stats[$name])) $med_stats[$name] = ['qty' => 0, 'purchased_qty' => 0, 'returned_qty' => 0, 'cost' => 0, 'revenue' => 0, 'profit' => 0];
            $med_stats[$name]['qty'] += 1;
            $med_stats[$name]['purchased_qty'] += 1;
            $med_stats[$name]['cost'] += $cost;
            $med_stats[$name]['revenue'] += $rev;
            $med_stats[$name]['profit'] += ($rev - $cost);
            
            if (!isset($doc_med_stats[$did][$name])) $doc_med_stats[$did][$name] = ['qty' => 0, 'purchased_qty' => 0, 'returned_qty' => 0, 'cost' => 0, 'revenue' => 0, 'profit' => 0];
            $doc_med_stats[$did][$name]['qty'] += 1;
            $doc_med_stats[$did][$name]['purchased_qty'] += 1;
            $doc_med_stats[$did][$name]['cost'] += $cost;
            $doc_med_stats[$did][$name]['revenue'] += $rev;
            $doc_med_stats[$did][$name]['profit'] += ($rev - $cost);
        }

        $meds = json_decode($row['medicines'], true) ?: [];
        foreach ($meds as $m) {
            $name = $m['name'] ?? 'Unknown';
            $p_qty = (float)($m['qty'] ?? 0);
            $r_qty = (float)($m['returned_qty'] ?? 0);
            $qty = $p_qty - $r_qty;
            $amt = (float)($m['amount'] ?? 0) - (float)($m['returned_amount'] ?? 0);
            
            $batch_id = $m['batch_id'] ?? null;
            if ($batch_id && isset($inv_batches[$batch_id])) {
                $unit_cost = $inv_batches[$batch_id]['purchase_price'];
                $tps = max(1, (int)$inv_batches[$batch_id]['tablets_per_strip']);
            } else {
                $unit_cost = $inv_costs[$name] ?? 0;
                $tps = max(1, (int)$inv_tps[$name] ?? 1);
            }
            $actual_unit_cost = $unit_cost / $tps;
            $total_cost = $actual_unit_cost * $qty;
            $profit = $amt - $total_cost;

            if (!isset($med_stats[$name])) $med_stats[$name] = ['qty' => 0, 'purchased_qty' => 0, 'returned_qty' => 0, 'cost' => 0, 'revenue' => 0, 'profit' => 0];
            $med_stats[$name]['qty'] += $qty;
            $med_stats[$name]['purchased_qty'] += $p_qty;
            $med_stats[$name]['returned_qty'] += $r_qty;
            $med_stats[$name]['cost'] += $total_cost;
            $med_stats[$name]['revenue'] += $amt;
            $med_stats[$name]['profit'] += $profit;
            
            if (!isset($doc_med_stats[$did][$name])) $doc_med_stats[$did][$name] = ['qty' => 0, 'purchased_qty' => 0, 'returned_qty' => 0, 'cost' => 0, 'revenue' => 0, 'profit' => 0];
            $doc_med_stats[$did][$name]['qty'] += $qty;
            $doc_med_stats[$did][$name]['purchased_qty'] += $p_qty;
            $doc_med_stats[$did][$name]['returned_qty'] += $r_qty;
            $doc_med_stats[$did][$name]['cost'] += $total_cost;
            $doc_med_stats[$did][$name]['revenue'] += $amt;
            $doc_med_stats[$did][$name]['profit'] += $profit;
        }
    }
    
    $all_medicines = [];
    $true_med_cost = 0;
    foreach ($med_stats as $name => $stats) {
        $all_medicines[] = [
            'name' => $name, 
            'qty' => $stats['qty'], 
            'purchased_qty' => $stats['purchased_qty'] ?? $stats['qty'],
            'returned_qty' => $stats['returned_qty'] ?? 0,
            'cost' => $stats['cost'],
            'revenue' => $stats['revenue'],
            'profit' => $stats['profit']
        ];
        $true_med_cost += $stats['cost'];
    }
    usort($all_medicines, function($a, $b) { return $b['revenue'] <=> $a['revenue']; });
    
    // Add per-doctor medicines to doctor_stats
    foreach ($doc_med_stats as $did => $d_stats) {
        $doc_all_meds = [];
        foreach ($d_stats as $name => $stats) {
            $doc_all_meds[] = [
                'name' => $name, 
                'qty' => $stats['qty'], 
                'purchased_qty' => $stats['purchased_qty'] ?? $stats['qty'],
                'returned_qty' => $stats['returned_qty'] ?? 0,
                'cost' => $stats['cost'],
                'revenue' => $stats['revenue'],
                'profit' => $stats['profit']
            ];
        }
        usort($doc_all_meds, function($a, $b) { return $b['revenue'] <=> $a['revenue']; });
        
        if (isset($doctor_stats[$did])) {
            $doctor_stats[$did]['all_medicines'] = $doc_all_meds;
        }
    }

    $inj_rev = (float)($pr['inj_fee'] ?? 0);
    $iv_rev = (float)($pr['iv_fee'] ?? 0);
    $upt_rev = (float)($pr['upt_fee'] ?? 0);
    
    // Consolidate Medicine + Injection + IV + UPT
    $medicine_revenue = $med_rev + $inj_rev + $iv_rev + $upt_rev;
    // med_cost already accurately includes all pharmacy costs from cost_amount!
    $medicine_cost = $med_cost; 
    $medicine_profit = $medicine_revenue - $medicine_cost;
    
    // Treatment Fee = UPT (Folded into medicine as requested)
    $treatment_revenue = 0;
    $treatment_profit = 0;

    $upi_collections = [];
    $get_acc_label = function($acc_name) {
        return trim($acc_name) ? trim($acc_name) : "Legacy UPI";
    };

    $stmt_upi = $conn->query("SELECT p.upi_account, u.account_name, SUM(p.gpay_amount) as amount, SUM(p.phonepe_amount) as phonepe 
        FROM prescriptions p 
        LEFT JOIN upi_accounts u ON p.upi_account = u.short_name 
        WHERE p.status='dispensed' AND $date_filter $doc_filter
        GROUP BY p.upi_account, u.account_name");
    foreach ($stmt_upi->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $label = $get_acc_label($row['account_name'] ?? '');
        if ($row['amount'] > 0) $upi_collections[$label] = ($upi_collections[$label] ?? 0) + $row['amount'];
        if ($row['phonepe'] > 0) $upi_collections[$label] = ($upi_collections[$label] ?? 0) + $row['phonepe'];
    }

    if ($doctor_type === 'all') {
        $stmt_upi = $conn->query("SELECT p.upi_account, u.account_name, SUM(p.gpay_amount) as amount, SUM(p.phonepe_amount) as phonepe 
            FROM direct_sales p 
            LEFT JOIN upi_accounts u ON p.upi_account = u.short_name 
            WHERE $date_filter 
            GROUP BY p.upi_account, u.account_name");
        foreach ($stmt_upi->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $label = $get_acc_label($row['account_name'] ?? '');
            if ($row['amount'] > 0) $upi_collections[$label] = ($upi_collections[$label] ?? 0) + $row['amount'];
            if ($row['phonepe'] > 0) $upi_collections[$label] = ($upi_collections[$label] ?? 0) + $row['phonepe'];
        }
    }

    $financial = [
        'Cash' => (float)($pr['cash_total'] ?? 0) + (float)($ds_pr['ds_cash'] ?? 0),
        'Bank Transfer' => (float)($ds_pr['ds_bank'] ?? 0)
    ];
    foreach ($upi_collections as $label => $amt) {
        if ($amt > 0) $financial[$label] = ($financial[$label] ?? 0) + $amt;
    }

    // Fetch individual scan records for details modal
    $pr_date_filter = str_replace('created_at', 'pr.created_at', $date_filter);
    $pr_doc_filter = str_replace('doctor_type', 'pr.doctor_type', $doc_filter);

    $scan_stmt = $conn->query("SELECT p.name as patient_name, p.patient_id, 
               pr.scan_type, pr.scan_notes, pr.scan_fee, pr.paid_amount, pr.balance_amount, pr.cash_amount, pr.gpay_amount, pr.phonepe_amount,
               pr.created_at as scan_date
        FROM prescriptions pr 
        JOIN patients p ON pr.patient_id = p.id
        WHERE pr.status='dispensed' AND pr.scan_fee > 0 AND $pr_date_filter $pr_doc_filter
        ORDER BY pr.id DESC");
    $all_scans = [];
    foreach ($scan_stmt->fetchAll(PDO::FETCH_ASSOC) as $srow) {
        $pmode = [];
        if (($srow['cash_amount'] ?? 0) > 0) $pmode[] = 'Cash';
        if (($srow['gpay_amount'] ?? 0) > 0 || ($srow['phonepe_amount'] ?? 0) > 0) $pmode[] = 'UPI';
        $all_scans[] = [
            'patient_name' => $srow['patient_name'],
            'patient_id' => $srow['patient_id'],
            'scan_type' => $srow['scan_type'] ?: 'General Scan',
            'scan_notes' => $srow['scan_notes'] ?? '',
            'scan_fee' => (float)$srow['scan_fee'],
            'scan_date' => $srow['scan_date'],
            'payment_mode' => empty($pmode) ? '-' : implode(', ', $pmode),
            'collected' => (float)$srow['paid_amount']
        ];
    }

    json_response([
        'all_scans' => $all_scans,
        'total_patients' => $total_patients,
        'total_income' => $total_income,
        'total_profit' => $total_profit,
        'total_discount' => $total_discount,
        'splits' => [
            'doctor_profit' => $doc_fee,
            'medicine_profit' => $medicine_profit,
            'true_med_profit' => $medicine_profit,
            'true_med_cost' => $medicine_cost,
            'medicine_revenue' => $medicine_revenue,
            'medicine_cost' => $medicine_cost,
            'scan_profit' => $scan_fee,
            'direct_medicine_revenue' => $ds_rev,
            'direct_medicine_cost' => $ds_cost,
            'direct_medicine_profit' => $ds_profit,
            'injection_profit' => 0,
            'iv_profit' => 0,
            'upt_profit' => 0,
            'treatment_revenue' => 0,
            'treatment_cost' => 0,
            'treatment_profit' => 0,
            'other_fees' => 0
        ],
        'payments' => [
            'cash_total' => (float)($pr['cash_total'] ?? 0) + (float)($ds_pr['ds_cash'] ?? 0),
            'total_paid' => (float)($pr['paid_amount'] ?? 0) + (float)($ds_pr['ds_paid'] ?? 0),
            'total_balance' => (float)($pr['total_balance'] ?? 0) + (float)($ds_pr['ds_balance'] ?? 0)
        ],
        'financial' => $financial,
        'doctor_stats' => $doctor_stats,
        'all_medicines' => $all_medicines,
        'total_fees_all' => (float)($pr['total_fees_all'] ?? 0)
    ]);
}

if ($uri === '/api/treatment/search' && $method === 'GET') {
    enforce_api_auth(['doctor']);
    $type = $_GET['type'] ?? '';
    $q = strtolower(trim($_GET['q'] ?? ''));
    if (!$q) json_response([]);
    
    $col = ($type === 'injection') ? 'injection_details' : 'iv_details';
    $conn = get_db();
    $stmt = $conn->prepare("SELECT DISTINCT $col FROM prescriptions WHERE LOWER($col) LIKE ? LIMIT 10");
    $stmt->execute(["%$q%"]);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    json_response($rows);
}

// ═══════════════════════════════════════════
// API — WHATSAPP LINK
// ═══════════════════════════════════════════

if (preg_match('/^\/api\/whatsapp_link\/direct\/(\d+)$/', $uri, $matches)) {
    enforce_api_auth(['pharmacist']);
    $sale_id = $matches[1];
    $conn = get_db();
    $stmt = $conn->prepare("SELECT * FROM direct_sales WHERE id=?");
    $stmt->execute([$sale_id]);
    $rec = $stmt->fetch();
    
    if (!$rec) json_response(['error' => 'Not found'], 404);

    $medicines = json_decode($rec['medicines'], true) ?: [];
    $medicine_total = (float)$rec['total_amount'];
    $injection_cost = (float)$rec['injection_cost'];
    $iv_cost = (float)$rec['iv_cost'];
    $upt_cost = (float)$rec['upt_cost'];
    $paid_amount = (float)$rec['paid_amount'];
    $balance_amount = (float)$rec['balance_amount'];
    $grand_total = $medicine_total + $injection_cost + $iv_cost + $upt_cost;

    $status_text = ($balance_amount > 0) ? "🛑 *Pending Amount: ₹" . number_format($balance_amount, 2) . " to be paid later*" : (($paid_amount > $grand_total) ? "💰 *Return Amount: ₹" . number_format($paid_amount - $grand_total, 2) . "*" : "✅ *Payment Completed*");

    $msg = "🏥 *Crescent Clinic and Scans*\n➖➖➖➖➖➖➖➖➖➖➖➖➖➖➖\n"
         . "*Patient:* {$rec['customer_name']}\n*Phone:* {$rec['mobile_number']}\n*Token:* DS-{$rec['id']}\n*Doctor:* Direct Medicine Sales\n"
         . "*Date/Time:* " . date('d-m-Y h:i A', strtotime($rec['created_at'])) . "\n"
         . "➖➖➖➖➖➖➖➖➖➖➖➖➖➖➖\n";
    
    if ($medicines) {
        $msg .= "*Medicines:*\n";
        foreach ($medicines as $i => $m) {
            $msg .= "  " . ($i + 1) . ". {$m['name']} x{$m['qty']} = ₹" . number_format($m['amount'], 2) . "\n";
        }
        $msg .= "\n*Medicines Total: ₹" . number_format($medicine_total, 2) . "*\n";
    }

    if ($injection_cost > 0) $msg .= "*Injection Fee: ₹" . number_format($injection_cost, 2) . "*\n";
    if ($iv_cost > 0) $msg .= "*IV Fee: ₹" . number_format($iv_cost, 2) . "*\n";
    if ($upt_cost > 0) $msg .= "*UPT Card Fee: ₹" . number_format($upt_cost, 2) . "*\n";

    $msg .= "➖➖➖➖➖➖➖➖➖➖➖➖➖➖➖\n*Grand Total: ₹" . number_format($grand_total, 2) . "*\n"
         . "*Paid via Cash: ₹" . number_format($rec['cash_amount'], 2) . "*\n"
         . "*Paid via GPay: ₹" . number_format($rec['gpay_amount'], 2) . "*\n"
         . "*Paid via PhonePe: ₹" . number_format($rec['phonepe_amount'] ?? 0, 2) . "*\n"
         . "*Total Paid: ₹" . number_format($paid_amount, 2) . "*\n\n"
         . $status_text . "\n"
         . "➖➖➖➖➖➖➖➖➖➖➖➖➖➖➖\nThank you!";

    $rec['doctor_name'] = 'Direct Medicine Sales';
    $rec['doctor_type'] = 'Pharmacy';
    $rec['medicines'] = $medicines;
    $rec['name'] = $rec['customer_name'];
    $rec['phone'] = $rec['mobile_number'];
    $rec['token'] = 'DS-' . $rec['id'];

    $phone = preg_replace('/[^0-9]/', '', $rec['phone']);
    if (strlen($phone) === 10) {
        $phone = '91' . $phone;
    }
    $link = "https://wa.me/$phone?text=" . urlencode($msg);
    json_response(['link' => $link, 'data' => $rec]);
}

if (preg_match('/^\/api\/whatsapp_link\/(\d+)$/', $uri, $matches)) {
    enforce_api_auth(['receptionist', 'pharmacist']);
    $presc_id = $matches[1];
    $conn = get_db();
    $stmt = $conn->prepare("SELECT p.*, pr.diagnosis, pr.prescription_text, pr.medicines,
               pr.total_amount, pr.consultation_fee, pr.scan_fee,
               pr.paid_amount, pr.balance_amount, pr.injection_details, pr.iv_details,
               pr.injection_cost, pr.iv_cost, pr.upt_cost, pr.cash_amount, pr.gpay_amount,
               pr.discount_percent,
               pr.doctor_name as presc_doctor, pr.doctor_type as presc_doctor_type, p.completed_at
        FROM prescriptions pr JOIN patients p ON pr.patient_id=p.id
        WHERE pr.id=?");
    $stmt->execute([$presc_id]);
    $rec = $stmt->fetch();
    
    if (!$rec) json_response(['error' => 'Not found'], 404);

    $medicines = json_decode($rec['medicines'], true) ?: [];
    $medicine_total = (float)$rec['total_amount'];
    $consultation_fee = (float)$rec['consultation_fee'];
    $scan_fee = (float)$rec['scan_fee'];
    $injection_cost = (float)$rec['injection_cost'];
    $iv_cost = (float)$rec['iv_cost'];
    $upt_cost = (float)$rec['upt_cost'];
    $paid_amount = (float)$rec['paid_amount'];
    $balance_amount = (float)$rec['balance_amount'];
    $grand_total = $medicine_total + $consultation_fee + $scan_fee + $injection_cost + $iv_cost + $upt_cost;

    $status_text = ($balance_amount > 0) ? "🛑 *Pending Amount: ₹" . number_format($balance_amount, 2) . " to be paid later*" : (($paid_amount > $grand_total) ? "💰 *Return Amount: ₹" . number_format($paid_amount - $grand_total, 2) . "*" : "✅ *Payment Completed*");

    $msg = "🏥 *Crescent Clinic and Scans*\n➖➖➖➖➖➖➖➖➖➖➖➖➖➖➖\n"
         . "*Patient:* {$rec['name']}\n*Phone:* {$rec['phone']}\n*Token:* {$rec['token']}\n*Doctor:* " . format_doctor_name($rec['presc_doctor'], $rec['presc_doctor_type']) . "\n"
         . "*Patient In:* " . date('d-m-Y h:i A', strtotime($rec['created_at'])) . "\n"
         . "*Patient Out:* " . date('d-m-Y h:i A', strtotime($rec['completed_at'])) . "\n"
         . "➖➖➖➖➖➖➖➖➖➖➖➖➖➖➖\n*Diagnosis:* " . ($rec['diagnosis'] ?: 'N/A') . "\n";
    
    if ($rec['prescription_text']) $msg .= "*Prescription:* {$rec['prescription_text']}\n";
    if ($rec['injection_details']) $msg .= "*Injection:* {$rec['injection_details']}\n";
    if ($rec['iv_details']) $msg .= "*IV Fluid:* {$rec['iv_details']}\n";
    
    if ($medicines) {
        $msg .= "➖➖➖➖➖➖➖➖➖➖➖➖➖➖➖\n*Medicines:*\n";
        foreach ($medicines as $i => $m) {
            $msg .= "  " . ($i + 1) . ". {$m['name']} x{$m['qty']} = ₹" . number_format($m['amount'], 2) . "\n";
        }
        $msg .= "\n*Medicines Total: ₹" . number_format($medicine_total, 2) . "*\n";
    }

    $msg .= "*Doctor Fee: ₹" . number_format($consultation_fee, 2) . "*\n";
    if ($scan_fee > 0) $msg .= "*Scan Fee: ₹" . number_format($scan_fee, 2) . "*\n";
    if ($injection_cost > 0) $msg .= "*Injection Fee: ₹" . number_format($injection_cost, 2) . "*\n";
    if ($iv_cost > 0) $msg .= "*IV Fee: ₹" . number_format($iv_cost, 2) . "*\n";
    if ($upt_cost > 0) $msg .= "*UPT Card Fee: ₹" . number_format($upt_cost, 2) . "*\n";
    
    $rec['prev_balance_info'] = get_prev_balance_info($conn, $rec['patient_id'], $presc_id);
    $prev_info = $rec['prev_balance_info'];

    $msg .= "➖➖➖➖➖➖➖➖➖➖➖➖➖➖➖\n*Grand Total: ₹" . number_format($grand_total, 2) . "*\n"
         . "*Paid via Cash: ₹" . number_format($rec['cash_amount'], 2) . "*\n"
         . "*Paid via GPay: ₹" . number_format($rec['gpay_amount'], 2) . "*\n"
         . "*Paid via PhonePe: ₹" . number_format($rec['phonepe_amount'] ?? 0, 2) . "*\n"
         . "*Total Paid: ₹" . number_format($paid_amount, 2) . "*\n\n"
         . $status_text . "\n";

    if ($prev_info) {
        $msg .= "➖➖➖➖➖➖➖➖➖➖➖➖➖➖➖\n";
        if ($prev_info['cleared'] > 0) {
            $msg .= "*Previous Visit Date:* " . ($prev_info['date'] ?: 'N/A') . "\n";
            $msg .= "*Original Pending Balance: ₹" . number_format($prev_info['original'], 2) . "*\n";
            $msg .= "*Amount Cleared: ₹" . number_format($prev_info['cleared'], 2) . "*\n";
            $msg .= "*Remaining Pending Balance: ₹" . number_format($prev_info['remaining'], 2) . "*\n";
        } else if ($prev_info['remaining'] > 0) {
            $msg .= "*Previous Visit Pending Balance: ₹" . number_format($prev_info['remaining'], 2) . "*\n";
            $msg .= "*Previous Visit Date:* " . ($prev_info['date'] ?: 'N/A') . "\n";
            $msg .= "_You visited the clinic on " . ($prev_info['date'] ?: 'N/A') . " and an outstanding balance of ₹" . number_format($prev_info['remaining'], 2) . " is still pending._\n";
        }
    }
    $msg .= "➖➖➖➖➖➖➖➖➖➖➖➖➖➖➖\nThank you!";

    $rec['doctor_name'] = $rec['presc_doctor'];
    $rec['doctor_type'] = $rec['presc_doctor_type'];
    $rec['medicines'] = $medicines; // decoded

    $phone = preg_replace('/[^0-9]/', '', $rec['phone']);
    if (strlen($phone) === 10) {
        $phone = '91' . $phone;
    }
    $link = "https://wa.me/$phone?text=" . urlencode($msg);
    json_response(['link' => $link, 'data' => $rec]);
}

// ═══════════════════════════════════════════
// API — PDF GENERATION
// ═══════════════════════════════════════════

if (preg_match('/^\/api\/generate_pdf\/(\d+)$/', $uri, $matches)) {
    enforce_api_auth(['doctor', 'pharmacist']);
    require_once __DIR__ . '/../pdf_gen.php';
    $presc_id = $matches[1];
    $pdf_content = generate_prescription_pdf($presc_id);
    
    if ($pdf_content) {
        if (ob_get_length()) ob_end_clean();
        
        $conn = get_db();
        $stmt = $conn->prepare("SELECT name, token FROM patients p JOIN prescriptions pr ON p.id=pr.patient_id WHERE pr.id=?");
        $stmt->execute([$presc_id]);
        $info = $stmt->fetch();
        
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="prescription_' . $info['name'] . '_' . $info['token'] . '.pdf"');
        header('Content-Length: ' . strlen($pdf_content));
        echo $pdf_content;
    } else {
        json_response(['error' => 'Not found'], 404);
    }
    exit;
}

// ═══════════════════════════════════════════
// API — MASTER CONTROL (MANAGEMENT)
// ═══════════════════════════════════════════

if ($uri === '/api/management/verify_security' && $method === 'POST') {
    enforce_admin();
    $conn = get_db();
    $password = $input['password'] ?? '';
    
    $stmt = $conn->prepare("SELECT id, admin_security_password FROM users WHERE username = ? AND role = 'management'");
    $stmt->execute([$_SESSION['username'] ?? '']);
    $user = $stmt->fetch();
    
    if ($user && $user['admin_security_password']) {
        if (strpos($user['admin_security_password'], '$2') === 0) {
            if (password_verify($password, $user['admin_security_password'])) {
                json_response(['success' => true]);
            }
        } else {
            if ($user['admin_security_password'] === $password) {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $upd = $conn->prepare("UPDATE users SET admin_security_password = ? WHERE id = ?");
                $upd->execute([$hash, $user['id']]);
                json_response(['success' => true]);
            }
        }
    }
    json_response(['success' => false, 'error' => 'Incorrect Admin Security Password'], 403);
}

if ($uri === '/api/management/users' && $method === 'GET') {
    enforce_admin();
    $conn = get_db();
    $stmt = $conn->query("SELECT id, username, password, role, doctor_type, display_name, details, specialization, photo_path, token_prefix, is_active, doctor_registration_number, admin_security_password FROM users ORDER BY role, display_name");
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        if (!empty($row['photo_path']) && strpos($row['photo_path'], '/static/') !== 0) {
            $row['photo_path'] = get_supabase_signed_url('profiles', basename($row['photo_path']));
        }
    }
    json_response(['success' => true, 'users' => $rows]);
}

if ($uri === '/api/management/user/save' && $method === 'POST') {
    enforce_admin();
    $id = $_POST['id'] ?? null;
    $username = $_POST['username'];
    $password_raw = $_POST['password'] ?? '';
    $role = $_POST['role'];
    
    // Normalize doctor_type and token_prefix
    $doctor_type = $_POST['doctor_type'] ?? null;
    if ($doctor_type === 'undefined' || $doctor_type === '') {
        $doctor_type = null;
    }
    
    $display_name = $_POST['display_name'];
    $details = $_POST['details'] ?? '';
    $specialization = $_POST['specialization'] ?? '';
    
    $token_prefix = $_POST['token_prefix'] ?? null;
    if ($token_prefix === 'undefined' || $token_prefix === '') {
        $token_prefix = null;
    }
    
    $is_active = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
    
    $doctor_registration_number = $_POST['doctor_registration_number'] ?? null;
    if ($doctor_registration_number === 'undefined' || $doctor_registration_number === '') {
        $doctor_registration_number = null;
    }
    
    $admin_sec_raw = $_POST['admin_security_password'] ?? '';

    $conn = get_db();
    
    // Retrieve existing credentials if editing
    $db_password = null;
    $db_admin_security_password = '123';
    if ($id) {
        $stmt = $conn->prepare("SELECT password, admin_security_password FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $existing_creds = $stmt->fetch();
        if ($existing_creds) {
            $db_password = $existing_creds['password'];
            $db_admin_security_password = $existing_creds['admin_security_password'];
        }
    }

    // Process normal password
    if ($password_raw !== '' && $password_raw !== 'undefined') {
        $password = $password_raw;
    } else {
        $password = $db_password;
    }

    // Process admin security password
    if ($role === 'management') {
        if ($admin_sec_raw !== '' && $admin_sec_raw !== 'undefined') {
            $admin_security_password = $admin_sec_raw;
        } else {
            $admin_security_password = $db_admin_security_password;
        }
    } else {
        // Keep existing or default to '123' if not set
        $admin_security_password = $db_admin_security_password ?: '123';
    }
    
    $photo_path = $_POST['existing_photo'] ?? null;
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
        $filename = uniqid('user_') . '.' . $ext;
        $mime_type = mime_content_type($_FILES['photo']['tmp_name']);
        upload_to_supabase($_FILES['photo']['tmp_name'], 'profiles', $filename, $mime_type);
        $photo_path = 'profiles/' . $filename;
    }
    
    // Check for duplicate username
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $existing = $stmt->fetch();
    if ($existing && (!$id || $existing['id'] != $id)) {
        json_response(['success' => false, 'error' => 'Username already exists. Please choose a different username.'], 400);
    }
    
    // Check for duplicate display name
    $stmt = $conn->prepare("SELECT id FROM users WHERE display_name = ? AND role = ?");
    $stmt->execute([$display_name, $role]);
    $existing_name = $stmt->fetch();
    if ($existing_name && (!$id || $existing_name['id'] != $id)) {
        json_response(['success' => false, 'error' => 'A user with this Display Name already exists in this role. Please use a unique name (e.g. ' . $display_name . ' 2).'], 400);
    }

    if ($id) {
        // Update user
        $stmt = $conn->prepare("UPDATE users SET username=?, password=?, role=?, doctor_type=?, display_name=?, details=?, specialization=?, photo_path=?, token_prefix=?, is_active=?, admin_security_password=?, doctor_registration_number=? WHERE id=?");
        $stmt->execute([$username, $password, $role, $doctor_type, $display_name, $details, $specialization, $photo_path, $token_prefix, $is_active, $admin_security_password, $doctor_registration_number, $id]);
    } else {
        if (!$password) {
            json_response(['success' => false, 'error' => 'Password is required for new users.'], 400);
        }
        $stmt = $conn->prepare("INSERT INTO users (username, password, role, doctor_type, display_name, details, specialization, photo_path, token_prefix, is_active, admin_security_password, doctor_registration_number) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$username, $password, $role, $doctor_type, $display_name, $details, $specialization, $photo_path, $token_prefix, $is_active, $admin_security_password, $doctor_registration_number]);
    }
    json_response(['success' => true]);
}

if (preg_match('/^\/api\/management\/user\/delete\/(\d+)$/', $uri, $matches)) {
    enforce_admin();
    $uid = $matches[1];
    $conn = get_db();
    $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$uid]);
    json_response(['success' => true]);
}

if ($uri === '/api/management/patient/update' && $method === 'POST') {
    enforce_admin();
    $conn = get_db();

    $stmt = $conn->prepare("SELECT patient_id FROM patients WHERE id = ?");
    $stmt->execute([$input['id']]);
    $patient_id = $stmt->fetchColumn();

    if ($patient_id) {
        $stmt = $conn->prepare("UPDATE patients SET name=?, age=?, gender=?, phone=?, address=? WHERE patient_id=?");
        $stmt->execute([
            $input['name'], $input['age'], $input['gender'], $input['phone'],
            $input['address'], $patient_id
        ]);
    } else {
        $stmt = $conn->prepare("UPDATE patients SET name=?, age=?, gender=?, phone=?, address=? WHERE id=?");
        $stmt->execute([
            $input['name'], $input['age'], $input['gender'], $input['phone'],
            $input['address'], $input['id']
        ]);
    }
    json_response(['success' => true]);
}

if (preg_match('/^\/api\/management\/patient\/delete\/(\d+)$/', $uri, $matches)) {
    enforce_admin();
    $pid = $matches[1];
    $conn = get_db();
    
    $stmt = $conn->prepare("SELECT patient_id FROM patients WHERE id = ?");
    $stmt->execute([$pid]);
    $patient_id = $stmt->fetchColumn();

    if ($patient_id) {
        $stmt = $conn->prepare("DELETE FROM prescriptions WHERE patient_id IN (SELECT id FROM patients WHERE patient_id = ?)");
        $stmt->execute([$patient_id]);
        $stmt = $conn->prepare("DELETE FROM patients WHERE patient_id = ?");
        $stmt->execute([$patient_id]);
    } else {
        $stmt = $conn->prepare("DELETE FROM prescriptions WHERE patient_id = ?");
        $stmt->execute([$pid]);
        $stmt = $conn->prepare("DELETE FROM patients WHERE id = ?");
        $stmt->execute([$pid]);
    }
    json_response(['success' => true]);
}

// ═══════════════════════════════════════════
// API — AGENCY INVENTORY
// ═══════════════════════════════════════════

// Dashboard
if ($uri === '/api/agency/dashboard' && $method === 'GET') {
    enforce_api_auth(['pharmacist']);
    $conn = get_db();
    $total_items = $conn->query("SELECT COUNT(*) FROM agency_items")->fetchColumn();
    $total_qty = $conn->query("SELECT SUM(stock) FROM agency_items")->fetchColumn() ?: 0;
    
    // Total Stock Value = sum(stock * purchase_price)
    $total_value = $conn->query("SELECT SUM(stock * purchase_price) FROM agency_items")->fetchColumn() ?: 0;
    $low_stock = $conn->query("SELECT COUNT(*) FROM agency_items WHERE stock > 0 AND stock <= min_stock")->fetchColumn();
    $out_of_stock = $conn->query("SELECT COUNT(*) FROM agency_items WHERE stock <= 0")->fetchColumn();
    
    // Recent purchases (last 5)
    $stmt = $conn->query("SELECT p.*, s.name as supplier_name FROM agency_purchases p LEFT JOIN agency_suppliers s ON p.supplier_id = s.id ORDER BY p.created_at DESC LIMIT 5");
    $recent_purchases = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Recent transactions (last 5 transfers + adjustments)
    $stmt = $conn->query("
        SELECT 'Transfer' as type, t.quantity, t.created_at as created_at, i.item_name 
        FROM agency_stock_transfers t JOIN agency_items i ON t.item_id = i.id 
        UNION ALL 
        SELECT 'Adjustment' as type, a.quantity, a.created_at as created_at, i.item_name 
        FROM agency_stock_adjustments a JOIN agency_items i ON a.item_id = i.id 
        ORDER BY created_at DESC LIMIT 5
    ");
    $recent_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    json_response([
        'total_items' => $total_items,
        'total_stock_qty' => $total_qty,
        'total_stock_value' => $total_value,
        'low_stock_items' => $low_stock,
        'out_of_stock_items' => $out_of_stock,
        'recent_purchases' => $recent_purchases,
        'recent_transactions' => $recent_transactions
    ]);
}

// Categories
if ($uri === '/api/agency/categories' && $method === 'GET') {
    enforce_api_auth(['pharmacist']);
    $conn = get_db();
    $stmt = $conn->query("SELECT * FROM agency_categories ORDER BY name ASC");
    json_response($stmt->fetchAll(PDO::FETCH_ASSOC));
}

if ($uri === '/api/agency/categories/add' && $method === 'POST') {
    enforce_api_auth(['pharmacist']);
    $conn = get_db();
    if (empty($input['id'])) {
        $stmt = $conn->prepare("INSERT INTO agency_categories (name) VALUES (?)");
        $stmt->execute([$input['name']]);
    } else {
        $stmt = $conn->prepare("UPDATE agency_categories SET name=? WHERE id=?");
        $stmt->execute([$input['name'], $input['id']]);
    }
    json_response(['success' => true]);
}

if (preg_match('/^\/api\/agency\/categories\/delete\/(\d+)$/', $uri, $matches) && $method === 'DELETE') {
    enforce_api_auth(['pharmacist']);
    $conn = get_db();
    $stmt = $conn->prepare("DELETE FROM agency_categories WHERE id=?");
    $stmt->execute([$matches[1]]);
    json_response(['success' => true]);
}

// Suppliers
if ($uri === '/api/agency/suppliers' && $method === 'GET') {
    enforce_api_auth(['pharmacist']);
    $conn = get_db();
    $stmt = $conn->query("
        SELECT s.*, 
               COALESCE((SELECT SUM(grand_total) FROM agency_purchases WHERE supplier_id = s.id), 0) as calc_total_purchase,
               COALESCE((SELECT SUM(paid_amount) FROM agency_purchases WHERE supplier_id = s.id), 0) as calc_paid_amount,
               COALESCE((SELECT SUM(balance_amount) FROM agency_purchases WHERE supplier_id = s.id), 0) as calc_pending_balance
        FROM agency_suppliers s 
        ORDER BY s.name ASC
    ");
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($suppliers as &$s) {
        $s['total_purchase'] = $s['calc_total_purchase'];
        $s['paid_amount'] = $s['calc_paid_amount'];
        $s['pending_balance'] = $s['calc_pending_balance'];
        
        if ($s['total_purchase'] > 0 && $s['pending_balance'] <= 0) {
            $s['payment_status'] = 'Paid';
        } else if ($s['paid_amount'] > 0) {
            $s['payment_status'] = 'Partially Paid';
        } else {
            $s['payment_status'] = 'Not Paid';
        }
    }
    
    json_response($suppliers);
}

if ($uri === '/api/agency/suppliers/add' && $method === 'POST') {
    enforce_api_auth(['pharmacist']);
    $data = $input;
    $conn = get_db();
    try {
        if (empty($data['id'])) {
            $stmt = $conn->prepare("INSERT INTO agency_suppliers (name, company_name, phone, whatsapp, email, address, city, state, pincode, gst_number, dl_number, payment_type, status, total_purchase, payment_status, paid_amount, pending_balance, outstanding_balance, cash_amount, gpay_amount) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$data['name'], $data['company_name']??'', $data['phone']??'', $data['whatsapp']??'', $data['email']??'', $data['address']??'', $data['city']??'', $data['state']??'', $data['pincode']??'', $data['gst_number']??'', $data['dl_number']??'', $data['payment_type']??'', $data['status']??'Active', $data['total_purchase']??0, $data['payment_status']??'Not Paid', $data['paid_amount']??0, $data['pending_balance']??0, $data['outstanding_balance']??0, $data['cash_amount']??0, $data['gpay_amount']??0]);
            $supplier_id = $conn->lastInsertId();
            $conn->prepare("INSERT INTO agency_audit_trail (user_id, action, table_name, record_id, details) VALUES (?, ?, ?, ?, ?)")->execute([$_SESSION['user_id'] ?? 0, 'CREATE', 'agency_suppliers', $supplier_id, 'Added new supplier']);
        } else {
            $stmt = $conn->prepare("UPDATE agency_suppliers SET name=?, company_name=?, phone=?, whatsapp=?, email=?, address=?, city=?, state=?, pincode=?, gst_number=?, dl_number=?, payment_type=?, status=?, total_purchase=?, payment_status=?, paid_amount=?, pending_balance=?, outstanding_balance=?, cash_amount=?, gpay_amount=? WHERE id=?");
            $stmt->execute([$data['name'], $data['company_name']??'', $data['phone']??'', $data['whatsapp']??'', $data['email']??'', $data['address']??'', $data['city']??'', $data['state']??'', $data['pincode']??'', $data['gst_number']??'', $data['dl_number']??'', $data['payment_type']??'', $data['status']??'Active', $data['total_purchase']??0, $data['payment_status']??'Not Paid', $data['paid_amount']??0, $data['pending_balance']??0, $data['outstanding_balance']??0, $data['cash_amount']??0, $data['gpay_amount']??0, $data['id']]);
            $conn->prepare("INSERT INTO agency_audit_trail (user_id, action, table_name, record_id, details) VALUES (?, ?, ?, ?, ?)")->execute([$_SESSION['user_id'] ?? 0, 'UPDATE', 'agency_suppliers', $data['id'], 'Updated supplier details']);
        }
        json_response(['success' => true]);
    } catch (Exception $e) {
        json_response(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

if (preg_match('/^\/api\/agency\/suppliers\/delete\/(\d+)$/', $uri, $matches) && $method === 'DELETE') {
    enforce_api_auth(['pharmacist']);
    $conn = get_db();
    $stmt = $conn->prepare("DELETE FROM agency_suppliers WHERE id=?");
    $stmt->execute([$matches[1]]);
    json_response(['success' => true]);
}

// Items Master
if ($uri === '/api/agency/items' && $method === 'GET') {
    enforce_api_auth(['pharmacist']);
    $conn = get_db();
    $stmt = $conn->query("SELECT a.*, s.name as agency_name FROM agency_items a LEFT JOIN agency_suppliers s ON a.supplier_id = s.id ORDER BY a.item_name ASC");
    json_response($stmt->fetchAll(PDO::FETCH_ASSOC));
}

if (preg_match('/^\/api\/agency\/supplier\/details\/(\d+)$/', $uri, $matches) && $method === 'GET') {
    enforce_api_auth(['pharmacist']);
    $supplier_id = $matches[1];
    $conn = get_db();
    
    $stmt = $conn->prepare("
        SELECT s.*, 
               COALESCE((SELECT SUM(grand_total) FROM agency_purchases WHERE supplier_id = s.id), 0) as calc_total_purchase,
               COALESCE((SELECT SUM(paid_amount) FROM agency_purchases WHERE supplier_id = s.id), 0) as calc_paid_amount,
               COALESCE((SELECT SUM(balance_amount) FROM agency_purchases WHERE supplier_id = s.id), 0) as calc_pending_balance
        FROM agency_suppliers s WHERE s.id = ?
    ");
    $stmt->execute([$supplier_id]);
    $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$supplier) {
        json_response(['error' => 'Supplier not found'], 404);
    }
    
    $supplier['total_purchase'] = $supplier['calc_total_purchase'];
    $supplier['paid_amount'] = $supplier['calc_paid_amount'];
    $supplier['pending_balance'] = $supplier['calc_pending_balance'];
    
    // Fetch all purchases for the supplier to calculate aggregate payment totals dynamically
    $stmt_all = $conn->prepare("SELECT grand_total, paid_amount, payment_status, payment_mode, cash_amount, gpay_amount, phonepe_amount, bank_amount FROM agency_purchases WHERE supplier_id = ?");
    $stmt_all->execute([$supplier_id]);
    $all_purchases = $stmt_all->fetchAll(PDO::FETCH_ASSOC);
    
    $total_cash = 0.0;
    $total_gpay = 0.0;
    $total_phonepe = 0.0;
    $total_bank = 0.0;
    
    foreach ($all_purchases as $p) {
        if ($p['payment_status'] === 'Completed') {
            $c = (float)($p['cash_amount'] ?? 0);
            $g = (float)($p['gpay_amount'] ?? 0);
            $ph = (float)($p['phonepe_amount'] ?? 0);
            $b = (float)($p['bank_amount'] ?? 0);
            
            if ($c > 0 || $g > 0 || $ph > 0 || $b > 0) {
                $total_cash += $c;
                $total_gpay += $g;
                $total_phonepe += $ph;
                $total_bank += $b;
            } else {
                $amt = (float)($p['paid_amount'] > 0 ? $p['paid_amount'] : $p['grand_total']);
                $mode = trim(strtoupper($p['payment_mode'] ?? ''));
                
                if (strpos($mode, 'CASH') !== false) {
                    $total_cash += $amt;
                } else if (strpos($mode, 'GPAY') !== false || strpos($mode, 'GOOGLE') !== false) {
                    $total_gpay += $amt;
                } else if (strpos($mode, 'PHONEPE') !== false || strpos($mode, 'PHONE PE') !== false) {
                    $total_phonepe += $amt;
                } else if (strpos($mode, 'BANK') !== false || strpos($mode, 'TRANSFER') !== false) {
                    $total_bank += $amt;
                } else {
                    $total_cash += $amt;
                }
            }
        }
    }
    
    $supplier['cash_amount'] = $total_cash;
    $supplier['gpay_amount'] = $total_gpay;
    $supplier['phonepe_amount'] = $total_phonepe;
    $supplier['bank_amount'] = $total_bank;
    
    $stmt = $conn->prepare("SELECT * FROM agency_purchases WHERE supplier_id = ? ORDER BY purchase_date DESC");
    $stmt->execute([$supplier_id]);
    $purchases = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($purchases as &$p) {
        $istmt = $conn->prepare("SELECT pi.*, i.item_name FROM agency_purchase_items pi JOIN agency_items i ON pi.item_id = i.id WHERE pi.purchase_id = ?");
        $istmt->execute([$p['id']]);
        $p['items'] = $istmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    json_response(['supplier' => $supplier, 'purchases' => $purchases]);
}

if ($uri === '/api/agency/items/add' && $method === 'POST') {
    enforce_api_auth(['pharmacist']);
    $data = $input;
    $conn = get_db();
    try {
        $generic_name = trim($data['generic_name'] ?? '');
        if ($generic_name === '') {
            $generic_name = get_mapped_generic_name($conn, $data['item_name'] ?? '');
        }

        if (empty($data['id'])) {
            // Auto-detect category if not provided or empty
            $category = $data['category'] ?? '';
            if (empty(trim($category))) {
                $category = detect_medicine_category($data['item_name'] ?? '');
            }
            $stmt = $conn->prepare("INSERT INTO agency_items (item_code, item_name, generic_name, brand_name, category, medicine_type, hsn_code, unit, batch_number, mfg_date, expiry_date, purchase_price, selling_price, mrp, discount, gst, opening_stock, stock, min_stock, manufacturer, supplier_id, gst_percentage, reorder_level, rack_location, barcode, qr_code, row_location, col_location) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$data['item_code']??'', $data['item_name']??'', $generic_name, $data['brand_name']??'', $category, $data['medicine_type']??'', $data['hsn_code']??'', $data['unit']??'', $data['batch_number']??'', $data['mfg_date']??'', $data['expiry_date']??'', $data['purchase_price']??0, $data['selling_price']??0, $data['mrp']??0, $data['discount']??0, $data['gst']??0, $data['opening_stock']??0, $data['opening_stock']??0, $data['min_stock']??0, $data['manufacturer']??'', $data['supplier_id']??null, $data['gst_percentage']??0, $data['reorder_level']??0, $data['rack_location']??'', $data['barcode']??'', $data['qr_code']??'', $data['row_location']??'', $data['col_location']??'']);
            $item_id = $conn->lastInsertId();
            $conn->prepare("INSERT INTO agency_audit_trail (user_id, action, table_name, record_id, details) VALUES (?, ?, ?, ?, ?)")->execute([$_SESSION['user_id'] ?? 0, 'CREATE', 'agency_items', $item_id, 'Added new item: ' . ($data['item_name'] ?? '')]);
            
            // Record opening stock as movement if > 0
            if (($data['opening_stock'] ?? 0) > 0) {
                $conn->prepare("INSERT INTO agency_inventory_movements (item_id, movement_type, quantity, reference_id, reference_type, notes) VALUES (?, ?, ?, ?, ?, ?)")->execute([$item_id, 'IN', $data['opening_stock']??0, null, 'Opening Stock', 'Initial stock entry']);
            }
        } else {
            $stmt = $conn->prepare("UPDATE agency_items SET item_code=?, item_name=?, generic_name=?, brand_name=?, category=?, medicine_type=?, hsn_code=?, unit=?, batch_number=?, mfg_date=?, expiry_date=?, purchase_price=?, selling_price=?, mrp=?, discount=?, gst=?, min_stock=?, manufacturer=?, supplier_id=?, gst_percentage=?, reorder_level=?, rack_location=?, barcode=?, qr_code=?, row_location=?, col_location=? WHERE id=?");
            $stmt->execute([$data['item_code']??'', $data['item_name']??'', $generic_name, $data['brand_name']??'', $data['category']??'', $data['medicine_type']??'', $data['hsn_code']??'', $data['unit']??'', $data['batch_number']??'', $data['mfg_date']??'', $data['expiry_date']??'', $data['purchase_price']??0, $data['selling_price']??0, $data['mrp']??0, $data['discount']??0, $data['gst']??0, $data['min_stock']??0, $data['manufacturer']??'', $data['supplier_id']??null, $data['gst_percentage']??0, $data['reorder_level']??0, $data['rack_location']??'', $data['barcode']??'', $data['qr_code']??'', $data['row_location']??'', $data['col_location']??'', $data['id']]);
            $conn->prepare("INSERT INTO agency_audit_trail (user_id, action, table_name, record_id, details) VALUES (?, ?, ?, ?, ?)")->execute([$_SESSION['user_id'] ?? 0, 'UPDATE', 'agency_items', $data['id'], 'Updated item details: ' . ($data['item_name'] ?? '')]);
            $item_id = $data['id'];
        }
        
        // Auto-save new category
        if (!empty($data['category'])) {
            $cat = trim($data['category']);
            $checkCat = $conn->prepare("SELECT id FROM agency_categories WHERE name = ?");
            $checkCat->execute([$cat]);
            if (!$checkCat->fetch()) {
                $insCat = $conn->prepare("INSERT INTO agency_categories (name) VALUES (?)");
                $insCat->execute([$cat]);
            }
        }

        // Sync stock to pharmacy inventory
        $stmt_name = $conn->prepare("SELECT item_name, batch_number FROM agency_items WHERE id = ?");
        $stmt_name->execute([$item_id]);
        $item_info = $stmt_name->fetch(PDO::FETCH_ASSOC);
        if ($item_info) {
            sync_stock_item($conn, $item_info['item_name'], $item_info['batch_number'], 'agency');
        }

        json_response(['success' => true]);
    } catch (Exception $e) {
        json_response(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

// Migrate existing items: auto-detect categories for all items and fix missing/incorrect category values
if ($uri === '/api/agency/items/migrate-categories' && $method === 'POST') {
    enforce_api_auth(['pharmacist']);
    $conn = get_db();
    try {
        $stmt = $conn->query("SELECT id, item_name, category FROM agency_items");
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $updated = 0;
        
        $normalization_map = [
            'TABLET' => 'TAB',
            'TABLETS' => 'TAB',
            'CAPSULE' => 'CAP',
            'CAPSULES' => 'CAP',
            'SYRUP' => 'SYP',
            'SYRUPS' => 'SYP',
            'INJECTION' => 'INJ',
            'INJECTIONS' => 'INJ',
            'CREAM' => 'CRM',
            'CREAMS' => 'CRM',
            'GEL' => 'GEL',
            'GELS' => 'GEL',
            'DROP' => 'DROP',
            'DROPS' => 'DROP',
            'POWDER' => 'POW',
            'POWDERS' => 'POW',
            'LOTION' => 'LOT',
            'LOTIONS' => 'LOT',
            'SPRAY' => 'SPRAY',
            'SPRAYS' => 'SPRAY',
            'OINTMENT' => 'OINT',
            'OINTMENTS' => 'OINT'
        ];
        
        foreach ($items as $item) {
            $current = $item['category'] ?? '';
            $detected = detect_medicine_category($item['item_name']);
            
            $target = $current;
            if (!empty($detected)) {
                $target = $detected;
            } else {
                // If not detected in name, normalize expanded standard categories
                $current_upper = strtoupper(trim($current));
                if (isset($normalization_map[$current_upper])) {
                    $target = $normalization_map[$current_upper];
                }
            }
            
            // Only update if target category is different from current
            if ($target !== $current) {
                $upd = $conn->prepare("UPDATE agency_items SET category = ? WHERE id = ?");
                $upd->execute([$target, $item['id']]);
                $updated++;
                
                // Auto-save detected category to categories list
                if (!empty($target)) {
                    $chkC = $conn->prepare("SELECT id FROM agency_categories WHERE name = ?");
                    $chkC->execute([$target]);
                    if (!$chkC->fetch()) {
                        $conn->prepare("INSERT INTO agency_categories (name) VALUES (?)")->execute([$target]);
                    }
                }
            }
        }
        json_response(['success' => true, 'total_scanned' => count($items), 'total_updated' => $updated]);
    } catch (Exception $e) {
        json_response(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

// Update item min_stock (Low Stock Alert threshold) directly from Dashboard/Stock Details
if ($uri === '/api/agency/items/update-min-stock' && $method === 'POST') {
    enforce_api_auth(['pharmacist']);
    $conn = get_db();
    try {
        $item_id = $input['id'] ?? null;
        $min_stock = isset($input['min_stock']) ? (int)$input['min_stock'] : 0;
        
        if (!$item_id) {
            throw new Exception("Item ID is required");
        }
        
        $stmt = $conn->prepare("UPDATE agency_items SET min_stock = ? WHERE id = ?");
        $stmt->execute([$min_stock, $item_id]);
        
        // Sync stock to pharmacy inventory
        $stmt_name = $conn->prepare("SELECT item_name, batch_number FROM agency_items WHERE id = ?");
        $stmt_name->execute([$item_id]);
        $item_info = $stmt_name->fetch(PDO::FETCH_ASSOC);
        if ($item_info) {
            sync_stock_item($conn, $item_info['item_name'], $item_info['batch_number'], 'agency');
        }
        
        // Add audit trail entry
        $stmt_name = $conn->prepare("SELECT item_name FROM agency_items WHERE id = ?");
        $stmt_name->execute([$item_id]);
        $item_name = $stmt_name->fetchColumn();
        
        $conn->prepare("INSERT INTO agency_audit_trail (user_id, action, table_name, record_id, details) VALUES (?, ?, ?, ?, ?)")
             ->execute([$_SESSION['user_id'] ?? 0, 'UPDATE', 'agency_items', $item_id, "Updated low stock alert threshold directly from Stock Details screen to: $min_stock for item: $item_name"]);
             
        json_response(['success' => true]);
    } catch (Exception $e) {
        json_response(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

if (preg_match('/^\/api\/agency\/items\/delete\/(\d+)$/', $uri, $matches) && $method === 'DELETE') {
    enforce_api_auth(['pharmacist']);
    $conn = get_db();
    $item_id = $matches[1];
    
    // Get item name and batch before deleting
    $stmt_name = $conn->prepare("SELECT item_name, batch_number FROM agency_items WHERE id = ?");
    $stmt_name->execute([$item_id]);
    $item_info = $stmt_name->fetch(PDO::FETCH_ASSOC);
    
    $stmt = $conn->prepare("DELETE FROM agency_items WHERE id=?");
    $stmt->execute([$item_id]);
    
    if ($item_info) {
        sync_stock_item($conn, $item_info['item_name'], $item_info['batch_number'], 'agency');
    }
    
    json_response(['success' => true]);
}

// Purchase Entry
if ($uri === '/api/agency/purchase/add' && $method === 'POST') {
    enforce_api_auth(['pharmacist']);
    $data = $input;
    $conn = get_db();
    try {
        $conn->beginTransaction();

        $supplier_id = null;
        $supplier_name = trim($data['supplier_name'] ?? '');
        if (!empty($supplier_name)) {
            $check = $conn->prepare("SELECT id FROM agency_suppliers WHERE name = ? OR company_name = ?");
            $check->execute([$supplier_name, $supplier_name]);
            $supplier_id = $check->fetchColumn();
            
            if (!$supplier_id) {
                $ins = $conn->prepare("INSERT INTO agency_suppliers (name, company_name) VALUES (?, ?)");
                $ins->execute([$supplier_name, $supplier_name]);
                $supplier_id = $conn->lastInsertId();
            }
        } else {
            $supplier_id = $data['supplier_id'] ?? null;
        }

        $purc_id = !empty($data['id']) ? $data['id'] : null;
        $image_path = $data['image_path'] ?? null;
        $invoice_number = !empty($data['invoice_number']) ? $data['invoice_number'] : 'N/A';
        $purchase_date = !empty($data['purchase_date']) ? $data['purchase_date'] : date('Y-m-d');
        
        if ($purc_id) {
            // Revert old items stock
            $stmt = $conn->prepare("SELECT item_id, quantity, free_qty FROM agency_purchase_items WHERE purchase_id = ?");
            $stmt->execute([$purc_id]);
            $old_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach($old_items as $old_item) {
                $total_revert = ($old_item['quantity'] ?? 0) + ($old_item['free_qty'] ?? 0);
                $upd = $conn->prepare("UPDATE agency_items SET stock = stock - ? WHERE id = ?");
                $upd->execute([$total_revert, $old_item['item_id']]);
                
                // Sync stock to pharmacy inventory
                $stmt_name = $conn->prepare("SELECT item_name, batch_number FROM agency_items WHERE id = ?");
                $stmt_name->execute([$old_item['item_id']]);
                $item_info = $stmt_name->fetch(PDO::FETCH_ASSOC);
                if ($item_info) {
                    sync_stock_item($conn, $item_info['item_name'], $item_info['batch_number'], 'agency');
                }
            }
            $conn->prepare("DELETE FROM agency_purchase_items WHERE purchase_id = ?")->execute([$purc_id]);
            
            if (!$image_path) {
                $chk_img = $conn->prepare("SELECT image_path FROM agency_purchases WHERE id = ?");
                $chk_img->execute([$purc_id]);
                $image_path = $chk_img->fetchColumn();
            }
            
            $stmt = $conn->prepare("UPDATE agency_purchases SET supplier_id=?, invoice_number=?, purchase_date=?, payment_mode=?, credit_days=?, due_date=?, transport_name=?, vehicle_number=?, lr_number=?, doctor_name=?, clinic_name=?, doctor_reg_no=?, sub_total=?, discount_total=?, cgst_total=?, sgst_total=?, gst_total=?, grand_total=?, balance_amount=?, image_path=?, upi_reference=?, transaction_id=?, payment_date=?, bank_name=?, due_amount=?, outstanding_balance=?, purchase_type=? WHERE id=?");
            $stmt->execute([
                $supplier_id, $invoice_number, $purchase_date, 
                $data['payment_mode']??'Cash', $data['credit_days']??0, $data['due_date']??'',
                $data['transport_name']??'', $data['vehicle_number']??'', $data['lr_number']??'',
                $data['doctor_name']??'', $data['clinic_name']??'', $data['doctor_reg_no']??'',
                $data['sub_total']??0, $data['discount_total']??0, $data['cgst_total']??0, 
                $data['sgst_total']??0, $data['gst_total']??0, $data['grand_total']??0,
                $data['grand_total']??0, $image_path, $data['upi_reference']??'', $data['transaction_id']??'', 
                $data['payment_date']??'', $data['bank_name']??'', $data['due_amount']??0, 
                $data['outstanding_balance']??0, $data['purchase_type']??'Regular', $purc_id
            ]);
            $conn->prepare("INSERT INTO agency_audit_trail (user_id, action, table_name, record_id, details) VALUES (?, ?, ?, ?, ?)")->execute([$_SESSION['user_id'] ?? 0, 'UPDATE', 'agency_purchases', $purc_id, 'Updated purchase entry']);
        } else {
            $stmt = $conn->prepare("INSERT INTO agency_purchases (supplier_id, invoice_number, purchase_date, payment_mode, credit_days, due_date, transport_name, vehicle_number, lr_number, doctor_name, clinic_name, doctor_reg_no, sub_total, discount_total, cgst_total, sgst_total, gst_total, grand_total, balance_amount, payment_status, image_path, upi_reference, transaction_id, payment_date, bank_name, due_amount, outstanding_balance, purchase_type) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([
                $supplier_id, $invoice_number, $purchase_date, 
                $data['payment_mode']??'Cash', $data['credit_days']??0, $data['due_date']??'',
                $data['transport_name']??'', $data['vehicle_number']??'', $data['lr_number']??'',
                $data['doctor_name']??'', $data['clinic_name']??'', $data['doctor_reg_no']??'',
                $data['sub_total']??0, $data['discount_total']??0, $data['cgst_total']??0, 
                $data['sgst_total']??0, $data['gst_total']??0, $data['grand_total']??0,
                $data['grand_total']??0, 'Not Paid', $image_path, $data['upi_reference']??'', 
                $data['transaction_id']??'', $data['payment_date']??'', $data['bank_name']??'', 
                $data['due_amount']??0, $data['outstanding_balance']??0, $data['purchase_type']??'Regular'
            ]);
            $purc_id = $conn->lastInsertId();
            $conn->prepare("INSERT INTO agency_audit_trail (user_id, action, table_name, record_id, details) VALUES (?, ?, ?, ?, ?)")->execute([$_SESSION['user_id'] ?? 0, 'CREATE', 'agency_purchases', $purc_id, 'Added new purchase entry']);
        }

        foreach ($data['items'] as $item) {
            $item_id = $item['item_id'] ?? null;
            $item_generic = trim($item['generic_name'] ?? '');
            if ($item_generic === '') {
                $item_generic = get_mapped_generic_name($conn, $item['item_name'] ?? '');
            }
            
            if (!$item_id) {
                $check = $conn->prepare("SELECT id FROM agency_items WHERE item_name = ? AND batch_number = ?");
                $batch_to_check = $item['batch_number'] ?? '';
                $check->execute([$item['item_name'] ?? '', $batch_to_check]);
                $exist = $check->fetch();
                if ($exist) {
                    $item_id = $exist['id'];
                } else {
                    $auto_cat = detect_medicine_category($item['item_name'] ?? '');
                    $ins = $conn->prepare("INSERT INTO agency_items (item_name, batch_number, expiry_date, purchase_price, selling_price, mrp, stock, category, supplier_id, generic_name, brand_name) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
                    $ins->execute([$item['item_name'] ?? '', $item['batch_number']??'', $item['expiry_date']??'', (float)($item['purchase_rate']??0), (float)($item['selling_price']??0), (float)($item['mrp']??0), 0, $auto_cat, $supplier_id, $item_generic, $item['brand_name'] ?? $item['item_name'] ?? '']);
                    $item_id = $conn->lastInsertId();
                }
            }

            // Always auto-detect and update category, generic_name, and brand_name for this item
            $auto_cat = detect_medicine_category($item['item_name'] ?? '');
            $updCat = $conn->prepare("UPDATE agency_items SET category = ?, generic_name = ?, brand_name = ? WHERE id = ?");
            $updCat->execute([$auto_cat, $item_generic, $item['brand_name'] ?? $item['item_name'] ?? '', $item_id]);
            
            if (!empty($auto_cat)) {
                // Auto-save detected category to categories list
                $chkC = $conn->prepare("SELECT id FROM agency_categories WHERE name = ?");
                $chkC->execute([$auto_cat]);
                if (!$chkC->fetch()) {
                    $conn->prepare("INSERT INTO agency_categories (name) VALUES (?)")->execute([$auto_cat]);
                }
            }

            $ins_item = $conn->prepare("INSERT INTO agency_purchase_items (purchase_id, item_id, hsn_code, batch_number, mfg_date, expiry_date, quantity, free_qty, unit, purchase_rate, mrp, discount, discount_percentage, taxable_amount, tax_amount, cgst, sgst, gst, gst_percentage, total_amount, generic_name) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $ins_item->execute([
                $purc_id, $item_id, $item['hsn_code']??'', $item['batch_number']??'', $item['mfg_date']??'', 
                $item['expiry_date']??'', (int)($item['quantity']??0), (int)($item['free_qty']??0), $item['unit']??'', 
                (float)($item['purchase_rate']??0), (float)($item['mrp']??0), (float)($item['discount']??0), (float)($item['discount_percentage']??0),
                (float)($item['taxable_amount']??0), (float)($item['tax_amount']??0), (float)($item['cgst']??0), (float)($item['sgst']??0), 
                (float)($item['gst']??0), (float)($item['gst_percentage']??0), (float)($item['total_amount']??0),
                $item_generic
            ]);

            $total_added = ($item['quantity']??0) + ($item['free_qty']??0);
            $upd = $conn->prepare("UPDATE agency_items SET stock = stock + ?, purchase_price = ?, selling_price = ?, mrp = ?, supplier_id = ? WHERE id = ?");
            $upd->execute([$total_added, $item['purchase_rate']??0, $item['selling_price']??0, $item['mrp']??0, $supplier_id, $item_id]);

            // Add Inventory Movement
            if ($total_added > 0) {
                $conn->prepare("INSERT INTO agency_inventory_movements (item_id, movement_type, quantity, reference_id, reference_type, notes) VALUES (?, ?, ?, ?, ?, ?)")->execute([$item_id, 'IN', $total_added, $purc_id, 'Purchase', 'Purchase Entry']);
            }

            // Sync stock to pharmacy inventory
            $stmt_name = $conn->prepare("SELECT item_name, batch_number FROM agency_items WHERE id = ?");
            $stmt_name->execute([$item_id]);
            $item_info = $stmt_name->fetch(PDO::FETCH_ASSOC);
            if ($item_info) {
                sync_stock_item($conn, $item_info['item_name'], $item_info['batch_number'], 'agency');
            }
        }
        
        // Auto-update Supplier Totals
        if ($supplier_id) {
            $grand = $data['grand_total'] ?? 0;
            $conn->prepare("UPDATE agency_suppliers SET total_purchase = total_purchase + ?, pending_balance = pending_balance + ?, payment_status = 'Not Paid' WHERE id = ?")->execute([$grand, $grand, $supplier_id]);
        }

        $conn->commit();
        json_response(['success' => true]);
    } catch (Exception $e) {
        $conn->rollBack();
        json_response(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

// Stock Adjustments
if ($uri === '/api/agency/stock/adjust' && $method === 'POST') {
    enforce_api_auth(['pharmacist']);
    $conn = get_db();
    $conn->beginTransaction();
    try {
        $item_id = $input['item_id'];
        $qty = (int)$input['quantity']; // negative or positive
        
        $reason = isset($input['reason']) ? trim($input['reason']) : '';
        $ins = $conn->prepare("INSERT INTO agency_stock_adjustments (item_id, quantity, reason) VALUES (?,?,?)");
        $ins->execute([$item_id, $qty, $reason]);

        $upd = $conn->prepare("UPDATE agency_items SET stock = stock + ? WHERE id = ?");
        $upd->execute([$qty, $item_id]);

        // Sync stock to pharmacy inventory
        $stmt_name = $conn->prepare("SELECT item_name, batch_number FROM agency_items WHERE id = ?");
        $stmt_name->execute([$item_id]);
        $item_info = $stmt_name->fetch(PDO::FETCH_ASSOC);
        if ($item_info) {
            sync_stock_item($conn, $item_info['item_name'], $item_info['batch_number'], 'agency');
        }

        $conn->commit();
        json_response(['success' => true]);
    } catch (Exception $e) {
        $conn->rollBack();
        json_response(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

// Stock Transfers (Agency -> Pharmacy)
if ($uri === '/api/agency/stock/transfer' && $method === 'POST') {
    enforce_api_auth(['pharmacist']);
    $conn = get_db();
    $conn->beginTransaction();
    try {
        $item_id = $input['item_id'] ?? null;
        $qty = (int)($input['quantity'] ?? 0);
        
        $ins = $conn->prepare("INSERT INTO agency_stock_transfers (item_id, quantity) VALUES (?,?)");
        $ins->execute([$item_id, $qty]);

        $upd = $conn->prepare("UPDATE agency_items SET stock = stock - ? WHERE id = ?");
        $upd->execute([$qty, $item_id]);

        $stmt_name = $conn->prepare("SELECT item_name, batch_number FROM agency_items WHERE id = ?");
        $stmt_name->execute([$item_id]);
        $item_info = $stmt_name->fetch(PDO::FETCH_ASSOC);
        if ($item_info) {
            sync_stock_item($conn, $item_info['item_name'], $item_info['batch_number'], 'agency');
        }

        $conn->commit();
        json_response(['success' => true]);
    } catch (Exception $e) {
        $conn->rollBack();
        json_response(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

// AI OCR ENDPOINT (REAL GEMINI VISION API)
if ($uri === '/api/agency/ocr_scan' && $method === 'POST') {
    enforce_api_auth(['pharmacist']);
    function log_ocr_error($msg) {
        $time = date('Y-m-d H:i:s');
        error_log("[$time] OCR Debug: $msg");
    }

    function preprocess_image_for_ocr($file_path) {
        if (!extension_loaded('gd')) {
            log_ocr_error("GD extension not loaded. Skipping preprocessing.");
            return false;
        }

        $info = @getimagesize($file_path);
        if (!$info) {
            log_ocr_error("Could not read image info for: $file_path");
            return false;
        }
        $mime = $info['mime'];

        switch ($mime) {
            case 'image/jpeg':
            case 'image/jpg':
                $im = @imagecreatefromjpeg($file_path);
                break;
            case 'image/png':
                $im = @imagecreatefrompng($file_path);
                break;
            case 'image/webp':
                if (function_exists('imagecreatefromwebp')) {
                    $im = @imagecreatefromwebp($file_path);
                } else {
                    $im = false;
                }
                break;
            default:
                $im = false;
                break;
        }

        if (!$im) {
            log_ocr_error("Failed to load image resource for MIME: $mime");
            return false;
        }

        // 1. Orientation Correction (from EXIF)
        if (($mime === 'image/jpeg' || $mime === 'image/jpg') && function_exists('exif_read_data')) {
            $exif = @exif_read_data($file_path);
            if (!empty($exif['Orientation'])) {
                $orientation = $exif['Orientation'];
                log_ocr_error("EXIF Orientation detected: $orientation");
                switch ($orientation) {
                    case 8:
                        $rotated = @imagerotate($im, 90, 0);
                        if ($rotated !== false) { @imagedestroy($im); $im = $rotated; }
                        break;
                    case 3:
                        $rotated = @imagerotate($im, 180, 0);
                        if ($rotated !== false) { @imagedestroy($im); $im = $rotated; }
                        break;
                    case 6:
                        $rotated = @imagerotate($im, -90, 0);
                        if ($rotated !== false) { @imagedestroy($im); $im = $rotated; }
                        break;
                }
            }
        }

        // 2. Enhance Contrast (negative values increase contrast in GD: -100 to 100)
        @imagefilter($im, IMG_FILTER_CONTRAST, -15);

        // 3. Enhance Brightness slightly
        @imagefilter($im, IMG_FILTER_BRIGHTNESS, 5);

        // 4. Sharpen using a 3x3 convolution matrix
        if (function_exists('imageconvolution')) {
            $sharpen = [
                [0, -1, 0],
                [-1, 5, -1],
                [0, -1, 0]
            ];
            @imageconvolution($im, $sharpen, 1, 0);
        }

        // Save preprocessed image back to a temporary file
        $temp_processed = tempnam(sys_get_temp_dir(), 'ocr_pre_');
        $saved = false;
        if ($temp_processed) {
            if (@imagejpeg($im, $temp_processed, 90)) {
                $saved = $temp_processed;
                log_ocr_error("Image preprocessed successfully: $temp_processed");
            } else {
                log_ocr_error("Failed to save preprocessed image as JPEG");
            }
            @imagedestroy($im);
        } else {
            log_ocr_error("Failed to create temporary file for preprocessed image");
        }

        return $saved;
    }

    if (!isset($_FILES['bill_image']) || $_FILES['bill_image']['error'] !== UPLOAD_ERR_OK) {
        json_response(['success' => false, 'error' => 'No image uploaded. Please upload a valid bill photo.'], 400);
    }
    
    $api_key = getenv('GEMINI_API_KEY'); 
    if (empty($api_key) || $api_key === 'YOUR_GEMINI_API_KEY_HERE') {
        json_response(['success' => false, 'error' => 'Gemini API Key is not configured.'], 400);
    }

    $image_path = $_FILES['bill_image']['tmp_name'];
    $mime_type = mime_content_type($image_path);
    $ext = pathinfo($_FILES['bill_image']['name'], PATHINFO_EXTENSION);
    $filename = time() . '_' . uniqid() . '.' . $ext;
    
    // Upload original to Supabase
    upload_to_supabase($image_path, 'ocr_scans', $filename, $mime_type);
    
    // We still need local copy for base64 reading and preprocessing since we send it to Gemini directly
    // Instead of completely skipping local processing, we just save a temp file
    $final_path = sys_get_temp_dir() . '/' . $filename;
    copy($image_path, $final_path);
    $image_url = get_supabase_signed_url('ocr_scans', $filename);

    $preprocessed_path = preprocess_image_for_ocr($final_path);
    if ($preprocessed_path && file_exists($preprocessed_path)) {
        $image_data = base64_encode(file_get_contents($preprocessed_path));
        @unlink($preprocessed_path); // Clean up the preprocessed temp file
    } else {
        $image_data = base64_encode(file_get_contents($final_path));
    }

    $prompt = "You are an AI trained to extract invoice details from medical agency bills and invoices. 
    EXTRACT EXACTLY WHAT IS VISIBLE on the document. 
    DO NOT guess, DO NOT calculate, and DO NOT assume any values.
    CRITICAL INSTRUCTIONS:
    1. Read text with extreme care. Medical bills are often printed on dot-matrix, thermal, or low-contrast printers. Analyze characters and numbers carefully (e.g. check for '8' vs '0', '1' vs 'l', decimals).
    2. Only include fields that actually exist in the invoice.
    3. If a field does not exist in the invoice, DO NOT include that key in your JSON response.
    4. The subtotal, tax totals, discount totals, and grand total must exactly match what is printed on the invoice. Do not calculate them yourself. If they are not printed, do not include them.
    5. DO NOT wrap the response in markdown backticks. Return RAW JSON ONLY.
    
    Structure your response using ONLY the keys that are present, similar to this format:
    {
        \"supplier\": { \"name\": \"...\", \"gst\": \"...\", \"invoice_number\": \"...\", \"date\": \"YYYY-MM-DD\" },
        \"doctor_name\": \"...\",
        \"clinic_name\": \"...\",
        \"doctor_reg_no\": \"...\",
        \"transport_name\": \"...\",
        \"vehicle_number\": \"...\",
        \"lr_number\": \"...\",
        \"items\": [
            {
                \"item_name\": \"...\",
                \"quantity\": 10,
                \"purchase_rate\": 50.00,
                \"total_amount\": 500.00,
                \"mrp\": 60.00,
                \"selling_price\": 55.00,
                \"batch_number\": \"...\",
                \"expiry_date\": \"MM-YY\",
                \"mfg_date\": \"MM-YY\",
                \"hsn_code\": \"...\",
                \"manufacturer\": \"...\",
                \"unit\": \"...\",
                \"pack_size\": \"...\",
                \"free_qty\": 0,
                \"discount_percentage\": 0.00,
                \"discount_amount\": 0.00,
                \"gst_percentage\": 12.00,
                \"taxable_amount\": 500.00,
                \"cgst\": 6.00,
                \"sgst\": 6.00,
                \"tax_amount\": 12.00
            }
        ],
        \"sub_total\": 500.00,
        \"gst_total\": 12.00,
        \"cgst_total\": 6.00,
        \"sgst_total\": 6.00,
        \"gst_percentage_global\": 12.00,
        \"discount_total\": 0.00,
        \"grand_total\": 512.00
    }";

    $payload = [
        "contents" => [
            [
                "parts" => [
                    ["text" => $prompt],
                    [
                        "inlineData" => [
                            "mimeType" => $mime_type,
                            "data" => $image_data
                        ]
                    ]
                ]
            ]
        ]
    ];

    $models_to_try = ['gemini-2.5-flash', 'gemini-2.0-flash', 'gemini-flash-latest', 'gemini-2.5-pro', 'gemini-pro-latest'];
    $json_data = null;
    $last_error = "Unknown error";
    $all_errors = [];

    foreach ($models_to_try as $model) {
        $api_version = 'v1beta';
        $ch = curl_init("https://generativelanguage.googleapis.com/{$api_version}/models/{$model}:generateContent?key=" . $api_key);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        
        $response = curl_exec($ch);
        if(curl_errno($ch)){
            $last_error = curl_error($ch);
            $all_errors[] = "cURL $model: $last_error";
            log_ocr_error("cURL Error ($model): $last_error");
            curl_close($ch);
            continue; // try next model
        }
        curl_close($ch);

        $result = json_decode($response, true);
        if (isset($result['error'])) {
            $last_error = "API Error: " . ($result['error']['message'] ?? 'Unknown');
            $all_errors[] = "$model failed: $last_error";
            log_ocr_error("API Error ($model): " . json_encode($result['error']));
            continue;
        }
        
        if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
            $text = $result['candidates'][0]['content']['parts'][0]['text'];
            
            $start = strpos($text, '{');
            $end = strrpos($text, '}');
            if ($start !== false && $end !== false) {
                $text = substr($text, $start, $end - $start + 1);
            }
            
            $json_data = json_decode($text, true);
            if ($json_data) {
                $json_data['success'] = true;
                $json_data['image_url'] = $image_url;
                log_ocr_error("Success with $model");
                json_response($json_data);
                exit;
            } else {
                $last_error = "JSON Decode Failed. Try a clearer image.";
                log_ocr_error("Parse Error ($model): $text");
            }
        } else {
            $last_error = "Unexpected response structure from AI.";
            log_ocr_error("Structure Error ($model): $response");
        }
    }
    
    $error_details = implode(" | ", $all_errors);
    json_response(['success' => false, 'error' => "AI Scan Failed. Reason: $last_error. History: $error_details"], 500);
}

// Reports
if ($uri === '/api/agency/reports' && $method === 'GET') {
    enforce_api_auth(['pharmacist']);
    $conn = get_db();
    
    // Expiry Report
    $exp_stmt = $conn->query("SELECT * FROM agency_items WHERE expiry_date != '' ORDER BY expiry_date ASC LIMIT 50");
    $expiry_report = $exp_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Purchase Report
    $pur_stmt = $conn->query("SELECT p.*, s.name as supplier_name FROM agency_purchases p LEFT JOIN agency_suppliers s ON p.supplier_id = s.id ORDER BY p.purchase_date DESC LIMIT 50");
    $purchase_report = $pur_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    json_response([
        'expiry_report' => $expiry_report,
        'purchase_report' => $purchase_report
    ]);
}

if (preg_match('/^\/api\/agency\/purchase\/mark_paid\/(\d+)$/', $uri, $matches) && $method === 'POST') {
    enforce_api_auth(['pharmacist']);
    $purc_id = $matches[1];
    $conn = get_db();
    try {
        $conn->beginTransaction();
        
        $stmt_purc = $conn->prepare("SELECT supplier_id, grand_total, balance_amount FROM agency_purchases WHERE id = ?");
        $stmt_purc->execute([$purc_id]);
        $purc = $stmt_purc->fetch(PDO::FETCH_ASSOC);
        
        if ($purc) {
            $cash_amount = (float)($input['cash_amount'] ?? 0);
            $gpay_amount = (float)($input['gpay_amount'] ?? 0);
            $phonepe_amount = (float)($input['phonepe_amount'] ?? 0);
            $bank_amount = (float)($input['bank_amount'] ?? 0);
            $upi_account = $input['upi_account'] ?? null;
            
            $modes = [];
            if ($cash_amount > 0) $modes[] = 'Cash';
            if ($gpay_amount > 0) $modes[] = 'UPI';
            if ($phonepe_amount > 0) $modes[] = 'PhonePe';
            if ($bank_amount > 0) $modes[] = 'Bank Transfer';
            
            $payment_mode = implode(' + ', $modes);
            if (empty($payment_mode)) {
                $payment_mode = 'Cash';
            }
            
            $stmt_update = $conn->prepare("
                UPDATE agency_purchases 
                SET payment_status = 'Completed', 
                    paid_amount = grand_total, 
                    balance_amount = 0, 
                    cash_amount = ?, 
                    gpay_amount = ?, 
                    phonepe_amount = ?, 
                    bank_amount = ?, 
                    payment_date = ?, 
                    payment_mode = ?,
                    upi_account = ? 
                WHERE id = ?
            ");
            $stmt_update->execute([
                $cash_amount, 
                $gpay_amount, 
                $phonepe_amount, 
                $bank_amount, 
                date('Y-m-d'), 
                $payment_mode, 
                $upi_account,
                $purc_id
            ]);
            
            if ($purc['supplier_id']) {
                $stmt_supp = $conn->prepare("
                    UPDATE agency_suppliers 
                    SET paid_amount = paid_amount + ?, 
                        pending_balance = pending_balance - ?, 
                        cash_amount = cash_amount + ?, 
                        gpay_amount = gpay_amount + ?, 
                        phonepe_amount = phonepe_amount + ?, 
                        bank_amount = bank_amount + ? 
                    WHERE id = ?
                ");
                $stmt_supp->execute([
                    $purc['balance_amount'], 
                    $purc['balance_amount'], 
                    $cash_amount, 
                    $gpay_amount, 
                    $phonepe_amount, 
                    $bank_amount, 
                    $purc['supplier_id']
                ]);
            }
        }
        
        $conn->commit();
        json_response(['success' => true]);
    } catch (Exception $e) {
        $conn->rollBack();
        json_response(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

if (preg_match('/^\/api\/agency\/purchase\/delete\/(\d+)$/', $uri, $matches) && $method === 'DELETE') {
    enforce_api_auth(['pharmacist']);
    $purc_id = $matches[1];
    $conn = get_db();
    try {
        $conn->beginTransaction();
        
        // Find purchase to get grand_total and supplier_id
        $stmt_purc = $conn->prepare("SELECT supplier_id, grand_total FROM agency_purchases WHERE id = ?");
        $stmt_purc->execute([$purc_id]);
        $purc = $stmt_purc->fetch(PDO::FETCH_ASSOC);
        
        // Find items and decrement stock
        $stmt = $conn->prepare("SELECT item_id, quantity FROM agency_purchase_items WHERE purchase_id = ?");
        $stmt->execute([$purc_id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach($items as $item) {
            $upd = $conn->prepare("UPDATE agency_items SET stock = stock - ? WHERE id = ?");
            $upd->execute([$item['quantity'], $item['item_id']]);
            
            // Sync stock to pharmacy inventory
            $stmt_name = $conn->prepare("SELECT item_name, batch_number FROM agency_items WHERE id = ?");
            $stmt_name->execute([$item['item_id']]);
            $item_info = $stmt_name->fetch(PDO::FETCH_ASSOC);
            if ($item_info) {
                sync_stock_item($conn, $item_info['item_name'], $item_info['batch_number'], 'agency');
            }
        }
        
        $conn->prepare("DELETE FROM agency_purchase_items WHERE purchase_id = ?")->execute([$purc_id]);
        $conn->prepare("DELETE FROM agency_purchases WHERE id = ?")->execute([$purc_id]);
        
        if ($purc && $purc['supplier_id']) {
            $conn->prepare("UPDATE agency_suppliers SET total_purchase = total_purchase - ?, pending_balance = pending_balance - ? WHERE id = ?")->execute([$purc['grand_total'], $purc['grand_total'], $purc['supplier_id']]);
        }
        
        $conn->commit();
        json_response(['success' => true]);
    } catch (Exception $e) {
        $conn->rollBack();
        json_response(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

if (preg_match('/^\/api\/agency\/purchase\/details\/(\d+)$/', $uri, $matches) && $method === 'GET') {
    enforce_api_auth(['pharmacist']);
    $purc_id = $matches[1];
    $conn = get_db();
    try {
        $stmt = $conn->prepare("SELECT p.*, s.name as supplier_name, s.gst_number as supplier_gst FROM agency_purchases p LEFT JOIN agency_suppliers s ON p.supplier_id = s.id WHERE p.id = ?");
        $stmt->execute([$purc_id]);
        $purchase = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$purchase) {
            json_response(['error' => 'Purchase not found'], 404);
        }
        
        $istmt = $conn->prepare("SELECT pi.*, i.item_name FROM agency_purchase_items pi LEFT JOIN agency_items i ON pi.item_id = i.id WHERE pi.purchase_id = ?");
        $istmt->execute([$purc_id]);
        $purchase['items'] = $istmt->fetchAll(PDO::FETCH_ASSOC);
        
        json_response(['success' => true, 'purchase' => $purchase]);
    } catch (Exception $e) {
        json_response(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

if ($uri === '/api/staff_records' && $method === 'GET') {
    enforce_admin();
    $conn = get_db();
    try {
        $stmt = $conn->query("SELECT * FROM staff_records ORDER BY id DESC");
        json_response(['success' => true, 'staff' => $stmt->fetchAll()]);
    } catch (Exception $e) {
        json_response(['success' => false, 'error' => $e->getMessage()], 400);
    }
}

if ($uri === '/api/staff_records/save' && $method === 'POST') {
    enforce_admin();
    $conn = get_db();
    try {
        $id = $input['id'] ?? null;
        $name = $input['name'] ?? '';
        $phone = $input['phone'] ?? '';
        $education = $input['education'] ?? '';
        $role = $input['role'] ?? '';
        $salary = $input['salary'] ?? 0.00;
        
        if ($id) {
            $stmt = $conn->prepare("UPDATE staff_records SET name=?, phone=?, education=?, role=?, salary=? WHERE id=?");
            $stmt->execute([$name, $phone, $education, $role, $salary, $id]);
        } else {
            $stmt = $conn->prepare("INSERT INTO staff_records (name, phone, education, role, salary) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$name, $phone, $education, $role, $salary]);
        }
        json_response(['success' => true]);
    } catch (Exception $e) {
        json_response(['success' => false, 'error' => $e->getMessage()], 400);
    }
}

if (preg_match('/^\/api\/staff_records\/delete\/(\d+)$/', $uri, $matches) && $method === 'DELETE') {
    enforce_admin();
    $id = $matches[1];
    $conn = get_db();
    try {
        $stmt = $conn->prepare("DELETE FROM staff_records WHERE id=?");
        $stmt->execute([$id]);
        json_response(['success' => true]);
    } catch (Exception $e) {
        json_response(['success' => false, 'error' => $e->getMessage()], 400);
    }
}

if ($uri === '/api/staff_records/pay_salary' && $method === 'POST') {
    enforce_admin();
    $conn = get_db();
    try {
        $id = $input['id'] ?? null;
        $date = $input['date'] ?? null; // Can be null to clear
        if (!$id) throw new Exception("Staff ID required");
        
        $conn->beginTransaction();
        
        $stmt = $conn->prepare("UPDATE staff_records SET last_salary_paid_date=? WHERE id=?");
        $stmt->execute([$date, $id]);
        
        $currentMonth = date('Y-m'); // "YYYY-MM"
        
        if ($date) {
            // Get staff salary
            $stmtSal = $conn->prepare("SELECT salary FROM staff_records WHERE id=?");
            $stmtSal->execute([$id]);
            $salary = $stmtSal->fetchColumn() ?: 0;
            
            // Delete any existing salary record for this month to avoid duplicates if they re-tick
            $del = $conn->prepare("DELETE FROM staff_payments WHERE staff_id=? AND payment_type='Salary' AND payment_month=?");
            $del->execute([$id, $currentMonth]);
            
            // Insert
            $ins = $conn->prepare("INSERT INTO staff_payments (staff_id, payment_type, payment_month, amount, payment_date, notes) VALUES (?, 'Salary', ?, ?, ?, 'Monthly Salary')");
            $ins->execute([$id, $currentMonth, $salary, $date]);
        } else {
            // Remove salary record for this month
            $del = $conn->prepare("DELETE FROM staff_payments WHERE staff_id=? AND payment_type='Salary' AND payment_month=?");
            $del->execute([$id, $currentMonth]);
        }
        
        $conn->commit();
        json_response(['success' => true]);
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        json_response(['success' => false, 'error' => $e->getMessage()], 400);
    }
}

if (preg_match('/^\/api\/staff_records\/history\/(\d+)$/', $uri, $matches) && $method === 'GET') {
    enforce_admin();
    $id = $matches[1];
    $conn = get_db();
    try {
        $stmt = $conn->prepare("SELECT * FROM staff_payments WHERE staff_id=? ORDER BY payment_date DESC, id DESC");
        $stmt->execute([$id]);
        json_response(['success' => true, 'history' => $stmt->fetchAll()]);
    } catch (Exception $e) {
        json_response(['success' => false, 'error' => $e->getMessage()], 400);
    }
}

if ($uri === '/api/staff_records/add_payment' && $method === 'POST') {
    enforce_admin();
    $conn = get_db();
    try {
        $staff_id = $input['staff_id'];
        $payment_type = $input['payment_type']; // Advance or Bonus
        $amount = $input['amount'];
        $payment_date = $input['payment_date'];
        $notes = $input['notes'] ?? '';
        $payment_month = substr($payment_date, 0, 7); // Extract YYYY-MM
        
        $ins = $conn->prepare("INSERT INTO staff_payments (staff_id, payment_type, payment_month, amount, payment_date, notes) VALUES (?, ?, ?, ?, ?, ?)");
        $ins->execute([$staff_id, $payment_type, $payment_month, $amount, $payment_date, $notes]);
        
        json_response(['success' => true]);
    } catch (Exception $e) {
        json_response(['success' => false, 'error' => $e->getMessage()], 400);
    }
}

if (preg_match('/^\/api\/staff_records\/delete_payment\/(\d+)$/', $uri, $matches) && $method === 'DELETE') {
    enforce_admin();
    $id = $matches[1];
    $conn = get_db();
    try {
        // Find if this is a Salary record to clear the checkbox in staff_records if it matches current month
        $stmt = $conn->prepare("SELECT * FROM staff_payments WHERE id=?");
        $stmt->execute([$id]);
        $payment = $stmt->fetch();
        
        if ($payment) {
            $conn->beginTransaction();
            if ($payment['payment_type'] === 'Salary' && $payment['payment_month'] === date('Y-m')) {
                // Also uncheck
                $conn->prepare("UPDATE staff_records SET last_salary_paid_date=NULL WHERE id=?")->execute([$payment['staff_id']]);
            }
            $conn->prepare("DELETE FROM staff_payments WHERE id=?")->execute([$id]);
            $conn->commit();
        }
        
        json_response(['success' => true]);
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        json_response(['success' => false, 'error' => $e->getMessage()], 400);
    }
}

if ($uri === '/api/agency/returns/add' && $method === 'POST') {
    enforce_api_auth(['pharmacist']);
    $data = $input;
    $conn = get_db();
    try {
        $conn->beginTransaction();

        $supplier_id = $data['supplier_id'] ?? null;
        
        $stmt = $conn->prepare("INSERT INTO agency_purchase_returns (return_date, supplier_id, original_purchase_id, reference_number, reason, sub_total, tax_total, grand_total, return_status) VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $data['return_date'], $supplier_id, $data['original_purchase_id'] ?? null, 
            $data['reference_number'] ?? '', $data['reason'] ?? '', $data['sub_total'] ?? 0, 
            $data['tax_total'] ?? 0, $data['grand_total'] ?? 0, 'Completed'
        ]);
        $return_id = $conn->lastInsertId();

        foreach ($data['items'] as $item) {
            $item_id = $item['item_id'];
            $qty = $item['return_quantity'] ?? 0;
            
            $ins_item = $conn->prepare("INSERT INTO agency_return_items (return_id, item_id, batch_number, return_quantity, unit_price, tax_amount, total_amount) VALUES (?,?,?,?,?,?,?)");
            $ins_item->execute([
                $return_id, $item_id, $item['batch_number'] ?? '', $qty, 
                $item['unit_price'] ?? 0, $item['tax_amount'] ?? 0, $item['total_amount'] ?? 0
            ]);

            // Deduct from stock
            if ($qty > 0) {
                $upd = $conn->prepare("UPDATE agency_items SET stock = stock - ? WHERE id = ?");
                $upd->execute([$qty, $item_id]);
                
                // Log inventory movement
                $conn->prepare("INSERT INTO agency_inventory_movements (item_id, movement_type, quantity, reference_id, reference_type, notes) VALUES (?, ?, ?, ?, ?, ?)")->execute([$item_id, 'OUT', $qty, $return_id, 'Return', 'Purchase Return']);
                
                // Sync stock to pharmacy inventory
                $stmt_name = $conn->prepare("SELECT item_name, batch_number FROM agency_items WHERE id = ?");
                $stmt_name->execute([$item_id]);
                $item_info = $stmt_name->fetch(PDO::FETCH_ASSOC);
                if ($item_info) {
                    sync_stock_item($conn, $item_info['item_name'], $item_info['batch_number'], 'agency');
                }
            }
        }
        
        // Auto-update Supplier Totals (deduct return amount from pending balance and total purchase)
        if ($supplier_id) {
            $grand = $data['grand_total'] ?? 0;
            $conn->prepare("UPDATE agency_suppliers SET total_purchase = total_purchase - ?, pending_balance = pending_balance - ? WHERE id = ?")->execute([$grand, $grand, $supplier_id]);
        }

        $conn->prepare("INSERT INTO agency_audit_trail (user_id, action, table_name, record_id, details) VALUES (?, ?, ?, ?, ?)")->execute([$_SESSION['user_id'] ?? 0, 'CREATE', 'agency_purchase_returns', $return_id, 'Processed purchase return']);

        $conn->commit();
        json_response(['success' => true]);
    } catch (Exception $e) {
        $conn->rollBack();
        json_response(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

// API – MEDICINE RETURNS
// ═══════════════════════════════════════════
if ($uri === '/api/return_medicines' && $method === 'POST') {
    $conn = get_db();
    try {
        $conn->beginTransaction();

        $sale_type = $input['sale_type'] ?? '';
        $sale_id = (int)($input['sale_id'] ?? 0);
        $returns = $input['returns'] ?? []; // Array of {name, qty, reason}
        $processed_by = $_SESSION['username'] ?? 'Admin';
        
        // New fields
        $total_refund_amount = (float)($input['total_refund_amount'] ?? 0);
        $refund_payment_mode = $input['refund_payment_mode'] ?? '';

        if (!in_array($sale_type, ['prescription', 'direct_sale']) || !$sale_id || empty($returns)) {
            throw new Exception("Invalid return request parameters.");
        }

        // Fetch original sale
        $table = ($sale_type === 'prescription') ? 'prescriptions' : 'direct_sales';
        $stmt = $conn->prepare("SELECT * FROM $table WHERE id = ?");
        $stmt->execute([$sale_id]);
        $sale = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sale) throw new Exception("Sale record not found.");

        $patient_id = ($sale_type === 'prescription') ? (int)$sale['patient_id'] : null;

        $medicines = json_decode($sale['medicines'], true) ?: [];
        
        // Compute total return amount and cost first
        $total_return_amount = 0;
        $total_return_cost = 0;
        foreach ($returns as $ret) {
            $r_name = trim($ret['name']);
            $r_qty = (float)$ret['qty'];
            $equivalent_tablets = (float)($ret['equivalent_tablets'] ?? $r_qty);
            foreach ($medicines as $m) {
                if ($m['name'] === $r_name) {
                    $sold_qty = (float)($m['qty'] ?? 0);
                    $unit_price = (float)($m['amount'] ?? 0) / ($sold_qty > 0 ? $sold_qty : 1);
                    $return_amt = $unit_price * $equivalent_tablets;
                    $total_return_amount += $return_amt;

                    $batch_id = $m['batch_id'] ?? '';
                    if ($batch_id) {
                        $inv_stmt = $conn->prepare("SELECT purchase_price, tablets_per_strip FROM inventory WHERE id = ?");
                        $inv_stmt->execute([$batch_id]);
                    } else {
                        $inv_stmt = $conn->prepare("SELECT AVG(purchase_price) as purchase_price, MAX(tablets_per_strip) as tablets_per_strip FROM inventory WHERE name = ?");
                        $inv_stmt->execute([$r_name]);
                    }
                    $inv_data = $inv_stmt->fetch();
                    $unit_cost = 0;
                    if ($inv_data && $inv_data['tablets_per_strip'] > 0) {
                        $unit_cost = (float)$inv_data['purchase_price'] / (int)$inv_data['tablets_per_strip'];
                    }
                    $total_return_cost += ($unit_cost * $equivalent_tablets);
                    break;
                }
            }
        }

        $discount = (float)($sale['discount_percent'] ?? 0);
        $total_return_amount_post_discount = $total_return_amount - ($total_return_amount * ($discount / 100));

        $current_balance = (float)$sale['balance_amount'];
        $balance_adjusted = min($total_return_amount_post_discount, $current_balance);
        $actual_refund = max(0.0, $total_return_amount_post_discount - $balance_adjusted);

        foreach ($returns as $ret) {
            $r_name = trim($ret['name']);
            $r_qty = (float)$ret['qty'];
            $return_type = trim($ret['return_type'] ?? 'Single Tablet');
            $equivalent_tablets = (float)($ret['equivalent_tablets'] ?? $r_qty);
            $reason = trim($ret['reason'] ?? '');

            if ($r_qty <= 0) throw new Exception("Return quantity must be greater than zero.");

            // Find medicine in sale
            $found = false;
            $unit_price = 0;
            $return_amt = 0;

            foreach ($medicines as &$m) {
                if ($m['name'] === $r_name) {
                    $sold_qty = (float)($m['qty'] ?? 0);
                    $already_returned = (float)($m['returned_qty'] ?? 0);
                    $avail_qty = $sold_qty - $already_returned;

                    if ($equivalent_tablets > $avail_qty) {
                        throw new Exception("Cannot return more than available quantity for $r_name.");
                    }

                    // Calculate proportional amount and cost
                    $unit_price = (float)($m['amount'] ?? 0) / ($sold_qty > 0 ? $sold_qty : 1);
                    $return_amt = $unit_price * $equivalent_tablets;
                    
                    // Update JSON object
                    $m['returned_qty'] = $already_returned + $equivalent_tablets;
                    $m['returned_amount'] = (float)($m['returned_amount'] ?? 0) + $return_amt;

                    $found = true;
                    break;
                }
            }

            if (!$found) throw new Exception("Medicine $r_name not found in this bill.");

            // Increase inventory stock
            $upd_inv = $conn->prepare("UPDATE inventory SET stock = stock + ? WHERE name = ? AND id = (SELECT id FROM inventory WHERE name = ? ORDER BY expiry_date DESC LIMIT 1)");
            $upd_inv->execute([$equivalent_tablets, $r_name, $r_name]);

            if ($upd_inv->rowCount() === 0) {
                $upd_inv_fallback = $conn->prepare("UPDATE inventory SET stock = stock + ? WHERE name = ?");
                $upd_inv_fallback->execute([$equivalent_tablets, $r_name]);
            }

            // Sync with pharmacy
            $inv_chk = $conn->prepare("SELECT batch_number FROM inventory WHERE name = ? ORDER BY expiry_date DESC LIMIT 1");
            $inv_chk->execute([$r_name]);
            $b_row = $inv_chk->fetch();
            if ($b_row) {
                sync_stock_item($conn, $r_name, $b_row['batch_number'], 'pharmacy');
            }

            // Insert into medicine_returns log
            $patient_name = ($sale_type === 'prescription') ? ($conn->query("SELECT name FROM patients WHERE id = " . (int)$sale['patient_id'])->fetchColumn() ?: 'Unknown') : ($sale['customer_name'] ?? 'Unknown');
            $bill_number = ($sale_type === 'prescription') ? ('PR-' . $sale['id']) : ('DS-' . $sale['id']);
            
            $logged_refund_mode = ($actual_refund == 0) ? 'Adjusted in Balance' : $refund_payment_mode;

            $log = $conn->prepare("INSERT INTO medicine_returns (return_date, return_time, patient_name, bill_number, medicine_name, returned_qty, return_type, processed_by, reason, sale_type, sale_id, patient_id, unit_price, return_amount, total_refund_amount, refund_payment_mode) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $log->execute([
                date('Y-m-d'), date('H:i:s'), $patient_name, $bill_number, $r_name, $r_qty, $return_type, $processed_by, $reason, $sale_type, $sale_id, $patient_id, $unit_price, $return_amt, $actual_refund, $logged_refund_mode
            ]);
        }

        // Update payment mode columns for direct_sales/prescriptions
        $new_paid = max(0.0, (float)$sale['paid_amount'] - $actual_refund);
        $refund_rem = $actual_refund;
        if ($refund_rem > 0) {
            $mode_col = '';
            if (stripos($refund_payment_mode, 'gpay') !== false || stripos($refund_payment_mode, 'upi') !== false) {
                $mode_col = 'gpay_amount';
            } elseif (stripos($refund_payment_mode, 'phonepe') !== false) {
                $mode_col = 'phonepe_amount';
            } elseif (stripos($refund_payment_mode, 'bank') !== false) {
                $mode_col = 'bank_amount';
            } else {
                $mode_col = 'cash_amount';
            }

            if ($mode_col && isset($sale[$mode_col]) && (float)$sale[$mode_col] >= $refund_rem) {
                $sale[$mode_col] = (float)$sale[$mode_col] - $refund_rem;
                $refund_rem = 0;
            } else {
                $cols_to_check = ['cash_amount', 'gpay_amount', 'phonepe_amount', 'bank_amount'];
                if ($mode_col) {
                    $cols_to_check = array_merge([$mode_col], array_diff($cols_to_check, [$mode_col]));
                }
                foreach ($cols_to_check as $c) {
                    if (isset($sale[$c]) && (float)$sale[$c] > 0) {
                        $val = (float)$sale[$c];
                        $deduct = min($refund_rem, $val);
                        $sale[$c] = $val - $deduct;
                        $refund_rem -= $deduct;
                        if ($refund_rem <= 0) break;
                    }
                }
            }
        }

        // Update sale record
        $new_medicines_json = json_encode($medicines);
        $new_total = max(0, (float)$sale['total_amount'] - $total_return_amount);
        $new_cost = max(0, (float)$sale['cost_amount'] - $total_return_cost);

        $grand_total_calc = $new_total + (float)($sale['consultation_fee'] ?? 0) + (float)($sale['scan_fee'] ?? 0) + (float)($sale['injection_cost'] ?? 0) + (float)($sale['iv_cost'] ?? 0) + (float)($sale['upt_cost'] ?? 0);
        $discount = (float)($sale['discount_percent'] ?? 0);
        $new_grand_total = $grand_total_calc - ($grand_total_calc * ($discount / 100));

        $new_balance = max(0.0, $new_grand_total - $new_paid);

        if ($table === 'direct_sales') {
            $new_status = ($new_balance > 0) ? 'pending' : 'completed';
            $upd_sale = $conn->prepare("UPDATE direct_sales SET medicines = ?, total_amount = ?, cost_amount = ?, balance_amount = ?, paid_amount = ?, cash_amount = ?, gpay_amount = ?, phonepe_amount = ?, bank_amount = ?, status = ? WHERE id = ?");
            $upd_sale->execute([
                $new_medicines_json, $new_total, $new_cost, $new_balance, $new_paid,
                (float)($sale['cash_amount'] ?? 0), (float)($sale['gpay_amount'] ?? 0), (float)($sale['phonepe_amount'] ?? 0), (float)($sale['bank_amount'] ?? 0),
                $new_status, $sale_id
            ]);
        } else {
            $upd_sale = $conn->prepare("UPDATE prescriptions SET medicines = ?, total_amount = ?, cost_amount = ?, balance_amount = ?, paid_amount = ?, cash_amount = ?, gpay_amount = ?, phonepe_amount = ? WHERE id = ?");
            $upd_sale->execute([
                $new_medicines_json, $new_total, $new_cost, $new_balance, $new_paid,
                (float)($sale['cash_amount'] ?? 0), (float)($sale['gpay_amount'] ?? 0), (float)($sale['phonepe_amount'] ?? 0), $sale_id
            ]);
        }

        $conn->commit();
        json_response(['success' => true]);
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        json_response(['success' => false, 'error' => $e->getMessage()], 400);
    }
}

if ($uri === '/api/returns_history' && $method === 'GET') {
    $sale_type = $_GET['sale_type'] ?? '';
    $sale_id = (int)($_GET['sale_id'] ?? 0);
    $conn = get_db();
    $stmt = $conn->prepare("SELECT * FROM medicine_returns WHERE sale_type = ? AND sale_id = ? ORDER BY id DESC");
    $stmt->execute([$sale_type, $sale_id]);
    json_response($stmt->fetchAll(PDO::FETCH_ASSOC));
}

// ══════════════════════════════════════════════════════════════════════
// API — UPI ACCOUNTS
// ══════════════════════════════════════════════════════════════════════

if ($uri === '/api/upi_accounts' && $method === 'GET') {
    try {
        enforce_api_auth(['receptionist', 'pharmacist']);
        $conn = get_db();
        $stmt = $conn->query("SELECT * FROM upi_accounts ORDER BY id ASC");
        json_response($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode([
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
        exit;
    }
}



if ($uri === '/api/upi_accounts/add' && $method === 'POST') {
    enforce_admin();
    $input = json_decode(file_get_contents('php://input'), true);
    $conn = get_db();
    try {
        $stmt = $conn->prepare("INSERT INTO upi_accounts (account_name, short_name, bank_name, account_number, upi_id, notes, is_active, account_holder_name, ifsc_code) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $input['account_name'] ?? '',
            $input['short_name'] ?? '',
            $input['bank_name'] ?? '',
            $input['account_number'] ?? '',
            $input['upi_id'] ?? '',
            $input['notes'] ?? '',
            1,
            $input['account_holder_name'] ?? '',
            $input['ifsc_code'] ?? ''
        ]);
        json_response(['success' => true]);
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            json_response(['success' => false, 'error' => 'Short name must be unique'], 400);
        }
        json_response(['success' => false, 'error' => $e->getMessage()], 400);
    }
}

if ($uri === '/api/upi_accounts/update' && $method === 'POST') {
    enforce_admin();
    $input = json_decode(file_get_contents('php://input'), true);
    $conn = get_db();
    try {
        $stmt = $conn->prepare("UPDATE upi_accounts SET account_name=?, short_name=?, bank_name=?, account_number=?, upi_id=?, notes=?, account_holder_name=?, ifsc_code=? WHERE id=?");
        $stmt->execute([
            $input['account_name'] ?? '',
            $input['short_name'] ?? '',
            $input['bank_name'] ?? '',
            $input['account_number'] ?? '',
            $input['upi_id'] ?? '',
            $input['notes'] ?? '',
            $input['account_holder_name'] ?? '',
            $input['ifsc_code'] ?? '',
            $input['id']
        ]);
        json_response(['success' => true]);
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            json_response(['success' => false, 'error' => 'Short name must be unique'], 400);
        }
        json_response(['success' => false, 'error' => $e->getMessage()], 400);
    }
}

if ($uri === '/api/upi_accounts/toggle' && $method === 'POST') {
    enforce_admin();
    $input = json_decode(file_get_contents('php://input'), true);
    $conn = get_db();
    $stmt = $conn->prepare("UPDATE upi_accounts SET is_active = CASE WHEN is_active=1 THEN 0 ELSE 1 END WHERE id=?");
    $stmt->execute([$input['id']]);
    json_response(['success' => true]);
}

if ($uri === '/api/upi_accounts/delete' && $method === 'POST') {
    enforce_admin();
    $input = json_decode(file_get_contents('php://input'), true);
    $conn = get_db();
    $stmt = $conn->prepare("DELETE FROM upi_accounts WHERE id=?");
    $stmt->execute([$input['id']]);
    json_response(['success' => true]);
}

// ═══════════════════════════════════════════
// API — VERCEL CRON
// ═══════════════════════════════════════════
if ($uri === '/api/cron/backup' && $method === 'GET') {
    require_once __DIR__ . '/../cron_backup.php';
    
    // Verify Vercel Cron Secret
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $cron_secret = getenv('CRON_SECRET');
    if ($cron_secret && $authHeader !== "Bearer $cron_secret") {
        json_response(['error' => 'Unauthorized cron request'], 401);
    }

    $status = run_auto_backup_check();
    json_response($status);
}

// ═══════════════════════════════════════════
// API — GENERIC MEDICINE MANAGEMENT MODULE
// ═══════════════════════════════════════════

/**
 * GET /api/generics/list
 * Returns all distinct generic names with their brand medicine counts.
 * Combines agency_items and inventory so no mapping is missed.
 * Supports optional ?q= search filter for the Generic Medicine List page.
 */
if ($uri === '/api/generics/list' && $method === 'GET') {
    enforce_api_auth(['pharmacist']);
    $q = trim($_GET['q'] ?? '');
    $conn = get_db();
    try {
        // 1. Sync from agency_items to generic_mappings
        $conn->exec("
            INSERT INTO generic_mappings (brand_name, batch_number, generic_name, agency_name, stock, mrp, row_location, col_location, purchase_rate, selling_rate, category, pack_size, expiry_date, min_stock)
            SELECT 
                ai.item_name, 
                ai.batch_number, 
                ai.generic_name, 
                (SELECT name FROM agency_suppliers WHERE id = ai.supplier_id LIMIT 1),
                ai.stock, 
                ai.mrp, 
                ai.row_location, 
                ai.col_location,
                ai.purchase_price,
                ai.selling_price,
                ai.category,
                ai.unit,
                ai.expiry_date,
                ai.min_stock
            FROM agency_items ai
            WHERE ai.generic_name IS NOT NULL AND TRIM(ai.generic_name) != ''
            ON DUPLICATE KEY UPDATE
                generic_name = VALUES(generic_name),
                agency_name = VALUES(agency_name),
                stock = VALUES(stock),
                mrp = VALUES(mrp),
                row_location = VALUES(row_location),
                col_location = VALUES(col_location),
                purchase_rate = VALUES(purchase_rate),
                selling_rate = VALUES(selling_rate),
                category = VALUES(category),
                pack_size = VALUES(pack_size),
                expiry_date = VALUES(expiry_date),
                min_stock = VALUES(min_stock)
        ");

        // 2. Sync from inventory to generic_mappings
        $conn->exec("
            INSERT INTO generic_mappings (brand_name, batch_number, generic_name, agency_name, stock, mrp, row_location, col_location, purchase_rate, selling_rate, category, pack_size, expiry_date, min_stock)
            SELECT 
                i.name, 
                i.batch_number, 
                i.generic_name, 
                i.agency_name,
                i.stock, 
                i.mrp, 
                i.row_location, 
                i.col_location,
                0.00,
                0.00,
                i.category,
                i.tablets_per_strip,
                i.expiry_date,
                i.min_stock
            FROM inventory i
            WHERE i.generic_name IS NOT NULL AND TRIM(i.generic_name) != ''
            ON DUPLICATE KEY UPDATE
                generic_name = VALUES(generic_name),
                agency_name = VALUES(agency_name),
                stock = VALUES(stock),
                mrp = VALUES(mrp),
                row_location = VALUES(row_location),
                col_location = VALUES(col_location),
                category = VALUES(category),
                pack_size = VALUES(pack_size),
                expiry_date = VALUES(expiry_date),
                min_stock = VALUES(min_stock)
        ");

        // 3. Remove mappings from generic_mappings if they were cleared (set to NULL or empty) in the main tables
        $conn->exec("
            DELETE FROM generic_mappings 
            WHERE (brand_name, batch_number) IN (
                SELECT item_name, batch_number FROM agency_items WHERE generic_name IS NULL OR TRIM(generic_name) = ''
            )
        ");
        $conn->exec("
            DELETE FROM generic_mappings 
            WHERE (brand_name, batch_number) IN (
                SELECT name, batch_number FROM inventory WHERE generic_name IS NULL OR TRIM(generic_name) = ''
            )
        ");

        // 4. Query from generic_mappings table
        $params = [];
        $where = "";
        if ($q !== '') {
            $where = "WHERE generic_name LIKE ? OR brand_name LIKE ?";
            $params[] = "%$q%";
            $params[] = "%$q%";
        }

        $sql = "
            SELECT 
                generic_name, 
                COUNT(DISTINCT case when LOWER(brand_name) != '(unmapped brand)' then brand_name end) AS brand_count 
            FROM generic_mappings
            $where
            GROUP BY LOWER(TRIM(generic_name))
            ORDER BY generic_name ASC
        ";

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        json_response($results);
    } catch (Exception $e) {
        json_response(['error' => $e->getMessage()], 500);
    }
}

/**
 * GET /api/generics/brands?generic=Paracetamol
 * Returns all distinct brand medicines mapped to the given generic name with full live stock info.
 */
if ($uri === '/api/generics/brands' && $method === 'GET') {
    enforce_api_auth(['pharmacist']);
    $generic = trim($_GET['generic'] ?? '');
    if ($generic === '') {
        json_response(['error' => 'generic parameter is required'], 400);
    }
    $conn = get_db();
    try {
        $is_unmapped = (strtolower(trim($generic)) === '(unmapped)');
        
        if ($is_unmapped) {
            $stmt = $conn->prepare("
                SELECT
                    brand_name,
                    generic_name,
                    category,
                    batch_number,
                    expiry_date,
                    mrp,
                    stock,
                    supplier_name,
                    row_location,
                    col_location,
                    pack_size,
                    min_stock
                FROM (
                    SELECT
                        ai.item_name AS brand_name,
                        ai.generic_name,
                        ai.category,
                        ai.batch_number,
                        ai.expiry_date,
                        ai.mrp,
                        ai.stock,
                        s.name AS supplier_name,
                        ai.row_location,
                        ai.col_location,
                        ai.unit AS pack_size,
                        ai.min_stock
                    FROM agency_items ai
                    LEFT JOIN agency_suppliers s ON ai.supplier_id = s.id
                    WHERE ai.generic_name IS NULL OR TRIM(ai.generic_name) = ''
                    
                    UNION ALL
                    
                    SELECT
                        i.name AS brand_name,
                        i.generic_name,
                        i.category,
                        i.batch_number,
                        i.expiry_date,
                        i.mrp,
                        i.stock,
                        i.agency_name AS supplier_name,
                        i.row_location,
                        i.col_location,
                        i.tablets_per_strip AS pack_size,
                        i.min_stock
                    FROM inventory i
                    WHERE (i.generic_name IS NULL OR TRIM(i.generic_name) = '')
                      AND NOT EXISTS (
                          SELECT 1 FROM agency_items ai 
                          WHERE ai.item_name = i.name AND ai.batch_number = i.batch_number
                      )
                ) AS combined
                ORDER BY brand_name ASC, batch_number ASC
            ");
            $stmt->execute([]);
        } else {
            $stmt = $conn->prepare("
                SELECT
                    brand_name,
                    generic_name,
                    category,
                    batch_number,
                    expiry_date,
                    mrp,
                    stock,
                    agency_name AS supplier_name,
                    row_location,
                    col_location,
                    pack_size,
                    min_stock
                FROM generic_mappings
                WHERE TRIM(LOWER(generic_name)) = TRIM(LOWER(?))
                ORDER BY brand_name ASC, batch_number ASC
            ");
            $stmt->execute([$generic]);
        }
        $brands = $stmt->fetchAll(PDO::FETCH_ASSOC);
        json_response($brands);
    } catch (Exception $e) {
        json_response(['error' => $e->getMessage()], 500);
    }
}

/**
 * POST /api/generics/update-mapping
 * Body: { brand_name: "DOLO 650", new_generic: "Paracetamol + Caffeine" }
 *
 * Updates the generic_name for ALL rows matching brand_name in:
 *   1. agency_items  (item_name = brand_name)
 *   2. inventory     (name      = brand_name)
 * This ensures a single source of truth — one edit propagates everywhere.
 */
if ($uri === '/api/generics/update-mapping' && $method === 'POST') {
    enforce_api_auth(['pharmacist']);
    $orig_brand = trim($input['orig_brand_name'] ?? '');
    $orig_batch = trim($input['orig_batch_number'] ?? '');
    
    $brand_name  = trim($input['brand_name'] ?? '');
    $generic_name = trim($input['generic_name'] ?? '');
    $category = trim($input['category'] ?? 'Tablet');
    $batch_number = trim($input['batch_number'] ?? '');
    $expiry_date = trim($input['expiry_date'] ?? '');
    $mrp = (float)($input['mrp'] ?? 0);
    $stock = (int)($input['stock'] ?? 0);
    $supplier_name = trim($input['supplier_name'] ?? '');
    $row_location = trim($input['row_location'] ?? '');
    $col_location = trim($input['col_location'] ?? '');
    $pack_size = trim($input['pack_size'] ?? '');
    $min_stock = (int)($input['min_stock'] ?? 0);

    if ($orig_brand === '' || $orig_batch === '') {
        json_response(['error' => 'orig_brand_name and orig_batch_number are required'], 400);
    }

    $conn = get_db();
    try {
        $conn->beginTransaction();

        $supplier_id = null;
        if (!empty($supplier_name)) {
            $check = $conn->prepare("SELECT id FROM agency_suppliers WHERE name = ? OR company_name = ?");
            $check->execute([$supplier_name, $supplier_name]);
            $supplier_id = $check->fetchColumn();
            
            if (!$supplier_id) {
                $ins = $conn->prepare("INSERT INTO agency_suppliers (name, company_name) VALUES (?, ?)");
                $ins->execute([$supplier_name, $supplier_name]);
                $supplier_id = $conn->lastInsertId();
            }
        }

        // Update agency_items
        $stmt = $conn->prepare("
            UPDATE agency_items SET
                item_name = ?,
                generic_name = ?,
                category = ?,
                batch_number = ?,
                expiry_date = ?,
                mrp = ?,
                stock = ?,
                unit = ?,
                row_location = ?,
                col_location = ?,
                min_stock = ?,
                supplier_id = ?
            WHERE TRIM(LOWER(item_name)) = TRIM(LOWER(?)) AND TRIM(LOWER(batch_number)) = TRIM(LOWER(?))
        ");
        $stmt->execute([
            $brand_name, $generic_name, $category, $batch_number, $expiry_date,
            $mrp, $stock, $pack_size, $row_location, $col_location, $min_stock, $supplier_id,
            $orig_brand, $orig_batch
        ]);
        $agency_rows = $stmt->rowCount();

        // Update inventory
        $stmt2 = $conn->prepare("
            UPDATE inventory SET
                name = ?,
                generic_name = ?,
                category = ?,
                batch_number = ?,
                expiry_date = ?,
                mrp = ?,
                stock = ?,
                tablets_per_strip = ?,
                row_location = ?,
                col_location = ?,
                min_stock = ?,
                agency_name = ?
            WHERE TRIM(LOWER(name)) = TRIM(LOWER(?)) AND TRIM(LOWER(batch_number)) = TRIM(LOWER(?))
        ");
        $stmt2->execute([
            $brand_name, $generic_name, $category, $batch_number, $expiry_date,
            $mrp, $stock, $pack_size, $row_location, $col_location, $min_stock, $supplier_name,
            $orig_brand, $orig_batch
        ]);
        $inv_rows = $stmt2->rowCount();

        // Update generic_mappings
        $stmt_gm = $conn->prepare("
            INSERT INTO generic_mappings (
                brand_name, batch_number, generic_name, agency_name, stock, mrp, 
                row_location, col_location, category, pack_size, min_stock, expiry_date
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                brand_name = VALUES(brand_name),
                batch_number = VALUES(batch_number),
                generic_name = VALUES(generic_name),
                agency_name = VALUES(agency_name),
                stock = VALUES(stock),
                mrp = VALUES(mrp),
                row_location = VALUES(row_location),
                col_location = VALUES(col_location),
                category = VALUES(category),
                pack_size = VALUES(pack_size),
                min_stock = VALUES(min_stock),
                expiry_date = VALUES(expiry_date)
        ");
        $stmt_gm->execute([
            $brand_name, $batch_number, $generic_name, $supplier_name, $stock, $mrp,
            $row_location, $col_location, $category, $pack_size, $min_stock, $expiry_date
        ]);
        
        // If rename occurred, delete old record
        if (trim(strtolower($orig_brand)) !== trim(strtolower($brand_name)) || trim(strtolower($orig_batch)) !== trim(strtolower($batch_number))) {
            $conn->prepare("DELETE FROM generic_mappings WHERE TRIM(LOWER(brand_name)) = TRIM(LOWER(?)) AND TRIM(LOWER(batch_number)) = TRIM(LOWER(?))")->execute([$orig_brand, $orig_batch]);
        }

        // Audit trail
        $conn->prepare("
            INSERT INTO agency_audit_trail
                (user_id, action, table_name, record_id, old_value, new_value, details)
            VALUES (?, 'GENERIC_REMAP', 'agency_items+inventory', 0, ?, ?, ?)
        ")->execute([
            $_SESSION['user_id'] ?? 0,
            $orig_brand,
            $brand_name,
            "Updated medicine details for '$orig_brand' ($orig_batch) -> '$brand_name' ($batch_number)"
        ]);

        $conn->commit();
        json_response([
            'success'       => true,
            'agency_rows'   => $agency_rows,
            'inv_rows'      => $inv_rows,
            'message'       => "Medicine details updated successfully across $agency_rows agency + $inv_rows inventory records."
        ]);
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        json_response(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

/**
 * POST /api/generics/delete-generic
 * Body: { generic_name: "..." }
 * Clears the generic name (sets to NULL) for all matched rows in agency_items and inventory.
 */
if ($uri === '/api/generics/delete-generic' && $method === 'POST') {
    enforce_api_auth(['pharmacist']);
    $generic_name = trim($input['generic_name'] ?? '');
    if ($generic_name === '') {
        json_response(['error' => 'generic_name is required'], 400);
    }
    
    $conn = get_db();
    try {
        $conn->beginTransaction();

        // Update agency_items
        $stmt = $conn->prepare("
            UPDATE agency_items SET generic_name = NULL 
            WHERE TRIM(LOWER(generic_name)) = TRIM(LOWER(?))
        ");
        $stmt->execute([$generic_name]);
        $agency_rows = $stmt->rowCount();

        // Update inventory
        $stmt2 = $conn->prepare("
            UPDATE inventory SET generic_name = NULL 
            WHERE TRIM(LOWER(generic_name)) = TRIM(LOWER(?))
        ");
        $stmt2->execute([$generic_name]);
        $inv_rows = $stmt2->rowCount();

        // Update generic_mappings
        $conn->prepare("DELETE FROM generic_mappings WHERE TRIM(LOWER(generic_name)) = TRIM(LOWER(?))")->execute([$generic_name]);

        // Audit trail
        $conn->prepare("
            INSERT INTO agency_audit_trail
                (user_id, action, table_name, record_id, old_value, new_value, details)
            VALUES (?, 'GENERIC_DELETE', 'agency_items+inventory', 0, ?, NULL, ?)
        ")->execute([
            $_SESSION['user_id'] ?? 0,
            $generic_name,
            "Deleted generic medicine '$generic_name' mapping across $agency_rows agency + $inv_rows inventory records."
        ]);

        $conn->commit();
        json_response([
            'success'     => true,
            'message'     => "Generic medicine '$generic_name' deleted. Cleared mappings across $agency_rows agency + $inv_rows inventory records."
        ]);
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        json_response(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

/**
 * POST /api/generics/delete-brand-mapping
 * Body: { brand_name: "...", batch_number: "..." }
 * Clears the generic name (sets to NULL) for a specific brand mapping in agency_items and inventory.
 */
if ($uri === '/api/generics/delete-brand-mapping' && $method === 'POST') {
    enforce_api_auth(['pharmacist']);
    $brand_name = trim($input['brand_name'] ?? '');
    $batch_number = trim($input['batch_number'] ?? '');
    
    if ($brand_name === '' || $batch_number === '') {
        json_response(['error' => 'brand_name and batch_number are required'], 400);
    }
    
    $conn = get_db();
    try {
        $conn->beginTransaction();

        // Update agency_items
        $stmt = $conn->prepare("
            UPDATE agency_items SET generic_name = NULL 
            WHERE TRIM(LOWER(item_name)) = TRIM(LOWER(?)) AND TRIM(LOWER(batch_number)) = TRIM(LOWER(?))
        ");
        $stmt->execute([$brand_name, $batch_number]);
        $agency_rows = $stmt->rowCount();

        // Update inventory
        $stmt2 = $conn->prepare("
            UPDATE inventory SET generic_name = NULL 
            WHERE TRIM(LOWER(name)) = TRIM(LOWER(?)) AND TRIM(LOWER(batch_number)) = TRIM(LOWER(?))
        ");
        $stmt2->execute([$brand_name, $batch_number]);
        $inv_rows = $stmt2->rowCount();

        // Update generic_mappings
        $conn->prepare("DELETE FROM generic_mappings WHERE TRIM(LOWER(brand_name)) = TRIM(LOWER(?)) AND TRIM(LOWER(batch_number)) = TRIM(LOWER(?))")->execute([$brand_name, $batch_number]);

        // Audit log
        $conn->prepare("
            INSERT INTO agency_audit_trail
                (user_id, action, table_name, record_id, old_value, new_value, details)
            VALUES (?, 'BRAND_UNMAP', 'agency_items+inventory', 0, ?, NULL, ?)
        ")->execute([
            $_SESSION['user_id'] ?? 0,
            $brand_name,
            "Removed generic mapping for brand '$brand_name' (Batch: $batch_number) across $agency_rows agency + $inv_rows inventory records."
        ]);

        $conn->commit();
        json_response([
            'success' => true,
            'message' => "Mapping for brand '$brand_name' (Batch: $batch_number) deleted successfully."
        ]);
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        json_response(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

/**
 * POST /api/generics/delete-brand-all-mappings
 * Body: { brand_name: "..." }
 * Clears the generic name (sets to NULL) for ALL batches of a specific brand name in agency_items and inventory.
 */
if ($uri === '/api/generics/delete-brand-all-mappings' && $method === 'POST') {
    enforce_api_auth(['pharmacist']);
    $brand_name = trim($input['brand_name'] ?? '');
    
    if ($brand_name === '') {
        json_response(['error' => 'brand_name is required'], 400);
    }
    
    $conn = get_db();
    try {
        $conn->beginTransaction();

        // Update agency_items
        $stmt = $conn->prepare("
            UPDATE agency_items SET generic_name = NULL 
            WHERE TRIM(LOWER(item_name)) = TRIM(LOWER(?))
        ");
        $stmt->execute([$brand_name]);
        $agency_rows = $stmt->rowCount();

        // Update inventory
        $stmt2 = $conn->prepare("
            UPDATE inventory SET generic_name = NULL 
            WHERE TRIM(LOWER(name)) = TRIM(LOWER(?))
        ");
        $stmt2->execute([$brand_name]);
        $inv_rows = $stmt2->rowCount();

        // Update generic_mappings
        $conn->prepare("DELETE FROM generic_mappings WHERE TRIM(LOWER(brand_name)) = TRIM(LOWER(?))")->execute([$brand_name]);

        // Audit log
        $conn->prepare("
            INSERT INTO agency_audit_trail
                (user_id, action, table_name, record_id, old_value, new_value, details)
            VALUES (?, 'BRAND_ALL_UNMAP', 'agency_items+inventory', 0, ?, NULL, ?)
        ")->execute([
            $_SESSION['user_id'] ?? 0,
            $brand_name,
            "Removed all generic mappings for brand '$brand_name' across $agency_rows agency + $inv_rows inventory records."
        ]);

        $conn->commit();
        json_response([
            'success' => true,
            'message' => "Successfully removed generic mappings for all batches of brand '$brand_name'."
        ]);
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        json_response(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

/**
 * POST /api/generics/import
 * Body: { mappings: [ { brand_name: "...", generic_name: "..." }, ... ] }
 * Performs batch mapping update across both inventory tables.
 */
if ($uri === '/api/generics/import' && $method === 'POST') {
    enforce_api_auth(['pharmacist']);
    $mappings = $input['mappings'] ?? [];
    if (!is_array($mappings)) {
        json_response(['error' => 'Invalid mappings format'], 400);
    }
    
    $conn = get_db();
    
    $imported = 0;
    $duplicate = 0;
    $skipped = 0;
    $failed = 0;
    
    try {
        $conn->beginTransaction();
        
        // Prepare checks
        $check_agency = $conn->prepare("SELECT DISTINCT generic_name FROM agency_items WHERE TRIM(LOWER(item_name)) = TRIM(LOWER(?))");
        $check_inv = $conn->prepare("SELECT DISTINCT generic_name FROM inventory WHERE TRIM(LOWER(name)) = TRIM(LOWER(?))");
        
        // Prepare updates
        $update_agency = $conn->prepare("UPDATE agency_items SET generic_name = ? WHERE TRIM(LOWER(item_name)) = TRIM(LOWER(?))");
        $update_inv = $conn->prepare("UPDATE inventory SET generic_name = ? WHERE TRIM(LOWER(name)) = TRIM(LOWER(?))");
        
        // Prepare inserts
        $insert_agency = $conn->prepare("
            INSERT INTO agency_items 
                (item_name, generic_name, batch_number, stock, mrp, category, brand_name)
            VALUES (?, ?, ?, 0, 0.00, 'Tablet', ?)
        ");
        
        $check_exact_placeholder = $conn->prepare("SELECT COUNT(*) FROM agency_items WHERE TRIM(LOWER(generic_name)) = TRIM(LOWER(?)) AND item_name = '(Unmapped Brand)'");

        foreach ($mappings as $map) {
            $brand = trim($map['brand_name'] ?? '');
            $generic = trim($map['generic_name'] ?? '');
            
            if ($brand === '' && $generic === '') {
                $failed++;
                continue;
            }
            
            if ($brand !== '' && $generic === '') {
                // Scenario A: Brand Medicine Only
                // Check if brand exists in agency_items
                $check_agency->execute([$brand]);
                $agency_rows = $check_agency->fetchAll(PDO::FETCH_ASSOC);
                
                // Check if brand exists in inventory
                $check_inv->execute([$brand]);
                $inv_rows = $check_inv->fetchAll(PDO::FETCH_ASSOC);
                
                $exists = (count($agency_rows) > 0 || count($inv_rows) > 0);
                
                if ($exists) {
                    $duplicate++;
                } else {
                    $past_generic = get_mapped_generic_name($conn, $brand);
                    $mapped_gen = ($past_generic !== '') ? $past_generic : null;
                    
                    $insert_agency->execute([$brand, $mapped_gen, 'IMPORT-BATCH', $brand]);
                    $imported++;
                }
            }
            elseif ($brand === '' && $generic !== '') {
                // Scenario B: Generic Medicine Only
                $check_exact_placeholder->execute([$generic]);
                $placeholder_count = $check_exact_placeholder->fetchColumn();
                
                if ($placeholder_count > 0) {
                    $duplicate++;
                } else {
                    $insert_agency->execute(['(Unmapped Brand)', $generic, 'placeholder-batch', '(Unmapped Brand)']);
                    $imported++;
                }
            }
            else {
                // Scenario C: Both Generic & Brand
                // Check if brand exists in agency_items
                $check_agency->execute([$brand]);
                $agency_rows = $check_agency->fetchAll(PDO::FETCH_ASSOC);
                
                // Check if brand exists in inventory
                $check_inv->execute([$brand]);
                $inv_rows = $check_inv->fetchAll(PDO::FETCH_ASSOC);
                
                $exists = (count($agency_rows) > 0 || count($inv_rows) > 0);
                
                if ($exists) {
                    // Check if it is a duplicate (already mapped to this generic name)
                    $already_mapped = true;
                    foreach ($agency_rows as $row) {
                        if (trim($row['generic_name'] ?? '') !== $generic) {
                            $already_mapped = false;
                        }
                    }
                    foreach ($inv_rows as $row) {
                        if (trim($row['generic_name'] ?? '') !== $generic) {
                            $already_mapped = false;
                        }
                    }
                    
                    if ($already_mapped) {
                        $duplicate++;
                    } else {
                        // Perform the update
                        $update_agency->execute([$generic, $brand]);
                        $update_inv->execute([$generic, $brand]);
                        $imported++;
                    }
                } else {
                    // Brand does not exist in database yet, so insert new brand with this generic name
                    $insert_agency->execute([$brand, $generic, 'IMPORT-BATCH', $brand]);
                    $imported++;
                }
            }
        }
        
        // Audit trail
        $conn->prepare("
            INSERT INTO agency_audit_trail
                (user_id, action, table_name, record_id, old_value, new_value, details)
            VALUES (?, 'GENERIC_IMPORT', 'agency_items+inventory', 0, '', '', ?)
        ")->execute([
            $_SESSION['user_id'] ?? 0,
            "Imported mappings (Scenario A,B,C): $imported imported/created, $duplicate duplicate/skipped."
        ]);
        
        $conn->commit();
        json_response([
            'success' => true,
            'imported' => $imported,
            'duplicate' => $duplicate,
            'message' => "Import complete: $imported imported/created, $duplicate already existed."
        ]);
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        json_response(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

// 404 for API
json_response(['error' => 'API Endpoint not found'], 404);
