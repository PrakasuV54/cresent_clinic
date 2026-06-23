<?php
/**
 * Authentication and Session Management
 */

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', 86400); // 24 hours
    ini_set('session.cookie_lifetime', 86400); // 24 hours
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_samesite', 'Lax');

    require_once __DIR__ . '/session_handler.php';
    
    // Serverless cold-start initialization: Seed the ephemeral DB if it's empty
    if (!file_exists(DB_PATH) || filesize(DB_PATH) === 0) {
        init_db();
    } else {
        // Also ensure newer tables exist on warm starts
        try {
            $check = get_db()->query("SELECT 1 FROM agency_purchases LIMIT 1");
        } catch (Exception $e) {
            init_db();
        }
    }

    session_set_save_handler(new DatabaseSessionHandler(), true);
    session_start();
}

function login_required($role = null) {
    if (!isset($_SESSION['user_id'])) {
        header('Location: /login');
        exit;
    }
    
    // Management/Admin has access to everything
    if ($_SESSION['role'] === 'management') {
        return;
    }

    if ($role && $_SESSION['role'] !== $role) {
        header('Location: /login');
        exit;
    }
}

function get_session_user() {
    return isset($_SESSION['user_id']) ? $_SESSION : null;
}
