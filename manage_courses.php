<?php
// Manage Courses - Backend Added

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include shared system configuration
require_once 'config.php';
require_once 'system_config.php';
require_once 'includes/audit.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) || !isset($_SESSION['is_admin'])) {
    header('Location: admin_login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];
    
    $action = $_POST['action'];
    $userId = $_SESSION['admin_id'] ?? $_SESSION['counselor_id'] ?? 0;
    $userType = isset($_SESSION['admin_id']) ? 'admin' : 'counselor';
    
    switch ($action) {
        case 'add_course':
            $courseName = trim($_POST['course_name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $clusterId = (int)($_POST['cluster_id'] ?? 0);
            $possibleCareers = trim($_POST['possible_careers'] ?? '');
            $selectedJobIds = array_map('intval', (array)($_POST['job_ids'] ?? []));
            
            if (empty($courseName) || empty($description)) {
                $response['message'] = 'Course name and description are required';
                echo json_encode($response);
                exit;
            }
            
            $hTraits = isset($_POST['holland_traits']) && is_array($_POST['holland_traits']) ? implode(',', $_POST['holland_traits']) : '';
            $bTraits = isset($_POST['bigfive_traits']) && is_array($_POST['bigfive_traits']) ? implode(',', $_POST['bigfive_traits']) : '';
            $sStrands = isset($_POST['shs_strands']) && is_array($_POST['shs_strands']) ? implode(',', $_POST['shs_strands']) : '';

            $stmt = $mysqli->prepare("
                INSERT INTO courses (course_name, description, cluster_id, possible_careers, holland_traits, bigfive_traits, shs_strands, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->bind_param('ssissss', $courseName, $description, $clusterId, $possibleCareers, $hTraits, $bTraits, $sStrands);
            
            if ($stmt->execute()) {
                $newCourseId = $mysqli->insert_id;
                // Sync course_jobs
                if (!empty($selectedJobIds)) {
                    $insJob = $mysqli->prepare("INSERT IGNORE INTO course_jobs (course_id, job_id) VALUES (?, ?)");
                    foreach ($selectedJobIds as $jid) {
                        if ($jid > 0) { 
                            $insJob->bind_param('ii', $newCourseId, $jid); 
                            $insJob->execute(); 
                            
                            // Fetch job title
                            $jTitle = '';
                            $jQ = $mysqli->query("SELECT job_title FROM jobs WHERE id = {$jid}");
                            if ($jQ && ($jR = $jQ->fetch_assoc())) { $jTitle = $jR['job_title']; }
                            log_activity($userId, $userType, 'Link Job to Course', 'course_jobs', null, "Admin linked job {$jTitle} to {$courseName}", null, json_encode(['course_name' => $courseName, 'job_title' => $jTitle, 'job_id' => $jid]));
                        }
                    }
                    $insJob->close();
                }

                // Sync course_schools
                $selectedSchoolIds = array_map('intval', (array)($_POST['school_ids'] ?? []));
                if (!empty($selectedSchoolIds)) {
                    $insSchool = $mysqli->prepare("INSERT IGNORE INTO course_schools (course_id, school_id, is_specialization, notes) VALUES (?, ?, ?, '')");
                    foreach ($selectedSchoolIds as $sid) {
                        if ($sid > 0) {
                            $isSpec = isset($_POST['is_specialization_' . $sid]) ? 1 : 0;
                            $insSchool->bind_param('iii', $newCourseId, $sid, $isSpec);
                            $insSchool->execute();
                            
                            // Fetch school name
                            $sName = '';
                            $sQ = $mysqli->query("SELECT name FROM schools WHERE id = {$sid}");
                            if ($sQ && ($sR = $sQ->fetch_assoc())) { $sName = $sR['name']; }
                            $linkAction = $isSpec ? "marked {$sName} as specialization for" : "linked {$sName} to";
                            log_activity($userId, $userType, 'Link School to Course', 'course_schools', null, "Admin {$linkAction} {$courseName}", null, json_encode(['course_name' => $courseName, 'school_name' => $sName, 'school_id' => $sid, 'is_specialization' => $isSpec]));
                        }
                    }
                    $insSchool->close();
                }
                
                $clusterName = 'Unknown';
                if ($clusterId > 0) {
                    $cQ = $mysqli->query("SELECT name FROM clusters WHERE id = {$clusterId}");
                    if ($cQ && ($cR = $cQ->fetch_assoc())) {
                        $clusterName = $cR['name'];
                    }
                }
                
                $descriptionText = "Admin added {$courseName} under {$clusterName} cluster";
                log_activity($userId, $userType, 'Added Course', 'courses', $newCourseId, $descriptionText, null, json_encode(['course_name' => $courseName, 'description' => $description, 'cluster_id' => $clusterId]));

                $response['success'] = true;
                $response['message'] = 'Course added successfully';
                $response['id'] = $newCourseId;
            } else {
                $response['message'] = 'Failed to add course: ' . $stmt->error;
            }
            $stmt->close();
            echo json_encode($response);
            exit;
            
        case 'get_course':
            $courseId = (int)($_POST['id'] ?? 0);
            if ($courseId <= 0) {
                $response['message'] = 'Invalid course ID';
                echo json_encode($response);
                exit;
            }
            
            $stmt = $mysqli->prepare("SELECT * FROM courses WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $courseId);
            $stmt->execute();
            $result = $stmt->get_result();
            $course = $result->fetch_assoc();
            $stmt->close();
            
            if ($course) {
                $response['success'] = true;
                $response['course'] = $course;
            } else {
                $response['message'] = 'Course not found';
            }
            echo json_encode($response);
            exit;

        case 'get_course_details':
            $courseId = (int)($_POST['id'] ?? 0);
            if ($courseId <= 0) {
                $response['message'] = 'Invalid course ID';
                echo json_encode($response);
                exit;
            }
            
            $stmt = $mysqli->prepare("
                SELECT c.*, s.name as strand_name, s.code as strand_code, cl.name as cluster_name
                FROM courses c
                LEFT JOIN strands s ON c.strand_id = s.id
                LEFT JOIN clusters cl ON c.cluster_id = cl.id
                WHERE c.id = ? LIMIT 1
            ");
            $stmt->bind_param('i', $courseId);
            $stmt->execute();
            $course = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if ($course) {
                // Fetch linked jobs
                $jobStmt = $mysqli->prepare("
                    SELECT j.job_title 
                    FROM course_jobs cj
                    JOIN jobs j ON cj.job_id = j.id
                    WHERE cj.course_id = ?
                    ORDER BY j.job_title
                ");
                $jobStmt->bind_param('i', $courseId);
                $jobStmt->execute();
                $jobRes = $jobStmt->get_result();
                $jobs = [];
                while ($jRow = $jobRes->fetch_assoc()) {
                    $jobs[] = $jRow['job_title'];
                }
                $jobStmt->close();
                
                // Fetch linked schools
                $schoolStmt = $mysqli->prepare("
                    SELECT s.name, MAX(cs.is_specialization) AS is_specialization
                    FROM course_schools cs
                    JOIN schools s ON cs.school_id = s.id
                    WHERE cs.course_id = ?
                    GROUP BY s.id, s.name
                    ORDER BY MAX(cs.is_specialization) DESC, s.name
                ");
                $schoolStmt->bind_param('i', $courseId);
                $schoolStmt->execute();
                $schoolRes = $schoolStmt->get_result();
                $schools = [];
                while ($sRow = $schoolRes->fetch_assoc()) {
                    $schools[] = [
                        'name' => $sRow['name'],
                        'is_specialization' => (int)$sRow['is_specialization']
                    ];
                }
                $schoolStmt->close();
                
                $response['success'] = true;
                $response['course'] = $course;
                $response['jobs'] = $jobs;
                $response['schools'] = $schools;
            } else {
                $response['message'] = 'Course not found';
            }
            echo json_encode($response);
            exit;
            
        case 'edit_course':
            $courseId = (int)($_POST['id'] ?? 0);
            $courseName = trim($_POST['course_name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $clusterId = (int)($_POST['cluster_id'] ?? 0);
            $possibleCareers = trim($_POST['possible_careers'] ?? '');
            $selectedJobIds = array_map('intval', (array)($_POST['job_ids'] ?? []));
            
            if ($courseId <= 0 || empty($courseName) || empty($description)) {
                $response['message'] = 'Invalid data provided';
                echo json_encode($response);
                exit;
            }
            
            // Fetch old course row
            $oldCourse = null;
            $cQuery = $mysqli->prepare("SELECT * FROM courses WHERE id = ?");
            if ($cQuery) {
                $cQuery->bind_param('i', $courseId);
                $cQuery->execute();
                $oldCourse = $cQuery->get_result()->fetch_assoc();
                $cQuery->close();
            }

            // Fetch old linked job IDs
            $oldJobIds = [];
            $jobQuery = $mysqli->prepare("SELECT job_id FROM course_jobs WHERE course_id = ?");
            if ($jobQuery) {
                $jobQuery->bind_param('i', $courseId);
                $jobQuery->execute();
                $jobRes = $jobQuery->get_result();
                while ($row = $jobRes->fetch_assoc()) {
                    $oldJobIds[] = (int)$row['job_id'];
                }
                $jobQuery->close();
            }

            // Fetch old linked schools
            $oldSchoolLinks = [];
            $schQuery = $mysqli->prepare("SELECT school_id, MAX(is_specialization) AS is_specialization FROM course_schools WHERE course_id = ? GROUP BY school_id");
            if ($schQuery) {
                $schQuery->bind_param('i', $courseId);
                $schQuery->execute();
                $schRes = $schQuery->get_result();
                while ($row = $schRes->fetch_assoc()) {
                    $oldSchoolLinks[(int)$row['school_id']] = (int)$row['is_specialization'];
                }
                $schQuery->close();
            }

            $hTraits = isset($_POST['holland_traits']) && is_array($_POST['holland_traits']) ? implode(',', $_POST['holland_traits']) : '';
            $bTraits = isset($_POST['bigfive_traits']) && is_array($_POST['bigfive_traits']) ? implode(',', $_POST['bigfive_traits']) : '';
            $sStrands = isset($_POST['shs_strands']) && is_array($_POST['shs_strands']) ? implode(',', $_POST['shs_strands']) : '';

            $stmt = $mysqli->prepare("
                UPDATE courses SET course_name = ?, description = ?, cluster_id = ?, 
                possible_careers = ?, holland_traits = ?, bigfive_traits = ?, shs_strands = ?
                WHERE id = ?
            ");
            $stmt->bind_param('ssissssi', $courseName, $description, $clusterId, $possibleCareers, $hTraits, $bTraits, $sStrands, $courseId);
            
            if ($stmt->execute()) {
                // Sync course_jobs: delete all then re-insert selected
                $delJobs = $mysqli->prepare("DELETE FROM course_jobs WHERE course_id = ?");
                $delJobs->bind_param('i', $courseId);
                $delJobs->execute();
                $delJobs->close();
                if (!empty($selectedJobIds)) {
                    $insJob = $mysqli->prepare("INSERT IGNORE INTO course_jobs (course_id, job_id) VALUES (?, ?)");
                    foreach ($selectedJobIds as $jid) {
                        if ($jid > 0) { $insJob->bind_param('ii', $courseId, $jid); $insJob->execute(); }
                    }
                    $insJob->close();
                }

                // Sync course_schools: delete all then re-insert selected
                $selectedSchoolIds = array_map('intval', (array)($_POST['school_ids'] ?? []));
                $delSchools = $mysqli->prepare("DELETE FROM course_schools WHERE course_id = ?");
                $delSchools->bind_param('i', $courseId);
                $delSchools->execute();
                $delSchools->close();
                if (!empty($selectedSchoolIds)) {
                    $insSchool = $mysqli->prepare("INSERT IGNORE INTO course_schools (course_id, school_id, is_specialization, notes) VALUES (?, ?, ?, '')");
                    foreach ($selectedSchoolIds as $sid) {
                        if ($sid > 0) {
                            $isSpec = isset($_POST['is_specialization_' . $sid]) ? 1 : 0;
                            $insSchool->bind_param('iii', $courseId, $sid, $isSpec);
                            $insSchool->execute();
                            
                            // Log school link/specialization edits
                            $sName = '';
                            $sQ = $mysqli->query("SELECT name FROM schools WHERE id = {$sid}");
                            if ($sQ && ($sR = $sQ->fetch_assoc())) { $sName = $sR['name']; }
                            
                            $wasLinked = isset($oldSchoolLinks[$sid]);
                            $oldSpec = $wasLinked ? $oldSchoolLinks[$sid] : null;
                            
                            if (!$wasLinked) {
                                $linkAction = $isSpec ? "marked {$sName} as specialization for" : "linked {$sName} to";
                                log_activity($userId, $userType, 'Link School to Course', 'course_schools', null, "Admin {$linkAction} " . ($newCourse['course_name'] ?? $courseName), null, json_encode(['course_name' => ($newCourse['course_name'] ?? $courseName), 'school_name' => $sName, 'school_id' => $sid, 'is_specialization' => $isSpec]));
                            } elseif ($oldSpec !== $isSpec) {
                                $linkAction = $isSpec ? "marked {$sName} as specialization for" : "removed specialization tag of {$sName} from";
                                log_activity($userId, $userType, 'Update School Specialization', 'course_schools', null, "Admin {$linkAction} " . ($newCourse['course_name'] ?? $courseName), json_encode(['course_name' => ($newCourse['course_name'] ?? $courseName), 'school_name' => $sName, 'school_id' => $sid, 'is_specialization' => $oldSpec]), json_encode(['course_name' => ($newCourse['course_name'] ?? $courseName), 'school_name' => $sName, 'school_id' => $sid, 'is_specialization' => $isSpec]));
                            }
                        }
                    }
                    $insSchool->close();
                }
                
                // Log unlinking of schools that are no longer selected
                foreach ($oldSchoolLinks as $sid => $oldSpec) {
                    if (!in_array($sid, $selectedSchoolIds)) {
                        $sName = '';
                        $sQ = $mysqli->query("SELECT name FROM schools WHERE id = {$sid}");
                        if ($sQ && ($sR = $sQ->fetch_assoc())) { $sName = $sR['name']; }
                        log_activity($userId, $userType, 'Unlink School from Course', 'course_schools', null, "Admin unlinked school {$sName} from " . ($newCourse['course_name'] ?? $courseName), json_encode(['course_name' => ($newCourse['course_name'] ?? $courseName), 'school_name' => $sName, 'school_id' => $sid]), null);
                    }
                }

                // Fetch new course row for changes
                $newCourse = null;
                $cQuery2 = $mysqli->prepare("SELECT * FROM courses WHERE id = ?");
                if ($cQuery2) {
                    $cQuery2->bind_param('i', $courseId);
                    $cQuery2->execute();
                    $newCourse = $cQuery2->get_result()->fetch_assoc();
                    $cQuery2->close();
                }

                // Log changes in course fields (We now log full objects instead of just diffs)
                $oldChanges = $oldCourse;
                $newChanges = $newCourse;
                
                $descriptionText = "Admin edited course #{$courseId} (" . ($newCourse['course_name'] ?? $courseName) . ")";
                log_activity(
                    $userId,
                    $userType,
                    'Edited Course',
                    'courses',
                    $courseId,
                    $descriptionText,
                    !empty($oldChanges) ? json_encode($oldChanges) : null,
                    !empty($newChanges) ? json_encode($newChanges) : null
                );

                // Log job links/unlinks
                // Added jobs: in selectedJobIds but not oldJobIds
                $addedJobs = array_diff($selectedJobIds, $oldJobIds);
                foreach ($addedJobs as $jid) {
                    if ($jid > 0) {
                        $jTitle = '';
                        $jQ = $mysqli->query("SELECT job_title FROM jobs WHERE id = {$jid}");
                        if ($jQ && ($jR = $jQ->fetch_assoc())) { $jTitle = $jR['job_title']; }
                        log_activity($userId, $userType, 'Link Job to Course', 'course_jobs', null, "Admin linked job {$jTitle} to " . ($newCourse['course_name'] ?? $courseName), null, json_encode(['course_name' => ($newCourse['course_name'] ?? $courseName), 'job_title' => $jTitle, 'job_id' => $jid]));
                    }
                }
                // Removed jobs: in oldJobIds but not selectedJobIds
                $removedJobs = array_diff($oldJobIds, $selectedJobIds);
                foreach ($removedJobs as $jid) {
                    if ($jid > 0) {
                        $jTitle = '';
                        $jQ = $mysqli->query("SELECT job_title FROM jobs WHERE id = {$jid}");
                        if ($jQ && ($jR = $jQ->fetch_assoc())) { $jTitle = $jR['job_title']; }
                        log_activity($userId, $userType, 'Unlink Job from Course', 'course_jobs', null, "Admin unlinked job {$jTitle} from " . ($newCourse['course_name'] ?? $courseName), json_encode(['course_name' => ($newCourse['course_name'] ?? $courseName), 'job_title' => $jTitle, 'job_id' => $jid]), null);
                    }
                }

                $response['success'] = true;
                $response['message'] = 'Course updated successfully';
            } else {
                $response['message'] = 'Failed to update course';
            }
            $stmt->close();
            echo json_encode($response);
            exit;

        case 'get_course_jobs':
            // Returns job IDs currently linked to a course (used to pre-check boxes on edit)
            $courseId = (int)($_POST['id'] ?? 0);
            if ($courseId <= 0) {
                $response['message'] = 'Invalid course ID';
                echo json_encode($response); exit;
            }
            $stmt = $mysqli->prepare("SELECT job_id FROM course_jobs WHERE course_id = ?");
            $stmt->bind_param('i', $courseId);
            $stmt->execute();
            $result = $stmt->get_result();
            $jobIds = [];
            while ($row = $result->fetch_assoc()) { $jobIds[] = (int)$row['job_id']; }
            $stmt->close();
            $response['success'] = true;
            $response['job_ids'] = $jobIds;
            echo json_encode($response); exit;

        case 'get_course_schools':
            $courseId = (int)($_POST['id'] ?? 0);
            if ($courseId <= 0) {
                $response['message'] = 'Invalid course ID';
                echo json_encode($response); exit;
            }
            $stmt = $mysqli->prepare("SELECT school_id, MAX(is_specialization) AS is_specialization FROM course_schools WHERE course_id = ? GROUP BY school_id");
            $stmt->bind_param('i', $courseId);
            $stmt->execute();
            $result = $stmt->get_result();
            $schools = [];
            while ($row = $result->fetch_assoc()) { 
                $schools[] = [
                    'school_id' => (int)$row['school_id'],
                    'is_specialization' => (int)$row['is_specialization']
                ]; 
            }
            $stmt->close();
            $response['success'] = true;
            $response['schools'] = $schools;
            echo json_encode($response); exit;

        case 'add_job_inline':
            // Quick-add a new job without leaving the course modal
            $title = trim($_POST['job_title'] ?? '');
            $desc  = trim($_POST['description'] ?? '');
            if (empty($title)) {
                $response['message'] = 'Job title is required.';
                echo json_encode($response); exit;
            }
            $chk = $mysqli->prepare("SELECT id FROM jobs WHERE job_title = ? LIMIT 1");
            $chk->bind_param('s', $title);
            $chk->execute(); $chk->store_result();
            if ($chk->num_rows > 0) {
                // Return existing job so it can be checked
                $chk->close();
                $existing = $mysqli->prepare("SELECT id, job_title FROM jobs WHERE job_title = ? LIMIT 1");
                $existing->bind_param('s', $title);
                $existing->execute();
                $job = $existing->get_result()->fetch_assoc();
                $existing->close();
                $response['success'] = true;
                $response['already_existed'] = true;
                $response['job'] = $job;
                echo json_encode($response); exit;
            }
            $chk->close();
            $stmt = $mysqli->prepare("INSERT INTO jobs (job_title, description, created_at) VALUES (?, ?, NOW())");
            $stmt->bind_param('ss', $title, $desc);
            if ($stmt->execute()) {
                $newId = $mysqli->insert_id;
                $response['success'] = true;
                $response['message'] = 'Job created and selected.';
                $response['job'] = ['id' => $newId, 'job_title' => $title];
            } else {
                $response['message'] = 'Failed to create job.';
            }
            $stmt->close();
            echo json_encode($response); exit;

        case 'delete_course':
            $courseId = (int)($_POST['id'] ?? 0);
            if ($courseId <= 0) {
                $response['message'] = 'Invalid course ID';
                echo json_encode($response);
                exit;
            }
            
            // Check if course is associated with any schools
            $check = $mysqli->prepare("SELECT COUNT(*) as count FROM course_schools WHERE course_id = ?");
            $check->bind_param('i', $courseId);
            $check->execute();
            $count = $check->get_result()->fetch_assoc()['count'];
            $check->close();
            
            if ($count > 0) {
                $response['message'] = 'Cannot delete course: It is associated with schools';
                echo json_encode($response);
                exit;
            }
            
            // Fetch course details before deleting
            $oldCourse = null;
            $cQuery = $mysqli->prepare("SELECT * FROM courses WHERE id = ?");
            if ($cQuery) {
                $cQuery->bind_param('i', $courseId);
                $cQuery->execute();
                $oldCourse = $cQuery->get_result()->fetch_assoc();
                $cQuery->close();
            }

            $stmt = $mysqli->prepare("DELETE FROM courses WHERE id = ?");
            $stmt->bind_param('i', $courseId);
            
            if ($stmt->execute()) {
                $cName = $oldCourse['course_name'] ?? 'Unknown Course';
                $descriptionText = "Admin removed {$cName} from the system";
                log_activity($userId, $userType, 'Deleted Course', 'courses', $courseId, $descriptionText, json_encode($oldCourse), null);

                $response['success'] = true;
                $response['message'] = 'Course deleted successfully';
            } else {
                $response['message'] = 'Failed to delete course';
            }
            $stmt->close();
            echo json_encode($response);
            exit;
    }
}

// Get courses with related data
$courses = [];
$result = $mysqli->query("
    SELECT c.*, s.name as strand_name, s.code as strand_code, cl.name as cluster_name
    FROM courses c
    LEFT JOIN strands s ON c.strand_id = s.id
    LEFT JOIN clusters cl ON c.cluster_id = cl.id
    ORDER BY c.course_name
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $courses[] = $row;
    }
}

// Get dropdown data
$strands  = $mysqli->query("SELECT id, name, code FROM strands ORDER BY name")->fetch_all(MYSQLI_ASSOC) ?? [];
$clusters = $mysqli->query("SELECT id, name FROM clusters ORDER BY name")->fetch_all(MYSQLI_ASSOC) ?? [];
$allJobs  = $mysqli->query("SELECT id, job_title FROM jobs ORDER BY job_title")->fetch_all(MYSQLI_ASSOC) ?? [];
$allSchools = $mysqli->query("SELECT id, name, city, province, region FROM schools ORDER BY name")->fetch_all(MYSQLI_ASSOC) ?? [];
$hollandTraits = ['Realistic', 'Investigative', 'Artistic', 'Social', 'Enterprising', 'Conventional'];
$bigFiveTraits = ['Openness', 'Conscientiousness', 'Extraversion', 'Agreeableness', 'Neuroticism'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Courses - <?php echo htmlspecialchars(getSystemConfig('name')); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="admin.css">
    <style>
        /* ── Jobs multi-select inside course modal ── */
        .jobs-multiselect {
            background: var(--input-bg, #0f172a);
            border: 1px solid var(--border-color, rgba(255,255,255,.12));
            border-radius: 10px;
            max-height: 120px;
            overflow-y: auto;
            padding: .25rem;
        }
        .job-checkbox-item {
            display: flex;
            align-items: center;
            gap: .5rem;
            padding: .3rem .5rem;
            border-radius: 6px;
            cursor: pointer;
            transition: background .15s;
            user-select: none;
        }
        .job-checkbox-item:hover { background: rgba(245,158,11,.08); }
        .job-checkbox-item input[type="checkbox"] {
            accent-color: #f59e0b;
            width: 14px; height: 14px;
            cursor: pointer;
            flex-shrink: 0;
        }
        .job-checkbox-item label {
            cursor: pointer;
            font-size: .82rem;
            color: var(--text-primary, #f1f5f9);
            font-weight: 500;
            line-height: 1.3;
        }
        .inline-add-job-btn {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            font-size: .78rem;
            font-weight: 600;
            color: #f59e0b;
            background: rgba(245,158,11,.12);
            border: 1px dashed rgba(245,158,11,.45);
            border-radius: 8px;
            padding: .28rem .7rem;
            cursor: pointer;
            transition: background .2s, border-color .2s;
            margin-top: .35rem;
        }
        .inline-add-job-btn:hover { background: rgba(245,158,11,.22); border-color: rgba(245,158,11,.7); }
        .quick-add-job-form {
            display: none;
            background: rgba(15, 23, 42, 0.8);
            border: 1px solid rgba(16, 185, 129, 0.3);
            border-radius: 10px;
            padding: .85rem;
            margin-top: .6rem;
        }
        .quick-add-job-form.visible { display: block; }
        .quick-add-job-form .qa-row { margin-bottom: .6rem; }
        .quick-add-job-form input,
        .quick-add-job-form textarea {
            font-size: .82rem;
            font-family: inherit;
            resize: none;
        }
        .quick-add-job-form input:focus,
        .quick-add-job-form textarea:focus { outline: none; border-color: #f59e0b; }
        .quick-add-job-form .qa-actions { display: flex; gap: .5rem; justify-content: flex-end; }
        .qa-save-btn {
            padding: .35rem .9rem;
            background: linear-gradient(135deg,#f59e0b,#d97706);
            border: none; border-radius: 8px;
            color: #fff; font-size: .78rem; font-weight: 700;
            cursor: pointer; transition: opacity .2s;
        }
        .qa-save-btn:hover { opacity: .85; }
        .qa-cancel-btn {
            padding: .35rem .8rem;
            background: transparent;
            border: 1px solid var(--border-color, rgba(255,255,255,.15));
            border-radius: 8px;
            color: var(--text-secondary,#94a3b8);
            font-size: .78rem; cursor: pointer;
        }
        .qa-cancel-btn:hover { border-color: #f59e0b; color: #f59e0b; }
        /* Compact modal body scroll so modal never overflows viewport */
        #addCourseModal .modal-body,
        #editCourseModal .modal-body {
            max-height: 68vh;
            overflow-y: auto;
            padding: 1rem 1.25rem;
        }
        /* Cluster + Description side-by-side */
        .course-form .cluster-desc-row {
            display: grid;
            grid-template-columns: 1fr 1.6fr;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        .school-checkbox-item:hover { background: rgba(245,158,11,.04); }
        .school-checkbox-item:last-child { border-bottom: none; }
        .school-left, .school-right {
            display: flex;
            align-items: center;
            gap: .5rem;
            user-select: none;
        }
        .school-left input[type="checkbox"],
        .school-right input[type="checkbox"] {
            accent-color: #f59e0b;
            width: 14px; height: 14px;
            cursor: pointer;
        }
        .school-left label,
        .school-right label {
            cursor: pointer;
            font-size: .82rem;
            color: var(--text-primary, #f1f5f9);
            font-weight: 500;
        }
        .school-right label {
            color: var(--text-secondary, #94a3b8);
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            font-size: 0.78rem;
        }
        .school-right input[type="checkbox"]:checked + label {
            color: #fbbf24;
            font-weight: 600;
        }
        .school-right input[type="checkbox"]:checked + label i {
            color: #fbbf24;
        }
        .school-right input[type="checkbox"]:disabled + label {
            opacity: 0.4;
            cursor: not-allowed;
        }
        /* ── Schools multi-select inside course modal ── */
        .schools-multiselect {
            background: var(--input-bg, #0f172a);
            border: 1px solid var(--border-color, rgba(255,255,255,.12));
            border-radius: 10px;
            max-height: 150px;
            overflow-y: auto;
            padding: .25rem;
        }
        .school-checkbox-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: .4rem .6rem;
            border-radius: 6px;
            transition: background .15s;
            border-bottom: 1px solid rgba(255,255,255,0.03);
        }
        /* ── Modal Design Customizations (Matching screenshot layout with dark theme) ── */
        #addCourseModal .modal-content, #editCourseModal .modal-content {
            max-width: 780px !important;
            border-radius: 16px !important;
            background: #0f172a !important;
            border: 1px solid rgba(255, 255, 255, 0.1) !important;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5) !important;
            overflow: hidden !important;
        }
        .modal-header-custom {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            text-align: center;
        }
        .header-left-box {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.85rem;
            text-align: center;
            width: 100%;
            padding: 0 2.5rem;
        }
        .icon-box-header {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: rgba(99, 102, 241, 0.15);
            color: #818cf8;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
        }
        .header-titles {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
        }
        .header-titles h2 {
            font-size: 1.25rem;
            font-weight: 700;
            color: #f8fafc;
            margin: 0;
            border: none;
            padding: 0;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        .header-titles p {
            font-size: 0.82rem;
            color: #94a3b8;
            margin: 3px 0 0 0;
            text-align: center;
        }
        .modal-header-custom .modal-close {
            position: absolute;
            right: 1.25rem;
            top: 50%;
            transform: translateY(-50%);
            margin: 0;
            z-index: 10;
        }
        .input-with-icon {
            position: relative;
            width: 100%;
        }
        .input-with-icon i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
            font-size: 0.9rem;
            pointer-events: none;
        }
        .input-with-icon.textarea-icon i {
            top: 1rem;
            transform: none;
        }
        .input-with-icon input, .input-with-icon select, .input-with-icon textarea {
            padding-left: 2.6rem !important;
            background: #0b1120 !important;
            border: 1px solid rgba(255, 255, 255, 0.1) !important;
            border-radius: 10px !important;
            color: #f8fafc !important;
        }
        .input-with-icon input:focus, .input-with-icon select:focus, .input-with-icon textarea:focus {
            border-color: rgba(99, 102, 241, 0.5) !important;
        }
        .modal-section-card {
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 14px;
            padding: 1.25rem;
            margin-top: 1.25rem;
        }
        .section-header-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .section-header-left {
            display: flex;
            align-items: center;
            gap: 0.85rem;
        }
        .section-icon-badge {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
        }
        .section-icon-badge.purple {
            background: rgba(99, 102, 241, 0.15);
            color: #818cf8;
        }
        .section-icon-badge.green {
            background: rgba(16, 185, 129, 0.15);
            color: #34d399;
        }
        .section-title-text {
            font-size: 0.95rem;
            font-weight: 700;
            color: #f8fafc;
            margin: 0;
        }
        .section-subtitle-text {
            font-size: 0.8rem;
            color: #94a3b8;
            margin: 1px 0 0 0;
        }
        .selection-count-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .selection-count-badge.purple {
            background: rgba(99, 102, 241, 0.15);
            color: #a5b4fc;
            border: 1px solid rgba(99, 102, 241, 0.3);
        }
        .selection-count-badge.green {
            background: rgba(16, 185, 129, 0.15);
            color: #6ee7b7;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        .section-search-box {
            position: relative;
            margin: 0.85rem 0 0.65rem 0;
        }
        .section-search-box i.search-icon {
            position: absolute;
            left: 0.85rem;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
            font-size: 0.8rem;
            pointer-events: none;
        }
        .section-search-box input {
            width: 100%;
            padding: 0.55rem 1rem 0.55rem 2.4rem;
            background: #0b1120;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 8px;
            color: #f8fafc;
            font-size: 0.85rem;
            outline: none;
            box-sizing: border-box;
        }
        .section-search-box input:focus {
            border-color: rgba(99, 102, 241, 0.4);
        }
        .list-table-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.4rem 0.75rem;
            font-size: 0.72rem;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
        }
        .custom-multiselect-list {
            max-height: 170px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 2px;
            padding: 0.3rem 0;
        }
        .custom-list-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.45rem 0.75rem;
            border-radius: 6px;
            transition: background 0.15s;
        }
        .custom-list-item:hover {
            background: rgba(255, 255, 255, 0.04);
        }
        .custom-list-item-left {
            display: flex;
            align-items: center;
            gap: 0.65rem;
        }
        .custom-list-item-left input[type="checkbox"] {
            accent-color: #6366f1;
            width: 15px;
            height: 15px;
            cursor: pointer;
        }
        .custom-list-item-left label {
            font-size: 0.85rem;
            color: #cbd5e1;
            font-weight: 500;
            cursor: pointer;
        }
        .spec-star-btn {
            background: transparent;
            border: none;
            cursor: pointer;
            font-size: 1.1rem;
            color: #475569;
            padding: 2px 6px;
            transition: color 0.2s, transform 0.15s;
        }
        .spec-star-btn:hover:not(:disabled) {
            transform: scale(1.15);
            color: #fbbf24;
        }
        .spec-star-btn.active {
            color: #f59e0b;
        }
        .spec-star-btn:disabled {
            opacity: 0.3;
            cursor: not-allowed;
        }
        .btn-dashed-add-custom {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            background: rgba(16, 185, 129, 0.06);
            border: 1px dashed rgba(16, 185, 129, 0.4);
            border-radius: 8px;
            color: #34d399;
            padding: 0.6rem;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            margin-top: 0.85rem;
            transition: background 0.2s, border-color 0.2s;
        }
        .btn-dashed-add-custom:hover {
            background: rgba(16, 185, 129, 0.12);
            border-color: rgba(16, 185, 129, 0.7);
        }

        /* ── Traits & Alignments Redesign ─────────────────── */
        .traits-redesign-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
        }
        @media (max-width: 900px) {
            .traits-redesign-grid {
                grid-template-columns: 1fr;
            }
        }
        .trait-group-card {
            background: #080d1a;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 1rem;
            display: flex;
            flex-direction: column;
            gap: 0.85rem;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .trait-group-card:hover {
            border-color: rgba(255, 255, 255, 0.14);
        }
        .trait-group-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-bottom: 0.65rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
        }
        .trait-group-title {
            display: flex;
            align-items: center;
            gap: 0.55rem;
            font-size: 0.88rem;
            font-weight: 700;
            color: #f1f5f9;
            letter-spacing: 0.01em;
        }
        .trait-group-icon {
            width: 26px;
            height: 26px;
            border-radius: 7px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
        }
        .trait-group-card.holland .trait-group-icon {
            background: rgba(245, 158, 11, 0.18);
            color: #fbbf24;
        }
        .trait-group-card.bigfive .trait-group-icon {
            background: rgba(129, 140, 248, 0.18);
            color: #a5b4fc;
        }
        .trait-group-card.strand .trait-group-icon {
            background: rgba(16, 185, 129, 0.18);
            color: #34d399;
        }
        .trait-category-tag {
            font-size: 0.68rem;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 999px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .trait-category-tag.holland {
            background: rgba(245, 158, 11, 0.1);
            color: #f59e0b;
            border: 1px solid rgba(245, 158, 11, 0.25);
        }
        .trait-category-tag.bigfive {
            background: rgba(129, 140, 248, 0.1);
            color: #818cf8;
            border: 1px solid rgba(129, 140, 248, 0.25);
        }
        .trait-category-tag.strand {
            background: rgba(16, 185, 129, 0.1);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, 0.25);
        }

        .trait-pill-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 0.45rem;
        }
        .trait-pill {
            position: relative;
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.45rem 0.75rem;
            border-radius: 8px;
            font-size: 0.82rem;
            font-weight: 500;
            color: #94a3b8;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
            cursor: pointer;
            user-select: none;
            transition: all 0.18s ease;
        }
        .trait-pill:hover {
            background: rgba(255, 255, 255, 0.06);
            border-color: rgba(255, 255, 255, 0.16);
            color: #f1f5f9;
            transform: translateY(-1px);
        }
        .trait-pill input[type="checkbox"] {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
            pointer-events: none;
        }
        .trait-pill-check {
            width: 15px;
            height: 15px;
            border-radius: 4px;
            border: 1.5px solid rgba(255, 255, 255, 0.22);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.65rem;
            color: transparent;
            background: transparent;
            transition: all 0.18s ease;
            flex-shrink: 0;
        }
        .trait-pill-text {
            font-size: 0.82rem;
            line-height: 1;
        }

        /* Holland Active */
        .trait-group-card.holland .trait-pill:has(input:checked),
        .trait-group-card.holland .trait-pill.is-checked {
            background: rgba(245, 158, 11, 0.14);
            border-color: rgba(245, 158, 11, 0.5);
            color: #fef08a;
            box-shadow: 0 0 12px rgba(245, 158, 11, 0.12);
        }
        .trait-group-card.holland .trait-pill:has(input:checked) .trait-pill-check,
        .trait-group-card.holland .trait-pill.is-checked .trait-pill-check {
            background: #f59e0b;
            border-color: #f59e0b;
            color: #0f172a;
        }

        /* Big Five Active */
        .trait-group-card.bigfive .trait-pill:has(input:checked),
        .trait-group-card.bigfive .trait-pill.is-checked {
            background: rgba(129, 140, 248, 0.14);
            border-color: rgba(129, 140, 248, 0.5);
            color: #e0e7ff;
            box-shadow: 0 0 12px rgba(129, 140, 248, 0.12);
        }
        .trait-group-card.bigfive .trait-pill:has(input:checked) .trait-pill-check,
        .trait-group-card.bigfive .trait-pill.is-checked .trait-pill-check {
            background: #6366f1;
            border-color: #6366f1;
            color: #ffffff;
        }

        /* Strand Active */
        .trait-group-card.strand .trait-pill:has(input:checked),
        .trait-group-card.strand .trait-pill.is-checked {
            background: rgba(16, 185, 129, 0.14);
            border-color: rgba(16, 185, 129, 0.5);
            color: #a7f3d0;
            box-shadow: 0 0 12px rgba(16, 185, 129, 0.12);
        }
        .trait-group-card.strand .trait-pill:has(input:checked) .trait-pill-check,
        .trait-group-card.strand .trait-pill.is-checked .trait-pill-check {
            background: #10b981;
            border-color: #10b981;
            color: #0f172a;
        }

        /* ── View Course Modal Custom Styles ── */
        #viewCourseModal .modal-content {
            max-width: 780px !important;
            width: 95% !important;
            max-height: 90vh !important;
            border-radius: 16px !important;
            background: #0f172a !important;
            border: 1px solid rgba(255, 255, 255, 0.1) !important;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.7) !important;
            overflow: hidden !important;
            display: flex !important;
            flex-direction: column !important;
            text-align: left !important;
            padding: 0 !important;
        }
        #viewCourseModal .modal-header-custom {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(15, 23, 42, 0.8);
            flex-shrink: 0;
        }
        #viewCourseModal .view-modal-body {
            padding: 1.5rem;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
        }
        #viewCourseModal .course-hero-card {
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.6), rgba(15, 23, 42, 0.8));
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 1.25rem 1.5rem;
        }
        #viewCourseModal .course-hero-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
            margin-bottom: 0.75rem;
        }
        #viewCourseModal .course-hero-title {
            font-size: 1.35rem;
            font-weight: 700;
            color: #f8fafc;
            margin: 0;
            line-height: 1.3;
        }
        #viewCourseModal .course-cluster-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            background: rgba(99, 102, 241, 0.15);
            border: 1px solid rgba(99, 102, 241, 0.3);
            color: #a5b4fc;
            padding: 0.35rem 0.85rem;
            border-radius: 999px;
            font-size: 0.82rem;
            font-weight: 600;
            white-space: nowrap;
        }
        #viewCourseModal .course-description-box {
            color: #cbd5e1;
            font-size: 0.92rem;
            line-height: 1.6;
            margin: 0;
            white-space: pre-line;
            border-top: 1px solid rgba(255, 255, 255, 0.06);
            padding-top: 0.85rem;
        }
        #viewCourseModal .view-details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.25rem;
        }
        @media (max-width: 640px) {
            #viewCourseModal .view-details-grid {
                grid-template-columns: 1fr;
            }
        }
        #viewCourseModal .view-card-section {
            background: rgba(15, 23, 42, 0.5);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 1.1rem;
            display: flex;
            flex-direction: column;
        }
        #viewCourseModal .view-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.85rem;
            padding-bottom: 0.65rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
        }
        #viewCourseModal .view-card-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: #f8fafc;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0;
        }
        #viewCourseModal .view-card-list {
            max-height: 200px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
            padding-right: 0.25rem;
        }
        #viewCourseModal .view-modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 0.75rem;
            background: rgba(15, 23, 42, 0.8);
            flex-shrink: 0;
        }
        #viewCourseModal .btn-edit-course-modal {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: #0f172a;
            border: none;
            padding: 0.6rem 1.25rem;
            border-radius: 8px;
            font-weight: 700;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.25);
            transition: all 0.2s;
        }
        #viewCourseModal .btn-edit-course-modal:hover {
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            box-shadow: 0 6px 16px rgba(245, 158, 11, 0.35);
            transform: translateY(-1px);
        }
        #viewCourseModal .btn-close-view-modal {
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #94a3b8;
            padding: 0.6rem 1.25rem;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        #viewCourseModal .btn-close-view-modal:hover {
            background: rgba(255, 255, 255, 0.1);
            color: #f8fafc;
        }

        /* ── Improved Courses Table Layout & Typography ── */
        .courses-table {
            width: 100% !important;
            border-collapse: collapse !important;
        }
        .courses-table th {
            padding: 1rem 1.25rem !important;
            font-size: 0.8rem !important;
            font-weight: 700 !important;
            color: #94a3b8 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.05em !important;
            white-space: nowrap !important;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08) !important;
        }
        .courses-table td {
            padding: 1.1rem 1.25rem !important;
            vertical-align: middle !important;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05) !important;
        }
        .courses-table tr:hover td {
            background: rgba(255, 255, 255, 0.02) !important;
        }
        .courses-table .course-name {
            font-weight: 700 !important;
            font-size: 0.95rem !important;
            color: #f8fafc !important;
            line-height: 1.45 !important;
            max-width: none !important;
            white-space: normal !important;
            overflow: visible !important;
            text-overflow: clip !important;
            min-width: 200px !important;
            word-break: normal !important;
        }
        .courses-table .cluster-badge {
            display: inline-flex !important;
            align-items: center !important;
            gap: 0.4rem !important;
            background: rgba(99, 102, 241, 0.15) !important;
            border: 1px solid rgba(99, 102, 241, 0.35) !important;
            color: #a5b4fc !important;
            padding: 0.35rem 0.85rem !important;
            border-radius: 999px !important;
            font-size: 0.82rem !important;
            font-weight: 600 !important;
            white-space: normal !important;
            line-height: 1.3 !important;
            max-width: 260px !important;
        }
        .courses-table .description-cell {
            color: #94a3b8 !important;
            font-size: 0.88rem !important;
            line-height: 1.55 !important;
            max-width: 480px !important;
            white-space: normal !important;
            overflow: visible !important;
            text-overflow: clip !important;
            word-break: break-word !important;
        }
    </style>
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
                    <button class="nav-group-toggle open active-group" onclick="toggleNavGroup(this)">
                        <i class="fa-solid fa-briefcase group-icon"></i>
                        <span>Career Management</span>
                        <i class="fa-solid fa-chevron-down chevron"></i>
                    </button>
                    <div class="nav-submenu open">
                        <a href="manage_clusters.php" class="nav-subitem">
                            <i class="fa-solid fa-layer-group"></i>
                            Manage Career Clusters
                        </a>
                        <a href="manage_courses.php" class="nav-subitem active">
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
                    <h1>Manage Courses</h1>
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

            <!-- Courses Content -->
            <div class="dashboard-content">
                <!-- Page Header -->
                <div class="page-header">
                    <div class="header-actions">
                        <div class="search-filter">
                            <div class="search-box-wrapper">
                                <div class="search-box">
                                    <i class="fa-solid fa-search"></i>
                                    <input type="text" id="searchInput" placeholder="Search courses, clusters, strand...">
                                </div>
                                <button class="btn-search" id="searchBtn">
                                    <i class="fa-solid fa-search"></i>
                                    Search
                                </button>
                            </div>
                            <button class="btn-clear" id="clearFilter">
                                <i class="fa-solid fa-times"></i>
                                Clear
                            </button>
                        </div>
                        <div class="header-info">
                            <span class="count-badge">
                                <i class="fa-solid fa-book"></i>
                                <span id="totalCourses"><?php echo count($courses); ?></span> Courses
                            </span>
                        </div>
                        <button class="btn-primary" id="addCourseBtn">
                            <i class="fa-solid fa-plus"></i>
                            Add Course
                        </button>
                    </div>
                </div>

                <!-- Courses Table -->
                <div class="table-section">
                    <div class="table-container">
                        <table class="data-table courses-table">
                            <thead>
                                <tr>
                                    <th>Course Name</th>
                                    <th>Career Cluster</th>
                                    <th>Description</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($courses)): ?>
                                <tr>
                                    <td colspan="4" class="no-data">
                                        <i class="fa-solid fa-inbox"></i>
                                        <p>No courses found. Click "Add Course" to create your first course.</p>
                                    </td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($courses as $course): ?>
                                    <tr data-id="<?php echo $course['id']; ?>">
                                        <td class="course-name">
                                            <?php echo htmlspecialchars($course['course_name']); ?>
                                        </td>
                                        <td>
                                            <?php if ($course['cluster_name']): ?>
                                            <span class="cluster-badge"><?php echo htmlspecialchars($course['cluster_name']); ?></span>
                                            <?php else: ?>
                                            <span class="cluster-badge">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="description-cell"><?php echo htmlspecialchars($course['description'] ?: '—'); ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn-action view" data-id="<?php echo $course['id']; ?>" title="View">
                                                    <i class="fa-solid fa-eye"></i>
                                                </button>
                                                <button class="btn-action edit" data-id="<?php echo $course['id']; ?>" title="Edit">
                                                    <i class="fa-solid fa-pen"></i>
                                                </button>
                                                <button class="btn-action delete" data-id="<?php echo $course['id']; ?>" title="Delete">
                                                    <i class="fa-solid fa-trash"></i>
                                                </button>
                                            </div>
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

    <!-- Add Course Modal -->
    <div class="modal" id="addCourseModal">
        <div class="modal-overlay"></div>
        <div class="modal-content">
            <div class="modal-header-custom">
                <div class="header-left-box">
                    <div class="icon-box-header">
                        <i class="fa-solid fa-plus"></i>
                    </div>
                    <div class="header-titles">
                        <h2>Add New Course</h2>
                        <p>Create a new course and connect it with schools and careers.</p>
                    </div>
                </div>
                <button class="modal-close" id="closeAddModal">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="modal-body" style="padding: 1.5rem;">
                <form id="addCourseForm" class="course-form">
                    <div class="form-row" style="margin-bottom: 1.25rem;">
                        <div class="form-group full-width">
                            <label for="courseName" style="font-weight:600; margin-bottom:0.4rem; display:block;">Course Name <span class="required">*</span></label>
                            <div class="input-with-icon">
                                <i class="fa-solid fa-graduation-cap"></i>
                                <input type="text" id="courseName" name="course_name" required placeholder="Enter course name">
                            </div>
                        </div>
                    </div>
                    <div class="cluster-desc-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem;">
                        <div class="form-group">
                            <label for="cluster" style="font-weight:600; margin-bottom:0.4rem; display:block;">Career Cluster <span class="required">*</span></label>
                            <div class="input-with-icon">
                                <i class="fa-solid fa-border-all"></i>
                                <select id="cluster" name="cluster_id">
                                    <option value="">Select cluster</option>
                                    <?php foreach ($clusters as $cluster): ?>
                                    <option value="<?php echo $cluster['id']; ?>"><?php echo htmlspecialchars($cluster['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="description" style="font-weight:600; margin-bottom:0.4rem; display:block;">Description <span class="required">*</span></label>
                            <div class="input-with-icon textarea-icon">
                                <i class="fa-solid fa-file-lines"></i>
                                <textarea id="description" name="description" rows="2" required placeholder="Enter course description..."></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Traits & Alignments Card -->
                    <div class="modal-section-card">
                        <div class="section-header-row" style="margin-bottom: 1rem;">
                            <div class="section-header-left">
                                <div class="section-icon-badge purple" style="background: rgba(245, 158, 11, 0.15); color: #fbbf24;">
                                    <i class="fa-solid fa-shapes"></i>
                                </div>
                                <div>
                                    <h4 class="section-title-text">Traits &amp; Alignments</h4>
                                    <p class="section-subtitle-text">Select matching traits and senior high strands for recommendation scoring.</p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="traits-redesign-grid">
                            <!-- Holland RIASEC Traits -->
                            <div class="trait-group-card holland">
                                <div class="trait-group-header">
                                    <div class="trait-group-title">
                                        <span class="trait-group-icon"><i class="fa-solid fa-compass"></i></span>
                                        <span>Holland Traits</span>
                                    </div>
                                    <span class="trait-category-tag holland">RIASEC</span>
                                </div>
                                <div class="trait-pill-grid">
                                    <?php foreach ($hollandTraits as $trait): ?>
                                    <label class="trait-pill" for="add_ht_<?php echo $trait; ?>">
                                        <input type="checkbox" name="holland_traits[]" id="add_ht_<?php echo $trait; ?>" value="<?php echo $trait; ?>">
                                        <span class="trait-pill-check"><i class="fa-solid fa-check"></i></span>
                                        <span class="trait-pill-text"><?php echo $trait; ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Big Five Personality Traits -->
                            <div class="trait-group-card bigfive">
                                <div class="trait-group-header">
                                    <div class="trait-group-title">
                                        <span class="trait-group-icon"><i class="fa-solid fa-brain"></i></span>
                                        <span>Big Five Traits</span>
                                    </div>
                                    <span class="trait-category-tag bigfive">OCEAN</span>
                                </div>
                                <div class="trait-pill-grid">
                                    <?php foreach ($bigFiveTraits as $trait): ?>
                                    <label class="trait-pill" for="add_bf_<?php echo $trait; ?>">
                                        <input type="checkbox" name="bigfive_traits[]" id="add_bf_<?php echo $trait; ?>" value="<?php echo $trait; ?>">
                                        <span class="trait-pill-check"><i class="fa-solid fa-check"></i></span>
                                        <span class="trait-pill-text"><?php echo $trait; ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- SHS Strands -->
                            <div class="trait-group-card strand">
                                <div class="trait-group-header">
                                    <div class="trait-group-title">
                                        <span class="trait-group-icon"><i class="fa-solid fa-graduation-cap"></i></span>
                                        <span>SHS Strands</span>
                                    </div>
                                    <span class="trait-category-tag strand">Track/Strand</span>
                                </div>
                                <div class="trait-pill-grid">
                                    <?php foreach ($strands as $strand): ?>
                                    <label class="trait-pill" for="add_st_<?php echo $strand['code']; ?>">
                                        <input type="checkbox" name="shs_strands[]" id="add_st_<?php echo $strand['code']; ?>" value="<?php echo $strand['code']; ?>">
                                        <span class="trait-pill-check"><i class="fa-solid fa-check"></i></span>
                                        <span class="trait-pill-text"><?php echo htmlspecialchars($strand['code']); ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Available Schools Card -->
                    <div class="modal-section-card">
                        <div class="section-header-row">
                            <div class="section-header-left">
                                <div class="section-icon-badge purple">
                                    <i class="fa-solid fa-landmark"></i>
                                </div>
                                <div>
                                    <h4 class="section-title-text">Available Schools</h4>
                                    <p class="section-subtitle-text">Select all schools that offer this course.</p>
                                </div>
                            </div>
                            <span class="selection-count-badge purple" id="addSchoolCountBadge">0 selected</span>
                        </div>
                        <div class="section-search-box">
                            <i class="fa-solid fa-magnifying-glass search-icon"></i>
                            <input type="text" id="addSchoolSearch" placeholder="Search schools by name..." onkeyup="filterMultiselect('addSchoolSearch', 'addSchoolsMultiselect')">
                        </div>
                        <div class="list-table-head">
                            <span>School Name</span>
                            <span>Best in this course</span>
                        </div>
                        <div class="custom-multiselect-list" id="addSchoolsMultiselect">
                            <?php foreach ($allSchools as $school): ?>
                            <div class="custom-list-item" data-search="<?php echo htmlspecialchars(strtolower($school['name'])); ?>">
                                <div class="custom-list-item-left">
                                    <input type="checkbox" name="school_ids[]" id="add_school_<?php echo $school['id']; ?>" value="<?php echo $school['id']; ?>" class="school-offer-cb" onchange="updateCountsAndStars('add')">
                                    <label for="add_school_<?php echo $school['id']; ?>"><?php echo htmlspecialchars($school['name']); ?> <span style="color:#64748b; font-size:0.8em;">(<?php echo htmlspecialchars($school['city']); ?>)</span></label>
                                </div>
                                <div>
                                    <input type="checkbox" name="is_specialization_<?php echo $school['id']; ?>" id="add_is_specialization_<?php echo $school['id']; ?>" value="1" disabled class="school-spec-cb" style="display:none;" onchange="updateStarIcon(this)">
                                    <button type="button" class="spec-star-btn" id="add_star_btn_<?php echo $school['id']; ?>" disabled onclick="toggleStarBtn('add', <?php echo $school['id']; ?>)" title="Toggle Specialization">
                                        <i class="fa-regular fa-star"></i>
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php if (empty($allSchools)): ?>
                            <p style="color:#64748b; font-size:0.85rem; padding:0.5rem; text-align:center;">No schools found in system.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Possible Careers Card -->
                    <div class="modal-section-card">
                        <div class="section-header-row">
                            <div class="section-header-left">
                                <div class="section-icon-badge green">
                                    <i class="fa-solid fa-briefcase"></i>
                                </div>
                                <div>
                                    <h4 class="section-title-text">Possible Careers</h4>
                                    <p class="section-subtitle-text">Select all careers that apply to this course.</p>
                                </div>
                            </div>
                            <span class="selection-count-badge green" id="addJobCountBadge">0 selected</span>
                        </div>
                        <div class="section-search-box">
                            <i class="fa-solid fa-magnifying-glass search-icon"></i>
                            <input type="text" id="addJobSearch" placeholder="Search careers by name..." onkeyup="filterMultiselect('addJobSearch', 'addJobsMultiselect')">
                        </div>
                        <div class="list-table-head">
                            <span>Career Name</span>
                        </div>
                        <div class="custom-multiselect-list" id="addJobsMultiselect">
                            <?php foreach ($allJobs as $job): ?>
                            <div class="custom-list-item" data-search="<?php echo htmlspecialchars(strtolower($job['job_title'])); ?>">
                                <div class="custom-list-item-left">
                                    <input type="checkbox" name="job_ids[]" id="add_job_<?php echo $job['id']; ?>" value="<?php echo $job['id']; ?>" onchange="updateCountsAndStars('add')">
                                    <label for="add_job_<?php echo $job['id']; ?>"><?php echo htmlspecialchars($job['job_title']); ?></label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php if (empty($allJobs)): ?>
                            <p style="color:#64748b; font-size:0.85rem; padding:0.5rem; text-align:center;">No careers found.</p>
                            <?php endif; ?>
                        </div>
                        <button type="button" class="btn-dashed-add-custom" id="addModalQuickAddBtn">
                            <i class="fa-solid fa-plus"></i> Add new job / career
                        </button>
                        <!-- Quick-add inline form -->
                        <div class="quick-add-job-form" id="addModalQuickForm" style="margin-top: 0.75rem;">
                            <div class="qa-row">
                                <input type="text" id="qaAddTitle" placeholder="Job title *" autocomplete="off">
                            </div>
                            <div class="qa-row">
                                <textarea id="qaAddDesc" rows="2" placeholder="Short description (optional)"></textarea>
                            </div>
                            <div class="qa-actions">
                                <button type="button" class="qa-cancel-btn" id="qaAddCancel">Cancel</button>
                                <button type="button" class="qa-save-btn" id="qaAddSave">Save &amp; Select</button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer" style="padding: 1rem 1.5rem; border-top: 1px solid rgba(255, 255, 255, 0.08); display: flex; justify-content: flex-end; gap: 0.75rem;">
                <button type="button" class="btn-secondary" id="cancelAdd" style="display:inline-flex; align-items:center; gap:0.4rem;">
                    <i class="fa-solid fa-xmark"></i> Cancel
                </button>
                <button type="submit" class="btn-primary" form="addCourseForm" style="display:inline-flex; align-items:center; gap:0.4rem; background: #6366f1; border-color: #6366f1;">
                    <i class="fa-solid fa-floppy-disk"></i> Add Course
                </button>
            </div>
        </div>
    </div>

    <!-- Edit Course Modal -->
    <div class="modal" id="editCourseModal">
        <div class="modal-overlay"></div>
        <div class="modal-content">
            <div class="modal-header-custom">
                <div class="header-left-box">
                    <div class="icon-box-header">
                        <i class="fa-solid fa-pen-to-square"></i>
                    </div>
                    <div class="header-titles">
                        <h2>Edit Course</h2>
                        <p>Modify course information, offering schools, and linked careers.</p>
                    </div>
                </div>
                <button class="modal-close" id="closeEditModal">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="modal-body" style="padding: 1.5rem;">
                <form id="editCourseForm" class="course-form">
                    <input type="hidden" id="editCourseId" name="id">
                    <div class="form-row" style="margin-bottom: 1.25rem;">
                        <div class="form-group full-width">
                            <label for="editCourseName" style="font-weight:600; margin-bottom:0.4rem; display:block;">Course Name <span class="required">*</span></label>
                            <div class="input-with-icon">
                                <i class="fa-solid fa-graduation-cap"></i>
                                <input type="text" id="editCourseName" name="course_name" required placeholder="Enter course name">
                            </div>
                        </div>
                    </div>
                    <div class="cluster-desc-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem;">
                        <div class="form-group">
                            <label for="editCluster" style="font-weight:600; margin-bottom:0.4rem; display:block;">Career Cluster <span class="required">*</span></label>
                            <div class="input-with-icon">
                                <i class="fa-solid fa-border-all"></i>
                                <select id="editCluster" name="cluster_id">
                                    <option value="">Select cluster</option>
                                    <?php foreach ($clusters as $cluster): ?>
                                    <option value="<?php echo $cluster['id']; ?>"><?php echo htmlspecialchars($cluster['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="editDescription" style="font-weight:600; margin-bottom:0.4rem; display:block;">Description <span class="required">*</span></label>
                            <div class="input-with-icon textarea-icon">
                                <i class="fa-solid fa-file-lines"></i>
                                <textarea id="editDescription" name="description" rows="2" required placeholder="Enter course description..."></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Traits & Alignments Card -->
                    <div class="modal-section-card">
                        <div class="section-header-row" style="margin-bottom: 1rem;">
                            <div class="section-header-left">
                                <div class="section-icon-badge purple" style="background: rgba(245, 158, 11, 0.15); color: #fbbf24;">
                                    <i class="fa-solid fa-shapes"></i>
                                </div>
                                <div>
                                    <h4 class="section-title-text">Traits &amp; Alignments</h4>
                                    <p class="section-subtitle-text">Select matching traits and senior high strands for recommendation scoring.</p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="traits-redesign-grid">
                            <!-- Holland RIASEC Traits -->
                            <div class="trait-group-card holland">
                                <div class="trait-group-header">
                                    <div class="trait-group-title">
                                        <span class="trait-group-icon"><i class="fa-solid fa-compass"></i></span>
                                        <span>Holland Traits</span>
                                    </div>
                                    <span class="trait-category-tag holland">RIASEC</span>
                                </div>
                                <div class="trait-pill-grid">
                                    <?php foreach ($hollandTraits as $trait): ?>
                                    <label class="trait-pill" for="edit_ht_<?php echo $trait; ?>">
                                        <input type="checkbox" name="holland_traits[]" id="edit_ht_<?php echo $trait; ?>" value="<?php echo $trait; ?>">
                                        <span class="trait-pill-check"><i class="fa-solid fa-check"></i></span>
                                        <span class="trait-pill-text"><?php echo $trait; ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Big Five Personality Traits -->
                            <div class="trait-group-card bigfive">
                                <div class="trait-group-header">
                                    <div class="trait-group-title">
                                        <span class="trait-group-icon"><i class="fa-solid fa-brain"></i></span>
                                        <span>Big Five Traits</span>
                                    </div>
                                    <span class="trait-category-tag bigfive">OCEAN</span>
                                </div>
                                <div class="trait-pill-grid">
                                    <?php foreach ($bigFiveTraits as $trait): ?>
                                    <label class="trait-pill" for="edit_bf_<?php echo $trait; ?>">
                                        <input type="checkbox" name="bigfive_traits[]" id="edit_bf_<?php echo $trait; ?>" value="<?php echo $trait; ?>">
                                        <span class="trait-pill-check"><i class="fa-solid fa-check"></i></span>
                                        <span class="trait-pill-text"><?php echo $trait; ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- SHS Strands -->
                            <div class="trait-group-card strand">
                                <div class="trait-group-header">
                                    <div class="trait-group-title">
                                        <span class="trait-group-icon"><i class="fa-solid fa-graduation-cap"></i></span>
                                        <span>SHS Strands</span>
                                    </div>
                                    <span class="trait-category-tag strand">Track/Strand</span>
                                </div>
                                <div class="trait-pill-grid">
                                    <?php foreach ($strands as $strand): ?>
                                    <label class="trait-pill" for="edit_st_<?php echo $strand['code']; ?>">
                                        <input type="checkbox" name="shs_strands[]" id="edit_st_<?php echo $strand['code']; ?>" value="<?php echo $strand['code']; ?>">
                                        <span class="trait-pill-check"><i class="fa-solid fa-check"></i></span>
                                        <span class="trait-pill-text"><?php echo htmlspecialchars($strand['code']); ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Available Schools Card -->
                    <div class="modal-section-card">
                        <div class="section-header-row">
                            <div class="section-header-left">
                                <div class="section-icon-badge purple">
                                    <i class="fa-solid fa-landmark"></i>
                                </div>
                                <div>
                                    <h4 class="section-title-text">Available Schools</h4>
                                    <p class="section-subtitle-text">Select all schools that offer this course.</p>
                                </div>
                            </div>
                            <span class="selection-count-badge purple" id="editSchoolCountBadge">0 selected</span>
                        </div>
                        <div class="section-search-box">
                            <i class="fa-solid fa-magnifying-glass search-icon"></i>
                            <input type="text" id="editSchoolSearch" placeholder="Search schools by name..." onkeyup="filterMultiselect('editSchoolSearch', 'editSchoolsMultiselect')">
                        </div>
                        <div class="list-table-head">
                            <span>School Name</span>
                            <span>Best in this course</span>
                        </div>
                        <div class="custom-multiselect-list" id="editSchoolsMultiselect">
                            <?php foreach ($allSchools as $school): ?>
                            <div class="custom-list-item" data-search="<?php echo htmlspecialchars(strtolower($school['name'])); ?>">
                                <div class="custom-list-item-left">
                                    <input type="checkbox" name="school_ids[]" id="edit_school_<?php echo $school['id']; ?>" value="<?php echo $school['id']; ?>" class="school-offer-cb" onchange="updateCountsAndStars('edit')">
                                    <label for="edit_school_<?php echo $school['id']; ?>"><?php echo htmlspecialchars($school['name']); ?> <span style="color:#64748b; font-size:0.8em;">(<?php echo htmlspecialchars($school['city']); ?>)</span></label>
                                </div>
                                <div>
                                    <input type="checkbox" name="is_specialization_<?php echo $school['id']; ?>" id="edit_is_specialization_<?php echo $school['id']; ?>" value="1" disabled class="school-spec-cb" style="display:none;" onchange="updateStarIcon(this)">
                                    <button type="button" class="spec-star-btn" id="edit_star_btn_<?php echo $school['id']; ?>" disabled onclick="toggleStarBtn('edit', <?php echo $school['id']; ?>)" title="Toggle Specialization">
                                        <i class="fa-regular fa-star"></i>
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php if (empty($allSchools)): ?>
                            <p style="color:#64748b; font-size:0.85rem; padding:0.5rem; text-align:center;">No schools found in system.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Possible Careers Card -->
                    <div class="modal-section-card">
                        <div class="section-header-row">
                            <div class="section-header-left">
                                <div class="section-icon-badge green">
                                    <i class="fa-solid fa-briefcase"></i>
                                </div>
                                <div>
                                    <h4 class="section-title-text">Possible Careers</h4>
                                    <p class="section-subtitle-text">Select all careers that apply to this course.</p>
                                </div>
                            </div>
                            <span class="selection-count-badge green" id="editJobCountBadge">0 selected</span>
                        </div>
                        <div class="section-search-box">
                            <i class="fa-solid fa-magnifying-glass search-icon"></i>
                            <input type="text" id="editJobSearch" placeholder="Search careers by name..." onkeyup="filterMultiselect('editJobSearch', 'editJobsMultiselect')">
                        </div>
                        <div class="list-table-head">
                            <span>Career Name</span>
                        </div>
                        <div class="custom-multiselect-list" id="editJobsMultiselect">
                            <?php foreach ($allJobs as $job): ?>
                            <div class="custom-list-item" data-search="<?php echo htmlspecialchars(strtolower($job['job_title'])); ?>">
                                <div class="custom-list-item-left">
                                    <input type="checkbox" name="job_ids[]" id="edit_job_<?php echo $job['id']; ?>" value="<?php echo $job['id']; ?>" onchange="updateCountsAndStars('edit')">
                                    <label for="edit_job_<?php echo $job['id']; ?>"><?php echo htmlspecialchars($job['job_title']); ?></label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php if (empty($allJobs)): ?>
                            <p style="color:#64748b; font-size:0.85rem; padding:0.5rem; text-align:center;">No careers found.</p>
                            <?php endif; ?>
                        </div>
                        <button type="button" class="btn-dashed-add-custom" id="editModalQuickAddBtn">
                            <i class="fa-solid fa-plus"></i> Add new job / career
                        </button>
                        <!-- Quick-add inline form -->
                        <div class="quick-add-job-form" id="editModalQuickForm" style="margin-top: 0.75rem;">
                            <div class="qa-row">
                                <input type="text" id="qaEditTitle" placeholder="Job title *" autocomplete="off">
                            </div>
                            <div class="qa-row">
                                <textarea id="qaEditDesc" rows="2" placeholder="Short description (optional)"></textarea>
                            </div>
                            <div class="qa-actions">
                                <button type="button" class="qa-cancel-btn" id="qaEditCancel">Cancel</button>
                                <button type="button" class="qa-save-btn" id="qaEditSave">Save &amp; Select</button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer" style="padding: 1rem 1.5rem; border-top: 1px solid rgba(255, 255, 255, 0.08); display: flex; justify-content: flex-end; gap: 0.75rem;">
                <button type="button" class="btn-secondary" id="cancelEdit" style="display:inline-flex; align-items:center; gap:0.4rem;">
                    <i class="fa-solid fa-xmark"></i> Cancel
                </button>
                <button type="submit" class="btn-primary" form="editCourseForm" style="display:inline-flex; align-items:center; gap:0.4rem; background: #6366f1; border-color: #6366f1;">
                    <i class="fa-solid fa-floppy-disk"></i> Save Changes
                </button>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal" id="deleteModal">
        <div class="modal-overlay"></div>
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fa-solid fa-exclamation-triangle"></i> Confirm Delete</h2>
                <button class="modal-close" id="closeDeleteModal">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="modal-body">
                <div class="delete-confirm">
                    <div class="delete-icon">
                        <i class="fa-solid fa-trash-alt"></i>
                    </div>
                    <p class="delete-message">Are you sure you want to delete this course?</p>
                    <p class="delete-warning">This action cannot be undone.</p>
                    <div class="delete-course-info">
                        <span class="course-name" id="deleteCourseName">Computer Science</span>
                        <span class="course-cluster" id="deleteCourseCluster">Information Technology</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" id="cancelDelete">Cancel</button>
                <button type="button" class="btn-danger" id="confirmDelete">Delete</button>
            </div>
        </div>
    </div>

    <!-- View Course Modal -->
    <div class="modal" id="viewCourseModal">
        <div class="modal-overlay"></div>
        <div class="modal-content">
            <div class="modal-header-custom">
                <div class="header-left-box">
                    <div class="icon-box-header" style="background: rgba(245, 158, 11, 0.15); color: #f59e0b;">
                        <i class="fa-solid fa-graduation-cap"></i>
                    </div>
                    <div class="header-titles">
                        <h2>Course Details</h2>
                        <p>View complete course specifications and institutional offerings</p>
                    </div>
                </div>
                <button class="modal-close" id="closeViewModal">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            
            <div class="view-modal-body">
                <!-- Course Hero Card -->
                <div class="course-hero-card">
                    <div class="course-hero-top">
                        <h3 class="course-hero-title" id="viewCourseName">—</h3>
                        <span class="course-cluster-pill" id="viewCourseClusterPill">
                            <i class="fa-solid fa-layer-group"></i>
                            <span id="viewCourseCluster">—</span>
                        </span>
                    </div>
                    <p class="course-description-box" id="viewCourseDescription">No description available.</p>
                </div>

                <!-- 2-Column Info: Linked Jobs & Offering Schools -->
                <div class="view-details-grid">
                    <!-- Linked Jobs -->
                    <div class="view-card-section">
                        <div class="view-card-header">
                            <h4 class="view-card-title">
                                <i class="fa-solid fa-briefcase" style="color: #818cf8;"></i>
                                Linked Careers & Jobs
                            </h4>
                            <span class="selection-count-badge purple" id="viewJobsCount">0 Jobs</span>
                        </div>
                        <div class="view-card-list" id="viewCourseJobsList">
                            <!-- Jobs populated dynamically -->
                        </div>
                    </div>

                    <!-- Offering Schools -->
                    <div class="view-card-section">
                        <div class="view-card-header">
                            <h4 class="view-card-title">
                                <i class="fa-solid fa-school" style="color: #fbbf24;"></i>
                                Offering Schools
                            </h4>
                            <span class="selection-count-badge green" id="viewSchoolsCount">0 Schools</span>
                        </div>
                        <div class="view-card-list" id="viewCourseSchoolsList">
                            <!-- Schools populated dynamically -->
                        </div>
                    </div>
                </div>
            </div>

            <div class="view-modal-footer">
                <button type="button" class="btn-close-view-modal" id="closeViewCourseBtn">Close</button>
                <button type="button" class="btn-edit-course-modal" id="viewEditCourseBtn">
                    <i class="fa-solid fa-pen"></i> Edit Course
                </button>
            </div>
        </div>
    </div>

    <!-- Status Modal -->
    <div class="modal" id="statusModal" style="z-index: 10000;">
        <div class="modal-overlay"></div>
        <div class="modal-content" style="max-width: 400px; text-align: center; border-radius: 12px; padding: 20px;">
            <div class="modal-body" style="padding: 1.5rem 1rem;">
                <div id="statusIcon" style="font-size: 3.5rem; margin-bottom: 1.2rem;"></div>
                <h2 id="statusTitle" style="font-size: 1.25rem; font-weight: 700; color: #f8fafc; margin-bottom: 0.5rem; border-bottom: none;"></h2>
                <p id="statusMessage" style="color: #cbd5e1; font-size: 0.95rem; margin-bottom: 1.5rem; line-height: 1.5;"></p>
                <button type="button" class="btn-primary" id="statusOkBtn" style="width: 100%; justify-content: center; padding: 0.75rem;">OK</button>
            </div>
        </div>
    </div>

    <script src="admin.js"></script>
    <script>
        // Manage Courses JavaScript
        document.addEventListener('DOMContentLoaded', function() {
            function showStatusModal(title, message, isSuccess, callback = null) {
                const modal = document.getElementById('statusModal');
                const icon = document.getElementById('statusIcon');
                const titleEl = document.getElementById('statusTitle');
                const msgEl = document.getElementById('statusMessage');
                const okBtn = document.getElementById('statusOkBtn');

                if (isSuccess) {
                    icon.innerHTML = '<i class="fa-solid fa-check-circle" style="color: #10b981;"></i>';
                } else {
                    icon.innerHTML = '<i class="fa-solid fa-times-circle" style="color: #ef4444;"></i>';
                }

                titleEl.textContent = title;
                msgEl.textContent = message;
                modal.classList.add('active');

                const handleClose = () => {
                    modal.classList.remove('active');
                    okBtn.removeEventListener('click', handleClose);
                    if (callback) callback();
                };

                okBtn.addEventListener('click', handleClose);
            }
            // Modal Elements
            const addModal = document.getElementById('addCourseModal');
            const editModal = document.getElementById('editCourseModal');
            const deleteModal = document.getElementById('deleteModal');
            const viewModal = document.getElementById('viewCourseModal');
            const closeViewModal = document.getElementById('closeViewModal');
            const closeViewCourseBtn = document.getElementById('closeViewCourseBtn');
            const viewEditCourseBtn = document.getElementById('viewEditCourseBtn');

            // Open Add Modal
            document.getElementById('addCourseBtn').addEventListener('click', () => {
                addModal.classList.add('active');
            });

            // Close Modals
            document.getElementById('closeAddModal').addEventListener('click', () => {
                addModal.classList.remove('active');
            });
            document.getElementById('cancelAdd').addEventListener('click', () => {
                addModal.classList.remove('active');
            });

            document.getElementById('closeEditModal').addEventListener('click', () => {
                editModal.classList.remove('active');
            });
            document.getElementById('cancelEdit').addEventListener('click', () => {
                editModal.classList.remove('active');
            });

            document.getElementById('closeDeleteModal').addEventListener('click', () => {
                deleteModal.classList.remove('active');
            });
            document.getElementById('cancelDelete').addEventListener('click', () => {
                deleteModal.classList.remove('active');
            });

            // Close View Modal
            function closeView() {
                viewModal.classList.remove('active');
            }
            if (closeViewModal) closeViewModal.addEventListener('click', closeView);
            if (closeViewCourseBtn) closeViewCourseBtn.addEventListener('click', closeView);

            // View Button Handlers
            document.querySelectorAll('.btn-action.view').forEach(btn => {
                btn.addEventListener('click', async () => {
                    const courseId = btn.dataset.id;
                    const fd = new FormData();
                    fd.append('action', 'get_course_details');
                    fd.append('id', courseId);
                    
                    try {
                        const data = await apiPost(fd);
                        if (data.success && data.course) {
                            const course = data.course;
                            const nameEl = document.getElementById('viewCourseName');
                            if (nameEl) nameEl.textContent = course.course_name || '—';
                            
                            const clusterEl = document.getElementById('viewCourseCluster');
                            if (clusterEl) clusterEl.textContent = course.cluster_name || 'Unassigned Cluster';
                            
                            const descEl = document.getElementById('viewCourseDescription');
                            if (descEl) descEl.textContent = course.description || 'No detailed description provided for this course.';
                            
                            // Set data-id on Edit Course button inside view modal
                            viewEditCourseBtn.setAttribute('data-id', course.id);
                            
                            // Render Jobs
                            const jobsList = document.getElementById('viewCourseJobsList');
                            const jobsCountEl = document.getElementById('viewJobsCount');
                            jobsList.innerHTML = '';
                            if (data.jobs && data.jobs.length > 0) {
                                if (jobsCountEl) jobsCountEl.textContent = `${data.jobs.length} Job${data.jobs.length > 1 ? 's' : ''}`;
                                data.jobs.forEach(job => {
                                    const item = document.createElement('div');
                                    item.style.padding = '0.55rem 0.75rem';
                                    item.style.background = 'rgba(15, 23, 42, 0.5)';
                                    item.style.border = '1px solid rgba(255, 255, 255, 0.05)';
                                    item.style.borderRadius = '8px';
                                    item.style.color = '#e2e8f0';
                                    item.style.fontSize = '0.88rem';
                                    item.style.display = 'flex';
                                    item.style.alignItems = 'center';
                                    item.style.gap = '0.6rem';
                                    item.innerHTML = `<i class="fa-solid fa-circle-chevron-right" style="color: #818cf8; font-size: 0.75rem; flex-shrink: 0;"></i> <span>${escHtml(job)}</span>`;
                                    jobsList.appendChild(item);
                                });
                            } else {
                                if (jobsCountEl) jobsCountEl.textContent = '0 Jobs';
                                jobsList.innerHTML = '<p style="color: #64748b; font-style: italic; font-size: 0.85rem; margin: 0; padding: 0.5rem 0;">No linked careers or jobs.</p>';
                            }
                            
                            // Render Schools
                            const schoolsList = document.getElementById('viewCourseSchoolsList');
                            const schoolsCountEl = document.getElementById('viewSchoolsCount');
                            schoolsList.innerHTML = '';
                            if (data.schools && data.schools.length > 0) {
                                if (schoolsCountEl) schoolsCountEl.textContent = `${data.schools.length} School${data.schools.length > 1 ? 's' : ''}`;
                                data.schools.forEach(school => {
                                    const item = document.createElement('div');
                                    item.style.padding = '0.55rem 0.75rem';
                                    item.style.background = 'rgba(15, 23, 42, 0.5)';
                                    item.style.border = '1px solid rgba(255, 255, 255, 0.05)';
                                    item.style.borderRadius = '8px';
                                    item.style.color = '#e2e8f0';
                                    item.style.fontSize = '0.88rem';
                                    item.style.display = 'flex';
                                    item.style.alignItems = 'center';
                                    item.style.justifyContent = 'space-between';
                                    item.style.gap = '0.5rem';
                                    
                                    let content = `<div style="display:flex;align-items:center;gap:0.6rem;min-width:0;"><i class="fa-solid fa-building-columns" style="color: #fbbf24; font-size: 0.8rem; flex-shrink:0;"></i> <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${escHtml(school.name)}</span></div>`;
                                    if (school.is_specialization === 1) {
                                        content += `<span class="specialization-badge" style="background: rgba(251, 191, 36, 0.15); color: #fbbf24; font-size: 0.72rem; font-weight: 700; padding: 0.2rem 0.5rem; border-radius: 999px; border: 1px solid rgba(251, 191, 36, 0.3); display: inline-flex; align-items: center; gap: 0.3rem; flex-shrink:0;"><i class="fa-solid fa-star" style="font-size: 0.65rem;"></i> Specialization</span>`;
                                    }
                                    item.innerHTML = content;
                                    schoolsList.appendChild(item);
                                });
                            } else {
                                if (schoolsCountEl) schoolsCountEl.textContent = '0 Schools';
                                schoolsList.innerHTML = '<p style="color: #64748b; font-style: italic; font-size: 0.85rem; margin: 0; padding: 0.5rem 0;">No offering schools.</p>';
                            }
                            
                            viewModal.classList.add('active');
                        } else {
                            alert(data.message || 'Failed to load course details');
                        }
                    } catch (e) {
                        console.error(e);
                        alert('Error loading course details');
                    }
                });
            });

            // Edit button inside View Modal
            viewEditCourseBtn.addEventListener('click', function() {
                const courseId = this.getAttribute('data-id');
                closeView();
                // Find and trigger the corresponding edit button on the main course list
                const editBtn = document.querySelector(`.btn-action.edit[data-id="${courseId}"]`);
                if (editBtn) {
                    editBtn.click();
                }
            });

            // AJAX helper
            async function apiPost(formData) {
                const res = await fetch('manage_courses.php', { method: 'POST', body: formData });
                return res.json();
            }

            // ── Multi-select counter & star helpers ────────────────────
            window.updateCountsAndStars = function(prefix) {
                const p = prefix === 'add' ? 'add' : 'edit';
                const schoolContainer = document.getElementById(`${p}SchoolsMultiselect`);
                if (schoolContainer) {
                    const checkedSchools = schoolContainer.querySelectorAll('.school-offer-cb:checked').length;
                    const badge = document.getElementById(`${p}SchoolCountBadge`);
                    if (badge) badge.textContent = `${checkedSchools} selected`;
                }
                const jobContainer = document.getElementById(`${p}JobsMultiselect`);
                if (jobContainer) {
                    const checkedJobs = jobContainer.querySelectorAll('input[type="checkbox"]:checked').length;
                    const badge = document.getElementById(`${p}JobCountBadge`);
                    if (badge) badge.textContent = `${checkedJobs} selected`;
                }
            };

            window.filterMultiselect = function(inputId, containerId) {
                const query = (document.getElementById(inputId)?.value || '').toLowerCase().trim();
                const items = document.querySelectorAll(`#${containerId} .custom-list-item`);
                items.forEach(item => {
                    const text = (item.getAttribute('data-search') || item.textContent).toLowerCase();
                    item.style.display = (!query || text.includes(query)) ? 'flex' : 'none';
                });
            };

            window.toggleStarBtn = function(prefix, schoolId) {
                const p = prefix === 'add' ? 'add_' : 'edit_';
                const specCb = document.getElementById(`${p}is_specialization_${schoolId}`);
                const starBtn = document.getElementById(`${p}star_btn_${schoolId}`);
                if (specCb && !specCb.disabled) {
                    specCb.checked = !specCb.checked;
                    if (specCb.checked) {
                        starBtn.classList.add('active');
                        starBtn.innerHTML = '<i class="fa-solid fa-star"></i>';
                    } else {
                        starBtn.classList.remove('active');
                        starBtn.innerHTML = '<i class="fa-regular fa-star"></i>';
                    }
                }
            };

            function uncheckAllJobBoxes(prefix) {
                document.querySelectorAll(`input[id^="${prefix}job_"]`).forEach(cb => cb.checked = false);
                updateCountsAndStars(prefix.replace('_', ''));
            }
            function uncheckAllSchoolBoxes(prefix) {
                document.querySelectorAll(`input[id^="${prefix}school_"]`).forEach(cb => {
                    cb.checked = false;
                });
                document.querySelectorAll(`input[id^="${prefix}is_specialization_"]`).forEach(cb => {
                    cb.checked = false;
                    cb.disabled = true;
                });
                document.querySelectorAll(`button[id^="${prefix}star_btn_"]`).forEach(btn => {
                    btn.disabled = true;
                    btn.classList.remove('active');
                    btn.innerHTML = '<i class="fa-regular fa-star"></i>';
                });
                updateCountsAndStars(prefix.replace('_', ''));
            }
            function uncheckAllTraitBoxes(prefix) {
                document.querySelectorAll(`input[name="holland_traits[]"][id^="${prefix}"], input[name="bigfive_traits[]"][id^="${prefix}"], input[name="shs_strands[]"][id^="${prefix}"]`).forEach(cb => {
                    cb.checked = false;
                    const pill = cb.closest('.trait-pill');
                    if (pill) pill.classList.remove('is-checked');
                });
            }

            document.querySelectorAll('.trait-pill input[type="checkbox"]').forEach(input => {
                input.addEventListener('change', function() {
                    const pill = this.closest('.trait-pill');
                    if (pill) {
                        pill.classList.toggle('is-checked', this.checked);
                    }
                });
            });

            // Listen for changes on school offer checkboxes to enable/disable specialization star
            document.querySelectorAll('.school-offer-cb').forEach(cb => {
                cb.addEventListener('change', function() {
                    const id = this.value;
                    const prefix = this.id.includes('edit_') ? 'edit_' : 'add_';
                    const specCb = document.getElementById(`${prefix}is_specialization_${id}`);
                    const starBtn = document.getElementById(`${prefix}star_btn_${id}`);
                    if (specCb) {
                        specCb.disabled = !this.checked;
                        if (!this.checked) {
                            specCb.checked = false;
                        }
                    }
                    if (starBtn) {
                        starBtn.disabled = !this.checked;
                        if (!this.checked) {
                            starBtn.classList.remove('active');
                            starBtn.innerHTML = '<i class="fa-regular fa-star"></i>';
                        }
                    }
                    updateCountsAndStars(prefix.replace('_', ''));
                });
            });
            function checkJobBoxes(prefix, ids) {
                ids.forEach(id => {
                    const cb = document.getElementById(`${prefix}job_${id}`);
                    if (cb) cb.checked = true;
                });
                updateCountsAndStars(prefix.replace('_', ''));
            }
            function addJobCheckboxToMultiselect(container, job, prefix, checked = true) {
                const placeholder = container.querySelector('p');
                if (placeholder) placeholder.remove();
                const item = document.createElement('div');
                item.className = 'custom-list-item';
                item.setAttribute('data-search', job.job_title.toLowerCase());
                item.innerHTML = `<div class="custom-list-item-left">
                    <input type="checkbox" name="job_ids[]" id="${prefix}job_${job.id}" value="${job.id}" ${checked ? 'checked' : ''} onchange="updateCountsAndStars('${prefix.replace('_','')}')">
                    <label for="${prefix}job_${job.id}">${escHtml(job.job_title)}</label>
                </div>`;
                container.appendChild(item);
                updateCountsAndStars(prefix.replace('_', ''));
            }
            function escHtml(str) {
                const d = document.createElement('div');
                d.textContent = str;
                return d.innerHTML;
            }

            // ── Quick-add job (Add modal) ─────────────────────────────
            document.getElementById('addModalQuickAddBtn').addEventListener('click', () => {
                document.getElementById('addModalQuickForm').classList.add('visible');
                document.getElementById('qaAddTitle').focus();
            });
            document.getElementById('qaAddCancel').addEventListener('click', () => {
                document.getElementById('addModalQuickForm').classList.remove('visible');
                document.getElementById('qaAddTitle').value = '';
                document.getElementById('qaAddDesc').value  = '';
            });
            document.getElementById('qaAddSave').addEventListener('click', async () => {
                const title = document.getElementById('qaAddTitle').value.trim();
                if (!title) { alert('Job title is required.'); return; }
                const fd = new FormData();
                fd.append('action', 'add_job_inline');
                fd.append('job_title', title);
                fd.append('description', document.getElementById('qaAddDesc').value.trim());
                try {
                    const data = await apiPost(fd);
                    if (data.success) {
                        const container = document.getElementById('addJobsMultiselect');
                        // Only add if not already in DOM
                        if (!document.getElementById(`add_job_${data.job.id}`)) {
                            addJobCheckboxToMultiselect(container, data.job, 'add_', true);
                            // Also add to edit modal so it shows next time
                            const editContainer = document.getElementById('editJobsMultiselect');
                            if (!document.getElementById(`edit_job_${data.job.id}`)) {
                                addJobCheckboxToMultiselect(editContainer, data.job, 'edit_', false);
                            }
                        } else {
                            document.getElementById(`add_job_${data.job.id}`).checked = true;
                        }
                        document.getElementById('addModalQuickForm').classList.remove('visible');
                        document.getElementById('qaAddTitle').value = '';
                        document.getElementById('qaAddDesc').value  = '';
                    } else {
                        alert(data.message || 'Failed to add job.');
                    }
                } catch { alert('Error adding job.'); }
            });

            // ── Quick-add job (Edit modal) ────────────────────────────
            document.getElementById('editModalQuickAddBtn').addEventListener('click', () => {
                document.getElementById('editModalQuickForm').classList.add('visible');
                document.getElementById('qaEditTitle').focus();
            });
            document.getElementById('qaEditCancel').addEventListener('click', () => {
                document.getElementById('editModalQuickForm').classList.remove('visible');
                document.getElementById('qaEditTitle').value = '';
                document.getElementById('qaEditDesc').value  = '';
            });
            document.getElementById('qaEditSave').addEventListener('click', async () => {
                const title = document.getElementById('qaEditTitle').value.trim();
                if (!title) { alert('Job title is required.'); return; }
                const fd = new FormData();
                fd.append('action', 'add_job_inline');
                fd.append('job_title', title);
                fd.append('description', document.getElementById('qaEditDesc').value.trim());
                try {
                    const data = await apiPost(fd);
                    if (data.success) {
                        const editContainer = document.getElementById('editJobsMultiselect');
                        if (!document.getElementById(`edit_job_${data.job.id}`)) {
                            addJobCheckboxToMultiselect(editContainer, data.job, 'edit_', true);
                            // Also mirror to add modal
                            const addContainer = document.getElementById('addJobsMultiselect');
                            if (!document.getElementById(`add_job_${data.job.id}`)) {
                                addJobCheckboxToMultiselect(addContainer, data.job, 'add_', false);
                            }
                        } else {
                            document.getElementById(`edit_job_${data.job.id}`).checked = true;
                        }
                        document.getElementById('editModalQuickForm').classList.remove('visible');
                        document.getElementById('qaEditTitle').value = '';
                        document.getElementById('qaEditDesc').value  = '';
                    } else {
                        alert(data.message || 'Failed to add job.');
                    }
                } catch { alert('Error adding job.'); }
            });

            // ── Open Add modal: clear checkboxes ─────────────────────
            document.getElementById('addCourseBtn').addEventListener('click', () => {
                uncheckAllJobBoxes('add_');
                uncheckAllSchoolBoxes('add_');
                uncheckAllTraitBoxes('add_');
                document.getElementById('addModalQuickForm').classList.remove('visible');
            });

            // Edit Button Handlers
            document.querySelectorAll('.btn-action.edit').forEach(btn => {
                btn.addEventListener('click', async () => {
                    const courseId = btn.dataset.id;
                    
                    // Fetch course data
                    const fd = new FormData();
                    fd.append('action', 'get_course');
                    fd.append('id', courseId);
                    try {
                        const data = await apiPost(fd);
                        if (data.success && data.course) {
                            const course = data.course;
                            document.getElementById('editCourseId').value = course.id;
                            document.getElementById('editCourseName').value = course.course_name || '';
                            document.getElementById('editCluster').value = course.cluster_id || '';
                            document.getElementById('editDescription').value = course.description || '';

                            uncheckAllTraitBoxes('edit_');
                            if (course.holland_traits) {
                                course.holland_traits.split(',').forEach(t => {
                                    const cb = document.getElementById(`edit_ht_${t.trim()}`);
                                    if (cb) {
                                        cb.checked = true;
                                        const pill = cb.closest('.trait-pill');
                                        if (pill) pill.classList.add('is-checked');
                                    }
                                });
                            }
                            if (course.bigfive_traits) {
                                course.bigfive_traits.split(',').forEach(t => {
                                    const cb = document.getElementById(`edit_bf_${t.trim()}`);
                                    if (cb) {
                                        cb.checked = true;
                                        const pill = cb.closest('.trait-pill');
                                        if (pill) pill.classList.add('is-checked');
                                    }
                                });
                            }
                            if (course.shs_strands) {
                                course.shs_strands.split(',').forEach(t => {
                                    const cb = document.getElementById(`edit_st_${t.trim()}`);
                                    if (cb) {
                                        cb.checked = true;
                                        const pill = cb.closest('.trait-pill');
                                        if (pill) pill.classList.add('is-checked');
                                    }
                                });
                            }

                            // Pre-check the linked jobs
                            uncheckAllJobBoxes('edit_');
                            uncheckAllSchoolBoxes('edit_');
                            document.getElementById('editModalQuickForm').classList.remove('visible');
                            const fdJobs = new FormData();
                            fdJobs.append('action', 'get_course_jobs');
                            fdJobs.append('id', courseId);
                            const jobData = await apiPost(fdJobs);
                            if (jobData.success) {
                                checkJobBoxes('edit_', jobData.job_ids);
                            }

                            // Fetch and pre-check linked schools
                            const fdSchools = new FormData();
                            fdSchools.append('action', 'get_course_schools');
                            fdSchools.append('id', courseId);
                            const schoolData = await apiPost(fdSchools);
                            if (schoolData.success) {
                                schoolData.schools.forEach(item => {
                                    const schCb = document.getElementById(`edit_school_${item.school_id}`);
                                    if (schCb) {
                                        schCb.checked = true;
                                        const specCb = document.getElementById(`edit_is_specialization_${item.school_id}`);
                                        const starBtn = document.getElementById(`edit_star_btn_${item.school_id}`);
                                        if (specCb) {
                                            specCb.disabled = false;
                                            if (item.is_specialization === 1) {
                                                specCb.checked = true;
                                            }
                                        }
                                        if (starBtn) {
                                            starBtn.disabled = false;
                                            if (item.is_specialization === 1) {
                                                starBtn.classList.add('active');
                                                starBtn.innerHTML = '<i class="fa-solid fa-star"></i>';
                                            }
                                        }
                                    }
                                });
                                updateCountsAndStars('edit');
                            }

                            editModal.classList.add('active');
                        } else {
                            alert(data.message || 'Failed to load course data');
                        }
                    } catch (e) {
                        alert('Error loading course data');
                    }
                });
            });

            // Delete Button Handlers
            let deleteCourseId = null;
            document.querySelectorAll('.btn-action.delete').forEach(btn => {
                btn.addEventListener('click', () => {
                    const row = btn.closest('tr');
                    const courseName = row.querySelector('.course-name').textContent.trim();
                    deleteCourseId = btn.dataset.id;
                    document.getElementById('deleteCourseName').textContent = courseName;
                    deleteModal.classList.add('active');
                });
            });

            // Form Validation - Add Course
            document.getElementById('addCourseForm').addEventListener('submit', async function(e) {
                e.preventDefault();
                
                const formData = new FormData(this);
                formData.append('action', 'add_course');
                
                try {
                    const data = await apiPost(formData);
                    if (data.success) {
                        showStatusModal('Success', 'Course added successfully!', true, () => location.reload());
                    } else {
                        showStatusModal('Error', data.message || 'Failed to add course', false);
                    }
                } catch (e) {
                    showStatusModal('Error', 'Error adding course', false);
                }
            });

            // Form Validation - Edit Course
            document.getElementById('editCourseForm').addEventListener('submit', async function(e) {
                e.preventDefault();
                
                const formData = new FormData(this);
                formData.append('action', 'edit_course');
                
                try {
                    const data = await apiPost(formData);
                    if (data.success) {
                        showStatusModal('Success', 'Course updated successfully!', true, () => location.reload());
                    } else {
                        showStatusModal('Error', data.message || 'Failed to update course', false);
                    }
                } catch (e) {
                    showStatusModal('Error', 'Error updating course', false);
                }
            });

            // Confirm Delete
            document.getElementById('confirmDelete').addEventListener('click', async () => {
                if (!deleteCourseId) return;
                
                const fd = new FormData();
                fd.append('action', 'delete_course');
                fd.append('id', deleteCourseId);
                
                deleteModal.classList.remove('active');
                try {
                    const data = await apiPost(fd);
                    if (data.success) {
                        showStatusModal('Success', 'Course deleted successfully!', true, () => location.reload());
                    } else {
                        showStatusModal('Error', data.message || 'Failed to delete course', false);
                    }
                } catch (e) {
                    showStatusModal('Error', 'Error deleting course', false);
                }
            });

            // Search Functionality
            const searchInput = document.getElementById('searchInput');
            const clearBtn = document.getElementById('clearFilter');

            searchInput.addEventListener('input', () => {
                const searchTerm = searchInput.value.toLowerCase().trim();
                const rows = document.querySelectorAll('.courses-table tbody tr');
                let visibleCount = 0;

                rows.forEach(row => {
                    const courseName = row.querySelector('.course-name')?.textContent.toLowerCase() || '';
                    const cluster = row.querySelector('.cluster-badge')?.textContent.toLowerCase() || '';
                    const description = row.querySelector('.description-cell')?.textContent.toLowerCase() || '';
                    
                    if (courseName.includes(searchTerm) || cluster.includes(searchTerm) || 
                        description.includes(searchTerm)) {
                        row.style.display = '';
                        visibleCount++;
                    } else {
                        row.style.display = 'none';
                    }
                });

                const totalSpan = document.getElementById('totalCourses');
                if (totalSpan) totalSpan.textContent = visibleCount;
            });

            clearBtn.addEventListener('click', () => {
                searchInput.value = '';
                document.querySelectorAll('.courses-table tbody tr').forEach(row => {
                    row.style.display = '';
                });
                const totalSpan = document.getElementById('totalCourses');
                if (totalSpan) totalSpan.textContent = document.querySelectorAll('.courses-table tbody tr:not(.no-data-row)').length;
            });

            // Close modals on overlay click
            document.querySelectorAll('.modal-overlay').forEach(overlay => {
                overlay.addEventListener('click', function() {
                    this.parentElement.classList.remove('active');
                });
            });
        });
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
            document.getElementById('notificationDropdown').style.display = 'none';
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
    <?php require_once __DIR__ . '/includes/session_timeout_footer.php'; ?>
</body>
</html>
