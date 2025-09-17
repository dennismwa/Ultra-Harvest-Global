<?php
// config/database.php
session_start();

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'zurihubc_UltraHarvest');
define('DB_PASS', 'PU7qh=43R0Bk7Jfb');
define('DB_NAME', 'zurihubc_Ultra Harvest');

// Site Configuration
define('SITE_NAME', 'Ultra Harvest Global');
define('SITE_URL', 'https://ultraharvest.zurihub.co.ke/');
define('SITE_EMAIL', 'admin@ultraharvest.com');

// Security
define('CSRF_SECRET', 'ultra_harvest_csrf_secret_key_2024');

// Database Connection Class
class Database {
    private static $instance = null;
    private $connection;
    
    private function __construct() {
        try {
            $this->connection = new PDO(
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
            die("Database connection failed: " . $e->getMessage());
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }
}

// Helper Functions
function sanitize($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

function generateReferralCode($length = 8) {
    $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $code;
}

function formatMoney($amount) {
    return 'KSh ' . number_format($amount, 2);
}

function timeAgo($datetime) {
    $time = time() - strtotime($datetime);
    
    if ($time < 60) return 'Just now';
    if ($time < 3600) return floor($time/60) . ' minutes ago';
    if ($time < 86400) return floor($time/3600) . ' hours ago';
    if ($time < 2592000) return floor($time/86400) . ' days ago';
    if ($time < 31536000) return floor($time/2592000) . ' months ago';
    
    return date('M j, Y', strtotime($datetime));
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    return isset($_SESSION['user_id']) && isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1;
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: /login.php');
        exit;
    }
}

function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        header('Location: /user/dashboard.php');
        exit;
    }
}

function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function sendNotification($user_id, $title, $message, $type = 'info') {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, ?)");
    return $stmt->execute([$user_id, $title, $message, $type]);
}

function getSystemSetting($key, $default = '') {
    static $settings = [];
    
    if (empty($settings)) {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->query("SELECT setting_key, setting_value FROM system_settings");
        while ($row = $stmt->fetch()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }
    
    return $settings[$key] ?? $default;
}

function updateSystemSetting($key, $value) {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("UPDATE system_settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = ?");
    return $stmt->execute([$value, $key]);
}

function logSystemHealth() {
    $db = Database::getInstance()->getConnection();
    
    // Get system statistics
    $stats = $db->query("SELECT * FROM admin_stats_overview")->fetch();
    
    $coverage_ratio = $stats['total_user_balances'] > 0 ? 
        ($stats['total_deposits'] - $stats['total_withdrawals'] - $stats['total_roi_paid']) / ($stats['total_user_balances'] + $stats['pending_roi_obligations']) : 1;
    
    $stmt = $db->prepare("
        INSERT INTO system_health_log 
        (total_deposits, total_withdrawals, total_roi_paid, pending_roi_obligations, 
         user_wallet_balances, platform_liquidity, coverage_ratio, active_users, active_packages_count) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    return $stmt->execute([
        $stats['total_deposits'],
        $stats['total_withdrawals'],
        $stats['total_roi_paid'],
        $stats['pending_roi_obligations'],
        $stats['total_user_balances'],
        $stats['total_deposits'] - $stats['total_withdrawals'] - $stats['total_roi_paid'],
        $coverage_ratio,
        $stats['active_users'],
        $stats['active_packages']
    ]);
}

// Error Handling
function logError($message, $file = '', $line = '') {
    $log = date('Y-m-d H:i:s') . " - Error: $message";
    if ($file) $log .= " in $file";
    if ($line) $log .= " on line $line";
    error_log($log . PHP_EOL, 3, __DIR__ . '/../logs/errors.log');
}

// Set error handler
set_error_handler(function($severity, $message, $file, $line) {
    logError($message, $file, $line);
});

// Initialize database connection
$db = Database::getInstance()->getConnection();

// Check for maintenance mode
if (getSystemSetting('site_maintenance', '0') == '1' && !isAdmin()) {
    if (!strpos($_SERVER['REQUEST_URI'], 'maintenance.php')) {
        header('Location: /maintenance.php');
        exit;
    }
}
?>