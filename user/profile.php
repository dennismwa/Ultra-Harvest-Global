<?php
require_once '../config/database.php';
requireLogin();

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

// Handle profile updates
if ($_POST) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'update_profile':
                $full_name = sanitize($_POST['full_name'] ?? '');
                $phone = sanitize($_POST['phone'] ?? '');
                $email = sanitize($_POST['email'] ?? '');
                
                if (empty($full_name) || empty($phone) || empty($email)) {
                    $error = 'All fields are required.';
                } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $error = 'Invalid email address.';
                } elseif (!preg_match('/^254[0-9]{9}$/', $phone)) {
                    $error = 'Please enter a valid phone number (254XXXXXXXXX).';
                } else {
                    // Check if email is already taken by another user
                    $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                    $stmt->execute([$email, $user_id]);
                    
                    if ($stmt->fetch()) {
                        $error = 'Email address is already in use by another account.';
                    } else {
                        $stmt = $db->prepare("UPDATE users SET full_name = ?, phone = ?, email = ?, updated_at = NOW() WHERE id = ?");
                        if ($stmt->execute([$full_name, $phone, $email, $user_id])) {
                            $_SESSION['full_name'] = $full_name;
                            $_SESSION['email'] = $email;
                            $success = 'Profile updated successfully.';
                        } else {
                            $error = 'Failed to update profile.';
                        }
                    }
                }
                break;
                
            case 'change_password':
                $current_password = $_POST['current_password'] ?? '';
                $new_password = $_POST['new_password'] ?? '';
                $confirm_password = $_POST['confirm_password'] ?? '';
                
                if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
                    $error = 'All password fields are required.';
                } elseif (strlen($new_password) < 6) {
                    $error = 'New password must be at least 6 characters long.';
                } elseif ($new_password !== $confirm_password) {
                    $error = 'New passwords do not match.';
                } else {
                    // Verify current password
                    $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
                    $stmt->execute([$user_id]);
                    $user_data = $stmt->fetch();
                    
                    if (!password_verify($current_password, $user_data['password'])) {
                        $error = 'Current password is incorrect.';
                    } else {
                        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                        $stmt = $db->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
                        
                        if ($stmt->execute([$hashed_password, $user_id])) {
                            $success = 'Password changed successfully.';
                        } else {
                            $error = 'Failed to change password.';
                        }
                    }
                }
                break;
        }
    }
}

// Get user data
$stmt = $db->prepare("
    SELECT u.*, 
           COUNT(ref.id) as total_referrals,
           COALESCE(SUM(CASE WHEN t.type = 'referral_commission' AND t.status = 'completed' THEN t.amount ELSE 0 END), 0) as total_referral_earnings
    FROM users u
    LEFT JOIN users ref ON u.id = ref.referred_by
    LEFT JOIN transactions t ON u.id = t.user_id
    WHERE u.id = ?
    GROUP BY u.id
");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Get user statistics
$stmt = $db->prepare("
    SELECT 
        COUNT(CASE WHEN ap.status = 'active' THEN 1 END) as active_packages,
        COUNT(CASE WHEN ap.status = 'completed' THEN 1 END) as completed_packages,
        COALESCE(SUM(CASE WHEN ap.status = 'active' THEN ap.expected_roi ELSE 0 END), 0) as pending_roi,
        COALESCE(SUM(CASE WHEN ap.status = 'completed' THEN ap.expected_roi ELSE 0 END), 0) as earned_roi
    FROM active_packages ap
    WHERE ap.user_id = ?
");
$stmt->execute([$user_id]);
$package_stats = $stmt->fetch();

// Get recent activity
$stmt = $db->prepare("
    SELECT * FROM transactions 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT 5
");
$stmt->execute([$user_id]);
$recent_activity = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Ultra Harvest Global</title>
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
        
        .profile-section {
            display: none;
        }
        
        .profile-section.active {
            display: block;
        }
        
        .nav-item.active {
            background: linear-gradient(45deg, #10b981, #34d399);
            color: white;
        }
        
        .stat-card {
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
    </style>
</head>
<body class="bg-gray-900 text-white min-h-screen">

    <!-- Header -->
    <header class="bg-gray-800/50 backdrop-blur-md border-b border-gray-700 sticky top-0 z-50">
        <div class="container mx-auto px-4">
            <div class="flex items-center justify-between h-16">
                <div class="flex items-center space-x-8">
                    <a href="/user/dashboard.php" class="flex items-center space-x-3">
                        <div class="w-10 h-10 bg-gradient-to-r from-emerald-500 to-yellow-500 rounded-full flex items-center justify-center">
                            <i class="fas fa-seedling text-white"></i>
                        </div>
                        <span class="text-xl font-bold bg-gradient-to-r from-emerald-400 to-yellow-400 bg-clip-text text-transparent">Ultra Harvest</span>
                    </a>
                    
                    <nav class="hidden md:flex space-x-6">
                        <a href="/user/dashboard.php" class="text-gray-300 hover:text-emerald-400 transition">Dashboard</a>
                        <a href="/user/packages.php" class="text-gray-300 hover:text-emerald-400 transition">Packages</a>
                        <a href="/user/transactions.php" class="text-gray-300 hover:text-emerald-400 transition">Transactions</a>
                        <a href="/user/referrals.php" class="text-gray-300 hover:text-emerald-400 transition">Referrals</a>
                    </nav>
                </div>

                <div class="flex items-center space-x-4">
                    <div class="flex items-center space-x-2 bg-gray-700/50 rounded-full px-4 py-2">
                        <i class="fas fa-wallet text-emerald-400"></i>
                        <span class="text-sm text-gray-300">Balance:</span>
                        <span class="font-bold text-white"><?php echo formatMoney($user['wallet_balance']); ?></span>
                    </div>
                    <a href="/user/dashboard.php" class="text-gray-400 hover:text-white">
                        <i class="fas fa-home text-xl"></i>
                    </a>
                </div>
            </div>
        </div>
    </header>

    <main class="container mx-auto px-4 py-8">
        
        <!-- Page Header -->
        <div class="text-center mb-8">
            <div class="w-24 h-24 bg-gradient-to-r from-emerald-500 to-yellow-500 rounded-full flex items-center justify-center mx-auto mb-4">
                <span class="text-3xl font-bold text-white"><?php echo strtoupper(substr($user['full_name'], 0, 2)); ?></span>
            </div>
            <h1 class="text-3xl font-bold text-white mb-2"><?php echo htmlspecialchars($user['full_name']); ?></h1>
            <p class="text-gray-400">Member since <?php echo date('M Y', strtotime($user['created_at'])); ?></p>
        </div>

        <!-- Error/Success Messages -->
        <?php if ($error): ?>
        <div class="mb-6 p-4 bg-red-500/20 border border-red-500/50 rounded-lg max-w-2xl mx-auto">
            <div class="flex items-center">
                <i class="fas fa-exclamation-circle text-red-400 mr-2"></i>
                <span class="text-red-300"><?php echo $error; ?></span>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($success): ?>
        <div class="mb-6 p-4 bg-emerald-500/20 border border-emerald-500/50 rounded-lg max-w-2xl mx-auto">
            <div class="flex items-center">
                <i class="fas fa-check-circle text-emerald-400 mr-2"></i>
                <span class="text-emerald-300"><?php echo $success; ?></span>
            </div>
        </div>
        <?php endif; ?>

        <!-- User Statistics -->
        <section class="grid md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="glass-card rounded-xl p-6 stat-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-400 text-sm">Wallet Balance</p>
                        <p class="text-2xl font-bold text-emerald-400"><?php echo formatMoney($user['wallet_balance']); ?></p>
                    </div>
                    <div class="w-12 h-12 bg-emerald-500/20 rounded-full flex items-center justify-center">
                        <i class="fas fa-wallet text-emerald-400 text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="glass-card rounded-xl p-6 stat-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-400 text-sm">Total Referrals</p>
                        <p class="text-2xl font-bold text-purple-400"><?php echo number_format($user['total_referrals']); ?></p>
                    </div>
                    <div class="w-12 h-12 bg-purple-500/20 rounded-full flex items-center justify-center">
                        <i class="fas fa-users text-purple-400 text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="glass-card rounded-xl p-6 stat-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-400 text-sm">Active Packages</p>
                        <p class="text-2xl font-bold text-blue-400"><?php echo number_format($package_stats['active_packages']); ?></p>
                    </div>
                    <div class="w-12 h-12 bg-blue-500/20 rounded-full flex items-center justify-center">
                        <i class="fas fa-chart-line text-blue-400 text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="glass-card rounded-xl p-6 stat-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-400 text-sm">Total ROI Earned</p>
                        <p class="text-2xl font-bold text-yellow-400"><?php echo formatMoney($user['total_roi_earned']); ?></p>
                    </div>
                    <div class="w-12 h-12 bg-yellow-500/20 rounded-full flex items-center justify-center">
                        <i class="fas fa-coins text-yellow-400 text-xl"></i>
                    </div>
                </div>
            </div>
        </section>

        <div class="grid lg:grid-cols-4 gap-8">
            
            <!-- Profile Navigation -->
            <div class="lg:col-span-1">
                <div class="glass-card rounded-xl p-6">
                    <h3 class="text-lg font-bold text-white mb-4">Profile Settings</h3>
                    <nav class="space-y-2">
                        <button onclick="showSection('personal')" class="nav-item w-full text-left px-4 py-3 rounded-lg transition active" data-section="personal">
                            <i class="fas fa-user mr-3"></i>Personal Info
                        </button>
                        <button onclick="showSection('security')" class="nav-item w-full text-left px-4 py-3 rounded-lg transition" data-section="security">
                            <i class="fas fa-lock mr-3"></i>Security
                        </button>
                        <button onclick="showSection('activity')" class="nav-item w-full text-left px-4 py-3 rounded-lg transition" data-section="activity">
                            <i class="fas fa-history mr-3"></i>Recent Activity
                        </button>
                        <button onclick="showSection('account')" class="nav-item w-full text-left px-4 py-3 rounded-lg transition" data-section="account">
                            <i class="fas fa-cog mr-3"></i>Account Settings
                        </button>
                    </nav>
                </div>
            </div>

            <!-- Profile Content -->
            <div class="lg:col-span-3">
                
                <!-- Personal Information -->
                <div id="personal-section" class="profile-section active">
                    <div class="glass-card rounded-xl p-6">
                        <h3 class="text-xl font-bold text-white mb-6">Personal Information</h3>
                        
                        <form method="POST" class="space-y-6">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            <input type="hidden" name="action" value="update_profile">
                            
                            <div class="grid md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-2">Full Name *</label>
                                    <input 
                                        type="text" 
                                        name="full_name" 
                                        value="<?php echo htmlspecialchars($user['full_name']); ?>"
                                        class="w-full px-4 py-3 bg-gray-800 border border-gray-600 rounded-lg text-white focus:border-emerald-500 focus:outline-none"
                                        required
                                    >
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-2">Email Address *</label>
                                    <input 
                                        type="email" 
                                        name="email" 
                                        value="<?php echo htmlspecialchars($user['email']); ?>"
                                        class="w-full px-4 py-3 bg-gray-800 border border-gray-600 rounded-lg text-white focus:border-emerald-500 focus:outline-none"
                                        required
                                    >
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-2">Phone Number *</label>
                                    <input 
                                        type="tel" 
                                        name="phone" 
                                        value="<?php echo htmlspecialchars($user['phone']); ?>"
                                        class="w-full px-4 py-3 bg-gray-800 border border-gray-600 rounded-lg text-white focus:border-emerald-500 focus:outline-none"
                                        placeholder="254XXXXXXXXX"
                                        required
                                    >
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-2">Referral Code</label>
                                    <div class="flex items-center space-x-2">
                                        <input 
                                            type="text" 
                                            value="<?php echo $user['referral_code']; ?>"
                                            class="flex-1 px-4 py-3 bg-gray-700 border border-gray-600 rounded-lg text-emerald-400 font-mono"
                                            readonly
                                        >
                                        <button type="button" onclick="copyReferralCode()" class="px-3 py-3 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg transition">
                                            <i class="fas fa-copy"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="bg-blue-500/10 border border-blue-500/30 rounded-lg p-4">
                                <h4 class="font-bold text-blue-400 mb-2">Account Information</h4>
                                <div class="grid md:grid-cols-2 gap-4 text-sm text-gray-300">
                                    <div>
                                        <p><strong>Member Since:</strong> <?php echo date('M j, Y', strtotime($user['created_at'])); ?></p>
                                        <p><strong>Account Status:</strong> 
                                            <span class="<?php echo $user['status'] === 'active' ? 'text-emerald-400' : 'text-red-400'; ?>">
                                                <?php echo ucfirst($user['status']); ?>
                                            </span>
                                        </p>
                                    </div>
                                    <div>
                                        <p><strong>Total Deposited:</strong> <?php echo formatMoney($user['total_deposited']); ?></p>
                                        <p><strong>Total Withdrawn:</strong> <?php echo formatMoney($user['total_withdrawn']); ?></p>
                                    </div>
                                </div>
                            </div>
                            
                            <button type="submit" class="px-6 py-3 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg font-medium transition">
                                <i class="fas fa-save mr-2"></i>Update Profile
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Security Settings -->
                <div id="security-section" class="profile-section">
                    <div class="glass-card rounded-xl p-6">
                        <h3 class="text-xl font-bold text-white mb-6">Change Password</h3>
                        
                        <form method="POST" class="space-y-6">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            <input type="hidden" name="action" value="change_password">
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-300 mb-2">Current Password *</label>
                                <div class="relative">
                                    <input 
                                        type="password" 
                                        name="current_password" 
                                        id="current_password"
                                        class="w-full px-4 py-3 bg-gray-800 border border-gray-600 rounded-lg text-white focus:border-emerald-500 focus:outline-none pr-12"
                                        required
                                    >
                                    <button type="button" onclick="togglePassword('current_password')" class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-white">
                                        <i class="fas fa-eye" id="current_password-eye"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-300 mb-2">New Password *</label>
                                <div class="relative">
                                    <input 
                                        type="password" 
                                        name="new_password" 
                                        id="new_password"
                                        class="w-full px-4 py-3 bg-gray-800 border border-gray-600 rounded-lg text-white focus:border-emerald-500 focus:outline-none pr-12"
                                        minlength="6"
                                        required
                                    >
                                    <button type="button" onclick="togglePassword('new_password')" class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-white">
                                        <i class="fas fa-eye" id="new_password-eye"></i>
                                    </button>
                                </div>
                                <div class="mt-2" id="password-strength">
                                    <div class="h-2 bg-gray-700 rounded-full overflow-hidden">
                                        <div id="strength-bar" class="h-full transition-all duration-300"></div>
                                    </div>
                                    <p id="strength-text" class="text-xs text-gray-500 mt-1">Enter new password</p>
                                </div>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-300 mb-2">Confirm New Password *</label>
                                <div class="relative">
                                    <input 
                                        type="password" 
                                        name="confirm_password" 
                                        id="confirm_password"
                                        class="w-full px-4 py-3 bg-gray-800 border border-gray-600 rounded-lg text-white focus:border-emerald-500 focus:outline-none pr-12"
                                        required
                                    >
                                    <button type="button" onclick="togglePassword('confirm_password')" class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-white">
                                        <i class="fas fa-eye" id="confirm_password-eye"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <div class="bg-yellow-500/10 border border-yellow-500/30 rounded-lg p-4">
                                <h4 class="font-bold text-yellow-400 mb-2">Password Security Tips:</h4>
                                <ul class="text-sm text-gray-300 space-y-1">
                                    <li>• Use at least 6 characters</li>
                                    <li>• Include uppercase and lowercase letters</li>
                                    <li>• Add numbers and special characters</li>
                                    <li>• Don't use personal information</li>
                                </ul>
                            </div>
                            
                            <button type="submit" class="px-6 py-3 bg-red-600 hover:bg-red-700 text-white rounded-lg font-medium transition">
                                <i class="fas fa-key mr-2"></i>Change Password
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div id="activity-section" class="profile-section">
                    <div class="glass-card rounded-xl p-6">
                        <h3 class="text-xl font-bold text-white mb-6">Recent Activity</h3>
                        
                        <?php if (empty($recent_activity)): ?>
                            <div class="text-center py-12">
                                <i class="fas fa-history text-6xl text-gray-600 mb-4"></i>
                                <h4 class="text-xl font-bold text-gray-400 mb-2">No Recent Activity</h4>
                                <p class="text-gray-500">Your account activity will appear here</p>
                            </div>
                        <?php else: ?>
                            <div class="space-y-4">
                                <?php foreach ($recent_activity as $activity): ?>
                                <div class="flex items-center space-x-4 p-4 bg-gray-800/50 rounded-lg">
                                    <div class="w-12 h-12 rounded-full flex items-center justify-center
                                        <?php 
                                        echo match($activity['type']) {
                                            'deposit' => 'bg-emerald-500/20 text-emerald-400',
                                            'withdrawal' => 'bg-red-500/20 text-red-400',
                                            'roi_payment' => 'bg-yellow-500/20 text-yellow-400',
                                            'package_investment' => 'bg-blue-500/20 text-blue-400',
                                            'referral_commission' => 'bg-purple-500/20 text-purple-400',
                                            default => 'bg-gray-500/20 text-gray-400'
                                        };
                                        ?>">
                                        <i class="fas <?php 
                                        echo match($activity['type']) {
                                            'deposit' => 'fa-arrow-down',
                                            'withdrawal' => 'fa-arrow-up',
                                            'roi_payment' => 'fa-coins',
                                            'package_investment' => 'fa-chart-line',
                                            'referral_commission' => 'fa-users',
                                            default => 'fa-exchange-alt'
                                        };
                                        ?>"></i>
                                    </div>
                                    <div class="flex-1">
                                        <h4 class="font-medium text-white capitalize">
                                            <?php echo str_replace('_', ' ', $activity['type']); ?>
                                        </h4>
                                        <p class="text-sm text-gray-400"><?php echo htmlspecialchars($activity['description'] ?? 'N/A'); ?></p>
                                        <p class="text-xs text-gray-500"><?php echo timeAgo($activity['created_at']); ?></p>
                                    </div>
                                    <div class="text-right">
                                        <p class="font-bold text-white"><?php echo formatMoney($activity['amount']); ?></p>
                                        <span class="px-2 py-1 rounded text-xs font-medium
                                            <?php 
                                            echo match($activity['status']) {
                                                'completed' => 'bg-emerald-500/20 text-emerald-400',
                                                'pending' => 'bg-yellow-500/20 text-yellow-400',
                                                'failed' => 'bg-red-500/20 text-red-400',
                                                default => 'bg-gray-500/20 text-gray-400'
                                            };
                                            ?>">
                                            <?php echo ucfirst($activity['status']); ?>
                                        </span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <div class="text-center mt-6">
                                <a href="/user/transactions.php" class="px-6 py-3 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg font-medium transition">
                                    <i class="fas fa-list mr-2"></i>View All Transactions
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Account Settings -->
                <div id="account-section" class="profile-section">
                    <div class="glass-card rounded-xl p-6">
                        <h3 class="text-xl font-bold text-white mb-6">Account Settings</h3>
                        
                        <div class="space-y-6">
                            <!-- Account Summary -->
                            <div class="bg-gradient-to-r from-emerald-600/20 to-yellow-600/20 rounded-lg p-6">
                                <h4 class="font-bold text-white mb-4">Account Summary</h4>
                                <div class="grid md:grid-cols-2 gap-6">
                                    <div>
                                        <h5 class="font-medium text-emerald-400 mb-2">Financial Overview</h5>
                                        <div class="space-y-1 text-sm text-gray-300">
                                            <p>Current Balance: <span class="text-white font-bold"><?php echo formatMoney($user['wallet_balance']); ?></span></p>
                                            <p>Total Deposited: <span class="text-emerald-400"><?php echo formatMoney($user['total_deposited']); ?></span></p>
                                            <p>Total Withdrawn: <span class="text-red-400"><?php echo formatMoney($user['total_withdrawn']); ?></span></p>
                                            <p>ROI Earned: <span class="text-yellow-400"><?php echo formatMoney($user['total_roi_earned']); ?></span></p>
                                        </div>
                                    </div>
                                    <div>
                                        <h5 class="font-medium text-purple-400 mb-2">Trading Summary</h5>
                                        <div class="space-y-1 text-sm text-gray-300">
                                            <p>Active Packages: <span class="text-blue-400"><?php echo number_format($package_stats['active_packages']); ?></span></p>
                                            <p>Completed Packages: <span class="text-emerald-400"><?php echo number_format($package_stats['completed_packages']); ?></span></p>
                                            <p>Pending ROI: <span class="text-yellow-400"><?php echo formatMoney($package_stats['pending_roi']); ?></span></p>
                                            <p>Referrals: <span class="text-purple-400"><?php echo number_format($user['total_referrals']); ?></span></p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Quick Actions -->
                            <div>
                                <h4 class="font-bold text-white mb-4">Quick Actions</h4>
                                <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-3">
                                    <a href="/user/deposit.php" class="flex items-center justify-center px-4 py-3 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg font-medium transition">
                                        <i class="fas fa-plus mr-2"></i>Deposit
                                    </a>
                                    <a href="/user/withdraw.php" class="flex items-center justify-center px-4 py-3 bg-red-600 hover:bg-red-700 text-white rounded-lg font-medium transition">
                                        <i class="fas fa-arrow-up mr-2"></i>Withdraw
                                    </a>
                                    <a href="/user/packages.php" class="flex items-center justify-center px-4 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition">
                                        <i class="fas fa-chart-line mr-2"></i>Trade
                                    </a>
                                    <a href="/user/referrals.php" class="flex items-center justify-center px-4 py-3 bg-purple-600 hover:bg-purple-700 text-white rounded-lg font-medium transition">
                                        <i class="fas fa-users mr-2"></i>Referrals
                                    </a>
                                </div>
                            </div>

                            <!-- Danger Zone -->
                            <div class="border-t border-gray-700 pt-6">
                                <h4 class="font-bold text-red-400 mb-4">Danger Zone</h4>
                                <div class="bg-red-500/10 border border-red-500/30 rounded-lg p-4">
                                    <p class="text-gray-300 text-sm mb-4">
                                        Once you delete your account, there is no going back. Please be certain.
                                    </p>
                                    <button onclick="confirmAccountDeletion()" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg font-medium transition">
                                        <i class="fas fa-trash mr-2"></i>Delete Account
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Mobile Bottom Navigation -->
    <div class="fixed bottom-0 left-0 right-0 bg-gray-800 border-t border-gray-700 md:hidden">
        <div class="grid grid-cols-4 py-2">
            <a href="/user/dashboard.php" class="flex flex-col items-center py-2 text-gray-400">
                <i class="fas fa-home text-xl mb-1"></i>
                <span class="text-xs">Home</span>
            </a>
            <a href="/user/packages.php" class="flex flex-col items-center py-2 text-gray-400">
                <i class="fas fa-chart-line text-xl mb-1"></i>
                <span class="text-xs">Trade</span>
            </a>
            <a href="/user/transactions.php" class="flex flex-col items-center py-2 text-gray-400">
                <i class="fas fa-receipt text-xl mb-1"></i>
                <span class="text-xs">History</span>
            </a>
            <a href="/user/profile.php" class="flex flex-col items-center py-2 text-emerald-400">
                <i class="fas fa-user text-xl mb-1"></i>
                <span class="text-xs">Profile</span>
            </a>
        </div>
    </div>

    <!-- Account Deletion Modal -->
    <div id="deleteModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm hidden items-center justify-center z-50">
        <div class="bg-gray-800 rounded-xl p-6 max-w-md mx-4 border border-red-500/30">
            <div class="text-center">
                <i class="fas fa-exclamation-triangle text-red-400 text-6xl mb-4"></i>
                <h3 class="text-xl font-bold text-white mb-4">Delete Account</h3>
                <p class="text-gray-300 mb-6">
                    This action cannot be undone. This will permanently delete your account and remove all your data from our servers.
                </p>
                <div class="flex space-x-3">
                    <button onclick="closeDeleteModal()" class="flex-1 px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded-lg font-medium transition">
                        Cancel
                    </button>
                    <button onclick="deleteAccount()" class="flex-1 px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg font-medium transition">
                        Delete Account
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Show/hide profile sections
        function showSection(sectionName) {
            document.querySelectorAll('.profile-section').forEach(section => {
                section.classList.remove('active');
            });
            
            document.querySelectorAll('.nav-item').forEach(item => {
                item.classList.remove('active');
            });
            
            document.getElementById(sectionName + '-section').classList.add('active');
            document.querySelector(`[data-section="${sectionName}"]`).classList.add('active');
        }

        // Toggle password visibility
        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const eye = document.getElementById(fieldId + '-eye');
            
            if (field.type === 'password') {
                field.type = 'text';
                eye.classList.remove('fa-eye');
                eye.classList.add('fa-eye-slash');
            } else {
                field.type = 'password';
                eye.classList.remove('fa-eye-slash');
                eye.classList.add('fa-eye');
            }
        }

        // Copy referral code
        function copyReferralCode() {
            const referralCode = '<?php echo $user['referral_code']; ?>';
            navigator.clipboard.writeText(referralCode).then(() => {
                // Show success message
                const button = event.target.closest('button');
                const originalHTML = button.innerHTML;
                button.innerHTML = '<i class="fas fa-check"></i>';
                button.classList.add('bg-emerald-600');
                
                setTimeout(() => {
                    button.innerHTML = originalHTML;
                    button.classList.remove('bg-emerald-600');
                }, 2000);
            }).catch(err => {
                console.error('Failed to copy: ', err);
                alert('Failed to copy referral code');
            });
        }

        // Password strength checker
        document.getElementById('new_password')?.addEventListener('input', function() {
            const password = this.value;
            const strengthBar = document.getElementById('strength-bar');
            const strengthText = document.getElementById('strength-text');
            
            let strength = 0;
            let feedback = [];
            
            if (password.length >= 6) strength += 20;
            else feedback.push('At least 6 characters');
            
            if (/[a-z]/.test(password)) strength += 20;
            else feedback.push('Lowercase letter');
            
            if (/[A-Z]/.test(password)) strength += 20;
            else feedback.push('Uppercase letter');
            
            if (/[0-9]/.test(password)) strength += 20;
            else feedback.push('Number');
            
            if (/[^A-Za-z0-9]/.test(password)) strength += 20;
            else feedback.push('Special character');
            
            // Update strength bar
            strengthBar.style.width = strength + '%';
            
            if (strength < 40) {
                strengthBar.className = 'h-full transition-all duration-300 bg-red-500';
                strengthText.textContent = 'Weak password';
                strengthText.className = 'text-xs text-red-400 mt-1';
            } else if (strength < 80) {
                strengthBar.className = 'h-full transition-all duration-300 bg-yellow-500';
                strengthText.textContent = 'Medium password';
                strengthText.className = 'text-xs text-yellow-400 mt-1';
            } else {
                strengthBar.className = 'h-full transition-all duration-300 bg-emerald-500';
                strengthText.textContent = 'Strong password';
                strengthText.className = 'text-xs text-emerald-400 mt-1';
            }
            
            if (feedback.length > 0 && password.length > 0) {
                strengthText.textContent += ' (Missing: ' + feedback.join(', ') + ')';
            }
        });

        // Confirm password match
        document.getElementById('confirm_password')?.addEventListener('input', function() {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = this.value;
            
            if (confirmPassword && newPassword !== confirmPassword) {
                this.classList.add('border-red-500');
                this.classList.remove('border-gray-600');
            } else {
                this.classList.remove('border-red-500');
                this.classList.add('border-gray-600');
            }
        });

        // Account deletion functions
        function confirmAccountDeletion() {
            document.getElementById('deleteModal').classList.remove('hidden');
            document.getElementById('deleteModal').classList.add('flex');
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.add('hidden');
            document.getElementById('deleteModal').classList.remove('flex');
        }

        function deleteAccount() {
            // In a real application, this would send a request to delete the account
            alert('Account deletion would be processed here. This is just a demo.');
            closeDeleteModal();
        }

        // Auto-hide messages after 5 seconds
        setTimeout(() => {
            const messages = document.querySelectorAll('.bg-red-500\\/20, .bg-emerald-500\\/20');
            messages.forEach(message => {
                message.style.transition = 'opacity 0.5s ease';
                message.style.opacity = '0';
                setTimeout(() => message.remove(), 500);
            });
        }, 5000);

        // Initialize first section as active
        document.addEventListener('DOMContentLoaded', function() {
            showSection('personal');
        });
    </script>

</body>
</html>