<?php
require_once '../config/database.php';
require_once '../config/mpesa.php';
requireAdmin();

$error = '';
$success = '';

// Handle settings update
if ($_POST) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $section = $_POST['section'] ?? '';
        
        try {
            switch ($section) {
                case 'mpesa':
                    $settings = [
                        'mpesa_consumer_key' => sanitize($_POST['mpesa_consumer_key'] ?? ''),
                        'mpesa_consumer_secret' => sanitize($_POST['mpesa_consumer_secret'] ?? ''),
                        'mpesa_shortcode' => sanitize($_POST['mpesa_shortcode'] ?? ''),
                        'mpesa_passkey' => sanitize($_POST['mpesa_passkey'] ?? ''),
                        'mpesa_environment' => sanitize($_POST['mpesa_environment'] ?? 'sandbox')
                    ];
                    
                    foreach ($settings as $key => $value) {
                        updateSystemSetting($key, $value);
                    }
                    
                    // Test M-Pesa connection if credentials provided
                    if (!empty($settings['mpesa_consumer_key']) && !empty($settings['mpesa_consumer_secret'])) {
                        $mpesa = new MpesaIntegration();
                        $test_result = $mpesa->testConnection();
                        if (!$test_result['success']) {
                            $error = 'M-Pesa settings saved but connection test failed: ' . $test_result['message'];
                        } else {
                            $success = 'M-Pesa settings saved and connection tested successfully.';
                        }
                    } else {
                        $success = 'M-Pesa settings saved successfully.';
                    }
                    break;
                    
                case 'referral':
                    $settings = [
                        'referral_commission_l1' => (float)($_POST['referral_commission_l1'] ?? 10),
                        'referral_commission_l2' => (float)($_POST['referral_commission_l2'] ?? 5)
                    ];
                    
                    foreach ($settings as $key => $value) {
                        updateSystemSetting($key, $value);
                    }
                    
                    $success = 'Referral settings updated successfully.';
                    break;
                    
                case 'withdrawal':
                    $settings = [
                        'min_withdrawal_amount' => (float)($_POST['min_withdrawal_amount'] ?? 100),
                        'max_withdrawal_amount' => (float)($_POST['max_withdrawal_amount'] ?? 1000000),
                        'withdrawal_processing_time' => (int)($_POST['withdrawal_processing_time'] ?? 24)
                    ];
                    
                    foreach ($settings as $key => $value) {
                        updateSystemSetting($key, $value);
                    }
                    
                    $success = 'Withdrawal settings updated successfully.';
                    break;
                    
                case 'system':
                    $settings = [
                        'platform_fee_percentage' => (float)($_POST['platform_fee_percentage'] ?? 0),
                        'auto_roi_processing' => isset($_POST['auto_roi_processing']) ? '1' : '0',
                        'site_maintenance' => isset($_POST['site_maintenance']) ? '1' : '0',
                        'email_notifications' => isset($_POST['email_notifications']) ? '1' : '0',
                        'sms_notifications' => isset($_POST['sms_notifications']) ? '1' : '0'
                    ];
                    
                    foreach ($settings as $key => $value) {
                        updateSystemSetting($key, $value);
                    }
                    
                    $success = 'System settings updated successfully.';
                    break;
                    
                default:
                    $error = 'Invalid settings section.';
            }
        } catch (Exception $e) {
            $error = 'Failed to update settings: ' . $e->getMessage();
        }
    }
}

// Get current settings
$current_settings = [];
$stmt = $db->query("SELECT setting_key, setting_value FROM system_settings");
while ($row = $stmt->fetch()) {
    $current_settings[$row['setting_key']] = $row['setting_value'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings - Ultra Harvest Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap');
        * { font-family: 'Poppins', sans-serif; }
        
        .glass-card {
            backdrop-filter: blur(20px);
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .setting-section {
            display: none;
        }
        
        .setting-section.active {
            display: block;
        }
        
        .nav-item.active {
            background: linear-gradient(45deg, #10b981, #34d399);
            color: white;
        }
    </style>
</head>
<body class="bg-gray-900 text-white min-h-screen">

    <!-- Header -->
    <header class="bg-gray-800/50 backdrop-blur-md border-b border-gray-700 sticky top-0 z-50">
        <div class="container mx-auto px-4">
            <div class="flex items-center justify-between h-16">
                <div class="flex items-center space-x-8">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 bg-gradient-to-r from-emerald-500 to-yellow-500 rounded-full flex items-center justify-center">
                            <i class="fas fa-seedling text-white"></i>
                        </div>
                        <div>
                            <span class="text-xl font-bold bg-gradient-to-r from-emerald-400 to-yellow-400 bg-clip-text text-transparent">Ultra Harvest</span>
                            <p class="text-xs text-gray-400">Admin Panel</p>
                        </div>
                    </div>
                    
                    <nav class="hidden md:flex space-x-6">
                        <a href="/admin/" class="text-gray-300 hover:text-emerald-400 transition">Dashboard</a>
                        <a href="/admin/users.php" class="text-gray-300 hover:text-emerald-400 transition">Users</a>
                        <a href="/admin/packages.php" class="text-gray-300 hover:text-emerald-400 transition">Packages</a>
                        <a href="/admin/transactions.php" class="text-gray-300 hover:text-emerald-400 transition">Transactions</a>
                        <a href="/admin/system-health.php" class="text-gray-300 hover:text-emerald-400 transition">System Health</a>
                        <a href="/admin/settings.php" class="text-emerald-400 font-medium">Settings</a>
                    </nav>
                </div>

                <div class="flex items-center space-x-4">
                    <a href="/logout.php" class="text-red-400 hover:text-red-300">
                        <i class="fas fa-sign-out-alt"></i>
                    </a>
                </div>
            </div>
        </div>
    </header>

    <main class="container mx-auto px-4 py-8">
        
        <!-- Page Header -->
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-white mb-2">System Settings</h1>
            <p class="text-gray-400">Configure platform settings and integrations</p>
        </div>

        <!-- Error/Success Messages -->
        <?php if ($error): ?>
        <div class="mb-6 p-4 bg-red-500/20 border border-red-500/50 rounded-lg">
            <div class="flex items-center">
                <i class="fas fa-exclamation-circle text-red-400 mr-2"></i>
                <span class="text-red-300"><?php echo $error; ?></span>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($success): ?>
        <div class="mb-6 p-4 bg-emerald-500/20 border border-emerald-500/50 rounded-lg">
            <div class="flex items-center">
                <i class="fas fa-check-circle text-emerald-400 mr-2"></i>
                <span class="text-emerald-300"><?php echo $success; ?></span>
            </div>
        </div>
        <?php endif; ?>

        <div class="grid lg:grid-cols-4 gap-8">
            
            <!-- Settings Navigation -->
            <div class="lg:col-span-1">
                <div class="glass-card rounded-xl p-6">
                    <h3 class="text-lg font-bold text-white mb-4">Settings Categories</h3>
                    <nav class="space-y-2">
                        <button onclick="showSection('mpesa')" class="nav-item w-full text-left px-4 py-3 rounded-lg transition" data-section="mpesa">
                            <i class="fas fa-mobile-alt mr-3"></i>M-Pesa Integration
                        </button>
                        <button onclick="showSection('referral')" class="nav-item w-full text-left px-4 py-3 rounded-lg transition" data-section="referral">
                            <i class="fas fa-users mr-3"></i>Referral System
                        </button>
                        <button onclick="showSection('withdrawal')" class="nav-item w-full text-left px-4 py-3 rounded-lg transition" data-section="withdrawal">
                            <i class="fas fa-arrow-up mr-3"></i>Withdrawals
                        </button>
                        <button onclick="showSection('system')" class="nav-item w-full text-left px-4 py-3 rounded-lg transition" data-section="system">
                            <i class="fas fa-cog mr-3"></i>System
                        </button>
                    </nav>
                </div>
            </div>

            <!-- Settings Content -->
            <div class="lg:col-span-3">
                
                <!-- M-Pesa Settings -->
                <div id="mpesa-section" class="setting-section active">
                    <div class="glass-card rounded-xl p-6">
                        <div class="flex items-center justify-between mb-6">
                            <div>
                                <h3 class="text-xl font-bold text-white">M-Pesa Integration</h3>
                                <p class="text-gray-400">Configure M-Pesa API credentials for payments</p>
                            </div>
                            <div class="flex items-center space-x-2">
                                <div class="w-3 h-3 <?php echo !empty($current_settings['mpesa_consumer_key']) ? 'bg-emerald-500' : 'bg-red-500'; ?> rounded-full"></div>
                                <span class="text-sm <?php echo !empty($current_settings['mpesa_consumer_key']) ? 'text-emerald-400' : 'text-red-400'; ?>">
                                    <?php echo !empty($current_settings['mpesa_consumer_key']) ? 'Configured' : 'Not Configured'; ?>
                                </span>
                            </div>
                        </div>
                        
                        <form method="POST" class="space-y-6">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            <input type="hidden" name="section" value="mpesa">
                            
                            <div class="grid md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-2">Consumer Key *</label>
                                    <input 
                                        type="text" 
                                        name="mpesa_consumer_key" 
                                        value="<?php echo htmlspecialchars($current_settings['mpesa_consumer_key'] ?? ''); ?>"
                                        class="w-full px-4 py-3 bg-gray-800 border border-gray-600 rounded-lg text-white focus:border-emerald-500 focus:outline-none"
                                        placeholder="Enter M-Pesa Consumer Key"
                                    >
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-2">Consumer Secret *</label>
                                    <input 
                                        type="password" 
                                        name="mpesa_consumer_secret" 
                                        value="<?php echo htmlspecialchars($current_settings['mpesa_consumer_secret'] ?? ''); ?>"
                                        class="w-full px-4 py-3 bg-gray-800 border border-gray-600 rounded-lg text-white focus:border-emerald-500 focus:outline-none"
                                        placeholder="Enter M-Pesa Consumer Secret"
                                    >
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-2">Business Shortcode *</label>
                                    <input 
                                        type="text" 
                                        name="mpesa_shortcode" 
                                        value="<?php echo htmlspecialchars($current_settings['mpesa_shortcode'] ?? ''); ?>"
                                        class="w-full px-4 py-3 bg-gray-800 border border-gray-600 rounded-lg text-white focus:border-emerald-500 focus:outline-none"
                                        placeholder="e.g., 174379"
                                    >
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-2">Passkey *</label>
                                    <input 
                                        type="password" 
                                        name="mpesa_passkey" 
                                        value="<?php echo htmlspecialchars($current_settings['mpesa_passkey'] ?? ''); ?>"
                                        class="w-full px-4 py-3 bg-gray-800 border border-gray-600 rounded-lg text-white focus:border-emerald-500 focus:outline-none"
                                        placeholder="Enter M-Pesa Passkey"
                                    >
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-2">Environment</label>
                                    <select name="mpesa_environment" class="w-full px-4 py-3 bg-gray-800 border border-gray-600 rounded-lg text-white focus:border-emerald-500 focus:outline-none">
                                        <option value="sandbox" <?php echo ($current_settings['mpesa_environment'] ?? 'sandbox') === 'sandbox' ? 'selected' : ''; ?>>Sandbox (Testing)</option>
                                        <option value="live" <?php echo ($current_settings['mpesa_environment'] ?? 'sandbox') === 'live' ? 'selected' : ''; ?>>Live (Production)</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="bg-yellow-500/10 border border-yellow-500/30 rounded-lg p-4">
                                <div class="flex items-start space-x-3">
                                    <i class="fas fa-exclamation-triangle text-yellow-400 mt-1"></i>
                                    <div>
                                        <h4 class="font-bold text-yellow-400 mb-1">Important Notes:</h4>
                                        <ul class="text-sm text-gray-300 space-y-1">
                                            <li>• Use Sandbox environment for testing</li>
                                            <li>• Switch to Live only in production</li>
                                            <li>• Keep credentials secure and private</li>
                                            <li>• Test connection after saving changes</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="flex space-x-3">
                                <button type="submit" class="px-6 py-3 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg font-medium transition">
                                    <i class="fas fa-save mr-2"></i>Save M-Pesa Settings
                                </button>
                                <button type="button" onclick="testMpesaConnection()" class="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition">
                                    <i class="fas fa-plug mr-2"></i>Test Connection
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Referral Settings -->
                <div id="referral-section" class="setting-section">
                    <div class="glass-card rounded-xl p-6">
                        <div class="mb-6">
                            <h3 class="text-xl font-bold text-white">Referral System</h3>
                            <p class="text-gray-400">Configure referral commission rates</p>
                        </div>
                        
                        <form method="POST" class="space-y-6">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            <input type="hidden" name="section" value="referral">
                            
                            <div class="grid md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-2">Level 1 Commission (%)</label>
                                    <input 
                                        type="number" 
                                        name="referral_commission_l1" 
                                        value="<?php echo htmlspecialchars($current_settings['referral_commission_l1'] ?? '10'); ?>"
                                        min="0" 
                                        max="50" 
                                        step="0.1"
                                        class="w-full px-4 py-3 bg-gray-800 border border-gray-600 rounded-lg text-white focus:border-emerald-500 focus:outline-none"
                                    >
                                    <p class="text-xs text-gray-500 mt-1">Commission for direct referrals</p>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-2">Level 2 Commission (%)</label>
                                    <input 
                                        type="number" 
                                        name="referral_commission_l2" 
                                        value="<?php echo htmlspecialchars($current_settings['referral_commission_l2'] ?? '5'); ?>"
                                        min="0" 
                                        max="25" 
                                        step="0.1"
                                        class="w-full px-4 py-3 bg-gray-800 border border-gray-600 rounded-lg text-white focus:border-emerald-500 focus:outline-none"
                                    >
                                    <p class="text-xs text-gray-500 mt-1">Commission for indirect referrals</p>
                                </div>
                            </div>
                            
                            <div class="bg-blue-500/10 border border-blue-500/30 rounded-lg p-4">
                                <h4 class="font-bold text-blue-400 mb-2">How Referral System Works:</h4>
                                <div class="text-sm text-gray-300 space-y-1">
                                    <p>• <strong>Level 1:</strong> User directly referred by another user</p>
                                    <p>• <strong>Level 2:</strong> User referred by a Level 1 referral</p>
                                    <p>• Commissions are paid on deposits and ROI payments</p>
                                    <p>• Commissions are automatically credited to referrer wallets</p>
                                </div>
                            </div>
                            
                            <button type="submit" class="px-6 py-3 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg font-medium transition">
                                <i class="fas fa-save mr-2"></i>Save Referral Settings
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Withdrawal Settings -->
                <div id="withdrawal-section" class="setting-section">
                    <div class="glass-card rounded-xl p-6">
                        <div class="mb-6">
                            <h3 class="text-xl font-bold text-white">Withdrawal Settings</h3>
                            <p class="text-gray-400">Configure withdrawal limits and processing</p>
                        </div>
                        
                        <form method="POST" class="space-y-6">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            <input type="hidden" name="section" value="withdrawal">
                            
                            <div class="grid md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-2">Minimum Withdrawal (KSh)</label>
                                    <input 
                                        type="number" 
                                        name="min_withdrawal_amount" 
                                        value="<?php echo htmlspecialchars($current_settings['min_withdrawal_amount'] ?? '100'); ?>"
                                        min="1" 
                                        step="0.01"
                                        class="w-full px-4 py-3 bg-gray-800 border border-gray-600 rounded-lg text-white focus:border-emerald-500 focus:outline-none"
                                    >
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-2">Maximum Withdrawal (KSh)</label>
                                    <input 
                                        type="number" 
                                        name="max_withdrawal_amount" 
                                        value="<?php echo htmlspecialchars($current_settings['max_withdrawal_amount'] ?? '1000000'); ?>"
                                        min="100" 
                                        step="0.01"
                                        class="w-full px-4 py-3 bg-gray-800 border border-gray-600 rounded-lg text-white focus:border-emerald-500 focus:outline-none"
                                    >
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-2">Processing Time (Hours)</label>
                                    <select name="withdrawal_processing_time" class="w-full px-4 py-3 bg-gray-800 border border-gray-600 rounded-lg text-white focus:border-emerald-500 focus:outline-none">
                                        <option value="1" <?php echo ($current_settings['withdrawal_processing_time'] ?? '24') == '1' ? 'selected' : ''; ?>>1 Hour</option>
                                        <option value="6" <?php echo ($current_settings['withdrawal_processing_time'] ?? '24') == '6' ? 'selected' : ''; ?>>6 Hours</option>
                                        <option value="12" <?php echo ($current_settings['withdrawal_processing_time'] ?? '24') == '12' ? 'selected' : ''; ?>>12 Hours</option>
                                        <option value="24" <?php echo ($current_settings['withdrawal_processing_time'] ?? '24') == '24' ? 'selected' : ''; ?>>24 Hours</option>
                                        <option value="48" <?php echo ($current_settings['withdrawal_processing_time'] ?? '24') == '48' ? 'selected' : ''; ?>>48 Hours</option>
                                        <option value="72" <?php echo ($current_settings['withdrawal_processing_time'] ?? '24') == '72' ? 'selected' : ''; ?>>72 Hours</option>
                                    </select>
                                </div>
                            </div>
                            
                            <button type="submit" class="px-6 py-3 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg font-medium transition">
                                <i class="fas fa-save mr-2"></i>Save Withdrawal Settings
                            </button>
                        </form>
                    </div>
                </div>

                <!-- System Settings -->
                <div id="system-section" class="setting-section">
                    <div class="glass-card rounded-xl p-6">
                        <div class="mb-6">
                            <h3 class="text-xl font-bold text-white">System Configuration</h3>
                            <p class="text-gray-400">General platform settings and features</p>
                        </div>
                        
                        <form method="POST" class="space-y-6">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            <input type="hidden" name="section" value="system">
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-300 mb-2">Platform Fee (%)</label>
                                <input 
                                    type="number" 
                                    name="platform_fee_percentage" 
                                    value="<?php echo htmlspecialchars($current_settings['platform_fee_percentage'] ?? '0'); ?>"
                                    min="0" 
                                    max="10" 
                                    step="0.1"
                                    class="w-full px-4 py-3 bg-gray-800 border border-gray-600 rounded-lg text-white focus:border-emerald-500 focus:outline-none"
                                >
                                <p class="text-xs text-gray-500 mt-1">Fee charged on deposits (0 for free)</p>
                            </div>
                            
                            <div class="space-y-4">
                                <h4 class="font-semibold text-white">System Features</h4>
                                
                                <label class="flex items-center space-x-3 cursor-pointer">
                                    <input 
                                        type="checkbox" 
                                        name="auto_roi_processing"
                                        <?php echo ($current_settings['auto_roi_processing'] ?? '1') === '1' ? 'checked' : ''; ?>
                                        class="w-5 h-5 text-emerald-600 bg-gray-800 border-gray-600 rounded focus:ring-emerald-500 focus:ring-2"
                                    >
                                    <div>
                                        <span class="text-white font-medium">Automatic ROI Processing</span>
                                        <p class="text-sm text-gray-400">Automatically process matured packages</p>
                                    </div>
                                </label>
                                
                                <label class="flex items-center space-x-3 cursor-pointer">
                                    <input 
                                        type="checkbox" 
                                        name="email_notifications"
                                        <?php echo ($current_settings['email_notifications'] ?? '1') === '1' ? 'checked' : ''; ?>
                                        class="w-5 h-5 text-emerald-600 bg-gray-800 border-gray-600 rounded focus:ring-emerald-500 focus:ring-2"
                                    >
                                    <div>
                                        <span class="text-white font-medium">Email Notifications</span>
                                        <p class="text-sm text-gray-400">Send email notifications to users</p>
                                    </div>
                                </label>
                                
                                <label class="flex items-center space-x-3 cursor-pointer">
                                    <input 
                                        type="checkbox" 
                                        name="sms_notifications"
                                        <?php echo ($current_settings['sms_notifications'] ?? '1') === '1' ? 'checked' : ''; ?>
                                        class="w-5 h-5 text-emerald-600 bg-gray-800 border-gray-600 rounded focus:ring-emerald-500 focus:ring-2"
                                    >
                                    <div>
                                        <span class="text-white font-medium">SMS Notifications</span>
                                        <p class="text-sm text-gray-400">Send SMS notifications to users</p>
                                    </div>
                                </label>
                            </div>
                            
                            <div class="border-t border-gray-700 pt-6">
                                <h4 class="font-semibold text-white mb-4">Maintenance Mode</h4>
                                <div class="bg-red-500/10 border border-red-500/30 rounded-lg p-4">
                                    <label class="flex items-center space-x-3 cursor-pointer">
                                        <input 
                                            type="checkbox" 
                                            name="site_maintenance"
                                            <?php echo ($current_settings['site_maintenance'] ?? '0') === '1' ? 'checked' : ''; ?>
                                            class="w-5 h-5 text-red-600 bg-gray-800 border-gray-600 rounded focus:ring-red-500 focus:ring-2"
                                            onchange="confirmMaintenanceMode(this)"
                                        >
                                        <div>
                                            <span class="text-red-400 font-medium">Enable Maintenance Mode</span>
                                            <p class="text-sm text-gray-400">Temporarily disable site access for regular users</p>
                                        </div>
                                    </label>
                                </div>
                            </div>
                            
                            <button type="submit" class="px-6 py-3 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg font-medium transition">
                                <i class="fas fa-save mr-2"></i>Save System Settings
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </main>
    <script>
        // Show/hide setting sections
        function showSection(sectionName) {
            // Hide all sections
            document.querySelectorAll('.setting-section').forEach(section => {
                section.classList.remove('active');
            });
            
            // Remove active class from all nav items
            document.querySelectorAll('.nav-item').forEach(item => {
                item.classList.remove('active');
            });
            
            // Show selected section
            document.getElementById(sectionName + '-section').classList.add('active');
            
            // Highlight active nav item
            document.querySelector(`[data-section="${sectionName}"]`).classList.add('active');
        }

        // Test M-Pesa connection
        function testMpesaConnection() {
            const button = event.target;
            const originalText = button.innerHTML;
            
            button.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Testing...';
            button.disabled = true;
            
            // Get form data
            const formData = new FormData();
            formData.append('test_mpesa', '1');
            formData.append('mpesa_consumer_key', document.querySelector('input[name="mpesa_consumer_key"]').value);
            formData.append('mpesa_consumer_secret', document.querySelector('input[name="mpesa_consumer_secret"]').value);
            
            fetch('/admin/test-mpesa.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('✅ M-Pesa connection successful!\n\n' + data.message);
                } else {
                    alert('❌ M-Pesa connection failed!\n\n' + data.message);
                }
            })
            .catch(error => {
                alert('❌ Connection test failed!\n\nError: ' + error.message);
            })
            .finally(() => {
                button.innerHTML = originalText;
                button.disabled = false;
            });
        }

        // Confirm maintenance mode
        function confirmMaintenanceMode(checkbox) {
            if (checkbox.checked) {
                const confirmed = confirm(
                    '⚠️ MAINTENANCE MODE WARNING\n\n' +
                    'This will:\n' +
                    '• Block all regular users from accessing the site\n' +
                    '• Only admins can access the platform\n' +
                    '• Stop all user activities and transactions\n\n' +
                    'Are you sure you want to enable maintenance mode?'
                );
                
                if (!confirmed) {
                    checkbox.checked = false;
                }
            }
        }

        // Form validation
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const section = this.querySelector('input[name="section"]').value;
                
                if (section === 'referral') {
                    const l1 = parseFloat(this.querySelector('input[name="referral_commission_l1"]').value);
                    const l2 = parseFloat(this.querySelector('input[name="referral_commission_l2"]').value);
                    
                    if (l2 >= l1) {
                        e.preventDefault();
                        alert('Level 2 commission must be less than Level 1 commission.');
                        return false;
                    }
                }
                
                if (section === 'withdrawal') {
                    const min = parseFloat(this.querySelector('input[name="min_withdrawal_amount"]').value);
                    const max = parseFloat(this.querySelector('input[name="max_withdrawal_amount"]').value);
                    
                    if (max <= min) {
                        e.preventDefault();
                        alert('Maximum withdrawal must be greater than minimum withdrawal.');
                        return false;
                    }
                }
            });
        });

        // Auto-save draft settings (prevent loss of changes)
        function autoSaveDraft() {
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                const inputs = form.querySelectorAll('input, select, textarea');
                inputs.forEach(input => {
                    input.addEventListener('input', function() {
                        const section = form.querySelector('input[name="section"]').value;
                        const key = `draft_${section}_${this.name}`;
                        localStorage.setItem(key, this.type === 'checkbox' ? this.checked : this.value);
                    });
                });
            });
        }

        // Restore draft settings
        function restoreDrafts() {
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                const inputs = form.querySelectorAll('input, select, textarea');
                inputs.forEach(input => {
                    const section = form.querySelector('input[name="section"]').value;
                    const key = `draft_${section}_${input.name}`;
                    const saved = localStorage.getItem(key);
                    
                    if (saved !== null) {
                        if (input.type === 'checkbox') {
                            input.checked = saved === 'true';
                        } else {
                            input.value = saved;
                        }
                    }
                });
            });
        }

        // Clear drafts on successful save
        function clearDrafts(section) {
            Object.keys(localStorage).forEach(key => {
                if (key.startsWith(`draft_${section}_`)) {
                    localStorage.removeItem(key);
                }
            });
        }

        // Show password toggle
        function addPasswordToggles() {
            document.querySelectorAll('input[type="password"]').forEach(input => {
                const wrapper = document.createElement('div');
                wrapper.className = 'relative';
                input.parentNode.insertBefore(wrapper, input);
                wrapper.appendChild(input);
                
                const toggle = document.createElement('button');
                toggle.type = 'button';
                toggle.className = 'absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-white';
                toggle.innerHTML = '<i class="fas fa-eye"></i>';
                toggle.onclick = function() {
                    if (input.type === 'password') {
                        input.type = 'text';
                        toggle.innerHTML = '<i class="fas fa-eye-slash"></i>';
                    } else {
                        input.type = 'password';
                        toggle.innerHTML = '<i class="fas fa-eye"></i>';
                    }
                };
                wrapper.appendChild(toggle);
            });
        }

        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            // Show first section by default
            showSection('mpesa');
            
            // Initialize features
            autoSaveDraft();
            restoreDrafts();
            addPasswordToggles();
            
            // Add URL hash support
            if (window.location.hash) {
                const section = window.location.hash.substring(1);
                if (document.getElementById(section + '-section')) {
                    showSection(section);
                }
            }
        });

        // Update URL hash when section changes
        document.querySelectorAll('.nav-item').forEach(item => {
            item.addEventListener('click', function() {
                const section = this.getAttribute('data-section');
                window.location.hash = section;
            });
        });

        // Export settings
        function exportSettings() {
            const settings = <?php echo json_encode($current_settings); ?>;
            const dataStr = JSON.stringify(settings, null, 2);
            const dataBlob = new Blob([dataStr], {type: 'application/json'});
            const url = URL.createObjectURL(dataBlob);
            const link = document.createElement('a');
            link.href = url;
            link.download = `ultra-harvest-settings-${new Date().toISOString().split('T')[0]}.json`;
            link.click();
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey) {
                switch(e.key) {
                    case '1': showSection('mpesa'); break;
                    case '2': showSection('referral'); break;
                    case '3': showSection('withdrawal'); break;
                    case '4': showSection('system'); break;
                    case 'e': e.preventDefault(); exportSettings(); break;
                }
            }
        });
    </script>
</body>
</html>