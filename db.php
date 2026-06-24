<?php
/**
 * Database Configuration and Helpers (PHP/PDO Version)
 */

$env_path = __DIR__ . '/.env';
if (file_exists($env_path)) {
    $lines = file($env_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            putenv(trim($name) . '=' . trim($value));
            $_ENV[trim($name)] = trim($value);
        }
    }
}

// Persistent SQLite for standard shared hosting
require_once __DIR__ . '/supabase_storage.php';

define('DB_PATH', __DIR__ . DIRECTORY_SEPARATOR . 'hospital_portal.db');

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

function get_db()
{
    static $conn = null;
    if ($conn !== null) {
        return $conn;
    }

    try {
        $db_url = getenv('TURSO_DATABASE_URL');
        $db_token = getenv('TURSO_AUTH_TOKEN');

        if ($db_url && $db_token) {
            error_log("Warning: Turso/LibSQL remote connections are not natively supported by PHP PDO on Vercel without a custom client. Falling back to ephemeral local SQLite database.");
        }
        
        $conn = new PDO('sqlite:' . DB_PATH);
        $conn->exec("PRAGMA journal_mode=WAL");

        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $conn->exec("PRAGMA foreign_keys=ON");

        return $conn;
    } catch (PDOException $e) {
        die("DB Connection Error: " . $e->getMessage());
    }
}

/**
 * Initialize Database - Ported from app.py init_db()
 */
function init_db()
{
    $conn = get_db();
    $conn->exec("PRAGMA foreign_keys=OFF");

    // Create Tables
    $conn->exec("CREATE TABLE IF NOT EXISTS sessions (
        id TEXT PRIMARY KEY,
        data TEXT NOT NULL,
        expires_at INTEGER NOT NULL
    )");

    $conn->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        password TEXT NOT NULL,
        role TEXT NOT NULL,
        doctor_type TEXT,
        display_name TEXT,
        specialization TEXT,
        details TEXT,
        photo_path TEXT,
        token_prefix TEXT,
        is_active INTEGER DEFAULT 1
    )");

    $conn->exec("CREATE TABLE IF NOT EXISTS system_settings (
        setting_key TEXT PRIMARY KEY,
        setting_value TEXT
    )");

    $conn->exec("CREATE TABLE IF NOT EXISTS whatsapp_backup_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        backup_date DATE NOT NULL UNIQUE,
        status TEXT NOT NULL,
        attempts INTEGER DEFAULT 1,
        last_attempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        error_message TEXT,
        pdf_path TEXT
    )");

    // Migration: Check if specialization column exists, if not, do table rebuild
    try {
        $stmt_users = $conn->query("SELECT specialization, details, photo_path FROM users LIMIT 1");
        $stmt_users->closeCursor();
        $stmt_users = null;
    } catch (Exception $e) {
        $conn->exec("CREATE TABLE users_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            role TEXT NOT NULL,
            doctor_type TEXT,
            display_name TEXT,
            specialization TEXT,
            details TEXT,
            photo_path TEXT
        )");
        $conn->exec("INSERT INTO users_new (id, username, password, role, doctor_type, display_name) SELECT id, username, password, role, doctor_type, display_name FROM users");
        $conn->exec("DROP TABLE users");
        $conn->exec("ALTER TABLE users_new RENAME TO users");
    }

    // Migration: Check if token_prefix column exists
    $stmt = $conn->query("PRAGMA table_info(users)");
    $cols = $stmt->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('token_prefix', $cols)) {
        $conn->exec("ALTER TABLE users ADD COLUMN token_prefix TEXT");
        $conn->exec("UPDATE users SET token_prefix = substr(doctor_type, 1, 1) WHERE role='doctor' AND doctor_type IS NOT NULL");
    }

    // Migration: Check if is_active column exists
    $stmt = $conn->query("PRAGMA table_info(users)");
    $cols = $stmt->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('is_active', $cols)) {
        $conn->exec("ALTER TABLE users ADD COLUMN is_active INTEGER DEFAULT 1");
    }

    // Migration: Check if doctor_registration_number column exists
    $stmt = $conn->query("PRAGMA table_info(users)");
    $cols = $stmt->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('doctor_registration_number', $cols)) {
        $conn->exec("ALTER TABLE users ADD COLUMN doctor_registration_number TEXT");
    }


    $conn->exec("CREATE TABLE IF NOT EXISTS patients (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        age INTEGER NOT NULL,
        gender TEXT NOT NULL,
        phone TEXT NOT NULL,
        address TEXT,
        doctor_id INTEGER,
        doctor_type TEXT NOT NULL,
        doctor_name TEXT NOT NULL,
        complaint TEXT,
        bp TEXT,
        temp TEXT,
        pulse TEXT,
        weight TEXT,
        height TEXT,
        token TEXT NOT NULL,
        status TEXT DEFAULT 'waiting' CHECK(status IN ('waiting','prescribed','completed')),
        created_at TIMESTAMP DEFAULT (datetime('now', '+05:30')),
        completed_at TIMESTAMP,
        spo2 INTEGER,
        patient_id TEXT
    )");

    // Migration: Remove check constraint on doctor_type in patients table
    $patient_sql = false;
    try {
        $stmt = $conn->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='patients'");
        if ($stmt) {
            $patient_sql = $stmt->fetchColumn();
            $stmt->closeCursor();
            $stmt = null;
        }
    } catch (Exception $e) {
        // Ignore schema read errors (e.g. lack of permissions on serverless platforms)
    }

    if ($patient_sql && strpos($patient_sql, "CHECK(doctor_type IN ('Gent','Lady'))") !== false) {
        $conn->exec("DROP TABLE IF EXISTS patients_new");
        $conn->exec("CREATE TABLE patients_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            age INTEGER NOT NULL,
            gender TEXT NOT NULL,
            phone TEXT NOT NULL,
            address TEXT,
            doctor_id INTEGER,
            doctor_type TEXT NOT NULL,
            doctor_name TEXT NOT NULL,
            complaint TEXT,
            bp TEXT,
            temp TEXT,
            pulse TEXT,
            weight TEXT,
            height TEXT,
            token TEXT NOT NULL,
            status TEXT DEFAULT 'waiting' CHECK(status IN ('waiting','prescribed','completed')),
            created_at TIMESTAMP DEFAULT (datetime('now', '+05:30')),
            completed_at TIMESTAMP,
            spo2 INTEGER,
            patient_id TEXT
        )");
        $conn->exec("INSERT INTO patients_new SELECT * FROM patients");
        $conn->exec("DROP TABLE patients");
        $conn->exec("ALTER TABLE patients_new RENAME TO patients");
    }

    $conn->exec("CREATE TABLE IF NOT EXISTS prescriptions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        patient_id INTEGER NOT NULL,
        doctor_id INTEGER,
        doctor_name TEXT NOT NULL,
        doctor_type TEXT,
        consultation_fee REAL DEFAULT 0.00,
        diagnosis TEXT,
        prescription_text TEXT,
        medicines TEXT,
        total_amount REAL DEFAULT 0.00,
        status TEXT DEFAULT 'pending' CHECK(status IN ('pending','dispensed')),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        scan_fee REAL DEFAULT 0.00,
        cost_amount REAL DEFAULT 0.00,
        diagnosis_photo TEXT,
        prescription_photo TEXT,
        upt_card BOOLEAN DEFAULT 0,
        injection_details TEXT,
        iv_details TEXT,
        injection_cost REAL DEFAULT 0.00,
        iv_cost REAL DEFAULT 0.00,
        upt_cost REAL DEFAULT 0.00,
        cash_amount REAL DEFAULT 0.00,
        gpay_amount REAL DEFAULT 0.00,
        paid_amount REAL DEFAULT 0.00,
        balance_amount REAL DEFAULT 0.00,
        FOREIGN KEY (patient_id) REFERENCES patients(id)
    )");
    $conn->exec("CREATE TABLE IF NOT EXISTS direct_sales (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        customer_name TEXT NOT NULL,
        mobile_number TEXT NOT NULL,
        medicines TEXT,
        injection_details TEXT,
        iv_details TEXT,
        injection_cost REAL DEFAULT 0.00,
        iv_cost REAL DEFAULT 0.00,
        upt_card BOOLEAN DEFAULT 0,
        upt_cost REAL DEFAULT 0.00,
        total_amount REAL DEFAULT 0.00,
        discount_percent REAL DEFAULT 0.00,
        cash_amount REAL DEFAULT 0.00,
        gpay_amount REAL DEFAULT 0.00,
        paid_amount REAL DEFAULT 0.00,
        balance_amount REAL DEFAULT 0.00,
        cost_amount REAL DEFAULT 0.00,
        status TEXT DEFAULT 'completed' CHECK(status IN ('pending','completed')),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $conn->exec("CREATE TABLE IF NOT EXISTS inventory (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        item_code TEXT,
        name TEXT NOT NULL,
        category TEXT DEFAULT 'Tablet',
        hsn_code TEXT,
        batch_number TEXT NOT NULL,
        mfg_date TEXT,
        expiry_date TEXT,
        mrp REAL DEFAULT 0.00,
        purchase_price REAL DEFAULT 0.00,
        selling_price REAL DEFAULT 0.00,
        opening_stock INTEGER DEFAULT 0,
        stock INTEGER DEFAULT 0,
        min_stock INTEGER DEFAULT 0,
        location TEXT,
        tablets_per_strip INTEGER DEFAULT 0,
        UNIQUE(name, batch_number)
    )");

    // Ensure doctor_id exists in patients and prescriptions
    $stmt = $conn->query("PRAGMA table_info(patients)");
    $cols = $stmt->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('doctor_id', $cols)) {
        $conn->exec("ALTER TABLE patients ADD COLUMN doctor_id INTEGER");
    }

    $stmt = $conn->query("PRAGMA table_info(prescriptions)");
    $cols = $stmt->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('doctor_id', $cols)) {
        $conn->exec("ALTER TABLE prescriptions ADD COLUMN doctor_id INTEGER");
    }
    if (!in_array('prev_balance_cleared', $cols)) {
        $conn->exec("ALTER TABLE prescriptions ADD COLUMN prev_balance_cleared REAL DEFAULT 0.00");
    }

    if (!in_array('discount_percent', $cols)) {
        $conn->exec("ALTER TABLE prescriptions ADD COLUMN discount_percent REAL DEFAULT 0.00");
    }

    // Migration: Populate doctor_id from existing data if possible
    $conn->exec("UPDATE patients SET doctor_id = (SELECT id FROM users WHERE users.display_name = patients.doctor_name OR users.username = patients.doctor_type LIMIT 1) WHERE doctor_id IS NULL");
    $conn->exec("UPDATE prescriptions SET doctor_id = (SELECT id FROM users WHERE users.display_name = prescriptions.doctor_name OR users.username = prescriptions.doctor_type LIMIT 1) WHERE doctor_id IS NULL");


    // Seed default users if none exist
    $stmt = $conn->query("SELECT COUNT(*) FROM users");
    if ($stmt->fetchColumn() == 0) {
        $seed = [];
        
        $pw_rec = getenv('DEFAULT_RECEPTION_PASSWORD');
        if ($pw_rec) $seed[] = ['receptionist', $pw_rec, 'receptionist', null, 'Receptionist', null];
        else error_log("Warning: DEFAULT_RECEPTION_PASSWORD missing. Receptionist account not created.");
        
        $pw_doc = getenv('DEFAULT_DOCTOR_PASSWORD');
        if ($pw_doc) {
            $seed[] = ['dr.rasith', $pw_doc, 'doctor', 'Gent', 'Dr. Mohamed Rasith Sir', 'G'];
            $seed[] = ['dr.basheera', $pw_doc, 'doctor', 'Lady', 'Dr. Jannathul Basheera Mam', 'L'];
        } else {
            error_log("Warning: DEFAULT_DOCTOR_PASSWORD missing. Doctor accounts not created.");
        }
        
        $pw_pha = getenv('DEFAULT_PHARMACIST_PASSWORD');
        if ($pw_pha) $seed[] = ['pharmacist', $pw_pha, 'pharmacist', null, 'Pharmacist', null];
        else error_log("Warning: DEFAULT_PHARMACIST_PASSWORD missing. Pharmacist account not created.");
        
        $pw_adm = getenv('DEFAULT_ADMIN_PASSWORD');
        if ($pw_adm) $seed[] = ['management', $pw_adm, 'management', null, 'Management Admin', null];
        else error_log("Warning: DEFAULT_ADMIN_PASSWORD missing. Management Admin account not created.");

        if (!empty($seed)) {
            $insert = $conn->prepare("INSERT INTO users (username, password, role, doctor_type, display_name, token_prefix) VALUES (?,?,?,?,?,?)");
            foreach ($seed as $user) {
                $insert->execute($user);
            }
        }
    }

    $conn->exec("CREATE TABLE IF NOT EXISTS staff_records (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        phone TEXT NOT NULL,
        education TEXT,
        role TEXT,
        salary REAL DEFAULT 0.00,
        status TEXT DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_salary_paid_date TEXT
    )");

    // Migration: Check if last_salary_paid_date column exists
    $stmt = $conn->query("PRAGMA table_info(staff_records)");
    $cols = $stmt->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('last_salary_paid_date', $cols)) {
        $conn->exec("ALTER TABLE staff_records ADD COLUMN last_salary_paid_date TEXT");
    }

    $conn->exec("CREATE TABLE IF NOT EXISTS staff_payments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        staff_id INTEGER NOT NULL,
        payment_type TEXT CHECK(payment_type IN ('Salary', 'Advance', 'Bonus')),
        payment_month TEXT,
        amount REAL DEFAULT 0.00,
        payment_date DATE,
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (staff_id) REFERENCES staff_records(id)
    )");

    // Migration: Check if min_stock column exists in inventory table
    $stmt = $conn->query("PRAGMA table_info(inventory)");
    $cols = $stmt->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('min_stock', $cols)) {
        $conn->exec("ALTER TABLE inventory ADD COLUMN min_stock INTEGER DEFAULT 0");
    }
    // Missing Agency and System Tables for Cold-Start
    $conn->exec("CREATE TABLE IF NOT EXISTS agency_categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE NOT NULL, description TEXT, status TEXT DEFAULT 'Active')");
    $conn->exec("CREATE TABLE IF NOT EXISTS agency_suppliers (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, company_name TEXT, phone TEXT, email TEXT, address TEXT, gst_number TEXT, whatsapp TEXT, dl_number TEXT, payment_type TEXT, total_purchase REAL DEFAULT 0.00, payment_status TEXT DEFAULT 'Not Paid', paid_amount REAL DEFAULT 0.00, pending_balance REAL DEFAULT 0.00, cash_amount REAL DEFAULT 0.00, gpay_amount REAL DEFAULT 0.00, city TEXT, state TEXT, pincode TEXT, status TEXT DEFAULT 'Active', outstanding_balance REAL DEFAULT 0.00, phonepe_amount REAL DEFAULT 0.00, bank_amount REAL DEFAULT 0.00, upi_account TEXT DEFAULT NULL)");
    $conn->exec("CREATE TABLE IF NOT EXISTS agency_items (id INTEGER PRIMARY KEY AUTOINCREMENT, item_code TEXT, item_name TEXT NOT NULL, generic_name TEXT, brand_name TEXT, category TEXT, medicine_type TEXT, unit TEXT, batch_number TEXT NOT NULL, purchase_price REAL DEFAULT 0.00, selling_price REAL DEFAULT 0.00, gst REAL DEFAULT 0.00, opening_stock INTEGER DEFAULT 0, stock INTEGER DEFAULT 0, min_stock INTEGER DEFAULT 0, expiry_date TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, hsn_code TEXT, mfg_date TEXT, mrp REAL DEFAULT 0.00, discount REAL DEFAULT 0.00, manufacturer TEXT, supplier_id INTEGER, gst_percentage REAL DEFAULT 0.00, reorder_level INTEGER DEFAULT 0, rack_location TEXT, barcode TEXT, qr_code TEXT, UNIQUE(item_name, batch_number))");
    $conn->exec("CREATE TABLE IF NOT EXISTS agency_purchases (id INTEGER PRIMARY KEY AUTOINCREMENT, supplier_id INTEGER, invoice_number TEXT, purchase_date TEXT, sub_total REAL DEFAULT 0.00, gst_total REAL DEFAULT 0.00, grand_total REAL DEFAULT 0.00, image_path TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, payment_mode TEXT, transport_details TEXT, doctor_name TEXT, clinic_name TEXT, doctor_reg_no TEXT, contact_number TEXT, address TEXT, credit_days INTEGER DEFAULT 0, due_date TEXT, transport_name TEXT, vehicle_number TEXT, lr_number TEXT, transport_date TEXT, cgst_total REAL DEFAULT 0.00, sgst_total REAL DEFAULT 0.00, discount_total REAL DEFAULT 0.00, payment_status TEXT DEFAULT 'Pending', paid_amount REAL DEFAULT 0.00, balance_amount REAL DEFAULT 0.00, cash_amount REAL DEFAULT 0.00, gpay_amount REAL DEFAULT 0.00, upi_reference TEXT, transaction_id TEXT, payment_date TEXT, bank_name TEXT, due_amount REAL DEFAULT 0.00, outstanding_balance REAL DEFAULT 0.00, purchase_type TEXT, phonepe_amount REAL DEFAULT 0.00, bank_amount REAL DEFAULT 0.00, upi_account TEXT DEFAULT NULL, account_id INTEGER DEFAULT NULL, FOREIGN KEY (supplier_id) REFERENCES agency_suppliers(id))");
    $conn->exec("CREATE TABLE IF NOT EXISTS agency_purchase_items (id INTEGER PRIMARY KEY AUTOINCREMENT, purchase_id INTEGER, item_id INTEGER, quantity INTEGER DEFAULT 0, unit TEXT, purchase_rate REAL DEFAULT 0.00, gst REAL DEFAULT 0.00, total_amount REAL DEFAULT 0.00, free_qty INTEGER DEFAULT 0, discount REAL DEFAULT 0.00, hsn_code TEXT, generic_name TEXT, category TEXT, batch_number TEXT, mfg_date TEXT, expiry_date TEXT, mrp REAL DEFAULT 0.00, cgst REAL DEFAULT 0.00, sgst REAL DEFAULT 0.00, taxable_amount REAL DEFAULT 0.00, discount_percentage REAL DEFAULT 0.00, gst_percentage REAL DEFAULT 0.00, tax_amount REAL DEFAULT 0.00, FOREIGN KEY (purchase_id) REFERENCES agency_purchases(id), FOREIGN KEY (item_id) REFERENCES agency_items(id))");
    $conn->exec("CREATE TABLE IF NOT EXISTS agency_stock_adjustments (id INTEGER PRIMARY KEY AUTOINCREMENT, item_id INTEGER, quantity INTEGER, reason TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (item_id) REFERENCES agency_items(id))");
    $conn->exec("CREATE TABLE IF NOT EXISTS agency_stock_transfers (id INTEGER PRIMARY KEY AUTOINCREMENT, item_id INTEGER, quantity INTEGER, to_location TEXT, transfer_date TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (item_id) REFERENCES agency_items(id))");
    $conn->exec("CREATE TABLE IF NOT EXISTS agency_ocr_documents (id INTEGER PRIMARY KEY AUTOINCREMENT, file_path TEXT, extracted_data TEXT, status TEXT DEFAULT 'pending', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $conn->exec("CREATE TABLE IF NOT EXISTS agency_purchase_returns (id INTEGER PRIMARY KEY AUTOINCREMENT, supplier_id INTEGER, purchase_id INTEGER, return_date TEXT, total_amount REAL DEFAULT 0.00, reason TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $conn->exec("CREATE TABLE IF NOT EXISTS agency_return_items (id INTEGER PRIMARY KEY AUTOINCREMENT, return_id INTEGER, item_id INTEGER, quantity INTEGER, amount REAL DEFAULT 0.00)");
    $conn->exec("CREATE TABLE IF NOT EXISTS agency_audit_trail (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT, table_name TEXT, record_id INTEGER, old_value TEXT, new_value TEXT, timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP, details TEXT)");
    $conn->exec("CREATE TABLE IF NOT EXISTS agency_inventory_movements (id INTEGER PRIMARY KEY AUTOINCREMENT, item_id INTEGER, movement_type TEXT, quantity INTEGER, reference_id INTEGER, reference_type TEXT, notes TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (item_id) REFERENCES agency_items(id))");
    $conn->exec("CREATE TABLE IF NOT EXISTS medicine_returns (id INTEGER PRIMARY KEY AUTOINCREMENT, return_date TEXT, return_time TEXT, patient_name TEXT, bill_number TEXT, medicine_name TEXT, returned_qty REAL, processed_by TEXT, reason TEXT, sale_type TEXT, sale_id INTEGER, patient_id INTEGER, unit_price REAL, return_amount REAL, total_refund_amount REAL, refund_payment_mode TEXT, return_type TEXT DEFAULT 'Single Tablet', account_id INTEGER DEFAULT NULL)");
    $conn->exec("CREATE TABLE IF NOT EXISTS upi_accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, account_name TEXT NOT NULL, short_name TEXT UNIQUE NOT NULL, bank_name TEXT, account_number TEXT, upi_id TEXT, notes TEXT, is_active INTEGER DEFAULT 1, account_holder_name TEXT DEFAULT NULL, ifsc_code TEXT DEFAULT NULL)");
    $conn->exec("CREATE TABLE IF NOT EXISTS system_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)");
    $conn->exec("CREATE TABLE IF NOT EXISTS whatsapp_backup_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, backup_date DATE NOT NULL UNIQUE, status TEXT NOT NULL, attempts INTEGER DEFAULT 1, last_attempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP, error_message TEXT, pdf_path TEXT)");
    // Ensure monitor user exists
    $stmt = $conn->query("SELECT COUNT(*) FROM users WHERE role='monitor'");
    if ($stmt->fetchColumn() == 0) {
        $pw_mon = getenv('DEFAULT_MONITOR_PASSWORD');
        if ($pw_mon) {
            $stmt_mon = $conn->prepare("INSERT INTO users (username, password, role, display_name) VALUES (?, ?, 'monitor', 'TV Monitor')");
            $stmt_mon->execute(['monitor', $pw_mon]);
        } else {
            error_log("Warning: DEFAULT_MONITOR_PASSWORD missing. Monitor account not created.");
        }
    }

    $conn->exec("PRAGMA foreign_keys=ON");
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

    $stmt = $conn->prepare("SELECT token FROM patients WHERE doctor_id = ? AND DATE(created_at) = DATE('now', 'localtime')");
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
