<?php
// Admin Login Page - With Backend Processing

require_once 'system_config.php';
require_once 'config.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['reset']) && $_GET['reset'] === 'success') {
    $success = 'Your password has been reset. You can log in with your new password.';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['session']) && $_GET['session'] === 'expired') {
    $error = 'Your session has expired due to inactivity. Please log in again.';
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (false) {
        $error = 'Session expired. Please refresh the page and try again.';
    } else {
        $email = trim($_POST['adminEmail'] ?? '');
        $password = $_POST['adminPassword'] ?? '';

        if (empty($email) || empty($password)) {
            $error = 'Please enter both email and password.';
        } else {
            // Use $mysqli from config.php
            $stmt = $mysqli->prepare("SELECT id, email, password, first_name, middle_name, last_name, suffix, role, status, must_change_password FROM admins WHERE email = ? AND role IN ('super_admin', 'Admin')");
            if (!$stmt) {
                $error = 'Database error: ' . $mysqli->error;
            } else {
                $stmt->bind_param('s', $email);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows === 1) {
                    $admin = $result->fetch_assoc();

                    // Check if account is deactivated
                    if (isset($admin['status']) && ($admin['status'] === 'inactive' || $admin['status'] === 'suspended')) {
                        $error = 'Your administrator account has been deactivated. Please contact the system administrator.';
                    } else {
                        // Check password - support both hashed and plain text during transition
                        $passwordValid = false;
                        // Try password_verify first (for hashed passwords)
                        if (password_verify($password, $admin['password'])) {
                            $passwordValid = true;
                        }
                        // Fallback to plain text comparison (for old passwords) and rehash immediately
                        elseif ($password === $admin['password']) {
                            $passwordValid = true;
                            $newHash = password_hash($password, PASSWORD_DEFAULT);
                            $rehashStmt = $mysqli->prepare("UPDATE admins SET password = ? WHERE id = ?");
                            if ($rehashStmt) {
                                $rehashStmt->bind_param('si', $newHash, $admin['id']);
                                $rehashStmt->execute();
                                $rehashStmt->close();
                            }
                        }

                        if ($passwordValid) {
                        session_regenerate_id(true);
                        // Password matches - set session variables
                        $nameParts = array_filter([$admin['first_name'], $admin['middle_name'], $admin['last_name']]);
                        $fullName = implode(' ', $nameParts);
                        if (!empty($admin['suffix'])) {
                            $fullName .= ' ' . $admin['suffix'];
                        }
                        $_SESSION['admin_id'] = $admin['id'];
                        $_SESSION['admin_email'] = $admin['email'];
                        $_SESSION['email'] = $admin['email']; // For dashboard compatibility
                        $_SESSION['admin_name'] = $fullName;
                        $_SESSION['admin_role'] = $admin['role'];
                        $_SESSION['is_admin'] = true;
                        $_SESSION['last_activity'] = time();

                        // Update last login
                        $updateStmt = $mysqli->prepare("UPDATE admins SET last_login = NOW() WHERE id = ?");
                        $updateStmt->bind_param('i', $admin['id']);
                        $updateStmt->execute();
                        $updateStmt->close();

                        // Redirect — force password change for admin-created accounts
                        if (!empty($admin['must_change_password'])) {
                            header('Location: set_password.php');
                        } else {
                            header('Location: admin_dashboard.php');
                        }
                        exit();
                    } else {
                        $error = 'Invalid email or password.';
                    }
                    } // closes the 'else' from line 49
                } else {
                    $error = 'Invalid email or password.';
                }

                $stmt->close();
            }
        }
    }
}

// Check if already logged in
if (isset($_SESSION['admin_id']) && isset($_SESSION['is_admin'])) {
    header('Location: admin_dashboard.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - <?php echo htmlspecialchars(getSystemConfig('short_name')); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="admin.css">
</head>
<body>
    <!-- Admin Login Container -->
    <div class="admin-login-container">
        <!-- Login Card -->
        <div class="admin-login-card">
            <!-- Header -->
            <div class="admin-login-header">
                <div class="admin-logo">
                    <?php echo getSystemLogo('logo-icon'); ?>
                    <h1><?php echo htmlspecialchars(getSystemConfig('short_name')); ?></h1>
                </div>
                <div class="admin-badge">
                    <i class="fa-solid fa-shield-halved"></i>
                    <span>Admin Portal</span>
                </div>
            </div>

            <!-- Login Form -->
            <?php if ($error): ?>
            <div class="error-alert">
                <i class="fa-solid fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
            <?php endif; ?>

            <?php if ($success): ?>
            <div class="success-alert" style="background: rgba(34, 197, 94, 0.1); border: 1px solid rgba(34, 197, 94, 0.3); color: #22c55e; padding: 1rem; border-radius: 8px; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                <i class="fa-solid fa-check-circle"></i>
                <span><?php echo htmlspecialchars($success); ?></span>
            </div>
            <?php endif; ?>

            <form id="adminLoginForm" class="admin-login-form" action="admin_login.php" method="POST">

                <!-- Email Input -->
                <div class="form-group">
                    <div class="floating-input">
                        <input type="email" id="adminEmail" name="adminEmail" placeholder=" " required>
                        <label for="adminEmail" class="select-label">Email Address</label>
                        <i class="fa-solid fa-envelope input-icon"></i>
                        <span class="validation-icon"></span>
                    </div>
                    <div class="error-message" id="usernameError"></div>
                </div>

                <!-- Password Input -->
                <div class="form-group">
                    <div class="floating-input password-input">
                        <input type="password" id="adminPassword" name="adminPassword" placeholder=" " required>
                        <label for="adminPassword" class="select-label">Password</label>
                        <i class="fa-solid fa-lock input-icon"></i>
                        <button type="button" class="toggle-password" onclick="toggleAdminPassword()">
                            <i class="fa-solid fa-eye" id="adminPasswordIcon"></i>
                        </button>
                    </div>
                    <div class="error-message" id="passwordError"></div>
                </div>

                <!-- Forgot Password Link -->
                <div class="forgot-password-link">
                    <a href="forgot_password.php?from=admin">
                        <i class="fa-solid fa-key"></i>
                        Forgot Password?
                    </a>
                </div>

                <!-- Login Button -->
                <button type="submit" class="admin-login-btn">
                    <i class="fa-solid fa-sign-in-alt"></i>
                    Login to Admin
                </button>

                <!-- Footer -->
                <div class="admin-login-footer">
                    <div class="security-note">
                        <i class="fa-solid fa-lock"></i>
                        <span>Secure admin authentication</span>
                    </div>
                    <div class="back-link">
                        <a href="index.php">
                            <i class="fa-solid fa-arrow-left"></i>
                            Back to Website
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleAdminPassword() {
            const passwordInput = document.getElementById('adminPassword');
            const icon = document.getElementById('adminPasswordIcon');

            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
    </script>
    <?php include 'includes/public_footer.php'; ?>
</body>
</html>
