<?php
require_once '../config/database.php';
requireAdmin();

$error = '';
$success = '';

// Handle user actions
if ($_POST) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';
        $user_id = (int)($_POST['user_id'] ?? 0);
        
        switch ($action) {
            case 'update_status':
                $status = sanitize($_POST['status']);
                if (in_array($status, ['active', 'suspended', 'banned'])) {
                    $stmt = $db->prepare("UPDATE users SET status = ? WHERE id = ? AND is_admin = 0");
                    if ($stmt->execute([$status, $user_id])) {
                        $success = 'User status updated successfully.';
                        
                        // Send notification to user
                        $message = match($status) {
                            'suspended' => 'Your account has been suspended. Please contact support for assistance.',
                            'banned' => 'Your account has been banned due to policy violations.',
                            'active' => 'Your account has been reactivated. Welcome back!'
                        };
                        sendNotification($user_id, 'Account Status Update', $message, $status === 'active' ? 'success' : 'warning');
                    } else {
                        $error = 'Failed to update user status.';
                    }
                }
                break;
                
            case 'adjust_balance':
                $amount = (float)($_POST['amount'] ?? 0);
                $type = $_POST['balance_type'] ?? 'credit'; // credit or debit
                $description = sanitize($_POST['description'] ?? '');
                
                if ($amount > 0) {
                    try {
                        $db->beginTransaction();
                        
                        if ($type === 'credit') {
                            $stmt = $db->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?");
                            $stmt->execute([$amount, $user_id]);
                            
                            // Create transaction record
                            $stmt = $db->prepare("INSERT INTO transactions (user_id, type, amount, status, description, processed_by) VALUES (?, 'deposit', ?, 'completed', ?, ?)");
                            $stmt->execute([$user_id, $amount, "Admin credit: " . $description, $_SESSION['user_id']]);
                            
                            sendNotification($user_id, 'Account Credited', formatMoney($amount) . " has been credited to your account. " . $description, 'success');
                        } else {
                            $stmt = $db->prepare("UPDATE users SET wallet_balance = wallet_balance - ? WHERE id = ? AND wallet_balance >= ?");
                            $affected = $stmt->execute([$amount, $user_id, $amount]);
                            
                            if ($stmt->rowCount() > 0) {
                                // Create transaction record
                                $stmt = $db->prepare("INSERT INTO transactions (user_id, type, amount, status, description, processed_by) VALUES (?, 'withdrawal', ?, 'completed', ?, ?)");
                                $stmt->execute([$user_id, $amount, "Admin debit: " . $description, $_SESSION['user_id']]);
                                
                                sendNotification($user_id, 'Account Debited', formatMoney($amount) . " has been debited from your account. " . $description, 'warning');
                            } else {
                                throw new Exception('Insufficient balance for debit');
                            }
                        }
                        
                        $db->commit();
                        $success = 'Balance adjusted successfully.';
                    } catch (Exception $e) {
                        $db->rollBack();
                        $error = 'Failed to adjust balance: ' . $e->getMessage();
                    }
                }
                break;
        }
    }
}

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$search = sanitize($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

// Build query conditions
$where_conditions = ["is_admin = 0"];
$params = [];

if ($status_filter !== 'all') {
    $where_conditions[] = "status = ?";
    $params[] = $status_filter;
}

if ($search) {
    $where_conditions[] = "(full_name LIKE ? OR email LIKE ? OR phone LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$where_clause = implode(' AND ', $where_conditions);

// Get total count
$count_sql = "SELECT COUNT(*) as total FROM users WHERE $where_clause";
$stmt = $db->prepare($count_sql);
$stmt->execute($params);
$total_records = $stmt->fetch()['total'];
$total_pages = ceil($total_records / $limit);

// Get users
$sql = "
    SELECT u.*,
           COALESCE(SUM(CASE WHEN t.type = 'deposit' AND t.status = 'completed' THEN t.amount ELSE 0 END), 0) as total_deposited,
           COALESCE(SUM(CASE WHEN t.type = 'withdrawal' AND t.status = 'completed' THEN t.amount ELSE 0 END), 0) as total_withdrawn,
           COUNT(CASE WHEN ref.id IS NOT NULL THEN 1 END) as total_referrals
    FROM users u
    LEFT JOIN transactions t ON u.id = t.user_id
    LEFT JOIN users ref ON u.id = ref.referred_by
    WHERE $where_clause
    GROUP BY u.id
    ORDER BY u.created_at DESC
    LIMIT $limit OFFSET $offset
";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

// Get summary statistics
$stats_sql = "
    SELECT 
        COUNT(*) as total_users,
        COUNT(CASE WHEN status = 'active' THEN 1 END) as active_users,
        COUNT(CASE WHEN status = 'suspended' THEN 1 END) as suspended_users,
        COUNT(CASE WHEN status = 'banned' THEN 1 END) as banned_users,
        COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) as new_users_30d
    FROM users 
    WHERE is_admin = 0
";
$stmt = $db->query($stats_sql);
$stats = $stmt->fetch();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Ultra Harvest Admin</title>
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
        
        .user-row {
            transition: all 0.3s ease;
        }
        
        .user-row:hover {
            background: rgba(255, 255, 255, 0.05);
        }
        
        .modal {
            display: none;
            backdrop-filter: blur(10px);
        }
        
        .modal.show {
            display: flex;
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
                        <a href="/admin/users.php" class="text-emerald-400 font-medium">Users</a>
                        <a href="/admin/packages.php" class="text-gray-300 hover:text-emerald-400 transition">Packages</a>
                        <a href="/admin/transactions.php" class="text-gray-300 hover:text-emerald-400 transition">Transactions</a>
                        <a href="/admin/system-health.php" class="text-gray-300 hover:text-emerald-400 transition">System Health</a>
                    </nav>
                </div>

                <div class="flex items-center space-x-4">
                    <a href="/" target="_blank" class="text-gray-400 hover:text-white">
                        <i class="fas fa-external-link-alt"></i>
                    </a>
                    <a href="/logout.php" class="text-red-400 hover:text-red-300">
                        <i class="fas fa-sign-out-alt"></i>
                    </a>
                </div>
            </div>
        </div>
    </header>

    <main class="container mx-auto px-4 py-8">
        
        <!-- Page Header -->
        <div class="flex items-center justify-between mb-8">
            <div>
                <h1 class="text-3xl font-bold text-white">User Management</h1>
                <p class="text-gray-400">Manage user accounts and activities</p>
            </div>
            <div class="text-right">
                <p class="text-2xl font-bold text-emerald-400"><?php echo number_format($stats['total_users']); ?></p>
                <p class="text-gray-400 text-sm">Total Users</p>
            </div>
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

        <!-- Statistics Overview -->
        <section class="grid md:grid-cols-2 lg:grid-cols-5 gap-6 mb-8">
            <div class="glass-card rounded-xl p-6">
                <div class="text-center">
                    <p class="text-emerald-400 text-3xl font-bold"><?php echo number_format($stats['active_users']); ?></p>
                    <p class="text-gray-400 text-sm">Active Users</p>
                </div>
            </div>
            <div class="glass-card rounded-xl p-6">
                <div class="text-center">
                    <p class="text-yellow-400 text-3xl font-bold"><?php echo number_format($stats['suspended_users']); ?></p>
                    <p class="text-gray-400 text-sm">Suspended</p>
                </div>
            </div>
            <div class="glass-card rounded-xl p-6">
                <div class="text-center">
                    <p class="text-red-400 text-3xl font-bold"><?php echo number_format($stats['banned_users']); ?></p>
                    <p class="text-gray-400 text-sm">Banned</p>
                </div>
            </div>
            <div class="glass-card rounded-xl p-6">
                <div class="text-center">
                    <p class="text-blue-400 text-3xl font-bold"><?php echo number_format($stats['new_users_30d']); ?></p>
                    <p class="text-gray-400 text-sm">New (30d)</p>
                </div>
            </div>
            <div class="glass-card rounded-xl p-6">
                <div class="text-center">
                    <p class="text-purple-400 text-3xl font-bold"><?php echo number_format($stats['total_users']); ?></p>
                    <p class="text-gray-400 text-sm">Total Users</p>
                </div>
            </div>
        </section>

        <!-- Filters and Search -->
        <section class="glass-card rounded-xl p-6 mb-8">
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                <!-- Status Filter -->
                <div class="flex flex-wrap gap-2">
                    <a href="?status=all&search=<?php echo urlencode($search); ?>" 
                       class="px-4 py-2 rounded-lg font-medium transition <?php echo $status_filter === 'all' ? 'bg-emerald-600 text-white' : 'bg-gray-800 text-gray-300 hover:bg-gray-700'; ?>">
                        All Users
                    </a>
                    <a href="?status=active&search=<?php echo urlencode($search); ?>" 
                       class="px-4 py-2 rounded-lg font-medium transition <?php echo $status_filter === 'active' ? 'bg-emerald-600 text-white' : 'bg-gray-800 text-gray-300 hover:bg-gray-700'; ?>">
                        Active
                    </a>
                    <a href="?status=suspended&search=<?php echo urlencode($search); ?>" 
                       class="px-4 py-2 rounded-lg font-medium transition <?php echo $status_filter === 'suspended' ? 'bg-emerald-600 text-white' : 'bg-gray-800 text-gray-300 hover:bg-gray-700'; ?>">
                        Suspended
                    </a>
                    <a href="?status=banned&search=<?php echo urlencode($search); ?>" 
                       class="px-4 py-2 rounded-lg font-medium transition <?php echo $status_filter === 'banned' ? 'bg-emerald-600 text-white' : 'bg-gray-800 text-gray-300 hover:bg-gray-700'; ?>">
                        Banned
                    </a>
                </div>

                <!-- Search -->
                <form method="GET" class="flex items-center space-x-3">
                    <input type="hidden" name="status" value="<?php echo $status_filter; ?>">
                    <div class="relative">
                        <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                        <input 
                            type="text" 
                            name="search" 
                            value="<?php echo htmlspecialchars($search); ?>"
                            placeholder="Search users..." 
                            class="pl-10 pr-4 py-2 bg-gray-800 border border-gray-600 rounded-lg text-white focus:border-emerald-500 focus:outline-none"
                        >
                    </div>
                    <button type="submit" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg font-medium transition">
                        Search
                    </button>
                    <?php if ($search): ?>
                    <a href="?status=<?php echo $status_filter; ?>" class="px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded-lg font-medium transition">
                        Clear
                    </a>
                    <?php endif; ?>
                </form>
            </div>
        </section>

        <!-- Users Table -->
        <section class="glass-card rounded-xl overflow-hidden">
            <?php if (empty($users)): ?>
                <div class="p-12 text-center">
                    <i class="fas fa-users text-6xl text-gray-600 mb-4"></i>
                    <h3 class="text-xl font-bold text-gray-400 mb-2">No users found</h3>
                    <p class="text-gray-500">No users match your current filters</p>
                </div>
            <?php else: ?>
                <!-- Desktop Table -->
                <div class="hidden lg:block overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-800/50">
                            <tr>
                                <th class="text-left p-4 text-gray-400 font-medium">User</th>
                                <th class="text-center p-4 text-gray-400 font-medium">Status</th>
                                <th class="text-right p-4 text-gray-400 font-medium">Balance</th>
                                <th class="text-right p-4 text-gray-400 font-medium">Deposited</th>
                                <th class="text-center p-4 text-gray-400 font-medium">Referrals</th>
                                <th class="text-center p-4 text-gray-400 font-medium">Joined</th>
                                <th class="text-center p-4 text-gray-400 font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                            <tr class="user-row border-b border-gray-800">
                                <td class="p-4">
                                    <div class="flex items-center space-x-3">
                                        <div class="w-10 h-10 bg-gradient-to-r from-emerald-500 to-blue-500 rounded-full flex items-center justify-center">
                                            <?php echo strtoupper(substr($user['full_name'], 0, 2)); ?>
                                        </div>
                                        <div>
                                            <p class="font-medium text-white"><?php echo htmlspecialchars($user['full_name']); ?></p>
                                            <p class="text-sm text-gray-400"><?php echo htmlspecialchars($user['email']); ?></p>
                                            <?php if ($user['phone']): ?>
                                                <p class="text-xs text-blue-400"><?php echo htmlspecialchars($user['phone']); ?></p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="p-4 text-center">
                                    <span class="px-3 py-1 rounded-full text-xs font-medium
                                        <?php 
                                        echo match($user['status']) {
                                            'active' => 'bg-emerald-500/20 text-emerald-400',
                                            'suspended' => 'bg-yellow-500/20 text-yellow-400',
                                            'banned' => 'bg-red-500/20 text-red-400',
                                            default => 'bg-gray-500/20 text-gray-400'
                                        };
                                        ?>">
                                        <?php echo ucfirst($user['status']); ?>
                                    </span>
                                </td>
                                <td class="p-4 text-right">
                                    <p class="font-bold text-white"><?php echo formatMoney($user['wallet_balance']); ?></p>
                                </td>
                                <td class="p-4 text-right">
                                    <p class="text-emerald-400 font-medium"><?php echo formatMoney($user['total_deposited']); ?></p>
                                </td>
                                <td class="p-4 text-center">
                                    <span class="text-purple-400 font-medium"><?php echo number_format($user['total_referrals']); ?></span>
                                </td>
                                <td class="p-4 text-center text-gray-300 text-sm">
                                    <?php echo date('M j, Y', strtotime($user['created_at'])); ?>
                                </td>
                                <td class="p-4 text-center">
                                    <div class="flex items-center justify-center space-x-2">
                                        <button onclick="openUserModal(<?php echo $user['id']; ?>)" class="px-3 py-1 bg-blue-600 hover:bg-blue-700 text-white rounded text-xs transition">
                                            <i class="fas fa-edit mr-1"></i>Edit
                                        </button>
                                        <button onclick="viewUserDetails(<?php echo $user['id']; ?>)" class="px-3 py-1 bg-emerald-600 hover:bg-emerald-700 text-white rounded text-xs transition">
                                            <i class="fas fa-eye mr-1"></i>View
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile Cards -->
                <div class="lg:hidden p-4">
                    <div class="space-y-4">
                        <?php foreach ($users as $user): ?>
                        <div class="bg-gray-800/50 rounded-lg p-4">
                            <div class="flex items-center justify-between mb-3">
                                <div class="flex items-center space-x-3">
                                    <div class="w-12 h-12 bg-gradient-to-r from-emerald-500 to-blue-500 rounded-full flex items-center justify-center">
                                        <?php echo strtoupper(substr($user['full_name'], 0, 2)); ?>
                                    </div>
                                    <div>
                                        <p class="font-medium text-white"><?php echo htmlspecialchars($user['full_name']); ?></p>
                                        <p class="text-sm text-gray-400"><?php echo htmlspecialchars($user['email']); ?></p>
                                    </div>
                                </div>
                                <span class="px-2 py-1 rounded text-xs font-medium
                                    <?php 
                                    echo match($user['status']) {
                                        'active' => 'bg-emerald-500/20 text-emerald-400',
                                        'suspended' => 'bg-yellow-500/20 text-yellow-400',
                                        'banned' => 'bg-red-500/20 text-red-400',
                                        default => 'bg-gray-500/20 text-gray-400'
                                    };
                                    ?>">
                                    <?php echo ucfirst($user['status']); ?>
                                </span>
                            </div>
                            <div class="grid grid-cols-3 gap-4 text-sm mb-3">
                                <div>
                                    <p class="text-gray-400">Balance</p>
                                    <p class="font-bold text-white"><?php echo formatMoney($user['wallet_balance']); ?></p>
                                </div>
                                <div>
                                    <p class="text-gray-400">Deposited</p>
                                    <p class="font-bold text-emerald-400"><?php echo formatMoney($user['total_deposited']); ?></p>
                                </div>
                                <div>
                                    <p class="text-gray-400">Referrals</p>
                                    <p class="font-bold text-purple-400"><?php echo number_format($user['total_referrals']); ?></p>
                                </div>
                            </div>
                            <div class="flex space-x-2">
                                <button onclick="openUserModal(<?php echo $user['id']; ?>)" class="flex-1 px-3 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded text-sm transition">
                                    <i class="fas fa-edit mr-1"></i>Edit
                                </button>
                                <button onclick="viewUserDetails(<?php echo $user['id']; ?>)" class="flex-1 px-3 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded text-sm transition">
                                    <i class="fas fa-eye mr-1"></i>View
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="p-6 border-t border-gray-800">
                    <div class="flex items-center justify-between">
                        <div class="text-sm text-gray-400">
                            Showing <?php echo ($offset + 1); ?> to <?php echo min($offset + $limit, $total_records); ?> of <?php echo number_format($total_records); ?> users
                        </div>
                        <div class="flex items-center space-x-2">
                            <?php if ($page > 1): ?>
                                <a href="?status=<?php echo $status_filter; ?>&search=<?php echo urlencode($search); ?>&page=<?php echo $page-1; ?>" 
                                   class="px-3 py-2 bg-gray-800 text-gray-300 rounded hover:bg-gray-700 transition">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            <?php endif; ?>
                            
                            <?php
                            $start = max(1, $page - 2);
                            $end = min($total_pages, $page + 2);
                            
                            for ($i = $start; $i <= $end; $i++):
                            ?>
                                <a href="?status=<?php echo $status_filter; ?>&search=<?php echo urlencode($search); ?>&page=<?php echo $i; ?>" 
                                   class="px-3 py-2 rounded transition <?php echo $i === $page ? 'bg-emerald-600 text-white' : 'bg-gray-800 text-gray-300 hover:bg-gray-700'; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                            
                            <?php if ($page < $total_pages): ?>
                                <a href="?status=<?php echo $status_filter; ?>&search=<?php echo urlencode($search); ?>&page=<?php echo $page+1; ?>" 
                                   class="px-3 py-2 bg-gray-800 text-gray-300 rounded hover:bg-gray-700 transition">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </main>

    <!-- User Edit Modal -->
    <div id="userModal" class="modal fixed inset-0 bg-black/50 flex items-center justify-center p-4 z-50">
        <div class="glass-card rounded-xl p-6 max-w-md w-full">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-xl font-bold text-white">Edit User</h3>
                <button onclick="closeUserModal()" class="text-gray-400 hover:text-white">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <form id="editUserForm" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="user_id" id="modal_user_id">
                
                <!-- Status Update -->
                <div class="mb-6">
                    <h4 class="font-medium text-white mb-3">Update Status</h4>
                    <div class="space-y-2">
                        <input type="hidden" name="action" value="update_status">
                        <label class="flex items-center space-x-2">
                            <input type="radio" name="status" value="active" class="text-emerald-600">
                            <span class="text-emerald-400">Active</span>
                        </label>
                        <label class="flex items-center space-x-2">
                            <input type="radio" name="status" value="suspended" class="text-yellow-600">
                            <span class="text-yellow-400">Suspended</span>
                        </label>
                        <label class="flex items-center space-x-2">
                            <input type="radio" name="status" value="banned" class="text-red-600">
                            <span class="text-red-400">Banned</span>
                        </label>
                    </div>
                    <button type="submit" class="mt-3 w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition">
                        Update Status
                    </button>
                </div>
            </form>

            <!-- Balance Adjustment -->
            <form method="POST" class="border-t border-gray-700 pt-6">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="user_id" id="balance_user_id">
                <input type="hidden" name="action" value="adjust_balance">
                
                <h4 class="font-medium text-white mb-3">Adjust Balance</h4>
                
                <div class="space-y-3">
                    <div>
                        <label class="block text-sm text-gray-300 mb-1">Amount (KSh)</label>
                        <input 
                            type="number" 
                            name="amount" 
                            min="1" 
                            step="0.01"
                            class="w-full px-3 py-2 bg-gray-800 border border-gray-600 rounded text-white focus:border-emerald-500 focus:outline-none"
                            required
                        >
                    </div>
                    
                    <div class="grid grid-cols-2 gap-2">
                        <label class="flex items-center space-x-2 p-3 bg-gray-800 rounded cursor-pointer">
                            <input type="radio" name="balance_type" value="credit" class="text-emerald-600" required>
                            <span class="text-emerald-400">Credit (+)</span>
                        </label>
                        <label class="flex items-center space-x-2 p-3 bg-gray-800 rounded cursor-pointer">
                            <input type="radio" name="balance_type" value="debit" class="text-red-600" required>
                            <span class="text-red-400">Debit (-)</span>
                        </label>
                    </div>
                    
                    <div>
                        <label class="block text-sm text-gray-300 mb-1">Description</label>
                        <input 
                            type="text" 
                            name="description" 
                            placeholder="Reason for adjustment"
                            class="w-full px-3 py-2 bg-gray-800 border border-gray-600 rounded text-white focus:border-emerald-500 focus:outline-none"
                            required
                        >
                    </div>
                </div>
                
                <button type="submit" class="mt-4 w-full px-4 py-2 bg-yellow-600 hover:bg-yellow-700 text-white rounded-lg transition">
                    Adjust Balance
                </button>
            </form>
        </div>
    </div>

    <script>
        function openUserModal(userId) {
            document.getElementById('modal_user_id').value = userId;
            document.getElementById('balance_user_id').value = userId;
            document.getElementById('userModal').classList.add('show');
        }

        function closeUserModal() {
            document.getElementById('userModal').classList.remove('show');
        }

        function viewUserDetails(userId) {
            // Redirect to user details page
            window.location.href = `/admin/user-details.php?id=${userId}`;
        }

        // Close modal when clicking outside
        document.getElementById('userModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeUserModal();
            }
        });

        // Confirm dangerous actions
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const action = this.querySelector('input[name="action"]')?.value;
                const status = this.querySelector('input[name="status"]:checked')?.value;
                const balanceType = this.querySelector('input[name="balance_type"]:checked')?.value;
                
                if (action === 'update_status' && (status === 'suspended' || status === 'banned')) {
                    if (!confirm(`Are you sure you want to ${status} this user?`)) {
                        e.preventDefault();
                    }
                }
                
                if (action === 'adjust_balance' && balanceType === 'debit') {
                    const amount = this.querySelector('input[name="amount"]').value;
                    if (!confirm(`Are you sure you want to debit KSh ${amount} from this user's account?`)) {
                        e.preventDefault();
                    }
                }
            });
        });

        // Auto-refresh every 60 seconds
        setTimeout(() => {
            location.reload();
        }, 60000);

        // Enhanced search with debounce
        let searchTimeout;
        const searchInput = document.querySelector('input[name="search"]');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    this.form.submit();
                }, 1000);
            });
        }
    </script>
</body>
</html>