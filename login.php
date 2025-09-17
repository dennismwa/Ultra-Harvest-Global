<?php
require_once 'config/database.php';

$error = '';
$success = '';

// Redirect if already logged in
if (isLoggedIn()) {
    if (isAdmin()) {
        header('Location: /admin/');
    } else {
        header('Location: /user/dashboard.php');
    }
    exit;
}

if ($_POST) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $email = sanitize($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $remember = isset($_POST['remember']);
        
        if (empty($email) || empty($password)) {
            $error = 'Email and password are required.';
        } else {
            $stmt = $db->prepare("SELECT id, password, full_name, status, is_admin FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            
            if (!$user) {
                $error = 'Invalid email or password.';
            } elseif ($user['status'] !== 'active') {
                $error = 'Your account has been ' . $user['status'] . '. Please contact support.';
            } elseif (!password_verify($password, $user['password'])) {
                $error = 'Invalid email or password.';
            } else {
                // Successful login
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['email'] = $email;
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['is_admin'] = $user['is_admin'];
                
                // Set remember me cookie if requested
                if ($remember) {
                    $token = bin2hex(random_bytes(32));
                    setcookie('remember_token', $token, time() + (30 * 24 * 60 * 60), '/'); // 30 days
                    // Store token in database for security
                }
                
                // Update last login
                $stmt = $db->prepare("UPDATE users SET updated_at = NOW() WHERE id = ?");
                $stmt->execute([$user['id']]);
                
                // Redirect based on user type
                if ($user['is_admin']) {
                    header('Location: /admin/');
                } else {
                    header('Location: /user/dashboard.php');
                }
                exit;
            }
        }
    }
}

// Check for success messages from other pages
if (isset($_GET['registered'])) {
    $success = 'Registration successful! Please login to your account.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Ultra Harvest Global</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap');
        * { font-family: 'Poppins', sans-serif; }
        
        .hero-bg {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1) 0%, rgba(251, 191, 36, 0.1) 100%);
            position: relative;
            overflow: hidden;
        }
        
        .hero-bg::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-image: 
                url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 1000"><polygon fill="%2310b981" fill-opacity="0.05" points="0,0 1000,300 1000,1000 0,700"/></svg>'),
                url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 1000"><polygon fill="%23fbbf24" fill-opacity="0.05" points="1000,0 0,400 0,1000 1000,600"/></svg>');
            background-size: cover;
        }
        
        .form-card {
            backdrop-filter: blur(20px);
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .forex-pattern {
            position: absolute;
            top: 20%;
            left: 5%;
            width: 40%;
            height: 60%;
            background: linear-gradient(45deg, rgba(16, 185, 129, 0.1), rgba(16, 185, 129, 0.05));
            border-radius: 20px;
            z-index: 1;
        }
        
        .wheat-pattern {
            position: absolute;
            top: 10%;
            right: 5%;
            width: 40%;
            height: 80%;
            background: linear-gradient(180deg, rgba(251, 191, 36, 0.1), rgba(251, 191, 36, 0.05));
            border-radius: 20px;
            z-index: 1;
        }
    </style>
</head>
<body class="bg-gray-900 text-white min-h-screen hero-bg relative">
    
    <!-- Background Patterns -->
    <div class="forex-pattern"></div>
    <div class="wheat-pattern"></div>
    
    <div class="relative z-10 min-h-screen flex flex-col">
        
        <!-- Header -->
        <header class="py-6">
            <div class="container mx-auto px-4">
                <div class="flex justify-between items-center">
                    <a href="/" class="flex items-center space-x-3">
                        <div class="w-12 h-12 bg-gradient-to-r from-emerald-500 to-yellow-500 rounded-full flex items-center justify-center">
                            <i class="fas fa-seedling text-white text-xl"></i>
                        </div>
                        <div>
                            <h1 class="text-2xl font-bold bg-gradient-to-r from-emerald-400 to-yellow-400 bg-clip-text text-transparent">Ultra Harvest</h1>
                            <p class="text-sm text-gray-300">Global</p>
                        </div>
                    </a>
                    
                    <div class="hidden md:flex space-x-6">
                        <a href="/" class="text-gray-300 hover:text-emerald-400 transition">Home</a>
                        <a href="/#packages" class="text-gray-300 hover:text-emerald-400 transition">Packages</a>
                        <a href="/register.php" class="px-4 py-2 bg-gradient-to-r from-emerald-500 to-emerald-600 rounded-full hover:from-emerald-600 hover:to-emerald-700 transition">Register</a>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="flex-1 flex items-center justify-center py-12">
            <div class="container mx-auto px-4">
                <div class="max-w-md mx-auto">
                    
                    <!-- Login Card -->
                    <div class="form-card rounded-3xl p-8 shadow-2xl">
                        <div class="text-center mb-8">
                            <h2 class="text-3xl font-bold mb-2">
                                Welcome Back to <span class="bg-gradient-to-r from-emerald-400 to-yellow-400 bg-clip-text text-transparent">Ultra Harvest</span>
                            </h2>
                            <p class="text-gray-300">Login to your trading account</p>
                        </div>

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

                        <form method="POST" class="space-y-6">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            
                            <!-- Email -->
                            <div>
                                <label class="block text-sm font-medium text-gray-300 mb-2">
                                    <i class="fas fa-envelope mr-2"></i>Email Address
                                </label>
                                <input 
                                    type="email" 
                                    name="email" 
                                    value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                                    class="w-full px-4 py-3 bg-white/10 border border-white/20 rounded-lg text-white placeholder-gray-400 focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500" 
                                    placeholder="Enter your email address"
                                    required
                                >
                            </div>

                            <!-- Password -->
                            <div>
                                <label class="block text-sm font-medium text-gray-300 mb-2">
                                    <i class="fas fa-lock mr-2"></i>Password
                                </label>
                                <div class="relative">
                                    <input 
                                        type="password" 
                                        name="password" 
                                        id="password"
                                        class="w-full px-4 py-3 bg-white/10 border border-white/20 rounded-lg text-white placeholder-gray-400 focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 pr-12" 
                                        placeholder="Enter your password"
                                        required
                                    >
                                    <button type="button" onclick="togglePassword()" class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-white">
                                        <i class="fas fa-eye" id="password-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- Remember Me & Forgot Password -->
                            <div class="flex items-center justify-between">
                                <label class="flex items-center">
                                    <input 
                                        type="checkbox" 
                                        name="remember"
                                        class="w-4 h-4 text-emerald-600 bg-white/10 border-white/20 rounded focus:ring-emerald-500 focus:ring-2"
                                    >
                                    <span class="ml-2 text-sm text-gray-300">Remember me</span>
                                </label>
                                <a href="/forgot-password.php" class="text-sm text-emerald-400 hover:text-emerald-300">
                                    Forgot Password?
                                </a>
                            </div>

                            <!-- Submit Button -->
                            <button 
                                type="submit" 
                                class="w-full py-4 bg-gradient-to-r from-yellow-500 to-yellow-600 text-black font-semibold rounded-lg hover:from-yellow-400 hover:to-yellow-500 transform hover:scale-[1.02] transition-all duration-300 shadow-lg hover:shadow-xl"
                            >
                                <i class="fas fa-sign-in-alt mr-2"></i>Login to Dashboard
                            </button>

                            <!-- Register Link -->
                            <div class="text-center pt-4 border-t border-white/20">
                                <p class="text-gray-300">
                                    Don't have an account? 
                                    <a href="/register.php" class="text-emerald-400 hover:text-emerald-300 font-medium">Register here</a>
                                </p>
                            </div>
                        </form>
                    </div>

                    <!-- Quick Stats -->
                    <div class="mt-8 grid grid-cols-3 gap-4 text-center text-sm">
                        <div class="bg-white/5 rounded-lg p-4 backdrop-blur-sm border border-white/10">
                            <div class="text-emerald-400 text-xl mb-2">
                                <i class="fas fa-users"></i>
                            </div>
                            <div class="text-white font-semibold">10,000+</div>
                            <div class="text-gray-400">Active Traders</div>
                        </div>
                        <div class="bg-white/5 rounded-lg p-4 backdrop-blur-sm border border-white/10">
                            <div class="text-yellow-400 text-xl mb-2">
                                <i class="fas fa-chart-line"></i>
                            </div>
                            <div class="text-white font-semibold">24/7</div>
                            <div class="text-gray-400">Trading</div>
                        </div>
                        <div class="bg-white/5 rounded-lg p-4 backdrop-blur-sm border border-white/10">
                            <div class="text-emerald-400 text-xl mb-2">
                                <i class="fas fa-shield-alt"></i>
                            </div>
                            <div class="text-white font-semibold">100%</div>
                            <div class="text-gray-400">Secure</div>
                        </div>
                    </div>

                    <!-- Demo Account -->
                    <div class="mt-6 text-center">
                        <p class="text-gray-400 text-sm mb-2">Want to try first?</p>
                        <a href="/demo.php" class="text-yellow-400 hover:text-yellow-300 font-medium">
                            <i class="fas fa-play mr-1"></i>Try Demo Account
                        </a>
                    </div>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <footer class="py-6">
            <div class="text-center">
                <p class="text-gray-400 mb-2">Growing Wealth Together</p>
                <div class="flex justify-center space-x-6 text-sm">
                    <a href="/terms.php" class="text-gray-500 hover:text-emerald-400">Terms</a>
                    <a href="/privacy.php" class="text-gray-500 hover:text-emerald-400">Privacy</a>
                    <a href="/help.php" class="text-gray-500 hover:text-emerald-400">Help</a>
                </div>
            </div>
        </footer>
    </div>

    <script>
        function togglePassword() {
            const field = document.getElementById('password');
            const eye = document.getElementById('password-eye');
            
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

        // Auto-focus on email field
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelector('input[name="email"]').focus();
        });

        // Form submission loading state
        document.querySelector('form').addEventListener('submit', function() {
            const button = this.querySelector('button[type="submit"]');
            button.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Logging in...';
            button.disabled = true;
        });

        // Enter key handler
        document.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                document.querySelector('form').submit();
            }
        });
    </script>
</body>
</html>