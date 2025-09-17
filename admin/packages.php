<?php
require_once '../config/database.php';
requireAdmin();

$error = '';
$success = '';

// Handle package actions
if ($_POST) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'create_package':
                $name = sanitize($_POST['name']);
                $icon = sanitize($_POST['icon']);
                $roi_percentage = (float)$_POST['roi_percentage'];
                $duration_hours = (int)$_POST['duration_hours'];
                $min_investment = (float)$_POST['min_investment'];
                $max_investment = !empty($_POST['max_investment']) ? (float)$_POST['max_investment'] : null;
                $description = sanitize($_POST['description']);
                $status = $_POST['status'] ?? 'active';
                
                if ($name && $roi_percentage && $duration_hours && $min_investment) {
                    $stmt = $db->prepare("
                        INSERT INTO packages (name, icon, roi_percentage, duration_hours, min_investment, max_investment, description, status) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    if ($stmt->execute([$name, $icon, $roi_percentage, $duration_hours, $min_investment, $max_investment, $description, $status])) {
                        $success = 'Package created successfully.';
                    } else {
                        $error = 'Failed to create package.';
                    }
                } else {
                    $error = 'Please fill in all required fields.';
                }
                break;
                
            case 'update_package':
                $package_id = (int)$_POST['package_id'];
                $name = sanitize($_POST['name']);
                $icon = sanitize($_POST['icon']);
                $roi_percentage = (float)$_POST['roi_percentage'];
                $duration_hours = (int)$_POST['duration_hours'];
                $min_investment = (float)$_POST['min_investment'];
                $max_investment = !empty($_POST['max_investment']) ? (float)$_POST['max_investment'] : null;
                $description = sanitize($_POST['description']);
                $status = $_POST['status'] ?? 'active';
                
                if ($name && $roi_percentage && $duration_hours && $min_investment) {
                    $stmt = $db->prepare("
                        UPDATE packages 
                        SET name=?, icon=?, roi_percentage=?, duration_hours=?, min_investment=?, max_investment=?, description=?, status=?, updated_at=NOW()
                        WHERE id=?
                    ");
                    if ($stmt->execute([$name, $icon, $roi_percentage, $duration_hours, $min_investment, $max_investment, $description, $status, $package_id])) {
                        $success = 'Package updated successfully.';
                    } else {
                        $error = 'Failed to update package.';
                    }
                } else {
                    $error = 'Please fill in all required fields.';
                }
                break;
                
            case 'toggle_status':
                $package_id = (int)$_POST['package_id'];
                $new_status = $_POST['new_status'];
                
                if (in_array($new_status, ['active', 'inactive'])) {
                    $stmt = $db->prepare("UPDATE packages SET status=?, updated_at=NOW() WHERE id=?");
                    if ($stmt->execute([$new_status, $package_id])) {
                        $success = 'Package status updated successfully.';
                    } else {
                        $error = 'Failed to update package status.';
                    }
                }
                break;
                
            case 'delete_package':
                $package_id = (int)$_POST['package_id'];
                
                // Check if package has active investments
                $stmt = $db->prepare("SELECT COUNT(*) as active_count FROM active_packages WHERE package_id = ? AND status = 'active'");
                $stmt->execute([$package_id]);
                $active_count = $stmt->fetch()['active_count'];
                
                if ($active_count > 0) {
                    $error = 'Cannot delete package with active investments. Please wait for all investments to complete.';
                } else {
                    $stmt = $db->prepare("DELETE FROM packages WHERE id = ?");
                    if ($stmt->execute([$package_id])) {
                        $success = 'Package deleted successfully.';
                    } else {
                        $error = 'Failed to delete package.';
                    }
                }
                break;
        }
    }
}

// Get packages with statistics
$stmt = $db->query("
    SELECT p.*,
           COUNT(ap.id) as total_investments,
           COUNT(CASE WHEN ap.status = 'active' THEN 1 END) as active_investments,
           COALESCE(SUM(CASE WHEN ap.status = 'active' THEN ap.investment_amount ELSE 0 END), 0) as active_amount,
           COALESCE(SUM(ap.investment_amount), 0) as total_invested
    FROM packages p
    LEFT JOIN active_packages ap ON p.id = ap.package_id
    GROUP BY p.id
    ORDER BY p.created_at DESC
");
$packages = $stmt->fetchAll();

// Get package performance statistics
$stmt = $db->query("
    SELECT 
        COUNT(*) as total_packages,
        COUNT(CASE WHEN status = 'active' THEN 1 END) as active_packages,
        COUNT(CASE WHEN status = 'inactive' THEN 1 END) as inactive_packages
    FROM packages
");
$stats = $stmt->fetch();

// Common emojis for packages
$emoji_options = ['🌱', '🌿', '🌳', '🌾', '🌟', '💎', '🚀', '💰', '📈', '⚡', '🔥', '💫', '🎯', '🏆', '👑'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Package Management - Ultra Harvest Admin</title>
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
        
        .package-card {
            transition: all 0.3s ease;
        }
        
        .package-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
        }
        
        .modal {
            display: none;
            backdrop-filter: blur(10px);
        }
        
        .modal.show {
            display: flex;
        }
        
        .emoji-picker {
            display: none;
        }
        
        .emoji-picker.show {
            display: grid;
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
                        <a href="/admin/packages.php" class="text-emerald-400 font-medium">Packages</a>
                        <a href="/admin/transactions.php" class="text-gray-300 hover:text-emerald-400 transition">Transactions</a>
                        <a href="/admin/system-health.php" class="text-gray-300 hover:text-emerald-400 transition">System Health</a>
                    </nav>
                </div>

                <div class="flex items-center space-x-4">
                    <button onclick="openCreateModal()" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg font-medium transition">
                        <i class="fas fa-plus mr-2"></i>New Package
                    </button>
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
                <h1 class="text-3xl font-bold text-white">Package Management</h1>
                <p class="text-gray-400">Create and manage trading packages</p>
            </div>
            <div class="text-right">
                <p class="text-2xl font-bold text-emerald-400"><?php echo number_format($stats['total_packages']); ?></p>
                <p class="text-gray-400 text-sm">Total Packages</p>
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
        <section class="grid md:grid-cols-3 gap-6 mb-8">
            <div class="glass-card rounded-xl p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-400 text-sm">Total Packages</p>
                        <p class="text-3xl font-bold text-white"><?php echo number_format($stats['total_packages']); ?></p>
                    </div>
                    <div class="w-12 h-12 bg-blue-500/20 rounded-full flex items-center justify-center">
                        <i class="fas fa-box text-blue-400 text-xl"></i>
                    </div>
                </div>
            </div>
            <div class="glass-card rounded-xl p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-400 text-sm">Active Packages</p>
                        <p class="text-3xl font-bold text-emerald-400"><?php echo number_format($stats['active_packages']); ?></p>
                    </div>
                    <div class="w-12 h-12 bg-emerald-500/20 rounded-full flex items-center justify-center">
                        <i class="fas fa-check-circle text-emerald-400 text-xl"></i>
                    </div>
                </div>
            </div>
            <div class="glass-card rounded-xl p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-400 text-sm">Inactive Packages</p>
                        <p class="text-3xl font-bold text-gray-400"><?php echo number_format($stats['inactive_packages']); ?></p>
                    </div>
                    <div class="w-12 h-12 bg-gray-500/20 rounded-full flex items-center justify-center">
                        <i class="fas fa-pause-circle text-gray-400 text-xl"></i>
                    </div>
                </div>
            </div>
        </section>

        <!-- Packages Grid -->
        <section class="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($packages as $package): ?>
            <div class="package-card glass-card rounded-xl p-6">
                <!-- Package Header -->
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center space-x-3">
                        <div class="text-4xl"><?php echo $package['icon']; ?></div>
                        <div>
                            <h3 class="text-xl font-bold text-white"><?php echo htmlspecialchars($package['name']); ?></h3>
                            <span class="px-2 py-1 rounded text-xs font-medium
                                <?php echo $package['status'] === 'active' ? 'bg-emerald-500/20 text-emerald-400' : 'bg-gray-500/20 text-gray-400'; ?>">
                                <?php echo ucfirst($package['status']); ?>
                            </span>
                        </div>
                    </div>
                    <div class="flex items-center space-x-2">
                        <button onclick="editPackage(<?php echo htmlspecialchars(json_encode($package)); ?>)" class="p-2 bg-blue-600 hover:bg-blue-700 text-white rounded transition">
                            <i class="fas fa-edit text-sm"></i>
                        </button>
                        <button onclick="togglePackageStatus(<?php echo $package['id']; ?>, '<?php echo $package['status'] === 'active' ? 'inactive' : 'active'; ?>')" 
                                class="p-2 <?php echo $package['status'] === 'active' ? 'bg-yellow-600 hover:bg-yellow-700' : 'bg-emerald-600 hover:bg-emerald-700'; ?> text-white rounded transition">
                            <i class="fas <?php echo $package['status'] === 'active' ? 'fa-pause' : 'fa-play'; ?> text-sm"></i>
                        </button>
                        <button onclick="deletePackage(<?php echo $package['id']; ?>)" class="p-2 bg-red-600 hover:bg-red-700 text-white rounded transition">
                            <i class="fas fa-trash text-sm"></i>
                        </button>
                    </div>
                </div>

                <!-- Package Details -->
                <div class="space-y-3 mb-6">
                    <div class="flex justify-between">
                        <span class="text-gray-400">ROI Percentage</span>
                        <span class="text-emerald-400 font-bold"><?php echo $package['roi_percentage']; ?>%</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-400">Duration</span>
                        <span class="text-white font-medium">
                            <?php 
                            if ($package['duration_hours'] < 24) {
                                echo $package['duration_hours'] . ' Hours';
                            } else {
                                echo ($package['duration_hours'] / 24) . ' Days';
                            }
                            ?>
                        </span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-400">Min Investment</span>
                        <span class="text-white font-medium"><?php echo formatMoney($package['min_investment']); ?></span>
                    </div>
                    <?php if ($package['max_investment']): ?>
                    <div class="flex justify-between">
                        <span class="text-gray-400">Max Investment</span>
                        <span class="text-white font-medium"><?php echo formatMoney($package['max_investment']); ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Package Statistics -->
                <div class="bg-gray-800/50 rounded-lg p-4 mb-4">
                    <div class="grid grid-cols-2 gap-4 text-sm">
                        <div>
                            <p class="text-gray-400">Total Investments</p>
                            <p class="text-white font-bold"><?php echo number_format($package['total_investments']); ?></p>
                        </div>
                        <div>
                            <p class="text-gray-400">Active Now</p>
                            <p class="text-emerald-400 font-bold"><?php echo number_format($package['active_investments']); ?></p>
                        </div>
                    </div>
                    <div class="mt-3 pt-3 border-t border-gray-700">
                        <div class="grid grid-cols-2 gap-4 text-sm">
                            <div>
                                <p class="text-gray-400">Total Invested</p>
                                <p class="text-yellow-400 font-bold"><?php echo formatMoney($package['total_invested']); ?></p>
                            </div>
                            <div>
                                <p class="text-gray-400">Active Amount</p>
                                <p class="text-purple-400 font-bold"><?php echo formatMoney($package['active_amount']); ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Description -->
                <?php if ($package['description']): ?>
                <div class="text-gray-300 text-sm">
                    <?php echo htmlspecialchars($package['description']); ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </section>

        <?php if (empty($packages)): ?>
        <div class="text-center py-12">
            <i class="fas fa-box text-6xl text-gray-600 mb-4"></i>
            <h3 class="text-xl font-bold text-gray-400 mb-2">No packages created yet</h3>
            <p class="text-gray-500 mb-6">Create your first trading package to get started</p>
            <button onclick="openCreateModal()" class="px-6 py-3 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg font-medium transition">
                <i class="fas fa-plus mr-2"></i>Create First Package
            </button>
        </div>
        <?php endif; ?>
    </main>

    <!-- Create/Edit Package Modal -->
    <div id="packageModal" class="modal fixed inset-0 bg-black/50 flex items-center justify-center p-4 z-50">
        <div class="glass-card rounded-xl p-6 max-w-md w-full max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-xl font-bold text-white" id="modalTitle">Create New Package</h3>
                <button onclick="closePackageModal()" class="text-gray-400 hover:text-white">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <form id="packageForm" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" id="formAction" value="create_package">
                <input type="hidden" name="package_id" id="packageId">
                
                <div class="space-y-4">
                    <!-- Package Name -->
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Package Name *</label>
                        <input 
                            type="text" 
                            name="name" 
                            id="packageName"
                            class="w-full px-3 py-2 bg-gray-800 border border-gray-600 rounded text-white focus:border-emerald-500 focus:outline-none"
                            placeholder="e.g., Seed, Growth, Elite"
                            required
                        >
                    </div>

                    <!-- Package Icon -->
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Icon *</label>
                        <div class="flex items-center space-x-2">
                            <input 
                                type="text" 
                                name="icon" 
                                id="packageIcon"
                                class="flex-1 px-3 py-2 bg-gray-800 border border-gray-600 rounded text-white focus:border-emerald-500 focus:outline-none"
                                placeholder="🌱"
                                required
                            >
                            <button type="button" onclick="toggleEmojiPicker()" class="px-3 py-2 bg-yellow-600 hover:bg-yellow-700 text-white rounded transition">
                                <i class="fas fa-smile"></i>
                            </button>
                        </div>
                        <!-- Emoji Picker -->
                        <div id="emojiPicker" class="emoji-picker grid grid-cols-8 gap-2 mt-2 p-3 bg-gray-800 rounded-lg border border-gray-600">
                            <?php foreach ($emoji_options as $emoji): ?>
                            <button type="button" onclick="selectEmoji('<?php echo $emoji; ?>')" class="text-2xl hover:bg-gray-700 p-2 rounded transition">
                                <?php echo $emoji; ?>
                            </button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- ROI Percentage -->
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">ROI Percentage (%) *</label>
                        <input 
                            type="number" 
                            name="roi_percentage" 
                            id="packageROI"
                            min="0.01" 
                            max="100" 
                            step="0.01"
                            class="w-full px-3 py-2 bg-gray-800 border border-gray-600 rounded text-white focus:border-emerald-500 focus:outline-none"
                            placeholder="e.g., 10.5"
                            required
                        >
                    </div>

                    <!-- Duration -->
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Duration (Hours) *</label>
                        <select 
                            name="duration_hours" 
                            id="packageDuration"
                            class="w-full px-3 py-2 bg-gray-800 border border-gray-600 rounded text-white focus:border-emerald-500 focus:outline-none"
                            required
                        >
                            <option value="6">6 Hours</option>
                            <option value="12">12 Hours</option>
                            <option value="24" selected>24 Hours (1 Day)</option>
                            <option value="48">48 Hours (2 Days)</option>
                            <option value="72">72 Hours (3 Days)</option>
                            <option value="168">168 Hours (1 Week)</option>
                        </select>
                    </div>

                    <!-- Minimum Investment -->
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Minimum Investment (KSh) *</label>
                        <input 
                            type="number" 
                            name="min_investment" 
                            id="packageMinInvestment"
                            min="1" 
                            step="0.01"
                            class="w-full px-3 py-2 bg-gray-800 border border-gray-600 rounded text-white focus:border-emerald-500 focus:outline-none"
                            placeholder="e.g., 1000"
                            required
                        >
                    </div>

                    <!-- Maximum Investment -->
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Maximum Investment (KSh)</label>
                        <input 
                            type="number" 
                            name="max_investment" 
                            id="packageMaxInvestment"
                            min="1" 
                            step="0.01"
                            class="w-full px-3 py-2 bg-gray-800 border border-gray-600 rounded text-white focus:border-emerald-500 focus:outline-none"
                            placeholder="Leave empty for no limit"
                        >
                    </div>

                    <!-- Description -->
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Description</label>
                        <textarea 
                            name="description" 
                            id="packageDescription"
                            rows="3"
                            class="w-full px-3 py-2 bg-gray-800 border border-gray-600 rounded text-white focus:border-emerald-500 focus:outline-none"
                            placeholder="Package description for users..."
                        ></textarea>
                    </div>

                    <!-- Status -->
                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-2">Status *</label>
                        <select 
                            name="status" 
                            id="packageStatus"
                            class="w-full px-3 py-2 bg-gray-800 border border-gray-600 rounded text-white focus:border-emerald-500 focus:outline-none"
                        >
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>

                    <!-- ROI Calculation Preview -->
                    <div class="bg-gray-800/50 rounded-lg p-4">
                        <h4 class="text-sm font-medium text-gray-300 mb-2">ROI Calculation Preview</h4>
                        <div id="roiPreview" class="text-sm text-gray-400">
                            Enter values to see calculation preview
                        </div>
                    </div>
                </div>

                <div class="flex space-x-3 mt-6">
                    <button type="submit" class="flex-1 px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg font-medium transition">
                        <i class="fas fa-save mr-2"></i><span id="submitText">Create Package</span>
                    </button>
                    <button type="button" onclick="closePackageModal()" class="px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded-lg font-medium transition">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Status Toggle Form -->
    <form id="statusForm" method="POST" style="display: none;">
        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
        <input type="hidden" name="action" value="toggle_status">
        <input type="hidden" name="package_id" id="statusPackageId">
        <input type="hidden" name="new_status" id="newStatus">
    </form>

    <!-- Delete Form -->
    <form id="deleteForm" method="POST" style="display: none;">
        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
        <input type="hidden" name="action" value="delete_package">
        <input type="hidden" name="package_id" id="deletePackageId">
    </form>

    <script>
        function openCreateModal() {
            document.getElementById('modalTitle').textContent = 'Create New Package';
            document.getElementById('formAction').value = 'create_package';
            document.getElementById('submitText').textContent = 'Create Package';
            document.getElementById('packageForm').reset();
            document.getElementById('packageId').value = '';
            document.getElementById('packageModal').classList.add('show');
            updateROIPreview();
        }

        function editPackage(packageData) {
            document.getElementById('modalTitle').textContent = 'Edit Package';
            document.getElementById('formAction').value = 'update_package';
            document.getElementById('submitText').textContent = 'Update Package';
            document.getElementById('packageId').value = packageData.id;
            
            // Fill form with existing data
            document.getElementById('packageName').value = packageData.name;
            document.getElementById('packageIcon').value = packageData.icon;
            document.getElementById('packageROI').value = packageData.roi_percentage;
            document.getElementById('packageDuration').value = packageData.duration_hours;
            document.getElementById('packageMinInvestment').value = packageData.min_investment;
            document.getElementById('packageMaxInvestment').value = packageData.max_investment || '';
            document.getElementById('packageDescription').value = packageData.description || '';
            document.getElementById('packageStatus').value = packageData.status;
            
            document.getElementById('packageModal').classList.add('show');
            updateROIPreview();
        }

        function closePackageModal() {
            document.getElementById('packageModal').classList.remove('show');
            document.getElementById('emojiPicker').classList.remove('show');
        }

        function toggleEmojiPicker() {
            const picker = document.getElementById('emojiPicker');
            picker.classList.toggle('show');
        }

        function selectEmoji(emoji) {
            document.getElementById('packageIcon').value = emoji;
            document.getElementById('emojiPicker').classList.remove('show');
        }

        function togglePackageStatus(packageId, newStatus) {
            const action = newStatus === 'active' ? 'activate' : 'deactivate';
            if (confirm(`Are you sure you want to ${action} this package?`)) {
                document.getElementById('statusPackageId').value = packageId;
                document.getElementById('newStatus').value = newStatus;
                document.getElementById('statusForm').submit();
            }
        }

        function deletePackage(packageId) {
            if (confirm('Are you sure you want to delete this package? This action cannot be undone.')) {
                document.getElementById('deletePackageId').value = packageId;
                document.getElementById('deleteForm').submit();
            }
        }

        function updateROIPreview() {
            const minInvestment = parseFloat(document.getElementById('packageMinInvestment').value) || 0;
            const roiPercentage = parseFloat(document.getElementById('packageROI').value) || 0;
            const duration = parseInt(document.getElementById('packageDuration').value) || 24;
            
            if (minInvestment > 0 && roiPercentage > 0) {
                const roiAmount = (minInvestment * roiPercentage) / 100;
                const totalReturn = minInvestment + roiAmount;
                const durationText = duration < 24 ? `${duration} hours` : `${duration/24} day(s)`;
                
                document.getElementById('roiPreview').innerHTML = `
                    <div class="space-y-1">
                        <div class="flex justify-between">
                            <span>Investment:</span>
                            <span class="text-white">KSh ${minInvestment.toLocaleString()}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>ROI (${roiPercentage}%):</span>
                            <span class="text-emerald-400">KSh ${roiAmount.toLocaleString()}</span>
                        </div>
                        <div class="flex justify-between font-medium">
                            <span>Total Return:</span>
                            <span class="text-yellow-400">KSh ${totalReturn.toLocaleString()}</span>
                        </div>
                        <div class="text-xs text-gray-500 mt-2">
                            Returns after ${durationText}
                        </div>
                    </div>
                `;
            } else {
                document.getElementById('roiPreview').textContent = 'Enter values to see calculation preview';
            }
        }

        // Real-time ROI preview updates
        document.getElementById('packageMinInvestment').addEventListener('input', updateROIPreview);
        document.getElementById('packageROI').addEventListener('input', updateROIPreview);
        document.getElementById('packageDuration').addEventListener('change', updateROIPreview);

        // Close modal when clicking outside
        document.getElementById('packageModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closePackageModal();
            }
        });

        // Form validation
        document.getElementById('packageForm').addEventListener('submit', function(e) {
            const minInvestment = parseFloat(document.getElementById('packageMinInvestment').value);
            const maxInvestment = parseFloat(document.getElementById('packageMaxInvestment').value);
            
            if (maxInvestment && maxInvestment <= minInvestment) {
                e.preventDefault();
                alert('Maximum investment must be greater than minimum investment.');
                return false;
            }
            
            const roiPercentage = parseFloat(document.getElementById('packageROI').value);
            if (roiPercentage > 50) {
                if (!confirm('ROI percentage is very high (>50%). Are you sure this is correct?')) {
                    e.preventDefault();
                    return false;
                }
            }
        });

        // Auto-save form data to prevent loss
        const formElements = ['packageName', 'packageIcon', 'packageROI', 'packageDuration', 'packageMinInvestment', 'packageMaxInvestment', 'packageDescription'];
        formElements.forEach(elementId => {
            const element = document.getElementById(elementId);
            element.addEventListener('input', function() {
                localStorage.setItem(`package_${elementId}`, this.value);
            });
        });

        // Restore form data on page load
        window.addEventListener('load', function() {
            formElements.forEach(elementId => {
                const savedValue = localStorage.getItem(`package_${elementId}`);
                if (savedValue && document.getElementById(elementId).value === '') {
                    document.getElementById(elementId).value = savedValue;
                }
            });
        });

        // Clear saved data when form is submitted successfully
        document.getElementById('packageForm').addEventListener('submit', function() {
            formElements.forEach(elementId => {
                localStorage.removeItem(`package_${elementId}`);
            });
        });

        // Package profitability calculator
        function calculatePackageProfitability() {
            // This could be expanded to show expected platform profit/loss for each package
            // based on historical data and current system health
        }

        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            // Any initialization code here
        });
    </script>
</body>
</html>