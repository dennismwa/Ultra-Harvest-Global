<?php
/**
 * Database Configuration and Helper Functions
 * Ultra Harvest Global
 */

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'zurihubc_Ultra_Harvest');  // Your database name
define('DB_USER', 'zurihubc_ultraharvest');   // Your database username
define('DB_PASS', 'your_database_password');   // Your database password

// Site Configuration
define('SITE_URL', 'https://your-domain.com');
define('SITE_NAME', 'Ultra Harvest Global');

// Security
define('CSRF_TOKEN_EXPIRE', 3600); // 1 hour

// Initialize database connection
try {
    $db = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("Database connection failed. Please try again later.");
}

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

/**
 * Authentication Functions
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function isAdmin() {
    return isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1;
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: /login.php');
        exit;
    }
}

function requireAdmin() {
    if (!isLoggedIn() || !isAdmin()) {
        header('Location: /login.php');
        exit;
    }
}

/**
 * CSRF Protection
 */
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
    }
    return $_SESSION['csrf_token'];
}

function verifyCSRFToken($token) {
    if (!isset($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_time'])) {
        return false;
    }
    
    // Check if token has expired
    if (time() - $_SESSION['csrf_token_time'] > CSRF_TOKEN_EXPIRE) {
        unset($_SESSION['csrf_token']);
        unset($_SESSION['csrf_token_time']);
        return false;
    }
    
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Input Sanitization
 */
function sanitize($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Money Formatting
 */
function formatMoney($amount) {
    return 'KSh ' . number_format($amount, 2);
}

/**
 * Time Functions
 */
function timeAgo($datetime) {
    $time = time() - strtotime($datetime);
    
    if ($time < 60) return 'just now';
    if ($time < 3600) return floor($time/60) . 'm ago';
    if ($time < 86400) return floor($time/3600) . 'h ago';
    if ($time < 2592000) return floor($time/86400) . 'd ago';
    if ($time < 31536000) return floor($time/2592000) . 'mo ago';
    
    return floor($time/31536000) . 'y ago';
}

/**
 * Notification System
 */
function sendNotification($user_id, $title, $message, $type = 'info') {
    global $db;
    try {
        $stmt = $db->prepare("
            INSERT INTO notifications (user_id, title, message, type) 
            VALUES (?, ?, ?, ?)
        ");
        return $stmt->execute([$user_id, $title, $message, $type]);
    } catch (Exception $e) {
        error_log("Failed to send notification: " . $e->getMessage());
        return false;
    }
}

function sendGlobalNotification($title, $message, $type = 'info') {
    global $db;
    try {
        $stmt = $db->prepare("
            INSERT INTO notifications (title, message, type, is_global) 
            VALUES (?, ?, ?, 1)
        ");
        return $stmt->execute([$title, $message, $type]);
    } catch (Exception $e) {
        error_log("Failed to send global notification: " . $e->getMessage());
        return false;
    }
}

/**
 * System Settings
 */
function getSystemSetting($key, $default = null) {
    global $db;
    try {
        $stmt = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch();
        return $result ? $result['setting_value'] : $default;
    } catch (Exception $e) {
        error_log("Failed to get system setting: " . $e->getMessage());
        return $default;
    }
}

function updateSystemSetting($key, $value) {
    global $db;
    try {
        $stmt = $db->prepare("
            INSERT INTO system_settings (setting_key, setting_value) 
            VALUES (?, ?) 
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        return $stmt->execute([$key, $value]);
    } catch (Exception $e) {
        error_log("Failed to update system setting: " . $e->getMessage());
        return false;
    }
}

/**
 * Referral Code Generation
 */
function generateReferralCode($length = 8) {
    $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $code;
}

/**
 * System Health Logging
 */
function logSystemHealth() {
    global $db;
    try {
        // Get statistics
        $stmt = $db->query("SELECT * FROM admin_stats_overview");
        $stats = $stmt->fetch();
        
        if ($stats) {
            $platform_liquidity = $stats['total_deposits'] - $stats['total_withdrawals'] - $stats['total_roi_paid'];
            $total_liabilities = $stats['total_user_balances'] + $stats['pending_roi_obligations'];
            $coverage_ratio = $total_liabilities > 0 ? $platform_liquidity / $total_liabilities : 1;
            
            $stmt = $db->prepare("
                INSERT INTO system_health_log (
                    total_deposits, total_withdrawals, total_roi_paid, 
                    pending_roi_obligations, user_wallet_balances, 
                    platform_liquidity, coverage_ratio, active_users, 
                    active_packages_count
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $stats['total_deposits'],
                $stats['total_withdrawals'],
                $stats['total_roi_paid'],
                $stats['pending_roi_obligations'],
                $stats['total_user_balances'],
                $platform_liquidity,
                $coverage_ratio,
                $stats['active_users'],
                $stats['active_packages']
            ]);
        }
    } catch (Exception $e) {
        error_log("Failed to log system health: " . $e->getMessage());
    }
}

/**
 * Database Schema Updates
 * Run this once to add missing fields
 */
function updateDatabaseSchema() {
    global $db;
    try {
        // Add mpesa_request_id to transactions table if it doesn't exist
        $db->exec("
            ALTER TABLE transactions 
            ADD COLUMN IF NOT EXISTS mpesa_request_id VARCHAR(100) DEFAULT NULL,
            ADD INDEX IF NOT EXISTS idx_mpesa_request_id (mpesa_request_id)
        ");
        
        echo "Database schema updated successfully.\n";
    } catch (Exception $e) {
        error_log("Database schema update failed: " . $e->getMessage());
        echo "Database schema update failed: " . $e->getMessage() . "\n";
    }
}

// Uncomment the line below to run the schema update once
// updateDatabaseSchema();

/**
 * Error Reporting for Development
 * Comment out in production
 */
if (isset($_GET['debug']) && $_GET['debug'] === '1') {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
}
?>
