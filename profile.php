<?php
require_once 'config.php';
require_once 'system_config.php';

requireLogin();

// Read-only guard: archived Grade 12 students cannot edit their profile
$_currentStudentForGuard = getCurrentStudent();
if (!empty($_currentStudentForGuard['is_readonly']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Your account is in read-only mode. Profile changes are not allowed.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_avatar') {
    $dbId = (int) ($_SESSION['student_db_id'] ?? 0);
    $sid = $_SESSION['student_id'] ?? '';
    
    if ($dbId > 0 && $sid !== '' && isset($_FILES['avatar'])) {
        $file = $_FILES['avatar'];
        
        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'Upload error: ' . $file['error']]);
            exit;
        }
        
        // Validate file type via MIME and getimagesize
        $allowedTypes = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp'
        ];
        $fileType = mime_content_type($file['tmp_name']);
        if (!array_key_exists($fileType, $allowedTypes)) {
            echo json_encode(['success' => false, 'message' => 'Invalid file type. Only JPG, PNG, GIF, and WebP are allowed.']);
            exit;
        }
        
        // Verify image signature
        if (@getimagesize($file['tmp_name']) === false) {
            echo json_encode(['success' => false, 'message' => 'Invalid image file signature.']);
            exit;
        }
        
        // Validate file size (max 5MB)
        $maxSize = 5 * 1024 * 1024; // 5MB
        if ($file['size'] > $maxSize) {
            echo json_encode(['success' => false, 'message' => 'File is too large. Maximum size is 5MB.']);
            exit;
        }
        
        // Create upload directory if it doesn't exist
        $uploadDir = __DIR__ . '/uploads/avatars/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        // Ensure .htaccess protection
        if (!file_exists($uploadDir . '.htaccess')) {
            @file_put_contents($uploadDir . '.htaccess', "<FilesMatch \"\.(php|phtml|php3|php4|php5|php7|phps|pl|py|jsp|asp|htm|html|shtml|sh|cgi)$\">\n    Require all denied\n</FilesMatch>\nOptions -Indexes\n");
        }
        
        // Generate unique filename using strict extension mapping
        $fileExt = $allowedTypes[$fileType];
        $fileName = uniqid('avatar_', true) . '.' . $fileExt;
        $filePath = $uploadDir . $fileName;
        $relativePath = 'uploads/avatars/' . $fileName;
        
        // Move uploaded file to target directory
        if (move_uploaded_file($file['tmp_name'], $filePath)) {
            // Update database
            $stmt = $mysqli->prepare("UPDATE students SET profile_picture = ?, updated_at = NOW() WHERE id = ? AND student_id = ?");
            
            if ($stmt) {
                $stmt->bind_param('sis', $relativePath, $dbId, $sid);
                $stmt->execute();
                $stmt->close();
                
                // Refresh session data
                $refetchStmt = $mysqli->prepare("SELECT * FROM students WHERE id = ? AND student_id = ?");
                if ($refetchStmt) {
                    $refetchStmt->bind_param('is', $dbId, $sid);
                    $refetchStmt->execute();
                    $refetched = $refetchStmt->get_result()->fetch_assoc();
                    $refetchStmt->close();
                    
                    if ($refetched) {
                        $_SESSION['student_data'] = $refetched;
                    }
                }
                
                echo json_encode(['success' => true, 'message' => 'Avatar uploaded successfully!', 'avatar_url' => $relativePath . '?v=' . time()]);
                exit;
            }
        }
        
        echo json_encode(['success' => false, 'message' => 'Failed to save uploaded file.']);
        exit;
    }
    
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $dbId = (int) ($_SESSION['student_db_id'] ?? 0);
    $sid = $_SESSION['student_id'] ?? '';
    
    if ($dbId > 0 && $sid !== '') {
        $firstName = trim($_POST['firstName'] ?? '');
        $middleName = trim($_POST['middleName'] ?? '');
        $lastName = trim($_POST['lastName'] ?? '');
        $suffix = trim($_POST['suffix'] ?? '');
        $birthdate = trim($_POST['birthdate'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        
        $stmt = $mysqli->prepare("
            UPDATE students 
            SET first_name = ?, middle_name = ?, last_name = ?, suffix = ?, birthdate = ?, phone = ?, email = ?, address = ?, updated_at = NOW()
            WHERE id = ? AND student_id = ?
        ");
        
        if ($stmt) {
            $stmt->bind_param(
                'ssssssssis', 
                $firstName, $middleName, $lastName, $suffix, 
                $birthdate, $phone, $email, $address,
                $dbId, $sid
            );
            
            $stmt->execute();
            $stmt->close();
            
            // Refresh session data by refetching the student
            $refetchStmt = $mysqli->prepare("SELECT * FROM students WHERE id = ? AND student_id = ?");
            if ($refetchStmt) {
                $refetchStmt->bind_param('is', $dbId, $sid);
                $refetchStmt->execute();
                $refetched = $refetchStmt->get_result()->fetch_assoc();
                $refetchStmt->close();
                
                if ($refetched) {
                    $_SESSION['student_data'] = $refetched;
                }
            }
            
            echo json_encode(['success' => true, 'message' => 'Profile updated successfully']);
            exit;
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error']);
            exit;
        }
    }
    
    echo json_encode(['success' => false, 'message' => 'Invalid session']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deactivate_account'])) {
    $dbId = (int) ($_SESSION['student_db_id'] ?? 0);
    $sid = $_SESSION['student_id'] ?? '';
    if ($dbId > 0 && $sid !== '') {
        // Fetch details before deactivation for logging/notifying
        $studentName = 'A student';
        $studentEmail = '';
        $nameStmt = $mysqli->prepare("SELECT first_name, middle_name, last_name, email FROM students WHERE id = ?");
        if ($nameStmt) {
            $nameStmt->bind_param('i', $dbId);
            $nameStmt->execute();
            $studentData = $nameStmt->get_result()->fetch_assoc();
            if ($studentData) {
                $studentName = trim(implode(' ', array_filter([$studentData['first_name'], $studentData['middle_name'], $studentData['last_name']])));
                $studentEmail = $studentData['email'];
            }
            $nameStmt->close();
        }

        $stmt = $mysqli->prepare("UPDATE students SET status = 'inactive' WHERE id = ? AND student_id = ? AND status = 'active'");
        if ($stmt) {
            $stmt->bind_param('is', $dbId, $sid);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                // Load audit and notify helpers
                require_once __DIR__ . '/includes/audit.php';
                require_once __DIR__ . '/includes/notify.php';
                
                // Write activity/audit log
                log_activity(
                    $dbId,
                    'student',
                    'Deactivated Student',
                    'students',
                    $dbId,
                    "Student {$studentName} ({$studentEmail}) deactivated their own account.",
                    json_encode(['status' => 'active']),
                    json_encode(['status' => 'inactive'])
                );

                // Notify admin
                notify_admin(
                    'Student Account Deactivated',
                    "Student {$studentName} has deactivated their account.",
                    'warning',
                    'manage_students.php'
                );
            }
            $stmt->close();
        }
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    redirect('login.php?account=inactive');
}

$student = getCurrentStudent();
if (!$student) {
    redirect('login.php');
}

$studentName   = getStudentDisplayName($student);
$avatarDataUri = getStudentAvatarUri($student);
$isReadonly    = !empty($student['is_readonly']);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - <?php echo htmlspecialchars(getSystemConfig('short_name')); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="user.css">
</head>
<body>
    <!-- Dashboard Container -->
    <div class="dashboard-container">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="logo">
                    <?php echo getSystemLogo('logo-icon'); ?>
                    <h2><?php echo htmlspecialchars(getSystemConfig('short_name')); ?></h2>
                </div>
                <button class="sidebar-toggle" id="sidebarToggle">
                    <i class="fa-solid fa-bars"></i>
                </button>
            </div>
            <nav class="sidebar-nav">
                <a href="dashboard.php" class="nav-item">
                    <i class="fa-solid fa-home"></i>
                    <span>Dashboard</span>
                </a>
                <a href="take_assessment.php" class="nav-item">
                    <i class="fa-solid fa-clipboard-list"></i>
                    <span>Take Assessment</span>
                </a>
                <a href="assessment_results.php" class="nav-item">
                    <i class="fa-solid fa-chart-line"></i>
                    <span>Assessment Results</span>
                </a>
                <a href="recommended_courses.php" class="nav-item">
                    <i class="fa-solid fa-graduation-cap"></i>
                    <span>Recommendations</span>
                </a>
                <a href="profile.php" class="nav-item active">
                    <i class="fa-solid fa-user"></i>
                    <span>Profile</span>
                </a>
                <a href="logout.php" class="nav-item logout">
                    <i class="fa-solid fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Top Bar -->
            <header class="top-bar">
                <button class="mobile-menu-toggle" id="mobileMenuToggle">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="page-title">
                    <h1>My Profile</h1>
                </div>
                <div class="top-bar-actions">
                    <?php require_once __DIR__ . '/includes/student_notifications_bell.php'; ?>
                    <div class="user-profile">
                        <img src="<?php echo $avatarDataUri; ?>" alt="User Avatar" class="user-avatar">
                        <div class="user-dropdown">
                            <span class="user-name"><?php echo $studentName; ?></span>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Profile Content -->
            <div class="dashboard-content">
                <?php if ($isReadonly): ?>
                <div style="
                    display:flex;align-items:flex-start;gap:1rem;
                    background:linear-gradient(135deg,rgba(245,158,11,.12),rgba(245,158,11,.06));
                    border:1px solid rgba(245,158,11,.35);
                    border-radius:14px;
                    padding:1.1rem 1.4rem;
                    margin-bottom:1.5rem;
                ">
                    <i class="fa-solid fa-lock" style="color:#f59e0b;font-size:1.3rem;margin-top:2px;flex-shrink:0;"></i>
                    <div>
                        <div style="font-weight:700;color:#f1f5f9;font-size:.95rem;margin-bottom:.2rem;">
                            Read-Only Account
                        </div>
                        <div style="font-size:.85rem;color:#94a3b8;line-height:1.5;">
                            Your Grade 12 account has been archived following the school year transition.
                            Profile information is displayed in view-only mode.
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Profile Header -->
                <section class="profile-header-section">
                    <div class="profile-header-card">
                        <div class="profile-avatar-container">
                            <img src="<?php echo $avatarDataUri; ?>" alt="Profile Picture" class="profile-avatar-large" id="profileAvatar">
                            <?php if (!$isReadonly): ?>
                            <button class="avatar-edit-btn" onclick="triggerAvatarUpload()">
                                <i class="fa-solid fa-camera"></i>
                            </button>
                            <input type="file" id="avatarInput" accept="image/*" style="display: none;" onchange="previewAvatar(event)">
                            <?php endif; ?>
                        </div>
                        <div class="profile-info">
                            <h2 class="profile-name" id="profileFullName"><?php echo $studentName; ?></h2>
                            <span class="profile-role">Student</span>
                        </div>
                        <?php if (!$isReadonly): ?>
                        <button class="btn-edit-profile" onclick="toggleEditMode()">
                            <i class="fa-solid fa-pen"></i>
                            Edit Profile
                        </button>
                        <?php endif; ?>
                    </div>
                </section>

                <!-- Personal Information -->
                <section class="profile-section">
                    <div class="profile-card">
                        <div class="card-header">
                            <h3><i class="fa-solid fa-user"></i> Personal Information</h3>
                        </div>
                        <div class="card-content">
                            <div class="info-grid" id="personalInfoView">
                                <div class="info-item">
                                    <span class="info-label">First Name</span>
                                    <span class="info-value" id="firstNameView"><?php echo htmlspecialchars($student['first_name']); ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Middle Name</span>
                                    <span class="info-value" id="middleNameView"><?php echo htmlspecialchars($student['middle_name'] ?? '-'); ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Last Name</span>
                                    <span class="info-value" id="lastNameView"><?php echo htmlspecialchars($student['last_name']); ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Suffix</span>
                                    <span class="info-value" id="suffixView"><?php echo htmlspecialchars($student['suffix'] ?? '-'); ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Birthdate</span>
                                    <span class="info-value" id="birthdateView"><?php echo htmlspecialchars($student['birthdate'] ?? 'Not provided'); ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Phone Number</span>
                                    <span class="info-value" id="phoneView"><?php echo htmlspecialchars($student['phone'] ?? 'Not provided'); ?></span>
                                </div>
                                <div class="info-item full-width">
                                    <span class="info-label">Email Address</span>
                                    <span class="info-value" id="emailView"><?php echo htmlspecialchars($student['email']); ?></span>
                                </div>
                                <div class="info-item full-width">
                                    <span class="info-label">Home Address</span>
                                    <span class="info-value" id="addressView"><?php echo htmlspecialchars($student['address'] ?? 'Not provided'); ?></span>
                                </div>
                            </div>

                            <form class="edit-form" id="personalInfoEdit" style="display: none;">
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="firstName">First Name</label>
                                        <input type="text" id="firstName" name="firstName" value="" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="middleName">Middle Name</label>
                                        <input type="text" id="middleName" name="middleName" value="">
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="lastName">Last Name</label>
                                        <input type="text" id="lastName" name="lastName" value="" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="suffix">Suffix</label>
                                        <input type="text" id="suffix" name="suffix" placeholder="e.g., Jr., Sr., III">
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="birthdate">Birthdate</label>
                                        <input type="date" id="birthdate" name="birthdate" value="" max="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="phone">Phone Number</label>
                                        <input type="tel" id="phone" name="phone" value="" maxlength="11" pattern="[0-9]*" inputmode="numeric" onkeypress="return event.charCode >= 48 && event.charCode <= 57" required>
                                    </div>
                                </div>
                                <div class="form-group full-width">
                                    <label for="email">Email Address</label>
                                    <input type="email" id="email" name="email" value="" required>
                                </div>
                                <div class="form-group full-width">
                                    <label for="address">Home Address</label>
                                    <textarea id="address" name="address" rows="3" required></textarea>
                                </div>
                            </form>
                        </div>
                    </div>
                </section>

                <!-- Academic Information -->
                <section class="profile-section">
                    <div class="profile-card">
                        <div class="card-header">
                            <h3><i class="fa-solid fa-graduation-cap"></i> Academic Information</h3>
                        </div>
                        <div class="card-content">
                            <div class="info-grid" id="academicInfoView">
                                <div class="info-item">
                                    <span class="info-label">School Year</span>
                                    <span class="info-value" id="schoolYearView"><?php echo htmlspecialchars($student['school_year'] ?? 'Not Assigned'); ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Grade Level</span>
                                    <span class="info-value" id="gradeLevelView"><?php echo htmlspecialchars($student['grade_level'] ?? 'Not Assigned'); ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Strand</span>
                                    <span class="info-value" id="strandView"><?php echo htmlspecialchars(($student['strand_code'] ? strtoupper($student['strand_code']) . ' - ' : '') . ($student['strand_name'] ?? 'Not Assigned')); ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">School</span>
                                    <span class="info-value" id="schoolView"><?php echo htmlspecialchars(getSystemConfig('school_name')); ?></span>
                                </div>
                            </div>

                            <form class="edit-form" id="academicInfoEdit" style="display: none;">
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="schoolYear">School Year</label>
                                        <input type="text" id="schoolYear" name="schoolYear" value="<?php echo htmlspecialchars($student['school_year'] ?? 'Not Assigned'); ?>" disabled title="School year cannot be changed">
                                        <small class="field-note">School year cannot be changed</small>
                                    </div>
                                    <div class="form-group">
                                        <label for="gradeLevel">Grade Level</label>
                                        <input type="text" id="gradeLevel" name="gradeLevel" value="<?php echo htmlspecialchars($student['grade_level'] ?? 'Not Assigned'); ?>" disabled title="Grade level cannot be changed">
                                        <small class="field-note">Grade level cannot be changed</small>
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="strand">Strand</label>
                                        <input type="text" id="strand" name="strand" value="<?php echo htmlspecialchars(($student['strand_code'] ? strtoupper($student['strand_code']) . ' - ' : '') . ($student['strand_name'] ?? 'Not Assigned')); ?>" disabled title="Strand cannot be changed">
                                        <small class="field-note">Strand cannot be changed</small>
                                    </div>
                                    <div class="form-group">
                                        <label for="school">School</label>
                                        <input type="text" id="school" name="school" value="<?php echo htmlspecialchars(getSystemConfig('school_name')); ?>" disabled title="School cannot be changed">
                                        <small class="field-note">School cannot be changed</small>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </section>

                <!-- Form Actions (shown in edit mode) -->
                <section class="form-actions" id="formActions" style="display: none;">
                    <button type="button" class="btn btn-secondary" onclick="toggleEditMode()">
                        <i class="fa-solid fa-times"></i>
                        Cancel
                    </button>
                    <button type="button" class="btn btn-primary" onclick="saveProfile()">
                        <i class="fa-solid fa-save"></i>
                        Save Changes
                    </button>
                </section>

                <!-- Profile Actions -->
                <section class="profile-actions">
                    <form id="deactivateAccountForm" method="post" action="profile.php" style="display:none;">
                        <input type="hidden" name="deactivate_account" value="1">
                    </form>

                    <button class="btn btn-deactivate" onclick="deactivateAccount()">
                        <i class="fa-solid fa-user-slash"></i>
                        Deactivate Account
                    </button>
                    <a href="logout.php" class="btn btn-logout">
                        <i class="fa-solid fa-sign-out-alt"></i>
                        Logout
                    </a>
                </section>
            </div>
        <?php include 'includes/app_footer.php'; ?>
        </main>
    </div>

    <script src="script.js"></script>
    <script>
        // Set max birthdate to today (prevent future dates)
        document.addEventListener('DOMContentLoaded', function() {
            const birthdateInput = document.getElementById('birthdate');
            if (birthdateInput) {
                const today = new Date().toISOString().split('T')[0];
                birthdateInput.setAttribute('max', today);
            }
        });
    </script>
    <?php require_once __DIR__ . '/includes/session_timeout_footer.php'; ?>
</body>
</html>
