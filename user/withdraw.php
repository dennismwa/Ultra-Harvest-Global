<?php
require_once '../config/database.php';
requireLogin();

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

// Get user data
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Get system settings
$min_withdrawal = (float)getSystemSetting('min_withdrawal_amount', 100);
$max_withdrawal = (float)getSystemSetting('max_withdrawal_amount', 1000000);

// Handle withdrawal request
if ($_POST && isset($_POST['make_withdrawal'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $amount = (float)$_POST['amount'];
        $phone = sanitize($_POST['phone']);
        
        // Validation
        if ($amount < $min_withdrawal) {
            $error = 'Minimum withdrawal amount is ' . formatMoney($min_withdrawal) . '.';
        } elseif ($amount > $max_withdrawal) {
            $error = 'Maximum withdrawal amount is ' . formatMoney($max_withdrawal) . '.';
        } elseif ($amount > $user['wallet_balance']) {
            $error = 'Insufficient wallet balance.';
        } elseif (!preg_match('/^254[0-9]{9}$/', $phone)) {
            $error = 'Please enter a valid M-Pesa phone number (254XXXXXXXXX).';
        } else {
            try {
                $db->beginTransaction();
                
                // Deduct from wallet balance
                $stmt = $db->prepare("UPDATE users SET wallet_balance = wallet_balance - ? WHERE id = ?");
                $stmt->execute([$amount, $user_id]);
                
                // Create withdrawal transaction
                $stmt = $db->prepare("
                    INSERT INTO transactions (user_id, type, amount, phone_number, status, description) 
                    VALUES (?, 'withdrawal', ?, ?, 'pending', 'M-Pesa withdrawal request')
                ");
                $stmt->execute([$user_id, $amount, $phone]);
                
                $db->commit();
                $success = 'Withdrawal request submitted successfully. Your request will be processed within 24 hours.';
                
                // Send notification
                sendNotification($user_id, 'Withdrawal Requested', "Your withdrawal request for " . formatMoney($amount) . " has been submitted and is pending approval.", 'info');
                
                // Refresh user balance
                $stmt = $db->prepare("SELECT wallet_balance FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch();
                
            } catch (Exception $e) {
                $db->rollBack();
                $error = 'Failed to process withdrawal request. Please try again.';
                error_log("Withdrawal error: " . $e->getMessage());
            }
        }
    }
}

// Get recent withdrawals
$stmt = $db->prepare("
    SELECT * FROM transactions 
    WHERE user_id = ? AND type = 'withdrawal' 
    ORDER BY created_at DESC 
    LIMIT 10
");
$stmt->execute([$user_id]);
$recent_withdrawals = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Withdraw Funds - Ultra Harvest Global</title>
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
        
        .mpesa-green { background: linear-gradient(45deg, #00A651, #00D157); }
        
        .amount-btn {
            transition: all 0.3s ease;
        }
        
        .amount-btn:hover {
            transform: scale(1.05);
        }
        
        .amount-btn.selected {
            background: linear-gradient(45deg, #ef4444, #f87171);
            color: white;
        }
        
        .warning-card {
            background: linear-gradient(45deg, rgba(245, 158, 11, 0.1), rgba(251, 191, 36, 0.1));
            border: 1px solid rgba(245, 158, 11, 0.3);
        }
    </style>
</head>
<body class="bg-gray-900 text-white min-h-screen">

    <!-- Header -->
    <header class="bg-gray-800/50 backdrop-blur-md border-b border-gray-700">
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
                    </nav>
                </div>

                <div class="flex items-center space-x-4">
                    <div class="flex items-center space-x-2 bg-gray-700/50 rounded-full px-4 py-2">
                        <i class="fas fa-wallet text-emerald-400"></i>
                        <span class="text-sm text-gray-300">Balance:</span>
                        <span class="font-bold text-white"><?php echo formatMoney($user['wallet_balance']); ?></span>
                    </div>
                    <a href="/user/dashboard.php" class="text-gray-400 hover:text-white">
                        <i class="fas fa-arrow-left text-xl"></i>
                    </a>
                </div>
            </div>
        </div>
    </header>

    <main class="container mx-auto px-4 py-8">
        
        <!-- Page Header -->
        <div class="text-center mb-8">
            <h1 class="text-4xl font-bold mb-2">
                <i class="fas fa-arrow-up text-red-400 mr-3"></i>
                Withdraw Funds
            </h1>
            <p class="text-xl text-gray-300">Request withdrawal to your M-Pesa account</p>
        </div>

        <!-- Error/Success Messages -->
        <?php if ($error): ?>
        <div class="max-w-2xl mx-auto mb-6 p-4 bg-red-500/20 border border-red-500/50 rounded-lg">
            <div class="flex items-center">
                <i class="fas fa-exclamation-circle text-red-400 mr-2"></i>
                <span class="text-red-300"><?php echo $error; ?></span>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($success): ?>
        <div class="max-w-2xl mx-auto mb-6 p-4 bg-emerald-500/20 border border-emerald-500/50 rounded-lg">
            <div class="flex items-center">
                <i class="fas fa-check-circle text-emerald-400 mr-2"></i>
                <span class="text-emerald-300"><?php echo $success; ?></span>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($user['wallet_balance'] < $min_withdrawal): ?>
        <!-- Insufficient Balance Warning -->
        <div class="max-w-2xl mx-auto mb-8">
            <div class="warning-card rounded-xl p-8 text-center">
                <i class="fas fa-exclamation-triangle text-yellow-400 text-4xl mb-4"></i>
                <h3 class="text-xl font-bold text-white mb-4">Insufficient Balance</h3>
                <p class="text-gray-300 mb-6">
                    Your current balance is <?php echo formatMoney($user['wallet_balance']); ?>. 
                    The minimum withdrawal amount is <?php echo formatMoney($min_withdrawal); ?>.
                </p>
                <div class="flex flex-col sm:flex-row gap-3 justify-center">
                    <a href="/user/deposit.php" class="px-6 py-3 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg font-medium transition">
                        <i class="fas fa-plus mr-2"></i>Deposit Funds
                    </a>
                    <a href="/user/packages.php" class="px-6 py-3 bg-yellow-600 hover:bg-yellow-700 text-white rounded-lg font-medium transition">
                        <i class="fas fa-chart-line mr-2"></i>Invest & Earn
                    </a>
                </div>
            </div>
        </div>
        <?php else: ?>

        <div class="grid lg:grid-cols-3 gap-8">
            
            <!-- Main Withdrawal Form -->
            <div class="lg:col-span-2">
                <div class="glass-card rounded-xl p-8">
                    
                    <!-- M-Pesa Header -->
                    <div class="text-center mb-8">
                        <div class="mpesa-green w-20 h-20 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-mobile-alt text-white text-3xl"></i>
                        </div>
                        <h2 class="text-2xl font-bold text-white mb-2">M-Pesa Withdrawal</h2>
                        <p class="text-gray-300">Withdraw directly to your M-Pesa account</p>
                    </div>

                    <form method="POST" class="space-y-6">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="make_withdrawal" value="1">

                        <!-- Available Balance -->
                        <div class="bg-gradient-to-r from-emerald-600/20 to-yellow-600/20 rounded-lg p-6">
                            <div class="text-center">
                                <p class="text-gray-300 mb-2">Available Balance</p>
                                <p class="text-4xl font-bold text-white mb-4"><?php echo formatMoney($user['wallet_balance']); ?></p>
                                <div class="grid grid-cols-2 gap-4 text-sm">
                                    <div>
                                        <p class="text-gray-400">Minimum</p>
                                        <p class="text-emerald-400 font-bold"><?php echo formatMoney($min_withdrawal); ?></p>
                                    </div>
                                    <div>
                                        <p class="text-gray-400">Maximum</p>
                                        <p class="text-red-400 font-bold"><?php echo formatMoney(min($max_withdrawal, $user['wallet_balance'])); ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Quick Amount Selection -->
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-4">
                                <i class="fas fa-coins mr-2"></i>Select Amount (KSh)
                            </label>
                            <div class="grid grid-cols-3 md:grid-cols-4 gap-3 mb-4">
                                <?php 
                                $balance = $user['wallet_balance'];
                                $quick_amounts = [];
                                
                                // Generate smart quick amounts based on balance
                                if ($balance >= 500) $quick_amounts[] = 500;
                                if ($balance >= 1000) $quick_amounts[] = 1000;
                                if ($balance >= 2000) $quick_amounts[] = 2000;
                                if ($balance >= 5000) $quick_amounts[] = 5000;
                                if ($balance >= 10000) $quick_amounts[] = 10000;
                                if ($balance >= 20000) $quick_amounts[] = 20000;
                                if ($balance >= 50000) $quick_amounts[] = 50000;
                                
                                // Add "All" button if balance is above minimum
                                if ($balance >= $min_withdrawal) {
                                    $quick_amounts[] = floor($balance);
                                }
                                
                                foreach ($quick_amounts as $index => $amount): 
                                ?>
                                <button 
                                    type="button" 
                                    class="amount-btn px-4 py-3 bg-gray-800 border border-gray-600 rounded-lg text-white hover:border-red-500 transition"
                                    data-amount="<?php echo $amount; ?>"
                                >
                                    <?php echo $index === count($quick_amounts) - 1 && $amount == floor($balance) ? 'All' : number_format($amount); ?>
                                </button>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Custom Amount Input -->
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">
                                <i class="fas fa-edit mr-2"></i>Or Enter Custom Amount
                            </label>
                            <div class="relative">
                                <span class="absolute left-4 top-1/2 transform -translate-y-1/2 text-gray-400">KSh</span>
                                <input 
                                    type="number" 
                                    name="amount" 
                                    id="amount"
                                    min="<?php echo $min_withdrawal; ?>"
                                    max="<?php echo min($max_withdrawal, $user['wallet_balance']); ?>"
                                    step="1"
                                    class="w-full pl-12 pr-4 py-4 bg-gray-800 border border-gray-600 rounded-lg text-white text-xl font-bold focus:border-red-500 focus:outline-none"
                                    placeholder="Enter amount"
                                    required
                                >
                            </div>
                            <div class="flex justify-between text-sm text-gray-500 mt-2">
                                <span>Minimum: <?php echo formatMoney($min_withdrawal); ?></span>
                                <span>Available: <?php echo formatMoney($user['wallet_balance']); ?></span>
                            </div>
                        </div>

                        <!-- Phone Number -->
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">
                                <i class="fas fa-phone mr-2"></i>M-Pesa Phone Number
                            </label>
                            <input 
                                type="tel" 
                                name="phone" 
                                id="phone"
                                value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>"
                                class="w-full px-4 py-4 bg-gray-800 border border-gray-600 rounded-lg text-white focus:border-red-500 focus:outline-none"
                                placeholder="254XXXXXXXXX"
                                pattern="254[0-9]{9}"
                                required
                            >
                            <p class="text-sm text-gray-500 mt-2">
                                <i class="fas fa-info-circle mr-1"></i>
                                Funds will be sent to this M-Pesa number
                            </p>
                        </div>

                        <!-- Withdrawal Summary -->
                        <div class="bg-gray-800/50 rounded-lg p-6" id="withdrawal-summary" style="display: none;">
                            <h3 class="font-bold text-white mb-4">Withdrawal Summary</h3>
                            <div class="space-y-3">
                                <div class="flex justify-between">
                                    <span class="text-gray-400">Amount</span>
                                    <span class="text-white font-bold" id="summary-amount">KSh 0</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-400">Processing Fee</span>
                                    <span class="text-emerald-400">FREE</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-400">Processing Time</span>
                                    <span class="text-yellow-400">Within 24 hours</span>
                                </div>
                                <div class="border-t border-gray-700 pt-3">
                                    <div class="flex justify-between">
                                        <span class="text-white font-medium">You Will Receive</span>
                                        <span class="text-emerald-400 font-bold text-xl" id="summary-total">KSh 0</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Terms Notice -->
                        <div class="bg-yellow-500/10 border border-yellow-500/30 rounded-lg p-4">
                            <div class="flex items-start space-x-3">
                                <i class="fas fa-exclamation-triangle text-yellow-400 mt-1"></i>
                                <div>
                                    <h4 class="font-bold text-yellow-400 mb-2">Important Notice</h4>
                                    <ul class="text-sm text-gray-300 space-y-1">
                                        <li>• Withdrawals are processed within 24 hours</li>
                                        <li>• Ensure your M-Pesa number is correct</li>
                                        <li>• No processing fees charged by Ultra Harvest</li>
                                        <li>• Minimum withdrawal: <?php echo formatMoney($min_withdrawal); ?></li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <!-- Submit Button -->
                        <button 
                            type="submit" 
                            class="w-full py-4 bg-gradient-to-r from-red-500 to-red-600 text-white font-bold text-lg rounded-lg hover:from-red-600 hover:to-red-700 transform hover:scale-[1.02] transition-all duration-300 shadow-lg"
                            id="withdraw-btn"
                            disabled
                        >
                            <i class="fas fa-arrow-up mr-2"></i>Request Withdrawal
                        </button>

                        <!-- Terms -->
                        <p class="text-xs text-gray-500 text-center leading-relaxed">
                            By proceeding, you confirm that the M-Pesa number provided is correct and belongs to you. 
                            Withdrawal requests cannot be cancelled once submitted.
                        </p>
                    </form>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="space-y-6">
                
                <!-- Processing Info -->
                <div class="glass-card rounded-xl p-6">
                    <h3 class="text-lg font-bold text-white mb-4">
                        <i class="fas fa-clock text-blue-400 mr-2"></i>
                        Processing Timeline
                    </h3>
                    <div class="space-y-4">
                        <div class="flex items-start space-x-3">
                            <div class="w-6 h-6 bg-blue-500 rounded-full flex items-center justify-center flex-shrink-0 mt-0.5">
                                <span class="text-white text-xs font-bold">1</span>
                            </div>
                            <div>
                                <p class="font-medium text-white">Request Submitted</p>
                                <p class="text-sm text-gray-400">Your withdrawal request is received</p>
                            </div>
                        </div>
                        <div class="flex items-start space-x-3">
                            <div class="w-6 h-6 bg-yellow-500 rounded-full flex items-center justify-center flex-shrink-0 mt-0.5">
                                <span class="text-white text-xs font-bold">2</span>
                            </div>
                            <div>
                                <p class="font-medium text-white">Under Review</p>
                                <p class="text-sm text-gray-400">Admin verifies your request</p>
                            </div>
                        </div>
                        <div class="flex items-start space-x-3">
                            <div class="w-6 h-6 bg-emerald-500 rounded-full flex items-center justify-center flex-shrink-0 mt-0.5">
                                <span class="text-white text-xs font-bold">3</span>
                            </div>
                            <div>
                                <p class="font-medium text-white">Payment Sent</p>
                                <p class="text-sm text-gray-400">Funds transferred to your M-Pesa</p>
                            </div>
                        </div>
                    </div>
                    <div class="mt-6 p-4 bg-emerald-600/20 rounded-lg">
                        <p class="text-emerald-400 font-medium text-center">
                            <i class="fas fa-bolt mr-2"></i>
                            Usually completed within 2-6 hours
                        </p>
                    </div>
                </div>

                <!-- Recent Withdrawals -->
                <div class="glass-card rounded-xl p-6">
                    <h3 class="text-lg font-bold text-white mb-4">
                        <i class="fas fa-history text-red-400 mr-2"></i>
                        Recent Withdrawals
                    </h3>
                    <?php if (empty($recent_withdrawals)): ?>
                        <div class="text-center py-6">
                            <i class="fas fa-inbox text-3xl text-gray-600 mb-3"></i>
                            <p class="text-gray-400">No withdrawals yet</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-3 max-h-64 overflow-y-auto">
                            <?php foreach (array_slice($recent_withdrawals, 0, 5) as $withdrawal): ?>
                            <div class="flex items-center justify-between p-3 bg-gray-800/30 rounded-lg">
                                <div>
                                    <p class="font-medium text-white"><?php echo formatMoney($withdrawal['amount']); ?></p>
                                    <p class="text-xs text-gray-400"><?php echo timeAgo($withdrawal['created_at']); ?></p>
                                    <?php if ($withdrawal['phone_number']): ?>
                                        <p class="text-xs text-blue-400"><?php echo $withdrawal['phone_number']; ?></p>
                                    <?php endif; ?>
                                </div>
                                <span class="px-2 py-1 rounded text-xs font-medium
                                    <?php 
                                    echo match($withdrawal['status']) {
                                        'completed' => 'bg-emerald-500/20 text-emerald-400',
                                        'pending' => 'bg-yellow-500/20 text-yellow-400',
                                        'failed' => 'bg-red-500/20 text-red-400',
                                        default => 'bg-gray-500/20 text-gray-400'
                                    };
                                    ?>">
                                    <?php echo ucfirst($withdrawal['status']); ?>
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Support -->
                <div class="glass-card rounded-xl p-6">
                    <h3 class="text-lg font-bold text-white mb-4">
                        <i class="fas fa-headset text-purple-400 mr-2"></i>
                        Need Help?
                    </h3>
                    <div class="space-y-3">
                        <a href="https://wa.me/254700000000" target="_blank" class="flex items-center text-green-400 hover:text-green-300">
                            <i class="fab fa-whatsapp mr-2"></i>WhatsApp Support
                        </a>
                        <a href="/user/support.php" class="flex items-center text-blue-400 hover:text-blue-300">
                            <i class="fas fa-ticket-alt mr-2"></i>Create Ticket
                        </a>
                        <a href="/help.php" class="flex items-center text-gray-400 hover:text-white">
                            <i class="fas fa-question-circle mr-2"></i>Help Center
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </main>

    <script>
        // Quick amount selection
        document.querySelectorAll('.amount-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                // Remove selected class from all buttons
                document.querySelectorAll('.amount-btn').forEach(b => b.classList.remove('selected'));
                
                // Add selected class to clicked button
                this.classList.add('selected');
                
                // Set amount in input
                const amount = this.getAttribute('data-amount');
                document.getElementById('amount').value = amount;
                
                // Update summary
                updateSummary();
            });
        });

        // Amount input changes
        document.getElementById('amount').addEventListener('input', function() {
            // Remove selected class from quick amount buttons
            document.querySelectorAll('.amount-btn').forEach(btn => btn.classList.remove('selected'));
            updateSummary();
        });

        // Phone number formatting
        document.getElementById('phone').addEventListener('input', function() {
            let value = this.value.replace(/\D/g, '');
            if (value.startsWith('0')) {
                value = '254' + value.substring(1);
            }
            if (!value.startsWith('254') && value.length > 0) {
                value = '254' + value;
            }
            this.value = value;
        });

        // Update withdrawal summary
        function updateSummary() {
            const amount = parseFloat(document.getElementById('amount').value) || 0;
            const minWithdrawal = <?php echo $min_withdrawal; ?>;
            const maxWithdrawal = <?php echo min($max_withdrawal, $user['wallet_balance']); ?>;
            const summaryElement = document.getElementById('withdrawal-summary');
            const submitBtn = document.getElementById('withdraw-btn');

            if (amount >= minWithdrawal && amount <= maxWithdrawal) {
                summaryElement.style.display = 'block';
                document.getElementById('summary-amount').textContent = 'KSh ' + amount.toLocaleString();
                document.getElementById('summary-total').textContent = 'KSh ' + amount.toLocaleString();
                submitBtn.disabled = false;
                submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
            } else {
                summaryElement.style.display = 'none';
                submitBtn.disabled = true;
                submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
            }
        }

        // Form submission
        document.querySelector('form').addEventListener('submit', function(e) {
            const amount = parseFloat(document.getElementById('amount').value) || 0;
            const phone = document.getElementById('phone').value;
            
            if (!phone || !phone.match(/^254[0-9]{9}$/)) {
                e.preventDefault();
                alert('Please enter a valid M-Pesa phone number');
                return;
            }
            
            if (!confirm(`Are you sure you want to withdraw KSh ${amount.toLocaleString()} to ${phone}?`)) {
                e.preventDefault();
                return;
            }
            
            const submitBtn = document.getElementById('withdraw-btn');
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Processing...';
            submitBtn.disabled = true;
        });

        // Initialize
        updateSummary();
    </script>
</body>
</html>