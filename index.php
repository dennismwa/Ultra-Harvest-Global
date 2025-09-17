<?php
require_once 'config/database.php';

// Redirect if already logged in
if (isLoggedIn()) {
    if (isAdmin()) {
        header('Location: /admin/');
    } else {
        header('Location: /user/dashboard.php');
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ultra Harvest Global - Copy Forex Trades. Harvest Profits Fast.</title>
    <meta name="description" content="Choose a package, press Copy, and let your money grow with Ultra Harvest Global">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap');
        
        * { font-family: 'Poppins', sans-serif; }
        
        .hero-bg {
            background: linear-gradient(135deg, 
                rgba(16, 185, 129, 0.1) 0%, 
                rgba(251, 191, 36, 0.1) 50%, 
                rgba(16, 185, 129, 0.1) 100%);
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
        
        .forex-chart {
            background: linear-gradient(45deg, #10b981, #34d399);
            border-radius: 20px;
            padding: 2rem;
            position: relative;
            box-shadow: 0 20px 40px rgba(16, 185, 129, 0.3);
        }
        
        .wheat-field {
            background: linear-gradient(180deg, #fbbf24 0%, #f59e0b 100%);
            border-radius: 20px;
            padding: 2rem;
            position: relative;
            box-shadow: 0 20px 40px rgba(251, 191, 36, 0.3);
        }
        
        .package-card {
            backdrop-filter: blur(20px);
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            position: relative;
            overflow: hidden;
        }
        
        .package-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(251, 191, 36, 0.1));
            z-index: -1;
        }
        
        .glow-text {
            text-shadow: 0 0 20px rgba(16, 185, 129, 0.5);
        }
        
        .float-animation {
            animation: float 6s ease-in-out infinite;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-20px); }
        }
        
        .pulse-glow {
            animation: pulse-glow 2s ease-in-out infinite;
        }
        
        @keyframes pulse-glow {
            0%, 100% { box-shadow: 0 0 20px rgba(16, 185, 129, 0.4); }
            50% { box-shadow: 0 0 40px rgba(16, 185, 129, 0.8); }
        }
    </style>
</head>
<body class="bg-gray-900 text-white">

    <!-- Header / Hero Section -->
    <div class="hero-bg min-h-screen">
        <div class="relative z-10">
            <!-- Navigation -->
            <nav class="container mx-auto px-4 py-6">
                <div class="flex justify-between items-center">
                    <div class="flex items-center space-x-3">
                        <div class="w-12 h-12 bg-gradient-to-r from-emerald-500 to-yellow-500 rounded-full flex items-center justify-center">
                            <i class="fas fa-seedling text-white text-xl"></i>
                        </div>
                        <div>
                            <h1 class="text-2xl font-bold bg-gradient-to-r from-emerald-400 to-yellow-400 bg-clip-text text-transparent">Ultra Harvest</h1>
                            <p class="text-sm text-gray-300">Global</p>
                        </div>
                    </div>
                    <div class="hidden md:flex space-x-6">
                        <a href="#how-it-works" class="text-gray-300 hover:text-emerald-400 transition">How It Works</a>
                        <a href="#packages" class="text-gray-300 hover:text-emerald-400 transition">Packages</a>
                        <a href="/login.php" class="text-emerald-400 hover:text-emerald-300 transition">Login</a>
                    </div>
                    <button class="md:hidden text-white">
                        <i class="fas fa-bars text-xl"></i>
                    </button>
                </div>
            </nav>

            <!-- Hero Content -->
            <div class="container mx-auto px-4 py-16">
                <div class="grid lg:grid-cols-2 gap-12 items-center min-h-[600px]">
                    <!-- Left Side - Main Content -->
                    <div class="text-center lg:text-left">
                        <h1 class="text-5xl lg:text-7xl font-bold mb-6 leading-tight">
                            Copy Forex Trades.
                            <span class="bg-gradient-to-r from-emerald-400 to-yellow-400 bg-clip-text text-transparent glow-text">
                                Harvest Profits
                            </span>
                            Fast.
                        </h1>
                        <p class="text-xl lg:text-2xl text-gray-300 mb-8 leading-relaxed">
                            Choose a package, press Copy, and let your money grow.
                        </p>
                        
                        <!-- CTA Buttons -->
                        <div class="flex flex-col sm:flex-row gap-4 justify-center lg:justify-start">
                            <a href="/register.php" class="px-8 py-4 bg-gradient-to-r from-yellow-500 to-yellow-600 text-black font-semibold rounded-full hover:from-yellow-400 hover:to-yellow-500 transform hover:scale-105 transition-all duration-300 pulse-glow">
                                <i class="fas fa-rocket mr-2"></i>Register Now
                            </a>
                            <a href="#how-it-works" class="px-8 py-4 border-2 border-emerald-500 text-emerald-400 font-semibold rounded-full hover:bg-emerald-500 hover:text-black transition-all duration-300">
                                <i class="fas fa-play-circle mr-2"></i>Learn How It Works
                            </a>
                        </div>
                    </div>

                    <!-- Right Side - Visual Elements -->
                    <div class="grid grid-cols-2 gap-6">
                        <!-- Forex Chart -->
                        <div class="forex-chart float-animation">
                            <div class="text-center">
                                <i class="fas fa-chart-line text-4xl text-white mb-4"></i>
                                <div class="h-20 flex items-end justify-between space-x-1">
                                    <div class="bg-white/30 w-3 h-8 rounded"></div>
                                    <div class="bg-white/50 w-3 h-16 rounded"></div>
                                    <div class="bg-white/40 w-3 h-12 rounded"></div>
                                    <div class="bg-white/60 w-3 h-20 rounded"></div>
                                    <div class="bg-white/45 w-3 h-10 rounded"></div>
                                </div>
                                <p class="text-sm text-white/80 mt-3">Live Forex Data</p>
                            </div>
                        </div>

                        <!-- Wheat Field -->
                        <div class="wheat-field float-animation" style="animation-delay: -3s;">
                            <div class="text-center">
                                <i class="fas fa-seedling text-4xl text-white mb-4"></i>
                                <div class="flex justify-center space-x-1 mb-3">
                                    <div class="w-2 h-8 bg-white/40 rounded-full"></div>
                                    <div class="w-2 h-10 bg-white/50 rounded-full"></div>
                                    <div class="w-2 h-6 bg-white/30 rounded-full"></div>
                                    <div class="w-2 h-12 bg-white/60 rounded-full"></div>
                                    <div class="w-2 h-8 bg-white/40 rounded-full"></div>
                                </div>
                                <p class="text-sm text-white/80">Growing Wealth</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Benefits Row -->
    <section class="py-16 bg-gray-800">
        <div class="container mx-auto px-4">
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-8">
                <div class="text-center group">
                    <div class="w-16 h-16 mx-auto mb-4 bg-gradient-to-r from-emerald-500 to-emerald-600 rounded-full flex items-center justify-center group-hover:scale-110 transition-transform">
                        <i class="fas fa-shield-alt text-2xl text-white"></i>
                    </div>
                    <h3 class="font-semibold text-lg mb-2">Secure & Transparent</h3>
                    <p class="text-gray-400 text-sm">Bank-level security with full transparency</p>
                </div>
                
                <div class="text-center group">
                    <div class="w-16 h-16 mx-auto mb-4 bg-gradient-to-r from-yellow-500 to-yellow-600 rounded-full flex items-center justify-center group-hover:scale-110 transition-transform">
                        <i class="fas fa-clock text-2xl text-white"></i>
                    </div>
                    <h3 class="font-semibold text-lg mb-2">ROI in 6H–3D</h3>
                    <p class="text-gray-400 text-sm">Fast returns on your investments</p>
                </div>
                
                <div class="text-center group">
                    <div class="w-16 h-16 mx-auto mb-4 bg-gradient-to-r from-emerald-500 to-yellow-500 rounded-full flex items-center justify-center group-hover:scale-110 transition-transform">
                        <i class="fas fa-handshake text-2xl text-white"></i>
                    </div>
                    <h3 class="font-semibold text-lg mb-2">Simple Copy System</h3>
                    <p class="text-gray-400 text-sm">One-click trading made easy</p>
                </div>
                
                <div class="text-center group">
                    <div class="w-16 h-16 mx-auto mb-4 bg-gradient-to-r from-yellow-500 to-emerald-500 rounded-full flex items-center justify-center group-hover:scale-110 transition-transform">
                        <i class="fas fa-wallet text-2xl text-white"></i>
                    </div>
                    <h3 class="font-semibold text-lg mb-2">Fast Withdrawals</h3>
                    <p class="text-gray-400 text-sm">Quick access to your profits</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Packages Section -->
    <section id="packages" class="py-20 bg-gray-900">
        <div class="container mx-auto px-4">
            <div class="text-center mb-16">
                <h2 class="text-4xl lg:text-5xl font-bold mb-4">
                    Choose Your 
                    <span class="bg-gradient-to-r from-emerald-400 to-yellow-400 bg-clip-text text-transparent">Growth Path</span>
                </h2>
                <p class="text-xl text-gray-300">Unlock exclusive trading packages designed for every investor</p>
            </div>

            <!-- Packages Grid -->
            <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-6 mb-12">
                <?php
                $packages = ['Seed', 'Sprout', 'Growth', 'Harvest'];
                $icons = ['🌱', '🌿', '🌳', '🌾'];
                
                for ($i = 0; $i < 4; $i++): ?>
                <div class="package-card rounded-2xl p-8 text-center relative">
                    <!-- Lock Overlay -->
                    <div class="absolute inset-0 bg-black/60 backdrop-blur-sm rounded-2xl flex items-center justify-center z-10">
                        <div class="text-center">
                            <i class="fas fa-lock text-4xl text-yellow-500 mb-4"></i>
                            <p class="text-white font-medium">Sign up to unlock</p>
                            <p class="text-gray-300 text-sm">package details</p>
                        </div>
                    </div>
                    
                    <div class="text-6xl mb-4"><?php echo $icons[$i]; ?></div>
                    <h3 class="text-2xl font-bold mb-2 text-white"><?php echo $packages[$i]; ?></h3>
                    <div class="h-32 flex items-center justify-center">
                        <div class="space-y-2 opacity-30">
                            <div class="h-4 bg-white/20 rounded w-3/4 mx-auto"></div>
                            <div class="h-4 bg-white/20 rounded w-1/2 mx-auto"></div>
                            <div class="h-4 bg-white/20 rounded w-2/3 mx-auto"></div>
                        </div>
                    </div>
                </div>
                <?php endfor; ?>
            </div>

            <!-- CTA Button -->
            <div class="text-center">
                <a href="/register.php" class="inline-block px-10 py-4 bg-gradient-to-r from-yellow-500 to-yellow-600 text-black font-bold text-lg rounded-full hover:from-yellow-400 hover:to-yellow-500 transform hover:scale-105 transition-all duration-300">
                    <i class="fas fa-unlock mr-2"></i>Create Account to Unlock
                </a>
            </div>
        </div>
    </section>

    <!-- How It Works -->
    <section id="how-it-works" class="py-20 bg-gradient-to-b from-gray-800 to-gray-900">
        <div class="container mx-auto px-4">
            <div class="text-center mb-16">
                <h2 class="text-4xl lg:text-5xl font-bold mb-4">How It Works</h2>
                <p class="text-xl text-gray-300">Three simple steps to start growing your wealth</p>
            </div>

            <div class="grid lg:grid-cols-3 gap-8">
                <div class="text-center group">
                    <div class="relative mb-8">
                        <div class="w-24 h-24 mx-auto bg-gradient-to-r from-emerald-500 to-emerald-600 rounded-full flex items-center justify-center group-hover:scale-110 transition-transform mb-4">
                            <i class="fas fa-user-plus text-3xl text-white"></i>
                        </div>
                        <div class="absolute top-0 right-0 w-8 h-8 bg-yellow-500 rounded-full flex items-center justify-center text-black font-bold">1</div>
                    </div>
                    <h3 class="text-2xl font-bold mb-4">Register Account</h3>
                    <p class="text-gray-400 leading-relaxed">Create your free account in less than 2 minutes. Secure, fast, and completely transparent.</p>
                </div>

                <div class="text-center group">
                    <div class="relative mb-8">
                        <div class="w-24 h-24 mx-auto bg-gradient-to-r from-yellow-500 to-yellow-600 rounded-full flex items-center justify-center group-hover:scale-110 transition-transform mb-4">
                            <i class="fas fa-credit-card text-3xl text-white"></i>
                        </div>
                        <div class="absolute top-0 right-0 w-8 h-8 bg-emerald-500 rounded-full flex items-center justify-center text-white font-bold">2</div>
                    </div>
                    <h3 class="text-2xl font-bold mb-4">Choose Package</h3>
                    <p class="text-gray-400 leading-relaxed">Select the perfect trading package that matches your investment goals and risk appetite.</p>
                </div>

                <div class="text-center group">
                    <div class="relative mb-8">
                        <div class="w-24 h-24 mx-auto bg-gradient-to-r from-emerald-500 to-yellow-500 rounded-full flex items-center justify-center group-hover:scale-110 transition-transform mb-4">
                            <i class="fas fa-chart-line text-3xl text-white"></i>
                        </div>
                        <div class="absolute top-0 right-0 w-8 h-8 bg-yellow-500 rounded-full flex items-center justify-center text-black font-bold">3</div>
                    </div>
                    <h3 class="text-2xl font-bold mb-4">Copy Trade & Get ROI</h3>
                    <p class="text-gray-400 leading-relaxed">Sit back and watch your investment grow with automated trading and guaranteed returns.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Testimonial -->
    <section class="py-16 bg-gray-800">
        <div class="container mx-auto px-4">
            <div class="max-w-4xl mx-auto">
                <div class="bg-gradient-to-r from-emerald-900/50 to-yellow-900/50 rounded-2xl p-8 border border-emerald-500/20">
                    <div class="text-center">
                        <div class="w-20 h-20 mx-auto mb-6 bg-gradient-to-r from-emerald-500 to-yellow-500 rounded-full flex items-center justify-center">
                            <i class="fas fa-user text-2xl text-white"></i>
                        </div>
                        <blockquote class="text-xl lg:text-2xl font-medium mb-6 text-gray-200 leading-relaxed">
                            "I started with Seed at KSh 500 and got returns in 24 hours. So simple! The platform is incredibly user-friendly and the profits are exactly as promised."
                        </blockquote>
                        <div>
                            <p class="font-semibold text-lg text-white">Sarah K.</p>
                            <p class="text-emerald-400">Nairobi, Kenya</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Final CTA Banner -->
    <section class="py-20 bg-gradient-to-r from-yellow-500 to-yellow-600">
        <div class="container mx-auto px-4 text-center">
            <h2 class="text-4xl lg:text-6xl font-bold text-black mb-6">
                Your harvest begins today
            </h2>
            <p class="text-xl text-black/80 mb-8 max-w-2xl mx-auto">
                Join thousands of successful traders who are already growing their wealth with Ultra Harvest Global
            </p>
            <a href="/register.php" class="inline-block px-12 py-5 bg-emerald-600 text-white font-bold text-xl rounded-full hover:bg-emerald-700 transform hover:scale-105 transition-all duration-300 shadow-2xl">
                <i class="fas fa-seedling mr-3"></i>Register Now
            </a>
        </div>
    </section>

    <!-- Footer -->
    <footer class="py-12 bg-gray-900 border-t border-gray-700">
        <div class="container mx-auto px-4">
            <div class="grid lg:grid-cols-3 gap-8">
                <div>
                    <div class="flex items-center space-x-3 mb-4">
                        <div class="w-10 h-10 bg-gradient-to-r from-emerald-500 to-yellow-500 rounded-full flex items-center justify-center">
                            <i class="fas fa-seedling text-white"></i>
                        </div>
                        <span class="text-xl font-bold bg-gradient-to-r from-emerald-400 to-yellow-400 bg-clip-text text-transparent">Ultra Harvest Global</span>
                    </div>
                    <p class="text-gray-400 text-lg font-medium mb-4">Growing Wealth Together</p>
                    <p class="text-gray-500">Your trusted partner in forex trading and wealth creation.</p>
                </div>
                
                <div class="lg:text-center">
                    <h3 class="font-semibold text-lg mb-4 text-white">Quick Links</h3>
                    <div class="space-y-2">
                        <a href="/terms.php" class="block text-gray-400 hover:text-emerald-400 transition">Terms & Conditions</a>
                        <a href="/privacy.php" class="block text-gray-400 hover:text-emerald-400 transition">Privacy Policy</a>
                        <a href="/help.php" class="block text-gray-400 hover:text-emerald-400 transition">Help Center</a>
                    </div>
                </div>
                
                <div class="lg:text-right">
                    <h3 class="font-semibold text-lg mb-4 text-white">Connect With Us</h3>
                    <div class="flex lg:justify-end space-x-4 mb-4">
                        <a href="#" class="w-10 h-10 bg-gradient-to-r from-emerald-500 to-yellow-500 rounded-full flex items-center justify-center hover:scale-110 transition-transform">
                            <i class="fab fa-facebook-f text-white"></i>
                        </a>
                        <a href="#" class="w-10 h-10 bg-gradient-to-r from-emerald-500 to-yellow-500 rounded-full flex items-center justify-center hover:scale-110 transition-transform">
                            <i class="fab fa-twitter text-white"></i>
                        </a>
                        <a href="#" class="w-10 h-10 bg-gradient-to-r from-emerald-500 to-yellow-500 rounded-full flex items-center justify-center hover:scale-110 transition-transform">
                            <i class="fab fa-instagram text-white"></i>
                        </a>
                        <a href="#" class="w-10 h-10 bg-gradient-to-r from-emerald-500 to-yellow-500 rounded-full flex items-center justify-center hover:scale-110 transition-transform">
                            <i class="fab fa-whatsapp text-white"></i>
                        </a>
                    </div>
                    <p class="text-gray-500 text-sm">
                        © <?php echo date('Y'); ?> Ultra Harvest Global. All rights reserved.
                    </p>
                </div>
            </div>
        </div>
    </footer>

    <!-- JavaScript for smooth scrolling and interactions -->
    <script>
        // Smooth scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                document.querySelector(this.getAttribute('href')).scrollIntoView({
                    behavior: 'smooth'
                });
            });
        });

        // Add scroll effect to navigation
        window.addEventListener('scroll', function() {
            const nav = document.querySelector('nav');
            if (window.scrollY > 100) {
                nav.classList.add('backdrop-blur-md', 'bg-gray-900/80');
            } else {
                nav.classList.remove('backdrop-blur-md', 'bg-gray-900/80');
            }
        });

        // Add loading animation
        window.addEventListener('load', function() {
            document.body.classList.add('loaded');
        });
    </script>
</body>
</html>