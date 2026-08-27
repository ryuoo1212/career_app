<?php
// Reports & Analytics Page - Enhanced Assessment & Historical Records Module

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';
require_once 'system_config.php';

// Auth check
if (!isset($_SESSION['admin_id']) || !isset($_SESSION['is_admin'])) {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    header('Location: admin_login.php');
    exit();
}

// ==================== AJAX HANDLERS ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false];

    // Helper: Build WHERE conditions & prepared statement params for filtering
    $buildFilterQuery = function($mysqli, $extraWhere = '') {
        $strandId    = (int)($_POST['strand_id'] ?? 0);
        $schoolYearId= (int)($_POST['school_year_id'] ?? 0);
        $searchTerm  = trim($_POST['search_term'] ?? '');

        $whereParts = ["sa.status = 'completed'"];
        $params     = [];
        $types      = '';

        if ($searchTerm !== '') {
            $whereParts[] = "(CONCAT(s.first_name, ' ', COALESCE(s.last_name, '')) LIKE ? OR s.student_id LIKE ?)";
            $likeTerm = '%' . $searchTerm . '%';
            $params[] = $likeTerm;
            $params[] = $likeTerm;
            $types .= 'ss';
        }

        if ($extraWhere !== '') {
            $whereParts[] = $extraWhere;
        }

        if ($strandId > 0) {
            $whereParts[] = "s.strand_id = ?";
            $params[] = $strandId;
            $types .= 'i';
        }

        if ($schoolYearId > 0) {
            $whereParts[] = "(sa.school_year_id = ? OR s.school_year_id = ?)";
            $params = array_merge($params, [$schoolYearId, $schoolYearId]);
            $types .= 'ii';
        }

        $whereSql = implode(' AND ', $whereParts);
        return [$whereSql, $types, $params];
    };

    switch ($_POST['action']) {
        case 'get_historical_records':
            $page     = max(1, (int)($_POST['page'] ?? 1));
            $perPage  = max(1, min(50, (int)($_POST['per_page'] ?? 10)));
            $offset   = ($page - 1) * $perPage;

            $sortBy    = $_POST['sort_by'] ?? 'completed_at';
            $sortOrder = strtoupper($_POST['sort_order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

            $sortMap = [
                'student_name'  => 'student_name',
                'school_id'     => 's.student_id',
                'strand_name'   => 'st.name',
                'completed_at'  => 'sa.completed_at',
                'overall_match' => 'sa.total_score',
                'top_course'    => 'top_course'
            ];
            $orderSql = ($sortMap[$sortBy] ?? 'sa.completed_at') . ' ' . $sortOrder;

            list($whereSql, $types, $params) = $buildFilterQuery($mysqli);

            // 1. Total records count
            $countSql = "
                SELECT COUNT(DISTINCT sa.id) AS total
                FROM student_assessments sa
                JOIN students s ON sa.student_id = s.id
                LEFT JOIN strands st ON s.strand_id = st.id
                LEFT JOIN school_years sy ON sa.school_year_id = sy.id OR s.school_year_id = sy.id
                WHERE $whereSql
            ";
            $countStmt = $mysqli->prepare($countSql);
            if ($types !== '') {
                $countStmt->bind_param($types, ...$params);
            }
            $countStmt->execute();
            $totalRecords = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
            $countStmt->close();

            $totalPages = max(1, (int)ceil($totalRecords / $perPage));
            $page       = min($page, $totalPages);
            $offset     = ($page - 1) * $perPage;

            // 2. Paginated data fetch
            $dataSql = "
                SELECT sa.id AS assessment_id, sa.student_id AS student_db_id, sa.completed_at, sa.created_at, sa.total_score,
                       CONCAT(
                           COALESCE(s.first_name, ''),
                           IF(s.middle_name IS NOT NULL AND s.middle_name != '', CONCAT(' ', UPPER(SUBSTRING(s.middle_name, 1, 1)), '.'), ''),
                           ' ', COALESCE(s.last_name, ''),
                           IF(s.suffix IS NOT NULL AND s.suffix != '', CONCAT(' ', s.suffix), '')
                       ) AS student_name,
                       s.student_id AS school_id, s.grade_level,
                       st.name AS strand_name, st.code AS strand_code,
                       COALESCE(sy.year_label, 'N/A') AS school_year,
                       (
                           SELECT c.course_name 
                           FROM recommendations r 
                           JOIN courses c ON r.course_id = c.id 
                           WHERE r.assessment_id = sa.id 
                           ORDER BY r.match_percentage DESC, r.rank ASC 
                           LIMIT 1
                       ) AS top_course
                FROM student_assessments sa
                JOIN students s ON sa.student_id = s.id
                LEFT JOIN strands st ON s.strand_id = st.id
                LEFT JOIN school_years sy ON sa.school_year_id = sy.id OR s.school_year_id = sy.id
                WHERE $whereSql
                GROUP BY sa.id
                ORDER BY $orderSql
                LIMIT ?, ?
            ";

            $dataStmt = $mysqli->prepare($dataSql);
            $bindTypes  = $types . 'ii';
            $bindParams = array_merge($params, [$offset, $perPage]);
            $dataStmt->bind_param($bindTypes, ...$bindParams);
            $dataStmt->execute();
            $result = $dataStmt->get_result();
            $records = [];
            while ($row = $result->fetch_assoc()) {
                $row['total_score_fmt'] = round((float)($row['total_score'] ?? 0), 1) . '%';
                $row['formatted_date']  = $row['completed_at'] ? date('M d, Y', strtotime($row['completed_at'])) : 'N/A';
                $records[] = $row;
            }
            $dataStmt->close();

            // 3. KPI Statistics for the matching filter set
            $kpiSql = "
                SELECT 
                    COUNT(DISTINCT sa.student_id) AS total_students,
                    COUNT(DISTINCT sa.id) AS total_completed,
                    AVG(sa.total_score) AS avg_score
                FROM student_assessments sa
                JOIN students s ON sa.student_id = s.id
                LEFT JOIN strands st ON s.strand_id = st.id
                LEFT JOIN school_years sy ON sa.school_year_id = sy.id OR s.school_year_id = sy.id
                WHERE $whereSql
            ";
            $kpiStmt = $mysqli->prepare($kpiSql);
            if ($types !== '') {
                $kpiStmt->bind_param($types, ...$params);
            }
            $kpiStmt->execute();
            $kpiRes = $kpiStmt->get_result()->fetch_assoc();
            $kpiStmt->close();

            // Top recommended course for matching filter set
            $topCourseSql = "
                SELECT c.course_name, COUNT(*) as cnt
                FROM recommendations r
                JOIN courses c ON r.course_id = c.id
                JOIN student_assessments sa ON r.assessment_id = sa.id
                JOIN students s ON sa.student_id = s.id
                LEFT JOIN strands st ON s.strand_id = st.id
                LEFT JOIN school_years sy ON sa.school_year_id = sy.id OR s.school_year_id = sy.id
                WHERE $whereSql
                GROUP BY c.id, c.course_name
                ORDER BY cnt DESC
                LIMIT 1
            ";
            $topCourseStmt = $mysqli->prepare($topCourseSql);
            if ($types !== '') {
                $topCourseStmt->bind_param($types, ...$params);
            }
            $topCourseStmt->execute();
            $topCourseRes = $topCourseStmt->get_result()->fetch_assoc();
            $topCourseStmt->close();

            $summaryStats = [
                'total_students'          => (int)($kpiRes['total_students'] ?? 0),
                'total_completed'         => (int)($kpiRes['total_completed'] ?? 0),
                'avg_score'               => round((float)($kpiRes['avg_score'] ?? 0), 1) . '%',
                'most_recommended_course' => $topCourseRes['course_name'] ?? 'N/A'
            ];

            // 4. Chart Data Generation
            // Chart A: Top 5 Recommended Courses (Pie Chart)
            $chartTopCoursesSql = "
                SELECT c.course_name, COUNT(*) AS cnt
                FROM recommendations r
                JOIN courses c ON r.course_id = c.id
                JOIN student_assessments sa ON r.assessment_id = sa.id
                JOIN students s ON sa.student_id = s.id
                LEFT JOIN strands st ON s.strand_id = st.id
                LEFT JOIN school_years sy ON sa.school_year_id = sy.id OR s.school_year_id = sy.id
                WHERE $whereSql
                GROUP BY c.id, c.course_name
                ORDER BY cnt DESC
                LIMIT 5
            ";
            $tcStmt = $mysqli->prepare($chartTopCoursesSql);
            if ($types !== '') { $tcStmt->bind_param($types, ...$params); }
            $tcStmt->execute();
            $tcRes = $tcStmt->get_result();
            $chartTopCourses = ['labels' => [], 'data' => []];
            while ($r = $tcRes->fetch_assoc()) {
                $chartTopCourses['labels'][] = $r['course_name'];
                $chartTopCourses['data'][]   = (int)$r['cnt'];
            }
            $tcStmt->close();

            // Chart B: Strand Distribution (Doughnut Chart)
            $chartStrandSql = "
                SELECT COALESCE(st.code, 'N/A') AS strand_code, COALESCE(st.name, 'Unassigned') AS strand_name, COUNT(DISTINCT sa.student_id) AS cnt
                FROM student_assessments sa
                JOIN students s ON sa.student_id = s.id
                LEFT JOIN strands st ON s.strand_id = st.id
                LEFT JOIN school_years sy ON sa.school_year_id = sy.id OR s.school_year_id = sy.id
                WHERE $whereSql
                GROUP BY st.id, strand_name, strand_code
                ORDER BY cnt DESC
            ";
            $csStmt = $mysqli->prepare($chartStrandSql);
            if ($types !== '') { $csStmt->bind_param($types, ...$params); }
            $csStmt->execute();
            $csRes = $csStmt->get_result();
            $chartStrand = ['labels' => [], 'data' => []];
            while ($r = $csRes->fetch_assoc()) {
                $chartStrand['labels'][] = $r['strand_code'] . ' - ' . $r['strand_name'];
                $chartStrand['data'][]   = (int)$r['cnt'];
            }
            $csStmt->close();

            $response = [
                'success'       => true,
                'records'       => $records,
                'total_records' => $totalRecords,
                'total_pages'   => $totalPages,
                'page'          => $page,
                'summary_stats' => $summaryStats,
                'charts' => [
                    'top_courses' => $chartTopCourses,
                    'strands'     => $chartStrand
                ]
            ];
            break;

        case 'get_student_details':
            $assessmentId = (int)($_POST['assessment_id'] ?? 0);
            if ($assessmentId <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid assessment ID']);
                exit;
            }

            // Fetch Assessment & Student info
            $stmt = $mysqli->prepare("
                SELECT sa.id AS assessment_id, sa.completed_at, sa.created_at, sa.total_score, sa.status,
                       s.id AS student_db_id, s.student_id AS school_id, s.email, s.phone, s.grade_level, s.section,
                       CONCAT(
                           COALESCE(s.first_name, ''),
                           IF(s.middle_name IS NOT NULL AND s.middle_name != '', CONCAT(' ', UPPER(SUBSTRING(s.middle_name, 1, 1)), '.'), ''),
                           ' ', COALESCE(s.last_name, ''),
                           IF(s.suffix IS NOT NULL AND s.suffix != '', CONCAT(' ', s.suffix), '')
                       ) AS student_name,
                       COALESCE(st.name, 'N/A') AS strand_name, COALESCE(st.code, '') AS strand_code,
                       COALESCE(sy.year_label, 'N/A') AS school_year
                FROM student_assessments sa
                JOIN students s ON sa.student_id = s.id
                LEFT JOIN strands st ON s.strand_id = st.id
                LEFT JOIN school_years sy ON sa.school_year_id = sy.id OR s.school_year_id = sy.id
                WHERE sa.id = ? LIMIT 1
            ");
            $stmt->bind_param('i', $assessmentId);
            $stmt->execute();
            $studentData = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$studentData) {
                echo json_encode(['success' => false, 'message' => 'Assessment not found']);
                exit;
            }

            // Fetch Category Scores
            $scoreStmt = $mysqli->prepare("
                SELECT category, percentage 
                FROM category_scores 
                WHERE assessment_id = ?
            ");
            $scoreStmt->bind_param('i', $assessmentId);
            $scoreStmt->execute();
            $scoreRes = $scoreStmt->get_result();
            $categoryScores = [
                'career'      => 0.0,
                'personality' => 0.0,
                'skills'      => 0.0,
                'strand'      => 0.0
            ];
            while ($sc = $scoreRes->fetch_assoc()) {
                $categoryScores[$sc['category']] = round((float)$sc['percentage'], 1);
            }
            $scoreStmt->close();

            // Fetch Top 5 Recommended Courses
            $recStmt = $mysqli->prepare("
                SELECT r.rank, r.match_percentage, r.explanation,
                       c.course_name, c.course_code,
                       COALESCE(cl.name, 'General') AS cluster_name
                FROM recommendations r
                JOIN courses c ON r.course_id = c.id
                LEFT JOIN clusters cl ON c.cluster_id = cl.id
                WHERE r.assessment_id = ?
                ORDER BY r.match_percentage DESC, r.rank ASC
                LIMIT 5
            ");
            $recStmt->bind_param('i', $assessmentId);
            $recStmt->execute();
            $recRes = $recStmt->get_result();
            $recommendations = [];
            while ($rc = $recRes->fetch_assoc()) {
                $rc['match_fmt'] = round((float)$rc['match_percentage'], 1) . '%';
                $recommendations[] = $rc;
            }
            $recStmt->close();

            $response = [
                'success'         => true,
                'student'         => $studentData,
                'category_scores' => $categoryScores,
                'recommendations' => $recommendations
            ];
            break;
    }

    echo json_encode($response);
    exit;
}

// ==================== PAGE DATA FETCHING (PHP LOAD) ====================

// Admin details & Notifications
$userName       = $_SESSION['admin_name'] ?? 'Admin User';
$adminId        = $_SESSION['admin_id']   ?? null;
$adminProfilePic = null;
$notifications  = [];
$unreadCount    = 0;

if ($adminId) {
    $profileStmt = $mysqli->prepare("SELECT profile_picture FROM admins WHERE id = ? LIMIT 1");
    $profileStmt->bind_param('i', $adminId);
    $profileStmt->execute();
    $adminData       = $profileStmt->get_result()->fetch_assoc();
    $adminProfilePic = $adminData['profile_picture'] ?? null;
    $profileStmt->close();

    $countStmt = $mysqli->prepare("SELECT COUNT(*) as count FROM notifications WHERE (user_id = ? OR user_id IS NULL) AND user_type = 'admin' AND is_read = 0");
    $countStmt->bind_param('i', $adminId);
    $countStmt->execute();
    $unreadCount = (int)($countStmt->get_result()->fetch_assoc()['count'] ?? 0);
    $countStmt->close();

    $notifStmt = $mysqli->prepare("SELECT * FROM notifications WHERE (user_id = ? OR user_id IS NULL) AND user_type = 'admin' ORDER BY created_at DESC LIMIT 10");
    $notifStmt->bind_param('i', $adminId);
    $notifStmt->execute();
    $notifResult = $notifStmt->get_result();
    while ($row = $notifResult->fetch_assoc()) {
        $notifications[] = $row;
    }
    $notifStmt->close();
}

// Filter options: Strands and School Years
$strandsRes = $mysqli->query("SELECT id, name, code FROM strands ORDER BY name ASC");
$strands    = [];
if ($strandsRes) { while ($s = $strandsRes->fetch_assoc()) { $strands[] = $s; } }

$schoolYearsRes = $mysqli->query("SELECT id, year_label FROM school_years ORDER BY year_label ASC");
$schoolYears    = [];
if ($schoolYearsRes) { while ($sy = $schoolYearsRes->fetch_assoc()) { $schoolYears[] = $sy; } }

// Overall analytics metrics for Tab 1 (Analytics Overview)
$totalAssessmentsResult = $mysqli->query("SELECT COUNT(*) as count FROM student_assessments WHERE status = 'completed'");
$totalAssessments       = (int)($totalAssessmentsResult->fetch_assoc()['count'] ?? 0);

$avgScoreResult = $mysqli->query("SELECT AVG(total_score) as avg_score FROM student_assessments WHERE status = 'completed'");
$avgScore       = round((float)($avgScoreResult->fetch_assoc()['avg_score'] ?? 0), 1);

$mostChosenClusterResult = $mysqli->query("
    SELECT cl.name, COUNT(*) as count
    FROM recommendations r
    LEFT JOIN courses c ON r.course_id = c.id
    LEFT JOIN clusters cl ON c.cluster_id = cl.id
    WHERE cl.name IS NOT NULL
    GROUP BY cl.id, cl.name
    ORDER BY count DESC
    LIMIT 1
");
$mostChosenCluster = $mostChosenClusterResult->fetch_assoc()['name'] ?? 'N/A';

$mostRecommendedCourseResult = $mysqli->query("
    SELECT c.course_name, COUNT(*) as count
    FROM recommendations r
    LEFT JOIN courses c ON r.course_id = c.id
    WHERE c.course_name IS NOT NULL
    GROUP BY c.id, c.course_name
    ORDER BY count DESC
    LIMIT 1
");
$mostRecommendedCourse = $mostRecommendedCourseResult->fetch_assoc()['course_name'] ?? 'N/A';

$stats = [
    'most_chosen_cluster'     => $mostChosenCluster,
    'avg_score'               => $avgScore . '%',
    'total_assessments'       => $totalAssessments,
    'most_recommended_course' => $mostRecommendedCourse
];

// Cluster distribution for Analytics tab pie
$clusterDistribution = [];
$clusterResult = $mysqli->query("
    SELECT s.name as strand_name, s.code, COUNT(DISTINCT sa.student_id) as count
    FROM student_assessments sa
    LEFT JOIN students st ON sa.student_id = st.id
    LEFT JOIN strands s ON st.strand_id = s.id
    WHERE sa.status = 'completed' AND s.id IS NOT NULL
    GROUP BY s.id, s.name, s.code
    ORDER BY count DESC
");

$totalStudents = (int)($mysqli->query("
    SELECT COUNT(DISTINCT sa.student_id) as count
    FROM student_assessments sa
    LEFT JOIN students st ON sa.student_id = st.id
    LEFT JOIN strands s ON st.strand_id = s.id
    WHERE sa.status = 'completed' AND s.id IS NOT NULL
")->fetch_assoc()['count'] ?? 0);

$clusterColors = ['#3b82f6', '#06b6d4', '#10b981', '#fbbf24', '#64748b', '#8b5cf6', '#ec4899', '#f97316'];
$colorIndex = 0;
$conicGradientParts = [];
$currentDeg = 0;

if ($clusterResult) {
    while ($row = $clusterResult->fetch_assoc()) {
        $percentage = $totalStudents > 0 ? round(($row['count'] / $totalStudents) * 100, 1) : 0;
        $degrees    = $totalStudents > 0 ? round(($row['count'] / $totalStudents) * 360) : 0;
        $endDeg     = $currentDeg + $degrees;
        $color      = $clusterColors[$colorIndex % count($clusterColors)];
        $conicGradientParts[] = "$color {$currentDeg}deg {$endDeg}deg";
        $clusterDistribution[$row['strand_name']] = [
            'percentage' => $percentage,
            'color'      => $color,
            'count'      => $row['count']
        ];
        $currentDeg = $endDeg;
        $colorIndex++;
    }
}
$conicGradient = !empty($conicGradientParts) ? implode(', ', $conicGradientParts) : '#e5e7eb 0deg 360deg';

// Monthly trend data for Tab 1
$trendsData = [];
$trendsQuery = $mysqli->query("
    SELECT DATE_FORMAT(completed_at, '%Y-%m') as month, COUNT(*) as count
    FROM student_assessments
    WHERE status = 'completed' AND completed_at IS NOT NULL
    GROUP BY month
    ORDER BY month ASC
");
if ($trendsQuery) {
    while ($row = $trendsQuery->fetch_assoc()) {
        $trendsData[] = [
            'month' => date('F Y', strtotime($row['month'] . '-01')),
            'count' => (int)$row['count']
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics — <?php echo htmlspecialchars(getSystemConfig('short_name')); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="admin.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body data-user-logged-in="true">
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

                <a href="reports.php" class="nav-item active">
                    <i class="fa-solid fa-chart-bar"></i>
                    <span>Reports</span>
                </a>
                <a href="activity_logs.php" class="nav-item">
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

        <!-- Main Content -->
        <main class="main-content">
            <!-- Top Bar -->
            <header class="top-bar">
                <button class="mobile-menu-toggle" id="mobileMenuToggle">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="page-title">
                    <h1>Reports & Analytics</h1>
                </div>
                
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

            <!-- Dashboard Content -->
            <div class="dashboard-content">
                
                <!-- Tab Switcher Navigation -->
                <div class="tabs-container" style="margin-bottom: 2rem; border-bottom: 1px solid var(--border-color, rgba(148,163,184,0.2));">
                    <div class="tabs" style="display: flex; gap: 2rem;">
                        <button class="tab-btn active" data-target="analytics-tab">
                            <i class="fa-solid fa-chart-pie"></i> Analytics Overview
                        </button>
                        <button class="tab-btn" data-target="records-tab">
                            <i class="fa-solid fa-folder-open"></i> Assessment Records
                        </button>
                    </div>
                </div>

                <!-- ==================== TAB 1: ANALYTICS OVERVIEW ==================== -->
                <div id="analytics-tab" class="tab-content active">
                    <div class="page-header reports-header">
                        <div class="export-wrapper">
                            <button class="btn-primary" id="exportPdfBtn">
                                <i class="fa-solid fa-file-pdf"></i> Export Analytics to PDF
                            </button>
                        </div>
                    </div>

                    <!-- Analytics Cards -->
                    <div class="stats-cards">
                        <div class="stat-card">
                            <div class="stat-icon"><i class="fa-solid fa-trophy"></i></div>
                            <div class="stat-content">
                                <h3>Most Chosen Cluster</h3>
                                <p class="stat-value" id="kpi-top-cluster" title="<?php echo htmlspecialchars($stats['most_chosen_cluster']); ?>"><?php echo htmlspecialchars($stats['most_chosen_cluster']); ?></p>
                                <small>Top career preference</small>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon"><i class="fa-solid fa-chart-line"></i></div>
                            <div class="stat-content">
                                <h3>Average Score</h3>
                                <p class="stat-value" id="kpi-avg-score"><?php echo htmlspecialchars($stats['avg_score']); ?></p>
                                <small>Assessment performance</small>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon"><i class="fa-solid fa-clipboard-check"></i></div>
                            <div class="stat-content">
                                <h3>Total Assessments</h3>
                                <p class="stat-value" id="kpi-total-assessments"><?php echo htmlspecialchars($stats['total_assessments']); ?></p>
                                <small>Completed evaluations</small>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon"><i class="fa-solid fa-graduation-cap"></i></div>
                            <div class="stat-content">
                                <h3>Top Course</h3>
                                <p class="stat-value" id="kpi-top-course" title="<?php echo htmlspecialchars($stats['most_recommended_course']); ?>"><?php echo htmlspecialchars($stats['most_recommended_course']); ?></p>
                                <small>Most recommended</small>
                            </div>
                        </div>
                    </div>

                    <!-- Analytics Charts -->
                    <div class="reports-charts">
                        <div class="chart-card">
                            <div class="chart-header">
                                <h3><i class="fa-solid fa-chart-pie"></i> Career Cluster Distribution</h3>
                            </div>
                            <div class="chart-body">
                                <div class="pie-chart-container">
                                    <div class="pie-chart" style="background: conic-gradient(<?php echo $conicGradient; ?>);"></div>
                                    <div class="pie-chart-center">
                                        <span class="total-number"><?php echo $totalStudents; ?></span>
                                        <span class="total-label">Students</span>
                                    </div>
                                </div>
                                <div class="chart-legend">
                                    <?php foreach ($clusterDistribution as $cluster => $data): ?>
                                    <div class="legend-item">
                                        <span class="legend-color" style="background-color: <?php echo $data['color']; ?>"></span>
                                        <span class="legend-label"><?php echo htmlspecialchars($cluster); ?></span>
                                        <span class="legend-percentage"><?php echo $data['percentage']; ?>%</span>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <div class="chart-card trends-card">
                            <div class="chart-header">
                                <h3><i class="fa-solid fa-chart-area"></i> Assessment Trends</h3>
                            </div>
                            <div class="chart-body">
                                <div class="trends-placeholder">
                                    <canvas id="assessmentTrendsChart" width="400" height="250"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ==================== TAB 2: HISTORICAL ASSESSMENT & STUDENT RECORDS ==================== -->
                <div id="records-tab" class="tab-content" style="display: none;">
                    
                    <!-- 4 Summary KPI Cards Grid -->
                    <div class="stats-cards reports-stats-cards-4">
                        <div class="stat-card">
                            <div class="stat-icon"><i class="fa-solid fa-user-graduate"></i></div>
                            <div class="stat-content">
                                <h3>Total Students Assessed</h3>
                                <p class="stat-value" id="kpi-total-students">0</p>
                                <small>Completed evaluation records</small>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon"><i class="fa-solid fa-clipboard-check"></i></div>
                            <div class="stat-content">
                                <h3>Completed Assessments</h3>
                                <p class="stat-value" id="kpi-total-completed">0</p>
                                <small>Evaluations finalized</small>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon"><i class="fa-solid fa-chart-line"></i></div>
                            <div class="stat-content">
                                <h3>Average Match Score</h3>
                                <p class="stat-value" id="kpi-avg-score-records">0%</p>
                                <small>Overall match average</small>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon"><i class="fa-solid fa-trophy"></i></div>
                            <div class="stat-content">
                                <h3>Top Course</h3>
                                <p class="stat-value" id="kpi-top-course-records" style="font-size: 1.1rem; line-height: 1.2;">N/A</p>
                                <small>Most recommended course</small>
                            </div>
                        </div>
                    </div>

                    <!-- Combined Filter and Export Bar -->
                    <div class="table-section" style="margin-bottom: 1.5rem;">
                        <div class="records-filter-bar">
                            <!-- Filter: School Year -->
                            <div class="oa-select-wrap">
                                <i class="fa-solid fa-calendar-days oa-select-icon"></i>
                                <select id="rec-school-year" class="oa-select">
                                    <option value="0">All School Years</option>
                                    <?php foreach ($schoolYears as $sy): ?>
                                    <option value="<?php echo (int)$sy['id']; ?>"><?php echo htmlspecialchars($sy['year_label']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Filter: Search -->
                            <div class="oa-select-wrap" style="width: 250px;">
                                <i class="fa-solid fa-search oa-select-icon"></i>
                                <input type="text" id="rec-search" class="oa-select" placeholder="Search name or ID..." style="padding-left: 2.2rem; width: 100%;">
                            </div>

                            <!-- Filter: Strand -->
                            <div class="oa-select-wrap">
                                <i class="fa-solid fa-filter oa-select-icon"></i>
                                <select id="rec-strand" class="oa-select">
                                    <option value="0">All Strands</option>
                                    <?php foreach ($strands as $st): ?>
                                    <option value="<?php echo (int)$st['id']; ?>"><?php echo htmlspecialchars($st['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Reset Button -->
                            <button id="rec-reset-btn" class="oa-action-btn oa-clear-btn" title="Reset All Filters">
                                <i class="fa-solid fa-rotate-left"></i> Reset
                            </button>

                            <!-- Export Button -->
                            <div style="margin-left: auto; display: flex; align-items: center;">
                                <button class="oa-action-btn oa-search-btn" id="btnExportAllPdf" title="Export assessment records to PDF">
                                    <i class="fa-solid fa-file-pdf"></i> Export PDF
                                </button>
                            </div>
                        </div>
                    </div>



                    <!-- 2 Interactive Charts Grid (Side-by-Side with Auto-Hide) -->
                    <div class="records-charts-grid" id="recordsChartsGrid">
                        <!-- Chart 1: Most Recommended Courses -->
                        <div class="chart-card" id="chartTopCoursesCard">
                            <div class="chart-header">
                                <h3><i class="fa-solid fa-chart-pie"></i> Most Recommended Courses</h3>
                            </div>
                            <div class="chart-body" style="height: 260px; position: relative;">
                                <canvas id="chartTopCourses"></canvas>
                            </div>
                        </div>

                        <!-- Chart 2: Strand Distribution -->
                        <div class="chart-card" id="chartStrandDistCard">
                            <div class="chart-header">
                                <h3><i class="fa-solid fa-chart-donut"></i> Strand Distribution</h3>
                            </div>
                            <div class="chart-body" style="height: 260px; position: relative;">
                                <canvas id="chartStrandDist"></canvas>
                            </div>
                        </div>
                    </div>

                </div> <!-- End Records Tab -->

            </div>
        <?php include 'includes/app_footer.php'; ?>
        </main>
    </div>

    <!-- ==================== STUDENT DETAILS MODAL ==================== -->
    <div class="modal-overlay" id="studentDetailsModal" style="display: none;">
        <div class="modal-container student-details-modal">
            <div class="modal-header">
                <div class="modal-header-info">
                    <h2 id="modalStudentName">Student Name</h2>
                    <span class="modal-subtitle" id="modalStudentMeta">LRN: 123456789 | Strand: STEM | Grade 12 (2025-2026)</span>
                </div>
                <button class="modal-close" id="modalCloseBtn">&times;</button>
            </div>
            
            <div class="modal-body">
                <!-- Status & Date Header -->
                <div class="modal-status-bar">
                    <div>
                        <span class="modal-label">Assessment Date:</span>
                        <strong id="modalAssessmentDate">Jul 23, 2026</strong>
                    </div>
                    <div>
                        <span class="modal-label">Status:</span>
                        <span class="status completed" id="modalAssessmentStatus">Completed</span>
                    </div>
                    <div>
                        <span class="modal-label">Overall Match Score:</span>
                        <span class="modal-match-badge" id="modalOverallMatch">0%</span>
                    </div>
                </div>

                <!-- 4 Category Scores Section -->
                <h3 class="modal-section-title"><i class="fa-solid fa-sliders"></i> Category Scores Breakdown</h3>
                <div class="modal-scores-grid">
                    <div class="score-card">
                        <div class="score-card-header">
                            <span><i class="fa-solid fa-compass" style="color: #3b82f6;"></i> Career Interest Score</span>
                            <strong id="scoreCareer">0%</strong>
                        </div>
                        <div class="score-bar-bg"><div class="score-bar-fill" id="barCareer" style="width: 0%; background: #3b82f6;"></div></div>
                    </div>

                    <div class="score-card">
                        <div class="score-card-header">
                            <span><i class="fa-solid fa-user-gear" style="color: #a855f7;"></i> Personality Score</span>
                            <strong id="scorePersonality">0%</strong>
                        </div>
                        <div class="score-bar-bg"><div class="score-bar-fill" id="barPersonality" style="width: 0%; background: #a855f7;"></div></div>
                    </div>

                    <div class="score-card">
                        <div class="score-card-header">
                            <span><i class="fa-solid fa-lightbulb" style="color: #10b981;"></i> Skills Score</span>
                            <strong id="scoreSkills">0%</strong>
                        </div>
                        <div class="score-bar-bg"><div class="score-bar-fill" id="barSkills" style="width: 0%; background: #10b981;"></div></div>
                    </div>

                    <div class="score-card">
                        <div class="score-card-header">
                            <span><i class="fa-solid fa-graduation-cap" style="color: #fbbf24;"></i> Strand Score</span>
                            <strong id="scoreStrand">0%</strong>
                        </div>
                        <div class="score-bar-bg"><div class="score-bar-fill" id="barStrand" style="width: 0%; background: #fbbf24;"></div></div>
                    </div>
                </div>

                <!-- Top 5 Recommended Courses Section -->
                <h3 class="modal-section-title" style="margin-top: 1.5rem;"><i class="fa-solid fa-trophy"></i> Top 5 Recommended Courses</h3>
                <div class="table-responsive">
                    <table class="data-table modal-rec-table">
                        <thead>
                            <tr>
                                <th style="width: 60px;">Rank</th>
                                <th>Recommended Course</th>
                                <th>Cluster</th>
                                <th>Match %</th>
                                <th>Recommendation Insight</th>
                            </tr>
                        </thead>
                        <tbody id="modalRecTbody">
                            <tr><td colspan="5" style="text-align: center;">Loading recommendations…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="modal-footer">
                <a href="#" id="modalPdfLink" target="_blank" class="oa-action-btn oa-search-btn">
                    <i class="fa-solid fa-file-pdf"></i> Download Assessment PDF
                </a>
                <button class="oa-action-btn oa-clear-btn" id="modalDismissBtn">Close</button>
            </div>
        </div>
    </div>

    <script src="script.js"></script>
    <script src="admin.js"></script>
    <script>
    // ==================== REPORTS & HISTORICAL RECORDS MODULE ====================

    document.addEventListener('DOMContentLoaded', function() {

        // ---- Tab Switcher Logic ----
        const tabBtns = document.querySelectorAll('.tab-btn');
        tabBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.style.display = 'none');
                
                btn.classList.add('active');
                const targetId = btn.getAttribute('data-target');
                const targetEl = document.getElementById(targetId);
                if (targetEl) targetEl.style.display = 'block';

                if (targetId === 'records-tab' && !recState.hasLoaded) {
                    recFetchRecords();
                }
            });
        });

        // Export Analytics PDF
        const exportPdfBtn = document.getElementById('exportPdfBtn');
        if (exportPdfBtn) {
            exportPdfBtn.addEventListener('click', function() {
                window.location.href = 'api/export_pdf.php?type=reports';
            });
        }

        // Tab 1: Assessment Trends Chart
        const trendsCtx = document.getElementById('assessmentTrendsChart');
        if (trendsCtx) {
            const trendsData = <?php echo json_encode($trendsData); ?>;
            const labels = trendsData.map(item => item.month);
            const data   = trendsData.map(item => item.count);

            new Chart(trendsCtx, {
                type: 'line',
                data: {
                    labels: labels.length > 0 ? labels : ['No Data'],
                    datasets: [{
                        label: 'Assessments Completed',
                        data: data.length > 0 ? data : [0],
                        borderColor: '#38bdf8',
                        backgroundColor: 'rgba(56, 189, 248, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { beginAtZero: true, ticks: { stepSize: 1, color: '#94a3b8' }, grid: { color: 'rgba(148, 163, 184, 0.1)' } },
                        x: { ticks: { color: '#94a3b8' }, grid: { display: false } }
                    }
                }
            });
        }

        // ==================== TAB 2: RECORDS STATE & FETCHING ====================

        let recState = {
            strand_id:     0,
            school_year_id:0,
            search_term:   '',
            sort_by:       'completed_at',
            sort_order:    'DESC',
            page:          1,
            hasLoaded:     false,
            isLoading:     false
        };

        // Chart instances
        let chartCoursesInst= null;
        let chartStrandInst = null;

        async function recFetchRecords() {
            if (recState.isLoading) return;
            recState.isLoading = true;

            const overlay = document.getElementById('rec-loading-overlay');
            if (overlay) overlay.style.display = 'flex';

            try {
                const fd = new FormData();
                fd.append('action',         'get_historical_records');
                fd.append('strand_id',      recState.strand_id);
                fd.append('school_year_id', recState.school_year_id);
                fd.append('search_term',    recState.search_term);
                fd.append('sort_by',        recState.sort_by);
                fd.append('sort_order',     recState.sort_order);
                fd.append('page',           recState.page);

                const res  = await fetch('reports.php', { method: 'POST', body: fd });
                const data = await res.json();

                if (data.success) {
                    recState.hasLoaded = true;
                    recRenderStats(data.summary_stats);
                    recRenderTable(data.records, data.total_records, data.page, data.total_pages);
                    recRenderPagination(data.page, data.total_pages, data.total_records);
                    recRenderCharts(data.charts);
                }
            } catch (e) {
                console.error('Historical records fetch error:', e);
            }

            recState.isLoading = false;
            if (overlay) overlay.style.display = 'none';
        }

        // Render 4 Summary Cards
        function recRenderStats(stats) {
            if (!stats) return;
            document.getElementById('kpi-total-students').textContent  = stats.total_students.toLocaleString();
            document.getElementById('kpi-total-completed').textContent = stats.total_completed.toLocaleString();
            document.getElementById('kpi-avg-score-records').textContent       = stats.avg_score;
            document.getElementById('kpi-top-course-records').textContent      = stats.most_recommended_course || 'N/A';
        }

        // Render Table Rows (7 Columns)
        function recRenderTable(records, total, page, totalPages) {
            const tbody = document.getElementById('rec-tbody');
            if (!tbody) return;
            if (!records || records.length === 0) {
                tbody.innerHTML = `<tr><td colspan="7" class="empty-cell" style="text-align: center; padding: 3rem 1rem;">
                    <p style="margin: 0; font-size: 0.95rem; font-weight: 500; color: #94a3b8;">No matching historical assessment records found.</p>
                    <small style="color: #64748b; font-size: 0.8rem; display: block; margin-top: 0.3rem;">Try adjusting your dropdown filters.</small>
                </td></tr>`;
                return;
            }

            let html = '';
            records.forEach(r => {
                html += `
                    <tr>
                        <td style="font-weight: 600; color: #ffffff;">${esc(r.student_name)}</td>
                        <td><span class="status-badge current" style="background: rgba(59, 130, 246, 0.15); color: #3b82f6;">${esc(r.school_id)}</span></td>
                        <td>${esc(r.strand_code ? r.strand_code + ' - ' + r.strand_name : r.strand_name)}</td>
                        <td>${esc(r.formatted_date)}</td>
                        <td><strong style="color: #fbbf24;">${esc(r.total_score_fmt)}</strong></td>
                        <td>${esc(r.top_course || 'N/A')}</td>
                        <td style="text-align: center;">
                            <button class="oa-action-btn oa-search-btn btn-view-detail" data-id="${r.assessment_id}" title="View Complete Assessment">
                                <i class="fa-solid fa-eye"></i> View
                            </button>
                        </td>
                    </tr>`;
            });
            tbody.innerHTML = html;

            // Bind click events to View buttons
            tbody.querySelectorAll('.btn-view-detail').forEach(btn => {
                btn.addEventListener('click', function() {
                    const id = this.getAttribute('data-id');
                    openStudentModal(id);
                });
            });
        }

        // Render Pagination Controls
        function recRenderPagination(page, totalPages, total) {
            const container = document.getElementById('rec-pagination');
            if (!container) return;
            if (totalPages <= 1 && total === 0) { container.innerHTML = ''; return; }

            const perPage = 10;
            const from    = total > 0 ? (page - 1) * perPage + 1 : 0;
            const to      = Math.min(page * perPage, total);

            let btns = `<span class="oa-page-info">Showing ${from}–${to} of ${total} records</span>`;

            btns += `<button class="oa-page-btn" ${page === 1 ? 'disabled' : ''} onclick="recGoTo(${page - 1})">
                        <i class="fa-solid fa-chevron-left"></i>
                     </button>`;

            const delta = 2;
            for (let p = 1; p <= totalPages; p++) {
                if (p === 1 || p === totalPages || (p >= page - delta && p <= page + delta)) {
                    btns += `<button class="oa-page-btn${p === page ? ' active' : ''}" onclick="recGoTo(${p})">${p}</button>`;
                } else if (p === page - delta - 1 || p === page + delta + 1) {
                    btns += `<span class="oa-page-ellipsis">…</span>`;
                }
            }

            btns += `<button class="oa-page-btn" ${page === totalPages ? 'disabled' : ''} onclick="recGoTo(${page + 1})">
                        <i class="fa-solid fa-chevron-right"></i>
                     </button>`;

            container.innerHTML = btns;
        }

        window.recGoTo = function(p) {
            recState.page = p;
            recFetchRecords();
        };

        // Render 2 Charts (Auto-hide cards when no data)
        function recRenderCharts(charts) {
            if (!charts) return;

            const cardCourses = document.getElementById('chartTopCoursesCard');
            const cardStrand  = document.getElementById('chartStrandDistCard');
            const grid        = document.getElementById('recordsChartsGrid');

            const hasCoursesData = charts.top_courses && charts.top_courses.data && charts.top_courses.data.length > 0 && charts.top_courses.data.some(val => val > 0);
            const hasStrandData  = charts.strands && charts.strands.data && charts.strands.data.length > 0 && charts.strands.data.some(val => val > 0);

            if (cardCourses) cardCourses.style.display = hasCoursesData ? 'block' : 'none';
            if (cardStrand)  cardStrand.style.display  = hasStrandData ? 'block' : 'none';

            if (grid) {
                if (hasCoursesData && hasStrandData) {
                    grid.style.display = 'grid';
                    grid.style.gridTemplateColumns = 'repeat(2, 1fr)';
                } else if (hasCoursesData || hasStrandData) {
                    grid.style.display = 'grid';
                    grid.style.gridTemplateColumns = '1fr';
                } else {
                    grid.style.display = 'none';
                }
            }

            // 1. Most Recommended Courses (Pie Chart)
            if (hasCoursesData) {
                const ctxCourses = document.getElementById('chartTopCourses');
                if (ctxCourses) {
                    if (chartCoursesInst) chartCoursesInst.destroy();
                    chartCoursesInst = new Chart(ctxCourses, {
                        type: 'pie',
                        data: {
                            labels: charts.top_courses.labels,
                            datasets: [{
                                data: charts.top_courses.data,
                                backgroundColor: ['#fbbf24', '#3b82f6', '#10b981', '#a855f7', '#ec4899']
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: { legend: { position: 'right', labels: { color: '#94a3b8', boxWidth: 12 } } }
                        }
                    });
                }
            }

            // 2. Strand Distribution (Doughnut Chart)
            if (hasStrandData) {
                const ctxStrand = document.getElementById('chartStrandDist');
                if (ctxStrand) {
                    if (chartStrandInst) chartStrandInst.destroy();
                    chartStrandInst = new Chart(ctxStrand, {
                        type: 'doughnut',
                        data: {
                            labels: charts.strands.labels,
                            datasets: [{
                                data: charts.strands.data,
                                backgroundColor: ['#06b6d4', '#10b981', '#fbbf24', '#64748b', '#ec4899', '#8b5cf6']
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: { legend: { position: 'right', labels: { color: '#94a3b8', boxWidth: 12 } } }
                        }
                    });
                }
            }
        }

        // ---- Filter Event Listeners ----
        ['rec-school-year', 'rec-strand'].forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                el.addEventListener('change', function() {
                    recState.school_year_id = parseInt(document.getElementById('rec-school-year').value, 10);
                    recState.strand_id      = parseInt(document.getElementById('rec-strand').value, 10);
                    recState.page           = 1;
                    recFetchRecords();
                });
            }
        });

        let recSearchTimeout = null;
        const searchInput = document.getElementById('rec-search');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                clearTimeout(recSearchTimeout);
                recSearchTimeout = setTimeout(() => {
                    recState.search_term = this.value;
                    recState.page = 1;
                    recFetchRecords();
                }, 300);
            });
        }

        document.getElementById('rec-reset-btn').addEventListener('click', function() {
            document.getElementById('rec-school-year').value = '0';
            document.getElementById('rec-strand').value      = '0';
            if (document.getElementById('rec-search')) document.getElementById('rec-search').value = '';

            recState.strand_id     = 0;
            recState.school_year_id= 0;
            recState.search_term   = '';
            recState.sort_by       = 'completed_at';
            recState.sort_order    = 'DESC';
            recState.page          = 1;

            recFetchRecords();
        });

        // Column Sort Listeners
        document.querySelectorAll('#historical-records-table th.sortable').forEach(th => {
            th.addEventListener('click', function() {
                const sortKey = this.getAttribute('data-sort');
                if (recState.sort_by === sortKey) {
                    recState.sort_order = recState.sort_order === 'ASC' ? 'DESC' : 'ASC';
                } else {
                    recState.sort_by    = sortKey;
                    recState.sort_order = 'ASC';
                }
                recFetchRecords();
            });
        });

        // Export PDF Handler
        document.getElementById('btnExportAllPdf').addEventListener('click', function() {
            window.location.href = 'api/export_pdf.php?type=assessment_results';
        });

        // ==================== STUDENT DETAILS MODAL ====================

        const modal        = document.getElementById('studentDetailsModal');
        const modalCloseBtn= document.getElementById('modalCloseBtn');
        const modalDismiss = document.getElementById('modalDismissBtn');

        function closeModal() {
            if (modal) modal.style.display = 'none';
        }

        if (modalCloseBtn) modalCloseBtn.addEventListener('click', closeModal);
        if (modalDismiss)  modalDismiss.addEventListener('click', closeModal);
        if (modal) {
            modal.addEventListener('click', function(e) {
                if (e.target === modal) closeModal();
            });
        }

        async function openStudentModal(assessmentId) {
            if (!modal) return;
            modal.style.display = 'flex';

            // Reset contents
            document.getElementById('modalStudentName').textContent = 'Loading Student Details…';
            document.getElementById('modalStudentMeta').textContent = 'Fetching assessment records…';
            document.getElementById('modalAssessmentDate').textContent = '—';
            document.getElementById('modalOverallMatch').textContent = '0%';
            document.getElementById('modalRecTbody').innerHTML = '<tr><td colspan="5" class="empty-cell"><i class="fa-solid fa-circle-notch fa-spin"></i> Fetching student assessment details…</td></tr>';

            try {
                const fd = new FormData();
                fd.append('action', 'get_student_details');
                fd.append('assessment_id', assessmentId);

                const res  = await fetch('reports.php', { method: 'POST', body: fd });
                const data = await res.json();

                if (data.success) {
                    const st = data.student;
                    const sc = data.category_scores;
                    const rc = data.recommendations;

                    document.getElementById('modalStudentName').textContent = st.student_name;
                    document.getElementById('modalStudentMeta').textContent = `LRN/ID: ${st.school_id} | Strand: ${st.strand_code} (${st.strand_name}) | ${st.grade_level || 'N/A'} (${st.school_year})`;
                    document.getElementById('modalAssessmentDate').textContent = st.completed_at ? new Date(st.completed_at).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) : 'N/A';
                    document.getElementById('modalOverallMatch').textContent = roundVal(st.total_score) + '%';
                    document.getElementById('modalPdfLink').href = `download_results_pdf.php?id=${st.assessment_id}`;

                    // Set scores
                    setModalScore('Career', sc.career);
                    setModalScore('Personality', sc.personality);
                    setModalScore('Skills', sc.skills);
                    setModalScore('Strand', sc.strand);

                    // Set recommendations table
                    const recTbody = document.getElementById('modalRecTbody');
                    if (!rc || rc.length === 0) {
                        recTbody.innerHTML = '<tr><td colspan="5" class="empty-cell">No course recommendations generated.</td></tr>';
                    } else {
                        let html = '';
                        rc.forEach((r, idx) => {
                            html += `
                                <tr>
                                    <td style="font-weight: 700; color: #fbbf24; text-align: center;">#${idx + 1}</td>
                                    <td style="font-weight: 600; color: #ffffff;">${esc(r.course_name)} <span style="font-size: .75rem; color: #94a3b8;">(${esc(r.course_code)})</span></td>
                                    <td>${esc(r.cluster_name)}</td>
                                    <td><strong style="color: #10b981;">${esc(r.match_fmt)}</strong></td>
                                    <td style="font-size: .85rem; color: #cbd5e1; max-width: 250px;">${esc(r.explanation || 'Directly aligned with user profile & skills assessment.')}</td>
                                </tr>`;
                        });
                        recTbody.innerHTML = html;
                    }
                }
            } catch (e) {
                console.error('Modal error:', e);
            }
        }

        function setModalScore(name, val) {
            const v = roundVal(val);
            document.getElementById('score' + name).textContent = v + '%';
            document.getElementById('bar' + name).style.width   = v + '%';
        }

        function roundVal(v) {
            return Math.round(parseFloat(v || 0) * 10) / 10;
        }

        function esc(text) {
            if (!text) return '';
            const d = document.createElement('div');
            d.textContent = text;
            return d.innerHTML;
        }

    });

    // Notification dropdown handlers
    document.getElementById('notificationBtn').addEventListener('click', function(e) {
        e.stopPropagation();
        const dropdown = document.getElementById('notificationDropdown');
        dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
    });
    document.addEventListener('click', function() {
        document.getElementById('notificationDropdown').style.display = 'none';
    });
    document.getElementById('notificationDropdown').addEventListener('click', function(e) {
        e.stopPropagation();
    });

    function markAllRead(e) {
        e.preventDefault(); e.stopPropagation();
        fetch('api/notifications.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=mark_all_read'
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.querySelectorAll('.notification-item.unread').forEach(item => item.classList.remove('unread'));
                document.getElementById('notificationBadge').textContent = '0';
                document.querySelector('.mark-all-read')?.remove();
            }
        });
    }
    </script>
    <?php require_once __DIR__ . '/includes/session_timeout_footer.php'; ?>
</body>
</html>
