<?php
// Activity Logs - admin-only
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';
require_once 'system_config.php';
require_once 'includes/audit.php';

// Admin auth check
if (!isset($_SESSION['admin_id']) || !isset($_SESSION['is_admin'])) {
    header('Location: admin_login.php');
    exit();
}

$student = getCurrentStudent(); // dummy if needed

// Fetch filters
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';
$userFilter = $_GET['user_filter'] ?? '';
$actionFilter = $_GET['action_filter'] ?? '';

// Build filters where clause
$where = [];
$params = [];
$types = '';

if (!empty($startDate)) {
    $where[] = "al.created_at >= ?";
    $params[] = $startDate . ' 00:00:00';
    $types .= 's';
}
if (!empty($endDate)) {
    $where[] = "al.created_at <= ?";
    $params[] = $endDate . ' 23:59:59';
    $types .= 's';
}
if (!empty($userFilter) && strpos($userFilter, '-') !== false) {
    list($utype, $uid) = explode('-', $userFilter);
    $where[] = "al.user_type = ? AND al.user_id = ?";
    $params[] = $utype;
    $params[] = (int)$uid;
    $types .= 'si';
}
if (!empty($actionFilter)) {
    $where[] = "al.action = ?";
    $params[] = $actionFilter;
    $types .= 's';
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$query = "
    SELECT 
        al.*,
        COALESCE(
            CONCAT(adm.first_name, ' ', IFNULL(adm.middle_name, ''), IF(adm.middle_name IS NOT NULL AND adm.middle_name != '', ' ', ''), adm.last_name, IF(adm.suffix IS NOT NULL AND adm.suffix != '', CONCAT(' ', adm.suffix), '')),
            CONCAT(cns.first_name, ' ', IFNULL(cns.middle_name, ''), IF(cns.middle_name IS NOT NULL AND cns.middle_name != '', ' ', ''), cns.last_name, IF(cns.suffix IS NOT NULL AND cns.suffix != '', CONCAT(' ', cns.suffix), '')),
            'System'
        ) AS user_name,
        COALESCE(adm.role, 'Counselor') AS user_role
    FROM audit_logs al
    LEFT JOIN admins adm ON al.user_id = adm.id AND al.user_type = 'admin'
    LEFT JOIN counselors cns ON al.user_id = cns.id AND al.user_type = 'counselor'
    $whereClause
    ORDER BY al.created_at DESC
    LIMIT 200
";

$stmt = $mysqli->prepare($query);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $logs = [];
}

// Fetch filter options list
$adminsRes = $mysqli->query("SELECT id, CONCAT(first_name, ' ', IFNULL(middle_name, ''), IF(middle_name IS NOT NULL AND middle_name != '', ' ', ''), last_name, IF(suffix IS NOT NULL AND suffix != '', CONCAT(' ', suffix), '')) AS full_name FROM admins ORDER BY first_name");
$cnsRes = $mysqli->query("SELECT id, CONCAT(first_name, ' ', IFNULL(middle_name, ''), IF(middle_name IS NOT NULL AND middle_name != '', ' ', ''), last_name, IF(suffix IS NOT NULL AND suffix != '', CONCAT(' ', suffix), '')) AS full_name FROM counselors ORDER BY first_name");

// Predefined actions list that should always be visible
$predefinedActions = [
    'Added School', 'Edited School', 'Deleted School',
    'Added Course', 'Edited Course', 'Deleted Course',
    'Added Job', 'Edited Job', 'Deleted Job',
    'Added Question', 'Edited Question', 'Deleted Question',
    'Added Student', 'Edited Student', 'Deleted Student', 'Activated Student', 'Deactivated Student',
    'Added Counselor', 'Edited Counselor', 'Deleted Counselor',
    'Link Job to Course', 'Link School to Course', 'Update School Specialization', 
    'Unlink School from Course', 'Unlink Job from Course',
    'Database Backup', 'Database Restore'
];

// Get distinct action types from database to also support dynamically added actions
$dbActions = [];
$actionsRes = $mysqli->query("SELECT DISTINCT action FROM audit_logs");
if ($actionsRes) {
    while ($row = $actionsRes->fetch_assoc()) {
        $dbActions[] = $row['action'];
    }
}

// Merge, deduplicate and sort all actions
$allActions = array_unique(array_merge($predefinedActions, $dbActions));
sort($allActions);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Logs - <?php echo htmlspecialchars(getSystemConfig('short_name')); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="admin.css">
    <style>
        body {
            background-color: #0b0f19;
            color: #f1f5f9;
        }
        .dashboard-content {
            padding: 2rem !important;
        }
        .page-header {
            margin-top: 0 !important;
            padding-top: 0 !important;
            margin-bottom: 2rem !important;
        }
        .page-header h2 {
            font-size: 1.75rem;
            font-weight: 800;
            background: linear-gradient(135deg, #ffffff 0%, #94a3b8 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 0.35rem;
            margin-top: 0;
        }
        .page-header p {
            color: #64748b;
            font-size: 0.95rem;
            margin: 0;
        }

        /* Filter Section Glassmorphism (Compressed) */
        .filter-card {
            background: rgba(30, 41, 59, 0.45);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 0.85rem 1.25rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 30px rgba(0, 0, 0, 0.2);
        }
        .filter-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: flex-end;
        }
        .filter-group {
            flex: 1;
            min-width: 140px;
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
        }
        .filter-group label {
            font-size: 0.7rem;
            font-weight: 700;
            color: #fbbf24;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.1rem;
            display: inline-block;
        }
        .filter-group select,
        .filter-group input {
            width: 100%;
            padding: 0.45rem 0.75rem;
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            color: #f1f5f9;
            font-size: 0.85rem;
            outline: none;
            transition: all 0.2s ease;
            font-family: inherit;
            height: 38px;
            box-sizing: border-box;
        }
        .filter-group select:focus,
        .filter-group input:focus {
            border-color: #fbbf24;
            box-shadow: 0 0 0 3px rgba(251, 191, 36, 0.15);
            background: rgba(15, 23, 42, 0.8);
        }
        .filter-actions {
            display: flex;
            gap: 0.5rem;
            flex: 0 0 auto;
        }
        .filter-actions .btn {
            padding: 0.45rem 1rem;
            font-size: 0.85rem;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;
            transition: all 0.2s ease;
            text-decoration: none;
            box-sizing: border-box;
            height: 38px;
        }
        .btn-filter-submit {
            background: linear-gradient(135deg, #fbbf24, #d97706);
            border: none;
            color: #0b0f19;
            box-shadow: 0 4px 12px rgba(251, 191, 36, 0.2);
        }
        .btn-filter-submit:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(251, 191, 36, 0.3);
        }
        .btn-filter-reset {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #94a3b8;
        }
        .btn-filter-reset:hover {
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
            border-color: rgba(255, 255, 255, 0.2);
        }

        /* Modern Table Card */
        .admin-card {
            background: rgba(30, 41, 59, 0.35);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 16px;
            padding: 0;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
        }
        .admin-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }
        .admin-table th {
            background: rgba(15, 23, 42, 0.4);
            padding: 1.1rem 1.5rem;
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #94a3b8;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }
        .admin-table td {
            padding: 1.1rem 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.04);
            vertical-align: middle;
        }
        .admin-table tbody tr {
            transition: all 0.2s ease;
        }
        .admin-table tbody tr:hover {
            background: rgba(255, 255, 255, 0.02);
        }
        .admin-table tr:last-child td {
            border-bottom: none;
        }

        /* Avatar styling */
        .user-avatar-cell {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .avatar-circle {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.875rem;
            color: #fff;
            text-transform: uppercase;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
            border: 1px solid rgba(255,255,255,0.1);
        }

        /* Modern action badges */
        .action-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.25rem 0.65rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.01em;
            border: 1px solid transparent;
        }
        .action-add { 
            background: rgba(16, 185, 129, 0.15); 
            border-color: rgba(16, 185, 129, 0.25); 
            color: #34d399; 
            box-shadow: 0 0 10px rgba(16, 185, 129, 0.05);
        }
        .action-edit { 
            background: rgba(59, 130, 246, 0.15); 
            border-color: rgba(59, 130, 246, 0.25); 
            color: #60a5fa; 
            box-shadow: 0 0 10px rgba(59, 130, 246, 0.05);
        }
        .action-delete { 
            background: rgba(239, 68, 68, 0.15); 
            border-color: rgba(239, 68, 68, 0.25); 
            color: #f87171; 
            box-shadow: 0 0 10px rgba(239, 68, 68, 0.05);
        }
        .action-default { 
            background: rgba(245, 158, 11, 0.15); 
            border-color: rgba(245, 158, 11, 0.25); 
            color: #fbbf24; 
            box-shadow: 0 0 10px rgba(245, 158, 11, 0.05);
        }

        .btn-changes {
            background: rgba(251, 191, 36, 0.1);
            border: 1px solid rgba(251, 191, 36, 0.25);
            color: #fbbf24;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            padding: 0.4rem 0.8rem;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            transition: all 0.2s ease;
        }
        .btn-changes:hover {
            background: rgba(251, 191, 36, 0.2);
            border-color: #fbbf24;
            color: #fff;
            transform: translateY(-1px);
        }

        /* Detail Modal Changes style */
        .changes-comparison {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.25rem;
            margin-top: 1rem;
        }
        @media (max-width: 768px) {
            .changes-comparison {
                grid-template-columns: 1fr;
            }
        }
        .change-box {
            background: #0f172a;
            border: 1px solid rgba(255,255,255,0.06);
            border-radius: 12px;
            padding: 1rem;
        }
        .payload-key-value-list {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            max-height: 300px;
            overflow-y: auto;
        }
        .payload-row {
            display: grid;
            grid-template-columns: 130px 1fr;
            padding: 0.6rem 0.8rem;
            background: rgba(255, 255, 255, 0.02);
            border-radius: 8px;
            border-left: 3px solid #fbbf24;
            font-size: 0.875rem;
            gap: 0.5rem;
        }
        .payload-row.empty {
            border-left-color: #475569;
            color: #64748b;
            font-style: italic;
            display: block;
            text-align: center;
        }
        .payload-key {
            font-weight: 700;
            color: #94a3b8;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.02em;
        }
        .payload-val {
            color: #f1f5f9;
            font-family: monospace;
            word-break: break-all;
            white-space: pre-wrap;
        }
    </style>
</head>
<body>
<div class="dashboard-container">

    <!-- ─── Sidebar ─────────────────────────────────────────── -->
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
                <a href="admin_dashboard.php" class="nav-item">
                    <i class="fa-solid fa-chart-line"></i>
                    <span>Dashboard</span>
                </a>
                <a href="manage_students.php" class="nav-item">
                    <i class="fa-solid fa-users"></i>
                    <span>Manage Students</span>
                </a>

                <!-- Assessments Group -->
                <div class="nav-group">
                    <button class="nav-group-toggle" onclick="toggleNavGroup(this)">
                        <i class="fa-solid fa-clipboard-check group-icon"></i>
                        <span>Assessments</span>
                        <i class="fa-solid fa-chevron-down chevron"></i>
                    </button>
                    <div class="nav-submenu">
                        <a href="manage_questions.php" class="nav-subitem">
                            <i class="fa-solid fa-circle-question"></i>
                            Manage Questions
                        </a>
                        <a href="ongoing_assessments.php" class="nav-subitem">
                            <i class="fa-solid fa-spinner"></i>
                            Ongoing Assessments
                        </a>
                        <a href="admin_assessment_results.php" class="nav-subitem">
                            <i class="fa-solid fa-file-circle-check"></i>
                            Assessment Results
                        </a>
                        <a href="admin-assessments.php" class="nav-subitem">
                            <i class="fa-solid fa-eye"></i>
                            Assessment Answers
                        </a>
                    </div>
                </div>

                <!-- Career Management Group -->
                <div class="nav-group">
                    <button class="nav-group-toggle" onclick="toggleNavGroup(this)">
                        <i class="fa-solid fa-briefcase group-icon"></i>
                        <span>Career Management</span>
                        <i class="fa-solid fa-chevron-down chevron"></i>
                    </button>
                    <div class="nav-submenu">
                        <a href="manage_clusters.php" class="nav-subitem">
                            <i class="fa-solid fa-layer-group"></i>
                            Manage Career Clusters
                        </a>
                        <a href="manage_courses.php" class="nav-subitem">
                            <i class="fa-solid fa-book-open"></i>
                            Manage Courses
                        </a>
                        <a href="manage_schools.php" class="nav-subitem">
                            <i class="fa-solid fa-school"></i>
                            Manage Schools
                        </a>
                        <a href="manage_jobs.php" class="nav-subitem">
                            <i class="fa-solid fa-hard-hat"></i>
                            Manage Jobs
                        </a>
                    </div>
                </div>

                <a href="reports.php" class="nav-item">
                    <i class="fa-solid fa-chart-bar"></i>
                    <span>Reports</span>
                </a>
                <a href="activity_logs.php" class="nav-item active">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                    <span>Activity Logs</span>
                </a>
                <a href="settings.php" class="nav-item">
                    <i class="fa-solid fa-gear"></i>
                    <span>Settings</span>
                </a>

                <div class="nav-separator"></div>

                <a href="logout.php" class="nav-item logout">
                    <i class="fa-solid fa-right-from-bracket"></i>
                    <span>Logout</span>
                </a>
            </nav>
    </aside>

    <!-- ─── Main Content ────────────────────────────────────── -->
    <main class="main-content">
        <header class="top-bar">
            <button class="mobile-menu-toggle" id="mobileMenuToggle">
                <i class="fa-solid fa-bars"></i>
            </button>
            <div class="page-title">
                <h1>Activity Logs</h1>
            </div>
            <?php 
            // Get admin name
            $userName = $_SESSION['admin_name'] ?? 'Admin User';
            
            // Get notifications
            $notifications = [];
            $unreadCount = 0;
            $adminId = $_SESSION['admin_id'] ?? null;
            $adminProfilePic = null;
            
            if ($adminId) {
                // Get admin profile picture
                $profileStmt = $mysqli->prepare("SELECT profile_picture FROM admins WHERE id = ? LIMIT 1");
                $profileStmt->bind_param('i', $adminId);
                $profileStmt->execute();
                $profileResult = $profileStmt->get_result();
                $adminData = $profileResult->fetch_assoc();
                $adminProfilePic = $adminData['profile_picture'] ?? null;
                $profileStmt->close();
                $countStmt = $mysqli->prepare("SELECT COUNT(*) as count FROM notifications WHERE (user_id = ? OR user_id IS NULL) AND user_type = 'admin' AND is_read = 0");
                $countStmt->bind_param('i', $adminId);
                $countStmt->execute();
                $unreadCount = $countStmt->get_result()->fetch_assoc()['count'] ?? 0;
                $countStmt->close();
                
                $notifStmt = $mysqli->prepare("SELECT * FROM notifications WHERE (user_id = ? OR user_id IS NULL) AND user_type = 'admin' ORDER BY created_at DESC LIMIT 10");
                $notifStmt->bind_param('i', $adminId);
                $notifStmt->execute();
                $result = $notifStmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $notifications[] = $row;
                }
                $notifStmt->close();
            }
            ?>
            <div class="top-bar-actions">
                <div class="notification-wrapper">
                    <button class="notification-btn" id="notificationBtn">
                        <i class="fa-solid fa-bell"></i>
                        <span class="notification-badge" id="notificationBadge" <?php echo $unreadCount == 0 ? 'style="display: none;"' : ''; ?>><?php echo $unreadCount > 9 ? '9+' : $unreadCount; ?></span>
                    </button>
                    <div class="notification-dropdown" id="notificationDropdown" style="display: none;">
                        <div class="notification-header">
                            <h4>Notifications</h4>
                            <?php if ($unreadCount > 0): ?>
                            <a href="#" class="mark-all-read" onclick="markAllRead(event)">Mark all as read</a>
                            <?php endif; ?>
                        </div>
                        <div class="notification-list">
                            <?php if (count($notifications) > 0): ?>
                                <?php foreach ($notifications as $notif): ?>
                                <div class="notification-item <?php echo $notif['is_read'] ? '' : 'unread'; ?>" data-id="<?php echo $notif['id']; ?>">
                                    <div class="notification-icon <?php echo $notif['type']; ?>">
                                        <i class="fa-solid <?php echo $notif['type'] === 'success' ? 'fa-check-circle' : ($notif['type'] === 'warning' ? 'fa-exclamation-triangle' : ($notif['type'] === 'error' ? 'fa-times-circle' : 'fa-info-circle')); ?>"></i>
                                    </div>
                                    <div class="notification-content">
                                        <p class="notification-title"><?php echo htmlspecialchars($notif['title']); ?></p>
                                        <p class="notification-message"><?php echo htmlspecialchars($notif['message']); ?></p>
                                        <span class="notification-time"><?php echo date('M d, Y H:i', strtotime($notif['created_at'])); ?></span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="no-notifications">
                                    <i class="fa-solid fa-bell-slash"></i>
                                    <p>No notifications yet</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="user-profile">
                    <div class="user-avatar">
                        <?php if ($adminProfilePic && file_exists(__DIR__ . '/' . $adminProfilePic)): ?>
                            <img src="<?php echo htmlspecialchars($adminProfilePic); ?>" alt="Admin" class="avatar-img">
                        <?php else: ?>
                            <i class="fa-solid fa-user-shield"></i>
                        <?php endif; ?>
                    </div>
                    <div class="user-dropdown">
                        <span class="user-name"><?php echo htmlspecialchars($userName); ?></span>
                    </div>
                </div>
            </div>
        </header>

        <div class="dashboard-content">
            <!-- Page Header Header -->
            <div class="page-header">
                <h2>System Audit Logs</h2>
                <p>Track all administrative modifications and database events</p>
            </div>

            <!-- Filter Card -->
            <div class="filter-card">
                <form method="get" action="activity_logs.php">
                    <div class="filter-grid">
                        <div class="filter-group">
                            <label for="startDate">Start Date</label>
                            <input type="date" id="startDate" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>">
                        </div>
                        <div class="filter-group">
                            <label for="endDate">End Date</label>
                            <input type="date" id="endDate" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>">
                        </div>
                        <div class="filter-group">
                            <label for="userFilter">Performed By</label>
                            <select id="userFilter" name="user_filter">
                                <option value="">All Users</option>
                                <optgroup label="Administrators">
                                    <?php while ($adm = $adminsRes->fetch_assoc()): 
                                        $val = 'admin-' . $adm['id'];
                                    ?>
                                    <option value="<?php echo $val; ?>" <?php echo $userFilter === $val ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($adm['full_name']); ?>
                                    </option>
                                    <?php endwhile; ?>
                                </optgroup>
                                <optgroup label="Counselors">
                                    <?php while ($cns = $cnsRes->fetch_assoc()): 
                                        $val = 'counselor-' . $cns['id'];
                                    ?>
                                    <option value="<?php echo $val; ?>" <?php echo $userFilter === $val ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cns['full_name']); ?>
                                    </option>
                                    <?php endwhile; ?>
                                </optgroup>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="actionFilter">Action Type</label>
                            <select id="actionFilter" name="action_filter">
                                <option value="">All Actions</option>
                                <?php foreach ($allActions as $act): ?>
                                <option value="<?php echo htmlspecialchars($act); ?>" <?php echo $actionFilter === $act ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($act); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-actions">
                            <button type="submit" class="btn btn-filter-submit">
                                <i class="fa-solid fa-filter"></i> Apply Filters
                            </button>
                            <a href="activity_logs.php" class="btn btn-filter-reset">Reset</a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Table Card -->
            <div class="admin-card">
                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th style="width: 15%;">Date/Time</th>
                                <th style="width: 15%;">User</th>
                                <th style="width: 12%;">Action</th>
                                <th>Description</th>
                                <th style="width: 10%;">Payload</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="5" class="no-data">
                                    <i class="fa-solid fa-history"></i>
                                    <p>No audit logs matching selection found.</p>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($logs as $log): 
                                // Parse badge class and icon
                                $actionName = strtolower($log['action']);
                                $actionIcon = 'fa-info-circle';
                                if (strpos($actionName, 'add') !== false || strpos($actionName, 'create') !== false) {
                                    $badgeClass = 'action-add';
                                    $actionIcon = 'fa-plus-circle';
                                } elseif (strpos($actionName, 'edit') !== false || strpos($actionName, 'update') !== false) {
                                    $badgeClass = 'action-edit';
                                    $actionIcon = 'fa-pen-to-square';
                                } elseif (strpos($actionName, 'delete') !== false || strpos($actionName, 'remove') !== false) {
                                    $badgeClass = 'action-delete';
                                    $actionIcon = 'fa-trash-can';
                                } else {
                                    $badgeClass = 'action-default';
                                    if (strpos($actionName, 'login') !== false) {
                                        $actionIcon = 'fa-sign-in-alt';
                                    } elseif (strpos($actionName, 'logout') !== false) {
                                        $actionIcon = 'fa-sign-out-alt';
                                    }
                                }

                                // Avatar bg
                                $avatarBg = '#64748b';
                                if ($log['user_type'] === 'admin') {
                                    $avatarBg = '#d97706';
                                } elseif ($log['user_type'] === 'counselor') {
                                    $avatarBg = '#2563eb';
                                }
                                $initials = strtoupper(substr($log['user_name'], 0, 2));
                            ?>
                            <tr>
                                <td style="font-size: 0.85rem; color: #94a3b8; white-space: nowrap;">
                                    <i class="fa-regular fa-calendar" style="color: #64748b; margin-right: 0.35rem;"></i>
                                    <?php echo date('M d, Y h:i A', strtotime($log['created_at'])); ?>
                                </td>
                                <td>
                                    <div class="user-avatar-cell">
                                        <div class="avatar-circle" style="background: <?php echo $avatarBg; ?>;">
                                            <?php echo htmlspecialchars($initials); ?>
                                        </div>
                                        <div>
                                            <div style="font-weight: 600; color: #f1f5f9; font-size: 0.875rem;">
                                                <?php echo htmlspecialchars($log['user_name']); ?>
                                            </div>
                                            <span style="font-size: 0.725rem; color: #64748b; font-weight: 500;">
                                                <?php echo htmlspecialchars($log['user_role']); ?>
                                            </span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="action-badge <?php echo $badgeClass; ?>">
                                        <i class="fa-solid <?php echo $actionIcon; ?>"></i>
                                        <?php echo htmlspecialchars($log['action']); ?>
                                    </span>
                                </td>
                                <td style="font-size: 0.875rem; color: #cbd5e1; line-height: 1.4;">
                                    <?php echo htmlspecialchars($log['description']); ?>
                                </td>
                                <td>
                                    <button class="btn-changes" onclick="viewLogChanges(<?php echo htmlspecialchars(json_encode($log)); ?>)">
                                        <i class="fa-solid fa-eye"></i> Details
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php include 'includes/app_footer.php'; ?>
    </main>
</div>

<!-- Changes Modal -->
<div class="modal" id="changesModal">
    <div class="modal-overlay" onclick="closeChangesModal()"></div>
    <div class="modal-content" style="max-width: 800px; background: #111827; color: #f1f5f9; border: 1px solid rgba(255, 255, 255, 0.08); box-shadow: 0 20px 50px rgba(0,0,0,0.6); border-radius: 16px;">
        <div class="modal-header" style="border-bottom: 1px solid rgba(255, 255, 255, 0.08); padding: 1.25rem 1.5rem;">
            <h2 id="modalTitle" style="color: #fbbf24; font-size: 1.15rem; display: flex; align-items: center; gap: 0.5rem; font-weight: 700; margin: 0;">
                <i class="fa-solid fa-database"></i> Action Payload Details
            </h2>
            <button class="modal-close" onclick="closeChangesModal()" style="font-size: 1.5rem; background: none; border: none; color: #64748b; cursor: pointer; transition: color 0.2s ease;">&times;</button>
        </div>
        <div class="modal-body" style="padding: 1.5rem;">
            <div id="modalDescription" style="font-size: 0.95rem; color: #f1f5f9; font-weight: 600; margin-bottom: 1.25rem; line-height: 1.5; padding: 0.85rem 1.1rem; background: rgba(255,255,255,0.03); border-radius: 10px; border-left: 4px solid #fbbf24;"></div>
            
            <div class="changes-comparison">
                <div class="change-box">
                    <h4 style="color: #f87171; font-size: 0.8rem; font-weight: 700; margin-top: 0; margin-bottom: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; display: flex; align-items: center; gap: 0.4rem;">
                        <i class="fa-solid fa-history"></i> Old Value (Before)
                    </h4>
                    <div id="oldValCode" class="payload-key-value-list"></div>
                </div>
                <div class="change-box">
                    <h4 style="color: #34d399; font-size: 0.8rem; font-weight: 700; margin-top: 0; margin-bottom: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; display: flex; align-items: center; gap: 0.4rem;">
                        <i class="fa-solid fa-circle-check"></i> New Value (After)
                    </h4>
                    <div id="newValCode" class="payload-key-value-list"></div>
                </div>
            </div>
        </div>
        <div class="modal-footer" style="padding: 1rem 1.5rem; border-top: 1px solid rgba(255, 255, 255, 0.08); display: flex; justify-content: flex-end; background: rgba(15, 23, 42, 0.2);">
            <button class="btn btn-secondary" onclick="closeChangesModal()" style="padding: 0.6rem 1.2rem; background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 8px; color: #94a3b8; font-weight: 600; font-size: 0.85rem; cursor: pointer; transition: all 0.2s ease;">Close</button>
        </div>
    </div>
</div>

<script src="script.js"></script>
<script>
    function escHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatPayload(payloadStr) {
        if (!payloadStr) {
            return `<div class="payload-row empty">No records / Empty</div>`;
        }
        try {
            const data = JSON.parse(payloadStr);
            if (typeof data !== 'object' || data === null) {
                return `<div class="payload-row"><div class="payload-key">Value</div><div class="payload-val">${escHtml(payloadStr)}</div></div>`;
            }
            
            const entries = Object.entries(data);
            if (entries.length === 0) {
                return `<div class="payload-row empty">No records / Empty</div>`;
            }
            
            let html = '';
            for (const [key, val] of entries) {
                const humanKey = key.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
                let displayVal = val;
                if (typeof val === 'object' && val !== null) {
                    displayVal = JSON.stringify(val, null, 2);
                } else if (val === null || val === undefined) {
                    displayVal = '—';
                }
                html += `<div class="payload-row"><div class="payload-key">${escHtml(humanKey)}</div><div class="payload-val">${escHtml(displayVal)}</div></div>`;
            }
            return html;
        } catch (e) {
            return `<div class="payload-row"><div class="payload-key">Value</div><div class="payload-val">${escHtml(payloadStr)}</div></div>`;
        }
    }

    function viewLogChanges(log) {
        document.getElementById('modalDescription').innerText = log.description || 'No description available.';
        
        const oldContainer = document.getElementById('oldValCode');
        const newContainer = document.getElementById('newValCode');
        
        oldContainer.innerHTML = formatPayload(log.old_value);
        newContainer.innerHTML = formatPayload(log.new_value);
        
        document.getElementById('changesModal').classList.add('active');
    }

    function closeChangesModal() {
        document.getElementById('changesModal').classList.remove('active');
    }
</script>
<script>
    // Notification dropdown toggle
    document.getElementById('notificationBtn').addEventListener('click', function(e) {
        e.stopPropagation();
        const dropdown = document.getElementById('notificationDropdown');
        dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
    });

    // Close dropdown when clicking outside
    document.addEventListener('click', function() {
        const dropdown = document.getElementById('notificationDropdown');
        if (dropdown) dropdown.style.display = 'none';
    });

    // Prevent dropdown close when clicking inside
    document.getElementById('notificationDropdown').addEventListener('click', function(e) {
        e.stopPropagation();
    });

    // Mark all notifications as read
    function markAllRead(e) {
        e.preventDefault();
        e.stopPropagation();
        
        fetch('api/notifications.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=mark_all_read'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.querySelectorAll('.notification-item.unread').forEach(item => {
                    item.classList.remove('unread');
                });
                document.getElementById('notificationBadge').textContent = '0';
                document.querySelector('.mark-all-read')?.remove();
            }
        });
    }

    // Mark single notification as read on click
    document.querySelectorAll('.notification-item').forEach(item => {
        item.addEventListener('click', function() {
            if (this.classList.contains('unread')) {
                const notifId = this.dataset.id;
                
                fetch('api/notifications.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=mark_read&id=' + notifId
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        this.classList.remove('unread');
                        const badge = document.getElementById('notificationBadge');
                        const currentCount = parseInt(badge.textContent);
                        if (currentCount > 0) {
                            badge.textContent = currentCount - 1;
                        }
                    }
                });
            }
        });
    });
</script>
<script src="admin.js"></script>
<?php require_once __DIR__ . '/includes/session_timeout_footer.php'; ?>
</body>
</html>
