<?php
require_once '../config/database.php';
requireLogin();

$user_id = $_SESSION['user_id'];

// Get user data
$stmt = $db->prepare("
    SELECT u.*, 
           COALESCE(SUM(CASE WHEN t.type = 'deposit' AND t.status = 'completed' THEN t.amount ELSE 0 END), 0) as total_deposited,
           COALESCE(SUM(CASE WHEN t.type = 'withdrawal' AND t.status = 'completed' THEN t.amount ELSE 0 END), 0) as total_withdrawn,
           COALESCE(SUM(CASE WHEN t.type = 'roi_payment' AND t.status = 'completed' THEN t.amount ELSE 0 END), 0) as total_roi_earned
    FROM users u 
    LEFT JOIN transactions t ON u.id = t.user_id 
    WHERE u.id = ? 
    GROUP BY u.id
");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Get active package
$stmt = $db->prepare("
    SELECT ap.*, p.name as package_name, p.icon 
    FROM active_packages ap 
    JOIN packages p ON ap.package_id = p.id 
    WHERE ap.user_id = ? AND ap.status = 'active' 
    ORDER BY ap.created_at DESC 
    LIMIT 1
");
$stmt->execute([$user_id]);
$active_package = $stmt->fetch();

// Get recent transactions
$stmt = $db->prepare("
    SELECT * FROM transactions 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT 10
");
$stmt->execute([$user_id]);
$recent_transactions = $stmt->fetchAll();

// Get recent user activity (for live feed)
$stmt = $db->query("
    SELECT u.full_name, t.type, t.amount, t.created_at,
           CASE 
               WHEN t.type = 'deposit' THEN 'deposited'
               WHEN t.type = 'withdrawal' THEN 'withdrew'
               WHEN t.type = 'roi_payment' THEN 'earned'
               WHEN t.type = 'package_investment' THEN 'activated'
           END as action_text
    FROM transactions t 
    JOIN users u ON t.user_id = u.id 
    WHERE t.status = 'completed' AND t.type IN ('deposit', 'withdrawal', 'roi_payment', 'package_investment')
    ORDER BY t.created_at DESC 
    LIMIT 15
");
$live_activity = $stmt->fetchAll();

// Get notifications
$stmt = $db->prepare("
    SELECT * FROM notifications 
    WHERE (user_id = ? OR is_global = 1) AND is_read = 0 
    ORDER BY created_at DESC 
    LIMIT 5
");
$stmt->execute([$user_id]);
$notifications = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Ultra Harvest Global</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap');
        * { font-family: 'Poppins', sans-serif; }
        
        .gradient-bg {
            background: linear-gradient(135deg, #10b981 0%, #fbbf24 100%);
        }
        
        .glass-card {
            backdrop-filter: blur(20px);
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .live-feed {
            animation: slideUp 0.5s ease-out;
        }
        
        @keyframes slideUp {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        .pulse-dot {
            animation: pulse-dot 2s infinite;
        }
        
        @keyframes pulse-dot {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        .status-green { color: #10b981; }
        .status-yellow { color: #f59e0b; }
        .status-red { color: #ef4444; }
    </style>
</head>
<body class="bg-gray-900 text-white min-h-screen">

    <!-- Header -->
    <header class="bg-gray-800/50 backdrop-blur-md border-b border-gray-700 sticky top-0 z-50">
        <div class="container mx-auto px-4">
            <div class="flex items-center justify-between h-16">
                <!-- Logo & Navigation -->
                <div class="flex items-center space-x-8">
                    <a href="/user/dashboard.php" class="flex items-center space-x-3">
                        <div class="w-10 h-10 bg-gradient-to-r from-emerald-500 to-yellow-500 rounded-full flex items-center justify-center">
                            <i class="fas fa-seedling text-white"></i>
                        </div>
                        <span class="text-xl font-bold bg-gradient-to-r from-emerald-400 to-yellow-400 bg-clip-text text-transparent">
                            Ultra Harvest
                        </span>
                    </a>
                    
                    <nav class="hidden md:flex space-x-6">
                        <a href="/user/dashboard.php" class="text-emerald-400 font-medium">Dashboard</a>
                        <a href="/user/packages.php" class="text-gray-300 hover:text-emerald-400 transition">Packages</a>
                        <a href="/user/transactions.php" class="text-gray-300 hover:text-emerald-400 transition">Transactions</a>
                        <a href="/user/referrals.php" class="text-gray-300 hover:text-emerald-400 transition">Referrals</a>
                    </nav>
                </div>

                <!-- User Info & Actions -->
                <div class="flex items-center space-x-4">
                    <!-- Wallet Balance -->
                    <div class="hidden lg:flex items-center space-x-4 bg-gray-700/50 rounded-full px-4 py-2">
                        <i class="fas fa-wallet text-emerald-400"></i>
                        <span class="text-sm text-gray-300">Balance:</span>
                        <span class="font-bold text-white"><?php echo formatMoney($user['wallet_balance']); ?></span>
                    </div>

                    <!-- Notifications -->
                    <div class="relative">
                        <button class="relative p-2 text-gray-400 hover:text-white transition">
                            <i class="fas fa-bell text-xl"></i>
                            <?php if (count($notifications) > 0): ?>
                                <span class="absolute -top-1 -right-1 w-5 h-5 bg-red-500 rounded-full text-xs flex items-center justify-center">
                                    <?php echo count($notifications); ?>
                                </span>
                            <?php endif; ?>
                        </button>
                    </div>

                    <!-- User Menu -->
                    <div class="relative group">
                        <button class="flex items-center space-x-2 bg-gray-700/50 rounded-full px-3 py-2 hover:bg-gray-600/50 transition">
                            <div class="w-8 h-8 bg-gradient-to-r from-emerald-500 to-yellow-500 rounded-full flex items-center justify-center">
                                <i class="fas fa-user text-white text-sm"></i>
                            </div>
                            <span class="hidden md:block text-sm"><?php echo htmlspecialchars($user['full_name']); ?></span>
                            <i class="fas fa-chevron-down text-xs"></i>
                        </button>
                        
                        <!-- Dropdown Menu -->
                        <div class="absolute right-0 top-full mt-2 w-48 bg-gray-800 rounded-lg shadow-xl border border-gray-700 opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all">
                            <div class="py-2">
                                <a href="/user/profile.php" class="block px-4 py-2 text-sm text-gray-300 hover:bg-gray-700 hover:text-white">
                                    <i class="fas fa-user mr-2"></i>Profile
                                </a>
                                <a href="/user/settings.php" class="block px-4 py-2 text-sm text-gray-300 hover:bg-gray-700 hover:text-white">
                                    <i class="fas fa-cog mr-2"></i>Settings
                                </a>
                                <div class="border-t border-gray-700"></div>
                                <a href="/logout.php" class="block px-4 py-2 text-sm text-red-400 hover:bg-gray-700">
                                    <i class="fas fa-sign-out-alt mr-2"></i>Logout
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Mobile Menu Button -->
                    <button class="md:hidden p-2 text-gray-400 hover:text-white">
                        <i class="fas fa-bars text-xl"></i>
                    </button>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="container mx-auto px-4 py-8">
        
        <!-- Hero Section - Balance & Actions -->
        <section class="gradient-bg rounded-2xl p-6 lg:p-8 mb-8 relative overflow-hidden">
            <div class="relative z-10">
                <div class="grid lg:grid-cols-3 gap-6 items-center">
                    <!-- Balance Info -->
                    <div class="lg:col-span-2">
                        <h1 class="text-3xl lg:text-4xl font-bold text-white mb-2">
                            Welcome back, <?php echo htmlspecialchars(explode(' ', $user['full_name'])[0]); ?>! 👋
                        </h1>
                        <div class="grid md:grid-cols-3 gap-4 mt-6">
                            <div class="text-center md:text-left">
                                <p class="text-white/80 text-sm">Wallet Balance</p>
                                <p class="text-2xl lg:text-3xl font-bold text-white"><?php echo formatMoney($user['wallet_balance']); ?></p>
                            </div>
                            <div class="text-center md:text-left">
                                <p class="text-white/80 text-sm">ROI Earned</p>
                                <p class="text-xl lg:text-2xl font-bold text-white"><?php echo formatMoney($user['total_roi_earned']); ?></p>
                            </div>
                            <?php if ($active_package): ?>
                            <div class="text-center md:text-left">
                                <p class="text-white/80 text-sm">Active Package</p>
                                <p class="text-lg font-bold text-white">
                                    <?php echo $active_package['icon']; ?> <?php echo $active_package['package_name']; ?>
                                </p>
                                <p class="text-sm text-white/70">ROI: <?php echo $active_package['roi_percentage']; ?>%</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Primary Actions -->
                    <div class="flex flex-col space-y-3">
                        <a href="/user/deposit.php" class="bg-white/20 hover:bg-white/30 text-white px-6 py-3 rounded-lg font-semibold text-center transition backdrop-blur-sm border border-white/20">
                            <i class="fas fa-plus mr-2"></i>Deposit Funds
                        </a>
                        <a href="/user/withdraw.php" class="bg-white/10 hover:bg-white/20 text-white px-6 py-3 rounded-lg font-semibold text-center transition backdrop-blur-sm border border-white/20">
                            <i class="fas fa-arrow-up mr-2"></i>Withdraw Funds
                        </a>
                        <a href="/user/packages.php" class="bg-white/10 hover:bg-white/20 text-white px-6 py-3 rounded-lg font-semibold text-center transition backdrop-blur-sm border border-white/20">
                            <i class="fas fa-chart-line mr-2"></i>Explore Packages
                        </a>
                    </div>
                </div>
            </div>

            <!-- Background Pattern -->
            <div class="absolute inset-0 opacity-10">
                <div class="absolute top-0 right-0 w-64 h-64 bg-white rounded-full -translate-y-32 translate-x-32"></div>
                <div class="absolute bottom-0 left-0 w-48 h-48 bg-white rounded-full translate-y-24 -translate-x-24"></div>
            </div>
        </section>

        <!-- Main Dashboard Grid -->
        <div class="grid lg:grid-cols-3 gap-8">
            
            <!-- Left Column - Recent Transactions -->
            <div class="lg:col-span-2">
                <div class="glass-card rounded-xl p-6 mb-8">
                    <div class="flex items-center justify-between mb-6">
                        <h2 class="text-xl font-bold text-white">Recent Transactions</h2>
                        <a href="/user/transactions.php" class="text-emerald-400 hover:text-emerald-300 text-sm">
                            View All <i class="fas fa-arrow-right ml-1"></i>
                        </a>
                    </div>

                    <?php if (empty($recent_transactions)): ?>
                        <div class="text-center py-8">
                            <i class="fas fa-receipt text-4xl text-gray-600 mb-4"></i>
                            <p class="text-gray-400">No transactions yet</p>
                            <p class="text-gray-500 text-sm">Start by depositing funds or investing in a package</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach (array_slice($recent_transactions, 0, 6) as $transaction): ?>
                            <div class="flex items-center justify-between p-4 bg-gray-800/50 rounded-lg">
                                <div class="flex items-center space-x-3">
                                    <div class="w-10 h-10 rounded-full flex items-center justify-center
                                        <?php 
                                        echo match($transaction['type']) {
                                            'deposit' => 'bg-emerald-500/20 text-emerald-400',
                                            'withdrawal' => 'bg-red-500/20 text-red-400',
                                            'roi_payment' => 'bg-yellow-500/20 text-yellow-400',
                                            'package_investment' => 'bg-blue-500/20 text-blue-400',
                                            default => 'bg-gray-500/20 text-gray-400'
                                        };
                                        ?>">
                                        <i class="fas <?php 
                                        echo match($transaction['type']) {
                                            'deposit' => 'fa-arrow-down',
                                            'withdrawal' => 'fa-arrow-up',
                                            'roi_payment' => 'fa-coins',
                                            'package_investment' => 'fa-chart-line',
                                            default => 'fa-exchange-alt'
                                        };
                                        ?>"></i>
                                    </div>
                                    <div>
                                        <p class="font-medium text-white capitalize">
                                            <?php echo str_replace('_', ' ', $transaction['type']); ?>
                                        </p>
                                        <p class="text-sm text-gray-400"><?php echo timeAgo($transaction['created_at']); ?></p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <p class="font-bold text-white"><?php echo formatMoney($transaction['amount']); ?></p>
                                    <p class="text-sm <?php 
                                    echo match($transaction['status']) {
                                        'completed' => 'status-green',
                                        'pending' => 'status-yellow',
                                        'failed' => 'status-red',
                                        default => 'text-gray-400'
                                    };
                                    ?>"><?php echo ucfirst($transaction['status']); ?></p>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Live User Activity Feed -->
                <div class="glass-card rounded-xl p-6">
                    <div class="flex items-center mb-6">
                        <div class="w-3 h-3 bg-emerald-500 rounded-full pulse-dot mr-3"></div>
                        <h2 class="text-xl font-bold text-white">Live Activity Feed</h2>
                    </div>
                    
                    <div class="space-y-3 max-h-80 overflow-y-auto">
                        <?php foreach ($live_activity as $activity): ?>
                        <div class="live-feed flex items-center justify-between p-3 bg-gray-800/30 rounded-lg">
                            <div class="flex items-center space-x-3">
                                <div class="w-8 h-8 bg-gradient-to-r from-emerald-500 to-yellow-500 rounded-full flex items-center justify-center text-xs">
                                    <?php echo strtoupper(substr($activity['full_name'], 0, 2)); ?>
                                </div>
                                <div>
                                    <p class="text-sm text-white">
                                        <span class="font-medium"><?php echo htmlspecialchars(explode(' ', $activity['full_name'])[0]); ?></span>
                                        <?php echo $activity['action_text']; ?>
                                        <span class="text-emerald-400"><?php echo formatMoney($activity['amount']); ?></span>
                                        <?php if ($activity['type'] === 'package_investment'): ?>
                                        <i class="fas fa-chart-line text-yellow-400 ml-1"></i>
                                        <?php endif; ?>
                                    </p>
                                    <p class="text-xs text-gray-400"><?php echo timeAgo($activity['created_at']); ?></p>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Right Column - Quick Actions & Notifications -->
            <div class="space-y-8">
                
                <!-- Quick Actions -->
                <div class="glass-card rounded-xl p-6">
                    <h2 class="text-xl font-bold text-white mb-6">Quick Actions</h2>
                    <div class="space-y-3">
                        <a href="/user/deposit.php" class="block w-full bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-3 rounded-lg font-medium text-center transition">
                            <i class="fas fa-plus mr-2"></i>Deposit Funds
                        </a>
                        <a href="/user/withdraw.php" class="block w-full bg-yellow-600 hover:bg-yellow-700 text-white px-4 py-3 rounded-lg font-medium text-center transition">
                            <i class="fas fa-arrow-up mr-2"></i>Withdraw Funds
                        </a>
                        <a href="/user/packages.php" class="block w-full bg-gray-700 hover:bg-gray-600 text-white px-4 py-3 rounded-lg font-medium text-center transition">
                            <i class="fas fa-chart-line mr-2"></i>View Packages
                        </a>
                    </div>
                </div>

                <!-- Notifications -->
                <div class="glass-card rounded-xl p-6">
                    <h2 class="text-xl font-bold text-white mb-6">Notifications</h2>
                    <?php if (empty($notifications)): ?>
                        <div class="text-center py-6">
                            <i class="fas fa-bell-slash text-3xl text-gray-600 mb-3"></i>
                            <p class="text-gray-400">No new notifications</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($notifications as $notification): ?>
                            <div class="p-4 bg-gray-800/50 rounded-lg">
                                <div class="flex items-start space-x-3">
                                    <i class="fas <?php 
                                    echo match($notification['type']) {
                                        'success' => 'fa-check-circle text-emerald-400',
                                        'warning' => 'fa-exclamation-triangle text-yellow-400',
                                        'error' => 'fa-exclamation-circle text-red-400',
                                        default => 'fa-info-circle text-blue-400'
                                    };
                                    ?> mt-1"></i>
                                    <div>
                                        <h3 class="font-medium text-white"><?php echo htmlspecialchars($notification['title']); ?></h3>
                                        <p class="text-sm text-gray-300 mt-1"><?php echo htmlspecialchars($notification['message']); ?></p>
                                        <p class="text-xs text-gray-500 mt-2"><?php echo timeAgo($notification['created_at']); ?></p>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Referral Info -->
                <div class="glass-card rounded-xl p-6">
                    <h2 class="text-xl font-bold text-white mb-6">Referral Program</h2>
                    <div class="bg-gradient-to-r from-emerald-600/20 to-yellow-600/20 rounded-lg p-4">
                        <h3 class="font-semibold text-white mb-2">Your Referral Code</h3>
                        <div class="flex items-center space-x-2">
                            <code class="bg-gray-800 px-3 py-1 rounded text-emerald-400 font-mono"><?php echo $user['referral_code']; ?></code>
                            <button onclick="copyReferralCode()" class="text-gray-400 hover:text-white">
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                        <p class="text-sm text-gray-300 mt-2">Earn <?php echo getSystemSetting('referral_commission_l1', 10); ?>% commission on referral deposits</p>
                        <a href="/user/referrals.php" class="text-emerald-400 hover:text-emerald-300 text-sm mt-3 inline-block">
                            View Details <i class="fas fa-arrow-right ml-1"></i>
                        </a>
                    </div>
                </div>

                <!-- Support -->
                <div class="glass-card rounded-xl p-6">
                    <h2 class="text-xl font-bold text-white mb-4">Need Help?</h2>
                    <div class="space-y-3">
                        <a href="/user/support.php" class="block text-emerald-400 hover:text-emerald-300">
                            <i class="fas fa-headset mr-2"></i>Contact Support
                        </a>
                        <a href="/help.php" class="block text-gray-400 hover:text-white">
                            <i class="fas fa-question-circle mr-2"></i>Help Center
                        </a>
                        <a href="https://wa.me/254700000000" target="_blank" class="block text-green-400 hover:text-green-300">
                            <i class="fab fa-whatsapp mr-2"></i>WhatsApp Support
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Mobile Bottom Navigation -->
    <div class="fixed bottom-0 left-0 right-0 bg-gray-800 border-t border-gray-700 md:hidden">
        <div class="grid grid-cols-4 py-2">
            <a href="/user/dashboard.php" class="flex flex-col items-center py-2 text-emerald-400">
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
            <a href="/user/profile.php" class="flex flex-col items-center py-2 text-gray-400">
                <i class="fas fa-user text-xl mb-1"></i>
                <span class="text-xs">Profile</span>
            </a>
        </div>
    </div>

    <script>
        function copyReferralCode() {
            const code = '<?php echo $user['referral_code']; ?>';
            navigator.clipboard.writeText(code).then(function() {
                alert('Referral code copied to clipboard!');
            }).catch(function() {
                // Fallback for older browsers
                const textArea = document.createElement('textarea');
                textArea.value = code;
                document.body.appendChild(textArea);
                textArea.select();
                document.execCommand('copy');
                document.body.removeChild(textArea);
                alert('Referral code copied to clipboard!');
            });
        }

        // Auto-refresh live activity feed every 30 seconds
        setInterval(function() {
            // You can implement AJAX refresh here if needed
        }, 30000);

        // Smooth scroll for mobile navigation
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                document.querySelector(this.getAttribute('href')).scrollIntoView({
                    behavior: 'smooth'
                });
            });
        });
    </script>
</body>
</html>