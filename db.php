<?php
/**
 * Database Configuration and Helpers (PHP/PDO Version)
 */

$env_path = __DIR__ . '/.env';
if (file_exists($env_path)) {
    $lines = file($env_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (is_array($lines)) {
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            if (strpos($line, '=') !== false) {
                list($name, $value) = explode('=', $line, 2);
                putenv(trim($name) . '=' . trim($value));
                $_ENV[trim($name)] = trim($value);
            }
        }
    }
}

require_once __DIR__ . '/supabase_storage.php';

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

function get_db()
{
    static $conn = null;
    static $migrated = false;

    if ($conn !== null) {
        return $conn;
    }

    try {
        $host = getenv('DB_HOST') ?: 'localhost';
        $dbname = getenv('DB_NAME') ?: 'u526658771_crescent';
        $username = getenv('DB_USER') ?: 'u526658771_nnp';
        $password = getenv('DB_PASSWORD') ?: 'Namaraja@4';

        $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // Auto-initialize if database is empty (check if 'users' table exists)
        $stmt = $conn->query("SHOW TABLES LIKE 'users'");
        if (!$stmt->fetch()) {
            init_db_schema($conn);
        }

        // Always run column migrations once per process to keep existing DBs up-to-date
        if (!$migrated) {
            $migrated = true;
            // Get all existing columns of the inventory table
            $existing_cols = [];
            try {
                $q = $conn->query("SHOW COLUMNS FROM inventory");
                if ($q) {
                    while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
                        $existing_cols[] = strtolower($row['Field']);
                    }
                }
            } catch (Exception $e) {
                // Table might not exist yet or other error, fallback to exec with try-catch
            }

            $col_migrations = [
                'generic_name' => "ALTER TABLE inventory ADD COLUMN generic_name VARCHAR(255) DEFAULT NULL",
                'brand_name' => "ALTER TABLE inventory ADD COLUMN brand_name VARCHAR(255) DEFAULT NULL",
                'agency_name' => "ALTER TABLE inventory ADD COLUMN agency_name VARCHAR(255) DEFAULT NULL",
                'row_location' => "ALTER TABLE inventory ADD COLUMN row_location VARCHAR(100) DEFAULT NULL",
                'col_location' => "ALTER TABLE inventory ADD COLUMN col_location VARCHAR(100) DEFAULT NULL",
            ];

            foreach ($col_migrations as $col_name => $sql) {
                if (empty($existing_cols) || !in_array(strtolower($col_name), $existing_cols)) {
                    try {
                        $conn->exec($sql);
                    } catch (Exception $e) {
                        // Fallback/ignore if already exists or fails
                    }
                }
            }

            // Create generic_mappings table if not exists
            try {
                $conn->exec("
                    CREATE TABLE IF NOT EXISTS generic_mappings (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        generic_name VARCHAR(255) NOT NULL,
                        brand_name VARCHAR(255) NOT NULL,
                        agency_name VARCHAR(255) DEFAULT NULL,
                        item_id INT DEFAULT NULL,
                        stock INT DEFAULT 0,
                        batch_number VARCHAR(100) DEFAULT NULL,
                        mrp DECIMAL(10, 2) DEFAULT 0.00,
                        purchase_rate DECIMAL(10, 2) DEFAULT 0.00,
                        selling_rate DECIMAL(10, 2) DEFAULT 0.00,
                        row_location VARCHAR(100) DEFAULT NULL,
                        col_location VARCHAR(100) DEFAULT NULL,
                        category VARCHAR(100) DEFAULT NULL,
                        pack_size VARCHAR(100) DEFAULT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        UNIQUE KEY uniq_brand_batch (brand_name, batch_number)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
            } catch (Exception $e) {
                // Ignore if error
            }
        }

        return $conn;
    } catch (PDOException $e) {
        // Return JSON so the JS api() helper can parse the error instead of crashing
        if (!headers_sent()) {
            header('Content-Type: application/json');
            http_response_code(500);
        }
        die(json_encode(['error' => 'DB Connection Error: ' . $e->getMessage()]));
    }
}

function init_db_schema($conn) {
    init_db();
}

/**
 * Initialize Database - Ported from app.py init_db()
 */
function init_db()
{
    $conn = get_db();
    $conn->exec("SET FOREIGN_KEY_CHECKS = 0");

    // Create Tables using MySQL compatible syntax
    $conn->exec("CREATE TABLE IF NOT EXISTS sessions (
        id VARCHAR(255) PRIMARY KEY,
        data TEXT NOT NULL,
        expires_at INT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(255) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        role VARCHAR(50) NOT NULL,
        doctor_type VARCHAR(50),
        display_name VARCHAR(255),
        specialization VARCHAR(255),
        details TEXT,
        photo_path VARCHAR(255),
        token_prefix VARCHAR(50),
        is_active TINYINT DEFAULT 1,
        admin_security_password VARCHAR(255) DEFAULT '123',
        doctor_registration_number VARCHAR(255)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->exec("CREATE TABLE IF NOT EXISTS system_settings (
        setting_key VARCHAR(255) PRIMARY KEY,
        setting_value TEXT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->exec("CREATE TABLE IF NOT EXISTS whatsapp_backup_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        backup_date DATE NOT NULL UNIQUE,
        status VARCHAR(50) NOT NULL,
        attempts INT DEFAULT 1,
        last_attempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        error_message TEXT,
        pdf_path VARCHAR(255)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->exec("CREATE TABLE IF NOT EXISTS patients (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        age INT NOT NULL,
        gender VARCHAR(50) NOT NULL,
        phone VARCHAR(50) NOT NULL,
        address TEXT,
        doctor_id INT,
        doctor_type VARCHAR(50) NOT NULL,
        doctor_name VARCHAR(255) NOT NULL,
        complaint TEXT,
        bp VARCHAR(50),
        temp VARCHAR(50),
        pulse VARCHAR(50),
        weight VARCHAR(50),
        height VARCHAR(50),
        token VARCHAR(50) NOT NULL,
        status VARCHAR(50) DEFAULT 'waiting',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        completed_at TIMESTAMP NULL DEFAULT NULL,
        spo2 INT,
        patient_id VARCHAR(100)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->exec("CREATE TABLE IF NOT EXISTS prescriptions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        patient_id INT NOT NULL,
        doctor_id INT,
        doctor_name VARCHAR(255) NOT NULL,
        doctor_type VARCHAR(50),
        consultation_fee DECIMAL(10,2) DEFAULT 0.00,
        diagnosis TEXT,
        prescription_text TEXT,
        medicines TEXT,
        total_amount DECIMAL(10,2) DEFAULT 0.00,
        status VARCHAR(50) DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        scan_fee DECIMAL(10,2) DEFAULT 0.00,
        cost_amount DECIMAL(10,2) DEFAULT 0.00,
        diagnosis_photo VARCHAR(255),
        prescription_photo VARCHAR(255),
        upt_card TINYINT DEFAULT 0,
        injection_details TEXT,
        iv_details TEXT,
        injection_cost DECIMAL(10,2) DEFAULT 0.00,
        iv_cost DECIMAL(10,2) DEFAULT 0.00,
        upt_cost DECIMAL(10,2) DEFAULT 0.00,
        cash_amount DECIMAL(10,2) DEFAULT 0.00,
        gpay_amount DECIMAL(10,2) DEFAULT 0.00,
        paid_amount DECIMAL(10,2) DEFAULT 0.00,
        balance_amount DECIMAL(10,2) DEFAULT 0.00,
        discount_percent DECIMAL(5,2) DEFAULT 0.00,
        scan_type VARCHAR(100) DEFAULT '-',
        scan_notes TEXT,
        phonepe_amount DECIMAL(10,2) DEFAULT 0.00,
        bank_amount DECIMAL(10,2) DEFAULT 0.00,
        upi_account VARCHAR(100) DEFAULT NULL,
        account_id INT DEFAULT NULL,
        prev_balance_cleared DECIMAL(10,2) DEFAULT 0.00,
        FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->exec("CREATE TABLE IF NOT EXISTS direct_sales (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_name VARCHAR(255) NOT NULL,
        mobile_number VARCHAR(50) NOT NULL,
        medicines TEXT,
        injection_details TEXT,
        iv_details TEXT,
        injection_cost DECIMAL(10,2) DEFAULT 0.00,
        iv_cost DECIMAL(10,2) DEFAULT 0.00,
        upt_card TINYINT DEFAULT 0,
        upt_cost DECIMAL(10,2) DEFAULT 0.00,
        total_amount DECIMAL(10,2) DEFAULT 0.00,
        discount_percent DECIMAL(5,2) DEFAULT 0.00,
        cash_amount DECIMAL(10,2) DEFAULT 0.00,
        gpay_amount DECIMAL(10,2) DEFAULT 0.00,
        paid_amount DECIMAL(10,2) DEFAULT 0.00,
        balance_amount DECIMAL(10,2) DEFAULT 0.00,
        cost_amount DECIMAL(10,2) DEFAULT 0.00,
        status VARCHAR(50) DEFAULT 'completed',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        phonepe_amount DECIMAL(10,2) DEFAULT 0.00,
        bank_amount DECIMAL(10,2) DEFAULT 0.00,
        payment_history TEXT,
        upi_account VARCHAR(100) DEFAULT NULL,
        account_id INT DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->exec("CREATE TABLE IF NOT EXISTS inventory (
        id INT AUTO_INCREMENT PRIMARY KEY,
        item_code VARCHAR(255),
        name VARCHAR(255) NOT NULL,
        generic_name VARCHAR(255),
        brand_name VARCHAR(255),
        agency_name VARCHAR(255),
        category VARCHAR(100) DEFAULT 'Tablet',
        hsn_code VARCHAR(100),
        batch_number VARCHAR(255) NOT NULL,
        mfg_date VARCHAR(100),
        expiry_date VARCHAR(100),
        mrp DECIMAL(10,2) DEFAULT 0.00,
        purchase_price DECIMAL(10,2) DEFAULT 0.00,
        selling_price DECIMAL(10,2) DEFAULT 0.00,
        opening_stock INT DEFAULT 0,
        stock INT DEFAULT 0,
        min_stock INT DEFAULT 0,
        location VARCHAR(255),
        tablets_per_strip INT DEFAULT 0,
        supplier_id INT,
        row_location VARCHAR(100),
        col_location VARCHAR(100),
        UNIQUE KEY uq_inventory_name_batch (name, batch_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Seed default users if none exist
    $stmt = $conn->query("SELECT COUNT(*) FROM users");
    if ($stmt->fetchColumn() == 0) {
        $seed = [];
        
        $pw_rec = getenv('DEFAULT_RECEPTION_PASSWORD') ?: 'reception123';
        $seed[] = ['receptionist', $pw_rec, 'receptionist', null, 'Receptionist', null];
        
        $pw_doc = getenv('DEFAULT_DOCTOR_PASSWORD') ?: '11';
        $seed[] = ['dr.rasith', $pw_doc, 'doctor', 'Gent', 'Dr. Mohamed Rasith Sir', 'G'];
        $seed[] = ['dr.basheera', $pw_doc, 'doctor', 'Lady', 'Dr. Jannathul Basheera Mam', 'L'];
        
        $pw_pha = getenv('DEFAULT_PHARMACIST_PASSWORD') ?: 'pharma123';
        $seed[] = ['pharmacist', $pw_pha, 'pharmacist', null, 'Pharmacist', null];
        
        $pw_adm = getenv('DEFAULT_ADMIN_PASSWORD') ?: 'admin123';
        $seed[] = ['management', $pw_adm, 'management', null, 'Management Admin', null];

        if (!empty($seed)) {
            $insert = $conn->prepare("INSERT INTO users (username, password, role, doctor_type, display_name, token_prefix) VALUES (?,?,?,?,?,?)");
            foreach ($seed as $user) {
                $insert->execute($user);
            }
        }
    }

    $conn->exec("CREATE TABLE IF NOT EXISTS staff_records (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        phone VARCHAR(50) NOT NULL,
        education VARCHAR(255),
        role VARCHAR(100),
        salary DECIMAL(10,2) DEFAULT 0.00,
        status VARCHAR(50) DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_salary_paid_date VARCHAR(100)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->exec("CREATE TABLE IF NOT EXISTS staff_payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        staff_id INT NOT NULL,
        payment_type VARCHAR(50) NOT NULL,
        payment_month VARCHAR(50),
        amount DECIMAL(10,2) DEFAULT 0.00,
        payment_date DATE,
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (staff_id) REFERENCES staff_records(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Missing Agency and System Tables for Cold-Start
    $conn->exec("CREATE TABLE IF NOT EXISTS agency_categories (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) UNIQUE NOT NULL, description TEXT, status VARCHAR(50) DEFAULT 'Active') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->exec("CREATE TABLE IF NOT EXISTS agency_suppliers (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, company_name VARCHAR(255), phone VARCHAR(50), email VARCHAR(255), address TEXT, gst_number VARCHAR(100), whatsapp VARCHAR(50), dl_number VARCHAR(100), payment_type VARCHAR(100), total_purchase DECIMAL(10,2) DEFAULT 0.00, payment_status VARCHAR(50) DEFAULT 'Not Paid', paid_amount DECIMAL(10,2) DEFAULT 0.00, pending_balance DECIMAL(10,2) DEFAULT 0.00, cash_amount DECIMAL(10,2) DEFAULT 0.00, gpay_amount DECIMAL(10,2) DEFAULT 0.00, city VARCHAR(100), state VARCHAR(100), pincode VARCHAR(50), status VARCHAR(50) DEFAULT 'Active', outstanding_balance DECIMAL(10,2) DEFAULT 0.00, phonepe_amount DECIMAL(10,2) DEFAULT 0.00, bank_amount DECIMAL(10,2) DEFAULT 0.00, upi_account VARCHAR(100) DEFAULT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->exec("CREATE TABLE IF NOT EXISTS agency_items (id INT AUTO_INCREMENT PRIMARY KEY, item_code VARCHAR(255), item_name VARCHAR(255) NOT NULL, generic_name VARCHAR(255), brand_name VARCHAR(255), category VARCHAR(100), medicine_type VARCHAR(100), unit VARCHAR(100), batch_number VARCHAR(255) NOT NULL, purchase_price DECIMAL(10,2) DEFAULT 0.00, selling_price DECIMAL(10,2) DEFAULT 0.00, gst DECIMAL(10,2) DEFAULT 0.00, opening_stock INT DEFAULT 0, stock INT DEFAULT 0, min_stock INT DEFAULT 0, expiry_date VARCHAR(100), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, hsn_code VARCHAR(100), mfg_date VARCHAR(100), mrp DECIMAL(10,2) DEFAULT 0.00, discount DECIMAL(10,2) DEFAULT 0.00, manufacturer VARCHAR(255), supplier_id INT, gst_percentage DECIMAL(5,2) DEFAULT 0.00, reorder_level INT DEFAULT 0, rack_location VARCHAR(255), barcode VARCHAR(255), qr_code VARCHAR(255), UNIQUE KEY uq_agency_items_name_batch (item_name, batch_number)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->exec("CREATE TABLE IF NOT EXISTS agency_purchases (id INT AUTO_INCREMENT PRIMARY KEY, supplier_id INT, invoice_number VARCHAR(255), purchase_date VARCHAR(100), sub_total DECIMAL(10,2) DEFAULT 0.00, gst_total DECIMAL(10,2) DEFAULT 0.00, grand_total DECIMAL(10,2) DEFAULT 0.00, image_path VARCHAR(255), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, payment_mode VARCHAR(100), transport_details TEXT, doctor_name VARCHAR(255), clinic_name VARCHAR(255), doctor_reg_no VARCHAR(255), contact_number VARCHAR(50), address TEXT, credit_days INT DEFAULT 0, due_date VARCHAR(100), transport_name VARCHAR(255), vehicle_number VARCHAR(100), lr_number VARCHAR(100), transport_date VARCHAR(100), cgst_total DECIMAL(10,2) DEFAULT 0.00, sgst_total DECIMAL(10,2) DEFAULT 0.00, discount_total DECIMAL(10,2) DEFAULT 0.00, payment_status VARCHAR(100) DEFAULT 'Pending', paid_amount DECIMAL(10,2) DEFAULT 0.00, balance_amount DECIMAL(10,2) DEFAULT 0.00, cash_amount DECIMAL(10,2) DEFAULT 0.00, gpay_amount DECIMAL(10,2) DEFAULT 0.00, upi_reference VARCHAR(255), transaction_id VARCHAR(255), payment_date VARCHAR(100), bank_name VARCHAR(255), due_amount DECIMAL(10,2) DEFAULT 0.00, outstanding_balance DECIMAL(10,2) DEFAULT 0.00, purchase_type VARCHAR(100), phonepe_amount DECIMAL(10,2) DEFAULT 0.00, bank_amount DECIMAL(10,2) DEFAULT 0.00, upi_account VARCHAR(100) DEFAULT NULL, account_id INT DEFAULT NULL, FOREIGN KEY (supplier_id) REFERENCES agency_suppliers(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->exec("CREATE TABLE IF NOT EXISTS agency_purchase_items (id INT AUTO_INCREMENT PRIMARY KEY, purchase_id INT, item_id INT, quantity INT DEFAULT 0, unit VARCHAR(100), purchase_rate DECIMAL(10,2) DEFAULT 0.00, gst DECIMAL(10,2) DEFAULT 0.00, total_amount DECIMAL(10,2) DEFAULT 0.00, free_qty INT DEFAULT 0, discount DECIMAL(10,2) DEFAULT 0.00, hsn_code VARCHAR(100), generic_name VARCHAR(255), category VARCHAR(100), batch_number VARCHAR(255), mfg_date VARCHAR(100), expiry_date VARCHAR(100), mrp DECIMAL(10,2) DEFAULT 0.00, cgst DECIMAL(10,2) DEFAULT 0.00, sgst DECIMAL(10,2) DEFAULT 0.00, taxable_amount DECIMAL(10,2) DEFAULT 0.00, discount_percentage DECIMAL(5,2) DEFAULT 0.00, gst_percentage DECIMAL(5,2) DEFAULT 0.00, tax_amount DECIMAL(10,2) DEFAULT 0.00, FOREIGN KEY (purchase_id) REFERENCES agency_purchases(id) ON DELETE CASCADE, FOREIGN KEY (item_id) REFERENCES agency_items(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->exec("CREATE TABLE IF NOT EXISTS agency_stock_adjustments (id INT AUTO_INCREMENT PRIMARY KEY, item_id INT, quantity INT, reason TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (item_id) REFERENCES agency_items(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->exec("CREATE TABLE IF NOT EXISTS agency_stock_transfers (id INT AUTO_INCREMENT PRIMARY KEY, item_id INT, quantity INT, to_location VARCHAR(255), transfer_date VARCHAR(100), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (item_id) REFERENCES agency_items(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->exec("CREATE TABLE IF NOT EXISTS agency_ocr_documents (id INT AUTO_INCREMENT PRIMARY KEY, file_path VARCHAR(255), extracted_data TEXT, status VARCHAR(50) DEFAULT 'pending', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->exec("CREATE TABLE IF NOT EXISTS agency_purchase_returns (id INT AUTO_INCREMENT PRIMARY KEY, supplier_id INT, purchase_id INT, return_date VARCHAR(100), total_amount DECIMAL(10,2) DEFAULT 0.00, reason TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->exec("CREATE TABLE IF NOT EXISTS agency_return_items (id INT AUTO_INCREMENT PRIMARY KEY, return_id INT, item_id INT, quantity INT, amount DECIMAL(10,2) DEFAULT 0.00) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->exec("CREATE TABLE IF NOT EXISTS agency_audit_trail (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, action VARCHAR(255), table_name VARCHAR(255), record_id INT, old_value TEXT, new_value TEXT, timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP, details TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->exec("CREATE TABLE IF NOT EXISTS agency_inventory_movements (id INT AUTO_INCREMENT PRIMARY KEY, item_id INT, movement_type VARCHAR(100), quantity INT, reference_id INT, reference_type VARCHAR(100), notes TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (item_id) REFERENCES agency_items(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->exec("CREATE TABLE IF NOT EXISTS medicine_returns (id INT AUTO_INCREMENT PRIMARY KEY, return_date VARCHAR(100), return_time VARCHAR(100), patient_name VARCHAR(255), bill_number VARCHAR(100), medicine_name VARCHAR(255), returned_qty DECIMAL(10,2), processed_by VARCHAR(255), reason TEXT, sale_type VARCHAR(100), sale_id INT, patient_id INT, unit_price DECIMAL(10,2), return_amount DECIMAL(10,2), total_refund_amount DECIMAL(10,2), refund_payment_mode VARCHAR(100), return_type VARCHAR(100) DEFAULT 'Single Tablet', account_id INT DEFAULT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->exec("CREATE TABLE IF NOT EXISTS upi_accounts (id INT AUTO_INCREMENT PRIMARY KEY, account_name VARCHAR(255) NOT NULL, short_name VARCHAR(255) UNIQUE NOT NULL, bank_name VARCHAR(255), account_number VARCHAR(100), upi_id VARCHAR(255), notes TEXT, is_active TINYINT DEFAULT 1, account_holder_name VARCHAR(255) DEFAULT NULL, ifsc_code VARCHAR(100) DEFAULT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Ensure monitor user exists
    $stmt = $conn->query("SELECT COUNT(*) FROM users WHERE role='monitor'");
    if ($stmt->fetchColumn() == 0) {
        $pw_mon = getenv('DEFAULT_MONITOR_PASSWORD') ?: '123';
        $stmt_mon = $conn->prepare("INSERT INTO users (username, password, role, display_name) VALUES (?, ?, 'monitor', 'TV Monitor')");
        $stmt_mon->execute(['monitor', $pw_mon]);
    }

    $conn->exec("SET FOREIGN_KEY_CHECKS = 1");
}

/**
 * Doctor naming logic
 */
function format_doctor_name($name, $type)
{
    if (!$name || $name === 'Unknown Doctor')
        return $name;
    $suffix = (stripos($type, 'Gent') !== false) ? ' (Sir)' : ' (Madam)';
    if (strpos($name, '(Sir)') !== false || strpos($name, '(Madam)') !== false)
        return $name;
    return $name . $suffix;
}

function get_doctor_name($doctor_id, $formatted = false)
{
    try {
        $conn = get_db();
        $stmt = $conn->prepare("SELECT display_name, doctor_type FROM users WHERE id=? LIMIT 1");
        $stmt->execute([$doctor_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            if ($formatted)
                return format_doctor_name($row['display_name'], $row['doctor_type']);
            return $row['display_name'];
        }
    } catch (Exception $e) {
    }

    return 'Unknown Doctor';
}

/**
 * Fetch all active doctors
 */
function get_all_doctors($only_active = true)
{
    try {
        $conn = get_db();
        $sql = "SELECT * FROM users WHERE role='doctor'";
        if ($only_active) {
            $sql .= " AND is_active = 1";
        }
        $sql .= " ORDER BY doctor_type ASC";
        $stmt = $conn->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Token generation logic
 */
function generate_token($doctor_id)
{
    $conn = get_db();

    // Get doctor info for prefix
    $stmt = $conn->prepare("SELECT token_prefix FROM users WHERE id = ?");
    $stmt->execute([$doctor_id]);
    $prefix = $stmt->fetchColumn() ?: 'D';
    $prefix = strtoupper($prefix);

    $stmt = $conn->prepare("SELECT token FROM patients WHERE doctor_id = ? AND DATE(created_at) = CURDATE()");
    $stmt->execute([$doctor_id]);

    $rows = $stmt->fetchAll();

    $used_numbers = [];
    foreach ($rows as $row) {
        $parts = explode('-', $row['token']);
        if (count($parts) > 1) {
            $used_numbers[] = (int) $parts[1];
        }
    }

    $next_num = 1;
    while (in_array($next_num, $used_numbers)) {
        $next_num++;
    }

    return sprintf("%s-%03d", $prefix, $next_num);
}

function resolve_upi_account($conn, $account_id) { if (!$account_id) return null; $stmt = $conn->prepare('SELECT short_name FROM upi_accounts WHERE id=?'); $stmt->execute([$account_id]); $res = $stmt->fetchColumn(); return $res ?: null; }
