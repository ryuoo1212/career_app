<?php
require_once 'system_config.php';

// Auto-sync hero illustration image if needed
$heroDest = __DIR__ . '/uploads/hero_career_journey.jpg';
if (!file_exists($heroDest)) {
    $heroSrc = 'C:/Users/faaaa/.gemini/antigravity-ide/brain/39628850-3451-4d61-8fe4-c31cfd29ff37/career_journey_hero_1786977206220.jpg';
    if (file_exists($heroSrc)) {
        if (!is_dir(__DIR__ . '/uploads')) {
            @mkdir(__DIR__ . '/uploads', 0777, true);
        }
        @copy($heroSrc, $heroDest);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(getSystemConfig('short_name')); ?> - Find the Right Career for Your Future</title>
    <link rel="stylesheet" href="style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body>
    <!-- Navigation Bar -->
    <header class="navbar">
        <div class="nav-container">
            <div class="nav-logo">
                <?php echo getSystemLogo('logo-icon'); ?>
                <h1 id="siteName"><?php echo htmlspecialchars(getSystemConfig('short_name')); ?></h1>
            </div>
            <div class="hamburger-menu">
                <div class="bar"></div>
                <div class="bar"></div>
                <div class="bar"></div>
            </div>
            <nav class="nav-links">
                <a href="#home" class="nav-link" id="navHome">Home</a>
                <a href="#careers" class="nav-link" id="navCareers">Careers</a>
                <a href="#courses" class="nav-link" id="navCourses">Courses</a>
                <a href="#about" class="nav-link" id="navAbout">About Us</a>
                <a href="admin_login.php" class="nav-btn admin-btn" id="adminNavBtn">Admin</a>
                <a href="login.php" class="nav-btn login-btn" id="navLogin">Login</a>
                <a href="signup.php" class="nav-btn get-started-btn" id="navGetStarted">Get Started</a>
            </nav>
        </div>
    </header>

    <!-- Admin Login Button (Hidden by default) -->
    <div id="adminLoginBtn" class="admin-login-btn">
        <a href="admin_login.php" class="btn-admin">
            Admin Login
        </a>
    </div>

    <!-- Hero Section -->
    <section class="hero" id="home">
        <div class="hero-container">
            <div class="hero-content">
                <h1 class="hero-title">
                    Find <span class="highlight">Right Career</span> for Your <span class="highlight">Future</span>
                </h1>
                <p class="hero-subtitle">
                    Discover your perfect career path through our comprehensive assessment system. 
                    Get personalized recommendations based on your skills, interests, and goals.
                </p>
                <div class="hero-cta">
                    <a href="login.php" class="btn btn-primary">Start Career Assessment</a>
                    <a href="signup.php" class="btn btn-primary">Get Started Free</a>
                    <p class="hero-note">
                        <span class="highlight">100+ Questions</span> • Instant Results • Free
                    </p>
                </div>
            </div>
            <div class="hero-visual">
                <div class="hero-image">
                    <img src="uploads/hero_career_journey.jpg" alt="Career Journey Roadmap: Assess, Explore, Plan, Learn, Achieve" class="hero-img-graphic" loading="eager">
                </div>
            </div>
        </div>
    </section>

    <!-- Career Categories Section -->
    <section class="categories" id="careers">
        <div class="container">
            <div class="section-header">
                <h2>Explore Career Categories</h2>
                <p>Find your path in diverse professional fields</p>
            </div>
            <div class="categories-grid">
                <div class="category-card">
                    <div class="category-icon">💻</div>
                    <h3>Technology</h3>
                    <p>Software development, AI, cybersecurity, and more</p>
                </div>
                <div class="category-card">
                    <div class="category-icon">⚕️</div>
                    <h3>Health</h3>
                    <p>Medicine, nursing, healthcare administration</p>
                </div>
                <div class="category-card">
                    <div class="category-icon">📈</div>
                    <h3>Business</h3>
                    <p>Finance, marketing, entrepreneurship, management</p>
                </div>
                <div class="category-card">
                    <div class="category-icon">⚖️</div>
                    <h3>Law</h3>
                    <p>Legal practice, corporate law, public policy</p>
                </div>
                <div class="category-card">
                    <div class="category-icon">🎨</div>
                    <h3>Arts & Design</h3>
                    <p>Creative arts, design, media, entertainment</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Stats Section -->
    <section class="stats">
        <div class="container">
            <div class="stats-grid">
                <div class="stat-item">
                    <div class="stat-icon">👥</div>
                    <div class="stat-number">10,000+</div>
                    <div class="stat-label">Students Guided</div>
                </div>
                <div class="stat-item">
                    <div class="stat-icon">📚</div>
                    <div class="stat-number">50+</div>
                    <div class="stat-label">Courses</div>
                </div>
                <div class="stat-item">
                    <div class="stat-icon">🎯</div>
                    <div class="stat-number">95%</div>
                    <div class="stat-label">Accuracy Rate</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Courses Section -->
    <section class="courses" id="courses">
        <div class="container">
            <div class="section-header">
                <h2>Our Courses</h2>
                <p>Comprehensive courses to help you succeed in your chosen career path</p>
            </div>
            <div class="courses-grid">
                <div class="course-card">
                    <div class="course-icon">⚕️</div>
                    <h3>Nursing</h3>
                    <p>Patient care, medical procedures, and healthcare fundamentals</p>
                    <div class="course-duration">4 Years</div>
                </div>
                <div class="course-card">
                    <div class="course-icon">🎓</div>
                    <h3>Education</h3>
                    <p>Teaching methods, curriculum design, and classroom management</p>
                    <div class="course-duration">4 Years</div>
                </div>
                <div class="course-card">
                    <div class="course-icon">⚖️</div>
                    <h3>Criminology</h3>
                    <p>Criminal justice, law enforcement, and forensic science</p>
                    <div class="course-duration">4 Years</div>
                </div>
                <div class="course-card">
                    <div class="course-icon">📋</div>
                    <h3>Administration</h3>
                    <p>Office management, HR, and business operations</p>
                    <div class="course-duration">4 Years</div>
                </div>
                <div class="course-card">
                    <div class="course-icon">💻</div>
                    <h3>Computer Science</h3>
                    <p>Programming, software development, and IT systems</p>
                    <div class="course-duration">4 Years</div>
                </div>
                <div class="course-card">
                    <div class="course-icon">📊</div>
                    <h3>Business Management</h3>
                    <p>Entrepreneurship, finance, and corporate leadership</p>
                    <div class="course-duration">4 Years</div>
                </div>
                <div class="course-card">
                    <div class="course-icon">🏗️</div>
                    <h3>Engineering</h3>
                    <p>Civil, mechanical, electrical, and industrial engineering</p>
                    <div class="course-duration">4-5 Years</div>
                </div>
                <div class="course-card">
                    <div class="course-icon">🎨</div>
                    <h3>Arts & Design</h3>
                    <p>Graphic design, multimedia arts, and creative industries</p>
                    <div class="course-duration">4 Years</div>
                </div>
            </div>
        </div>
    </section>

    <!-- About Us Section -->
    <section class="about" id="about">
        <div class="container">
            <div class="about-content">
                <div class="about-text">
                    <div class="section-header" style="text-align: left; margin-bottom: 1.5rem;">
                        <h2>About Us</h2>
                        <p>Empowering Students to Shape Their Future</p>
                    </div>
                    <p>We are dedicated to helping students and professionals discover their ideal career paths through advanced assessment technology and personalized guidance.</p>
                    <p>Our platform uses cutting-edge algorithms and industry insights to provide you with tailored career recommendations based on your skills, interests, and goals.</p>
                    <div class="about-stats">
                        <div class="about-stat">
                            <div class="stat-number">10,000+</div>
                            <div class="stat-label">Students Helped</div>
                        </div>
                        <div class="about-stat">
                            <div class="stat-number">95%</div>
                            <div class="stat-label">Success Rate</div>
                        </div>
                        <div class="about-stat">
                            <div class="stat-number">50+</div>
                            <div class="stat-label">Career Paths</div>
                        </div>
                    </div>
                </div>
                <div class="about-visual">
                    <div class="about-image">
                        <img src="uploads/hero_career_journey.jpg" alt="Career Path Guidance and Exploration" class="about-img-graphic" loading="lazy">
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="footer-content">
                <div class="footer-section">
                    <h3>About</h3>
                    <p> Helps students discover suitable career paths through personalized assessments, career recommendations, and guidance</p>
                </div>
                <div class="footer-section">
                    <h3>Contact</h3>
                    <p>Email: <?php echo htmlspecialchars(getSystemConfig('email') ?: 'info@careerpath.com'); ?></p>
                    <p>Phone: <?php echo htmlspecialchars(getSystemConfig('contact') ?: '(123) 456-7890'); ?></p>
                </div>
                <div class="footer-section">
                    <h3>Resources</h3>
                    <ul>
                        <li><a href="privacy.php">Privacy Policy</a></li>
                        <li><a href="terms.php">Terms of Service</a></li>
                    </ul>
                </div>
                <div class="footer-section">
                    <h3>Follow Us</h3>
                    <div class="social-icons">
                        <a href="#" aria-label="Facebook"><i class="fa-brands fa-facebook-f"></i></a>
                    </div>
                </div>
            </div>
            <div class="footer-bottom">
            
            </div>
        </div>
    </footer>

    <script src="script.js"></script>
</body>
</html>
