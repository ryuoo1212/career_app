<?php
// Manage Jobs

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

}

// ─────────────────────────────────────────────────────────────
// AJAX handlers
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];
    $action   = $_POST['action'];

    switch ($action) {

        // ── Add Job ──────────────────────────────────────────
        case 'add_job':
            $title = trim($_POST['job_title'] ?? '');
            $desc  = trim($_POST['description'] ?? '');
            $clusterId = isset($_POST['cluster_id']) && $_POST['cluster_id'] !== '' ? (int)$_POST['cluster_id'] : null;

            if (empty($title)) {
                $response['message'] = 'Job title is required.';
                echo json_encode($response); exit;
            }

            // Duplicate check
            $chk = $mysqli->prepare("SELECT id FROM jobs WHERE job_title = ? LIMIT 1");
            $chk->bind_param('s', $title);
            $chk->execute();
            $chk->store_result();
            if ($chk->num_rows > 0) {
                $response['message'] = 'A job with this title already exists.';
                $chk->close();
                echo json_encode($response); exit;
            }
            $chk->close();

            $stmt = $mysqli->prepare("INSERT INTO jobs (job_title, description, cluster_id, created_at) VALUES (?, ?, ?, NOW())");
            $stmt->bind_param('ssi', $title, $desc, $clusterId);
            if ($stmt->execute()) {
                $insertedId          = $mysqli->insert_id;
                
                // Link courses if provided
                if (isset($_POST['offered_course_ids']) && is_array($_POST['offered_course_ids'])) {
                    $newCourses = array_map('intval', $_POST['offered_course_ids']);
                    if (!empty($newCourses)) {
                        $addStmt = $mysqli->prepare("INSERT IGNORE INTO course_jobs (job_id, course_id) VALUES (?, ?)");
                        if ($addStmt) {
                            foreach ($newCourses as $cid) {
                                if ($cid > 0) {
                                    $addStmt->bind_param('ii', $insertedId, $cid);
                                    $addStmt->execute();
                                }
                            }
                            $addStmt->close();
                        }
                    }
                }

                $response['success'] = true;
                $response['message'] = 'Job added successfully.';
                $response['id']      = $insertedId;
                $response['job']     = ['id' => $insertedId, 'job_title' => $title, 'description' => $desc, 'cluster_id' => $clusterId];

                // Audit log
                $userId = $_SESSION['admin_id'] ?? $_SESSION['counselor_id'] ?? 0;
                $userType = isset($_SESSION['admin_id']) ? 'admin' : 'counselor';
                log_activity($userId, $userType, 'Added Job', 'jobs', $insertedId, "Admin added job: {$title}", null, json_encode($response['job']));
            } else {
                $response['message'] = 'Failed to add job: ' . $stmt->error;
            }
            $stmt->close();
            echo json_encode($response); exit;

        // ── Get Job ──────────────────────────────────────────
        case 'get_job':
            $jobId = (int)($_POST['id'] ?? 0);
            if ($jobId <= 0) {
                $response['message'] = 'Invalid job ID.';
                echo json_encode($response); exit;
            }
            $stmt = $mysqli->prepare("SELECT * FROM jobs WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $jobId);
            $stmt->execute();
            $job = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($job) {
                $response['success'] = true;
                
                // Fetch linked courses
                $job['courses'] = [];
                $cStmt = $mysqli->prepare("SELECT c.id AS course_id, c.course_name FROM course_jobs cj JOIN courses c ON cj.course_id = c.id WHERE cj.job_id = ? ORDER BY c.course_name");
                if ($cStmt) {
                    $cStmt->bind_param('i', $jobId);
                    $cStmt->execute();
                    $res = $cStmt->get_result();
                    while ($row = $res->fetch_assoc()) {
                        $job['courses'][] = $row;
                    }
                    $cStmt->close();
                }
                
                // Fetch linked competencies
                $job['competencies'] = [];
                $compStmt = $mysqli->prepare("SELECT c.id AS competency_id, c.name FROM job_competencies jc JOIN competencies c ON jc.competency_id = c.id WHERE jc.job_id = ? ORDER BY c.name");
                if ($compStmt) {
                    $compStmt->bind_param('i', $jobId);
                    $compStmt->execute();
                    $res = $compStmt->get_result();
                    while ($row = $res->fetch_assoc()) {
                        $job['competencies'][] = $row;
                    }
                    $compStmt->close();
                }

                $response['job']     = $job;
            } else {
                $response['message'] = 'Job not found.';
            }
            echo json_encode($response); exit;

        // ── Edit Job ─────────────────────────────────────────
        case 'edit_job':
            $jobId = (int)($_POST['id'] ?? 0);
            $title = trim($_POST['job_title'] ?? '');
            $desc  = trim($_POST['description'] ?? '');
            $clusterId = isset($_POST['cluster_id']) && $_POST['cluster_id'] !== '' ? (int)$_POST['cluster_id'] : null;

            if ($jobId <= 0 || empty($title)) {
                $response['message'] = 'Invalid data provided.';
                echo json_encode($response); exit;
            }

            // Duplicate check (exclude self)
            $chk = $mysqli->prepare("SELECT id FROM jobs WHERE job_title = ? AND id != ? LIMIT 1");
            $chk->bind_param('si', $title, $jobId);
            $chk->execute();
            $chk->store_result();
            if ($chk->num_rows > 0) {
                $response['message'] = 'Another job with this title already exists.';
                $chk->close();
                echo json_encode($response); exit;
            }
            $chk->close();

            // Fetch old value for audit logging
            $oldJob = null;
            $oldStmt = $mysqli->prepare("SELECT * FROM jobs WHERE id = ?");
            if ($oldStmt) {
                $oldStmt->bind_param('i', $jobId);
                $oldStmt->execute();
                $oldJob = $oldStmt->get_result()->fetch_assoc();
                $oldStmt->close();
            }

            $stmt = $mysqli->prepare("UPDATE jobs SET job_title = ?, description = ?, cluster_id = ? WHERE id = ?");
            $stmt->bind_param('ssii', $title, $desc, $clusterId, $jobId);
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = 'Job updated successfully.';
                
                // Sync courses
                if (isset($_POST['offered_course_ids']) && is_array($_POST['offered_course_ids'])) {
                    $newCourses = array_map('intval', $_POST['offered_course_ids']);
                    $currentCourses = [];
                    $crs = $mysqli->query("SELECT course_id FROM course_jobs WHERE job_id = $jobId");
                    while ($r = $crs->fetch_assoc()) {
                        $currentCourses[] = (int)$r['course_id'];
                    }
                    
                    $toAdd = array_diff($newCourses, $currentCourses);
                    $toRemove = array_diff($currentCourses, $newCourses);
                    
                    if (!empty($toRemove)) {
                        $remList = implode(',', $toRemove);
                        $mysqli->query("DELETE FROM course_jobs WHERE job_id = $jobId AND course_id IN ($remList)");
                    }
                    if (!empty($toAdd)) {
                        $addStmt = $mysqli->prepare("INSERT IGNORE INTO course_jobs (job_id, course_id) VALUES (?, ?)");
                        foreach ($toAdd as $cid) {
                            $addStmt->bind_param('ii', $jobId, $cid);
                            $addStmt->execute();
                        }
                        $addStmt->close();
                    }
                } else {
                    $mysqli->query("DELETE FROM course_jobs WHERE job_id = $jobId");
                }


                // Audit log
                $userId = $_SESSION['admin_id'] ?? $_SESSION['counselor_id'] ?? 0;
                $userType = isset($_SESSION['admin_id']) ? 'admin' : 'counselor';
                $newJob = ['id' => $jobId, 'job_title' => $title, 'description' => $desc, 'cluster_id' => $clusterId];
                
                // Identify changed fields (We now log full objects instead of just diffs)
                $oldChanges = $oldJob;
                $newChanges = $newJob;
                log_activity(
                    $userId,
                    $userType,
                    'Edited Job',
                    'jobs',
                    $jobId,
                    "Admin edited job #{$jobId} ({$title})",
                    !empty($oldChanges) ? json_encode($oldChanges) : null,
                    !empty($newChanges) ? json_encode($newChanges) : null
                );
            } else {
                $response['message'] = 'Failed to update job.';
            }
            $stmt->close();
            echo json_encode($response); exit;

        case 'delete_job':
            $jobId = (int)($_POST['id'] ?? 0);
            if ($jobId <= 0) {
                $response['message'] = 'Invalid job ID.';
                echo json_encode($response); exit;
            }

            // Fetch old value for audit logging
            $oldJob = null;
            $oldStmt = $mysqli->prepare("SELECT * FROM jobs WHERE id = ?");
            if ($oldStmt) {
                $oldStmt->bind_param('i', $jobId);
                $oldStmt->execute();
                $oldJob = $oldStmt->get_result()->fetch_assoc();
                $oldStmt->close();
            }

            // course_jobs rows are removed via ON DELETE CASCADE
            $stmt = $mysqli->prepare("DELETE FROM jobs WHERE id = ?");
            $stmt->bind_param('i', $jobId);
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = 'Job deleted successfully.';

                // Audit log
                $userId = $_SESSION['admin_id'] ?? $_SESSION['counselor_id'] ?? 0;
                $userType = isset($_SESSION['admin_id']) ? 'admin' : 'counselor';
                $jobTitle = $oldJob['job_title'] ?? "ID {$jobId}";
                log_activity($userId, $userType, 'Deleted Job', 'jobs', $jobId, "Admin deleted job: {$jobTitle}", json_encode($oldJob), null);
            } else {
                $response['message'] = 'Failed to delete job.';
            }
            $stmt->close();
            echo json_encode($response); exit;

        // ── Get all jobs (for course modal inline picker) ────
        case 'get_all_jobs':
            $result = $mysqli->query("SELECT id, job_title FROM jobs ORDER BY job_title");
            $jobs   = [];
            while ($row = $result->fetch_assoc()) {
                $jobs[] = $row;
            }
            $response['success'] = true;
            $response['jobs']    = $jobs;
            echo json_encode($response); exit;

        case 'get_job_courses':
            $jobId = (int)($_POST['id'] ?? 0);
            if ($jobId <= 0) {
                $response['message'] = 'Invalid job ID.';
                echo json_encode($response); exit;
            }
            $stmt = $mysqli->prepare("SELECT c.course_name FROM course_jobs cj JOIN courses c ON cj.course_id = c.id WHERE cj.job_id = ? ORDER BY c.course_name");
            if ($stmt) {
                $stmt->bind_param('i', $jobId);
                $stmt->execute();
                $result = $stmt->get_result();
                $courses = [];
                while ($row = $result->fetch_assoc()) {
                    $courses[] = $row['course_name'];
                }
                $stmt->close();
                $response['success'] = true;
                $response['courses'] = $courses;
            } else {
                $response['message'] = 'Database error: failed to prepare statement.';
            }
            echo json_encode($response); exit;
    }
}

// ─────────────────────────────────────────────────────────────
// Page data
// ─────────────────────────────────────────────────────────────
$jobs = [];
$result = $mysqli->query("SELECT j.*, c.name AS cluster_name, c.color AS cluster_color FROM jobs j LEFT JOIN clusters c ON j.cluster_id = c.id ORDER BY j.job_title");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $jobs[] = $row;
    }
}

// Fetch all courses for the multi-select
$allCourses = [];
$cRes = $mysqli->query("SELECT id, course_name FROM courses ORDER BY course_name");
if ($cRes) {
    while ($row = $cRes->fetch_assoc()) $allCourses[] = $row;
}

// Fetch all competencies for the multi-select
$allCompetencies = [];
$compRes = $mysqli->query("SELECT id, name FROM competencies ORDER BY name");
if ($compRes) {
    while ($row = $compRes->fetch_assoc()) $allCompetencies[] = $row;
}

// Fetch all clusters for the dropdown
$allClusters = [];
$clRes = $mysqli->query("SELECT id, name FROM clusters ORDER BY name");
if ($clRes) {
    while ($row = $clRes->fetch_assoc()) $allClusters[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Jobs - <?php echo htmlspecialchars(getSystemConfig('name')); ?></title>
    <meta name="description" content="Manage job/career positions and link them to courses for the CareerPath recommendation system.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="admin.css">
    <style>
        /* ── Layout: kill dead space ── */
        .dashboard-content {
            padding-top: 1.25rem !important;
        }
        .page-header {
            margin-top: 0 !important;
            padding-top: 0 !important;
        }
        /* Compact the empty-state no-data cell */
        td.no-data {
            padding: 2.5rem 1rem !important;
            text-align: center;
            color: var(--text-secondary, #94a3b8);
        }
        td.no-data i {
            font-size: 1.8rem;
            display: block;
            margin-bottom: .5rem;
            opacity: .35;
        }
        td.no-data p {
            margin: 0;
            font-size: .9rem;
        }

        /* Table description truncation */
        .desc-cell {
            max-width: 320px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            color: var(--text-secondary, #94a3b8);
            font-size: .875rem;
        }

        /* Job title cell */
        .job-title-cell {
            font-weight: 600;
            color: var(--text-primary, #f1f5f9);
        }
        .job-title-cell .job-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 30px; height: 30px;
            border-radius: 8px;
            background: rgba(245,158,11,.15);
            color: #f59e0b;
            margin-right: .55rem;
            font-size: .8rem;
            vertical-align: middle;
        }

        /* Inline quick-add job button inside modal */
        .inline-add-job-btn {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            font-size: .8rem;
            font-weight: 600;
            color: #f59e0b;
            background: rgba(245,158,11,.12);
            border: 1px dashed rgba(245,158,11,.45);
            border-radius: 8px;
            padding: .35rem .8rem;
            cursor: pointer;
            transition: background .2s, border-color .2s;
            margin-top: .5rem;
        }
        .inline-add-job-btn:hover {
            background: rgba(245,158,11,.22);
            border-color: rgba(245,158,11,.7);
        }

        /* Custom multi-select job checkboxes */
        .jobs-multiselect {
            background: var(--input-bg, #0f172a);
            border: 1px solid var(--border-color, rgba(255,255,255,.12));
            border-radius: 10px;
            max-height: 180px;
            overflow-y: auto;
            padding: .4rem;
        }
        .jobs-multiselect:empty::before {
            content: 'No jobs available.';
            color: var(--text-secondary,#94a3b8);
            font-size: .8rem;
            padding: .5rem;
            display: block;
        }
        .job-checkbox-item {
            display: flex;
            align-items: center;
            gap: .6rem;
            padding: .45rem .6rem;
            border-radius: 7px;
            cursor: pointer;
            transition: background .15s;
            user-select: none;
        }
        .job-checkbox-item:hover { background: rgba(245,158,11,.08); }
        .job-checkbox-item input[type="checkbox"] {
            accent-color: #f59e0b;
            width: 15px; height: 15px;
            cursor: pointer;
            flex-shrink: 0;
        }
        .job-checkbox-item label {
            cursor: pointer;
            font-size: .875rem;
            color: var(--text-primary, #f1f5f9);
            font-weight: 500;
        }

        /* Quick-add inline form inside job selector */
        .quick-add-job-form {
            display: none;
            background: rgba(245,158,11,.06);
            border: 1px solid rgba(245,158,11,.25);
            border-radius: 10px;
            padding: .85rem;
            margin-top: .6rem;
            gap: .5rem;
            flex-direction: column;
        }
        .quick-add-job-form.visible { display: flex; }
        .quick-add-job-form .qa-row {
            display: flex;
            gap: .5rem;
        }
        .quick-add-job-form input,
        .quick-add-job-form textarea {
            flex: 1;
            background: var(--input-bg, #0f172a);
            border: 1px solid var(--border-color, rgba(255,255,255,.12));
            border-radius: 8px;
            padding: .5rem .75rem;
            color: var(--text-primary,#f1f5f9);
            font-size: .85rem;
            font-family: inherit;
            resize: none;
        }
        .quick-add-job-form input:focus,
        .quick-add-job-form textarea:focus {
            outline: none;
            border-color: #f59e0b;
        }
        .quick-add-job-form .qa-actions {
            display: flex;
            gap: .5rem;
            justify-content: flex-end;
        }
        .qa-save-btn {
            padding: .4rem 1rem;
            background: linear-gradient(135deg,#f59e0b,#d97706);
            border: none; border-radius: 8px;
            color: #fff; font-size: .8rem; font-weight: 700;
            cursor: pointer; transition: opacity .2s;
        }
        .qa-save-btn:hover { opacity: .85; }
        .qa-cancel-btn {
            padding: .4rem .9rem;
            background: transparent;
            border: 1px solid var(--border-color, rgba(255,255,255,.15));
            border-radius: 8px;
            color: var(--text-secondary,#94a3b8);
            font-size: .8rem; cursor: pointer;
        }
        .qa-cancel-btn:hover { border-color: #f59e0b; color: #f59e0b; }

        /* No-results row when search finds nothing */
        .no-results-row td { text-align: center; padding: 3rem 1rem; color: var(--text-secondary,#94a3b8); }
        .no-results-row td i { font-size: 2rem; display: block; margin-bottom: .5rem; opacity: .4; }

        /* ── View Job Modal Custom Styles ── */
        #viewJobModal .modal-content {
            max-width: 680px !important;
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
        #viewJobModal .modal-header-custom {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(15, 23, 42, 0.8);
            flex-shrink: 0;
            text-align: center;
        }
        #viewJobModal .header-left-box {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.85rem;
            text-align: center;
            width: 100%;
            padding: 0 2.5rem;
        }
        #viewJobModal .icon-box-header {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: rgba(245, 158, 11, 0.15);
            color: #f59e0b;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0;
        }
        #viewJobModal .header-titles {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
        }
        #viewJobModal .header-titles h2 {
            font-size: 1.25rem;
            font-weight: 700;
            color: #f8fafc;
            margin: 0;
            padding: 0;
            border: none;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        #viewJobModal .header-titles p {
            font-size: 0.82rem;
            color: #94a3b8;
            margin: 3px 0 0 0;
            text-align: center;
        }
        #viewJobModal .modal-close {
            position: absolute;
            right: 1.25rem;
            top: 50%;
            transform: translateY(-50%);
            margin: 0;
            z-index: 10;
        }
        #viewJobModal .view-modal-body {
            padding: 1.5rem;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
        }
        #viewJobModal .job-hero-card {
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.6), rgba(15, 23, 42, 0.85));
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 1.25rem 1.5rem;
        }
        #viewJobModal .job-tag-label {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #94a3b8;
            margin-bottom: 0.5rem;
        }
        #viewJobModal .job-hero-title {
            font-size: 1.35rem;
            font-weight: 700;
            color: #f8fafc;
            margin: 0;
            line-height: 1.3;
        }
        #viewJobModal .job-description-box {
            color: #cbd5e1;
            font-size: 0.92rem;
            line-height: 1.6;
            margin: 0;
            white-space: pre-line;
            border-top: 1px solid rgba(255, 255, 255, 0.06);
            padding-top: 0.85rem;
            margin-top: 0.75rem;
        }
        #viewJobModal .linked-courses-card {
            background: rgba(15, 23, 42, 0.5);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 1.25rem;
            display: flex;
            flex-direction: column;
        }
        #viewJobModal .card-header-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.85rem;
            padding-bottom: 0.65rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
        }
        #viewJobModal .card-header-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: #f8fafc;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0;
        }
        #viewJobModal .courses-count-badge {
            background: rgba(99, 102, 241, 0.15);
            color: #a5b4fc;
            border: 1px solid rgba(99, 102, 241, 0.3);
            padding: 0.25rem 0.65rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        #viewJobModal .view-courses-list {
            max-height: 220px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 0.45rem;
            padding-right: 0.25rem;
        }
        #viewJobModal .view-modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 0.75rem;
            background: rgba(15, 23, 42, 0.8);
            flex-shrink: 0;
        }
        #viewJobModal .btn-edit-job-modal {
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
        #viewJobModal .btn-edit-job-modal:hover {
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            box-shadow: 0 6px 16px rgba(245, 158, 11, 0.35);
            transform: translateY(-1px);
        }
        #viewJobModal .btn-close-view-modal {
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
        #viewJobModal .btn-close-view-modal:hover {
            background: rgba(255, 255, 255, 0.1);
            color: #f8fafc;
        }

        /* ── Add & Edit Job Modal Custom Styles ── */
        #addJobModal .modal-content,
        #editJobModal .modal-content {
            max-width: 820px !important;
            width: 95% !important;
            max-height: 90vh !important;
            overflow-y: auto !important;
            text-align: left !important;
        }
        #addJobModal .modal-body,
        #editJobModal .modal-body {
            text-align: left !important;
            padding: 1.5rem !important;
        }
        .courses-offered-section {
            margin-top: 1.5rem;
            background: rgba(15, 23, 42, 0.65);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 14px;
            padding: 1.25rem;
            text-align: left;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.04);
        }
        .co-header-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
            gap: 0.75rem;
            flex-wrap: wrap;
        }
        .co-header-left {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .co-header-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.05rem;
            flex-shrink: 0;
        }
        .co-header-titles {
            display: flex;
            flex-direction: column;
            text-align: left;
        }
        .co-header-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: #f8fafc;
            display: flex;
            align-items: center;
            gap: 0.45rem;
            margin: 0;
        }
        .co-header-subtitle {
            font-size: 0.78rem;
            color: #94a3b8;
            margin: 0.15rem 0 0 0;
        }
        .co-header-right {
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }
        .co-count-badge {
            background: rgba(245, 158, 11, 0.15);
            border: 1px solid rgba(245, 158, 11, 0.3);
            color: #fbbf24;
            font-size: 0.75rem;
            font-weight: 700;
            padding: 0.3rem 0.75rem;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            letter-spacing: 0.02em;
        }
        .co-btn-clear-all {
            background: none;
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #94a3b8;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            padding: 0.3rem 0.65rem;
            border-radius: 6px;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }
        .co-btn-clear-all:hover {
            color: #f87171;
            border-color: rgba(239, 68, 68, 0.35);
            background: rgba(239, 68, 68, 0.12);
        }
        .co-search-box {
            position: relative;
            margin-bottom: 0.9rem;
        }
        .co-search-input {
            width: 100% !important;
            background: rgba(15, 23, 42, 0.8) !important;
            border: 1px solid rgba(255, 255, 255, 0.12) !important;
            border-radius: 10px !important;
            padding: 0.75rem 1rem 0.75rem 2.85rem !important;
            color: #f8fafc !important;
            font-size: 0.9rem !important;
            transition: all 0.2s !important;
        }
        .co-search-input:focus {
            border-color: #fbbf24 !important;
            box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.15) !important;
            outline: none !important;
        }
        .co-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: #1e293b;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            margin-top: 0.5rem;
            max-height: 220px;
            overflow-y: auto;
            z-index: 100;
            display: none;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.5), 0 8px 10px -6px rgba(0, 0, 0, 0.3);
        }
        .co-dropdown.active {
            display: block;
        }
        .co-dropdown::-webkit-scrollbar {
            width: 8px;
        }
        .co-dropdown::-webkit-scrollbar-track {
            background: rgba(0,0,0,0.1);
            border-radius: 4px;
        }
        .co-dropdown::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.15);
            border-radius: 4px;
        }
        .co-dropdown-item {
            padding: 0.75rem 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            cursor: pointer;
            transition: all 0.15s;
            border-bottom: 1px solid rgba(255,255,255,0.04);
            color: #cbd5e1;
            font-size: 0.9rem;
        }
        .co-dropdown-item:last-child { border-bottom: none; }
        .co-dropdown-item:hover {
            background: rgba(255, 255, 255, 0.04);
            color: #f8fafc;
        }
        .co-dropdown-item.selected {
            background: rgba(245, 158, 11, 0.08);
            color: #fbbf24;
        }
        .co-dropdown-item i {
            color: #94a3b8;
            font-size: 1rem;
        }
        .co-dropdown-item.selected i {
            color: #fbbf24;
        }
        .co-di-name { flex: 1; }
        .co-di-action {
            font-size: 0.75rem;
            font-weight: 600;
            background: rgba(255,255,255,0.1);
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            color: #94a3b8;
        }
        .co-dropdown-item:hover .co-di-action {
            background: #fbbf24;
            color: #0f172a;
        }
        .co-dropdown-item.selected .co-di-action {
            background: rgba(16, 185, 129, 0.15);
            color: #10b981;
        }
        .co-dropdown-item.selected:hover .co-di-action {
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
        }
        .co-chips-container {
            display: flex;
            flex-wrap: wrap;
            gap: 0.6rem;
            min-height: 40px;
        }
        .co-empty-state {
            width: 100%;
            text-align: center;
            padding: 1.5rem 0;
            color: #64748b;
            font-size: 0.85rem;
            background: rgba(15, 23, 42, 0.3);
            border: 1px dashed rgba(255,255,255,0.1);
            border-radius: 8px;
        }
        .co-empty-state i {
            display: block;
            font-size: 1.25rem;
            margin-bottom: 0.5rem;
            opacity: 0.5;
        }
        .co-empty-state p { margin: 0; }
        .co-chip {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.1), rgba(217, 119, 6, 0.05));
            border: 1px solid rgba(245, 158, 11, 0.2);
            color: #fde68a;
            padding: 0.4rem 0.5rem 0.4rem 0.75rem;
            border-radius: 8px;
            font-size: 0.82rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.6rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: all 0.2s;
            max-width: 100%;
        }
        .co-chip.comp-chip {
            background: linear-gradient(135deg, rgba(56, 189, 248, 0.1), rgba(2, 132, 199, 0.05));
            border: 1px solid rgba(56, 189, 248, 0.2);
            color: #bae6fd;
        }
        .co-chip:hover {
            border-color: rgba(245, 158, 11, 0.4);
            transform: translateY(-1px);
        }
        .co-chip.comp-chip:hover {
            border-color: rgba(56, 189, 248, 0.4);
        }
        .co-chip-icon {
            color: #fbbf24;
            font-size: 0.9rem;
            flex-shrink: 0;
        }
        .comp-chip .co-chip-icon {
            color: #38bdf8;
        }
        .co-chip-text {
            white-space: normal;
            word-break: break-word;
            line-height: 1.3;
        }
        .co-chip-remove {
            background: rgba(255, 255, 255, 0.08);
            border: none;
            color: #fca5a5;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
            flex-shrink: 0;
        }
        .co-chip-remove:hover {
            background: #ef4444;
            color: white;
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
                        <a href="manage_courses.php" class="nav-subitem">
                            <i class="fa-solid fa-book-open"></i>
                            Manage Courses
                        </a>
                        <a href="manage_schools.php" class="nav-subitem">
                            <i class="fa-solid fa-school"></i>
                            Manage Schools
                        </a>
                        <a href="manage_jobs.php" class="nav-subitem active">
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

    <!-- ─── Main Content ────────────────────────────────────── -->
    <main class="main-content">
        <!-- Top Bar -->
        <header class="top-bar">
            <button class="mobile-menu-toggle" id="mobileMenuToggle">
                <i class="fa-solid fa-bars"></i>
            </button>
            <div class="page-title">
                <h1>Manage Jobs</h1>
            </div>
            <?php
            $userName       = $_SESSION['admin_name'] ?? 'Admin User';
            $notifications  = [];
            $unreadCount    = 0;
            $adminId        = $_SESSION['admin_id'] ?? null;
            $adminProfilePic = null;

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
                $unreadCount = $countStmt->get_result()->fetch_assoc()['count'] ?? 0;
                $countStmt->close();

                $notifStmt = $mysqli->prepare("SELECT * FROM notifications WHERE (user_id = ? OR user_id IS NULL) AND user_type = 'admin' ORDER BY created_at DESC LIMIT 10");
                $notifStmt->bind_param('i', $adminId);
                $notifStmt->execute();
                $result = $notifStmt->get_result();
                while ($row = $result->fetch_assoc()) { $notifications[] = $row; }
                $notifStmt->close();
            }
            ?>
            <div class="top-bar-actions">
                <div class="notification-wrapper">
                    <button class="notification-btn" id="notificationBtn">
                        <i class="fa-solid fa-bell"></i>
                        <span class="notification-badge" id="notificationBadge" <?php echo $unreadCount == 0 ? 'style="display: none;"' : ''; ?>><?php echo $unreadCount > 9 ? '9+' : $unreadCount; ?></span>
                    </button>
                    <div class="notification-dropdown" id="notificationDropdown" style="display:none;">
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

        <!-- ─── Page Content ─────────────────────────────────── -->
        <div class="dashboard-content">

            <!-- Page Header -->
            <div class="page-header">
                <div class="header-actions">
                    <div class="search-filter">
                        <div class="search-box-wrapper">
                            <div class="search-box">
                                <i class="fa-solid fa-search"></i>
                                <input type="text" id="searchInput" placeholder="Search jobs...">
                            </div>
                            <button class="btn-search" id="searchBtn">
                                <i class="fa-solid fa-search"></i> Search
                            </button>
                        </div>
                        <button class="btn-clear" id="clearFilter">
                            <i class="fa-solid fa-times"></i> Clear
                        </button>
                    </div>
                    <div class="header-info">
                        <span class="count-badge">
                            <i class="fa-solid fa-hard-hat"></i>
                            <span id="totalJobs"><?php echo count($jobs); ?></span> Jobs
                        </span>
                    </div>
                    <button class="btn-primary" id="addJobBtn">
                        <i class="fa-solid fa-plus"></i> Add Job
                    </button>
                </div>
            </div>

            <!-- Jobs Table -->
            <div class="table-section">
                <div class="table-container">
                    <table class="data-table jobs-table" id="jobsTable">
                        <thead>
                            <tr>
                                <th>Job Title</th>
                                <th>Description</th>
                                <th>Date Added</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($jobs)): ?>
                            <tr>
                                <td colspan="4" class="no-data">
                                    <i class="fa-solid fa-inbox"></i>
                                    <p>No jobs found. Click "Add Job" to create your first job.</p>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($jobs as $job): ?>
                                <tr data-id="<?php echo $job['id']; ?>"
                                    data-title="<?php echo htmlspecialchars($job['job_title']); ?>"
                                    data-desc="<?php echo htmlspecialchars($job['description'] ?? ''); ?>">
                                    <td class="job-title-cell">
                                        <span class="job-icon"><i class="fa-solid fa-hard-hat"></i></span>
                                        <?php echo htmlspecialchars($job['job_title']); ?>
                                    </td>
                                    <td class="desc-cell" title="<?php echo htmlspecialchars($job['description'] ?? ''); ?>">
                                        <?php echo htmlspecialchars(
                                            !empty($job['description'])
                                                ? (strlen($job['description']) > 120 ? substr($job['description'], 0, 120) . '…' : $job['description'])
                                                : '—'
                                        ); ?>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($job['created_at'])); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn-action view" data-id="<?php echo $job['id']; ?>" title="View">
                                                <i class="fa-solid fa-eye"></i>
                                            </button>
                                            <button class="btn-action edit" data-id="<?php echo $job['id']; ?>" title="Edit">
                                                <i class="fa-solid fa-pen"></i>
                                            </button>
                                            <button class="btn-action delete" data-id="<?php echo $job['id']; ?>" title="Delete">
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
        </div><!-- /dashboard-content -->
        <?php include 'includes/app_footer.php'; ?>
    </main>
</div>

<!-- ══════════════════════════════════════════════════════════ -->
<!-- Add Job Modal                                              -->
<!-- ══════════════════════════════════════════════════════════ -->
<div class="modal" id="addJobModal">
    <div class="modal-overlay"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fa-solid fa-plus-circle"></i> Add New Job</h2>
            <button class="modal-close" id="closeAddModal"><i class="fa-solid fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form id="addJobForm" class="course-form">
                <div class="form-group">
                    <label for="jobTitle">Job Title <span class="required">*</span></label>
                    <input type="text" id="jobTitle" name="job_title" required placeholder="e.g. Software Engineer">
                </div>
                <div class="form-group">
                    <label for="jobDescription">Description</label>
                    <textarea id="jobDescription" name="description" rows="3"
                              placeholder="Brief description of this career/job position..."></textarea>
                </div>

                <!-- Related Courses Section -->
                <div class="courses-offered-section">
                    <div class="co-header-bar">
                        <div class="co-header-left">
                            <div class="co-header-icon" style="color: #fbbf24; background: rgba(245,158,11,0.15); border: 1px solid rgba(245,158,11,0.25);">
                                <i class="fa-solid fa-graduation-cap"></i>
                            </div>
                            <div class="co-header-titles">
                                <h3 class="co-header-title">Related Courses</h3>
                                <p class="co-header-subtitle">Academic programs that lead to this career</p>
                            </div>
                        </div>
                        <div class="co-header-right">
                            <span class="co-count-badge" id="addJobCourseCountBadge">
                                <i class="fa-solid fa-layer-group"></i> <span id="addJobCourseCount">0</span> Selected
                            </span>
                            <button type="button" class="co-btn-clear-all" id="addClearJobCoursesBtn" title="Clear all courses">
                                <i class="fa-solid fa-eraser"></i> Clear
                            </button>
                        </div>
                    </div>
                    <div class="co-search-box">
                        <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: #94a3b8; z-index: 2;"></i>
                        <input type="text" id="addJobCourseSearch" class="co-search-input" placeholder="Search courses to link..." autocomplete="off">
                        <div class="co-dropdown" id="addJobCourseDropdown">
                            <?php foreach ($allCourses as $c): ?>
                            <div class="co-dropdown-item" data-id="<?php echo $c['id']; ?>" data-name="<?php echo htmlspecialchars($c['course_name']); ?>">
                                <i class="fa-solid fa-book"></i>
                                <span class="co-di-name"><?php echo htmlspecialchars($c['course_name']); ?></span>
                                <span class="co-di-action"><i class="fa-solid fa-plus"></i> Add</span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="co-chips-container" id="addJobCourseChipsContainer">
                        <div class="co-empty-state" id="addJobCourseEmptyState">
                            <i class="fa-solid fa-link-slash"></i>
                            <p>No courses linked to this job yet.</p>
                        </div>
                    </div>
                </div>

                <!-- Hidden inputs for serialization -->
                <div id="addJobCoursesInputsContainer"></div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-secondary" id="cancelAdd">Cancel</button>
            <button type="submit" class="btn-primary" form="addJobForm">Add Job</button>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════ -->
<!-- Edit Job Modal                                             -->
<!-- ══════════════════════════════════════════════════════════ -->
<div class="modal" id="editJobModal">
    <div class="modal-overlay"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fa-solid fa-pen-to-square"></i> Edit Job</h2>
            <button class="modal-close" id="closeEditModal"><i class="fa-solid fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form id="editJobForm" class="course-form">
                <input type="hidden" id="editJobId" name="id">
                <div class="form-group">
                    <label for="editJobTitle">Job Title <span class="required">*</span></label>
                    <input type="text" id="editJobTitle" name="job_title" required>
                </div>
                <div class="form-group">
                    <label for="editJobDescription">Description</label>
                    <textarea id="editJobDescription" name="description" rows="3"></textarea>
                </div>

                <!-- Related Courses Section -->
                <div class="courses-offered-section">
                    <div class="co-header-bar">
                        <div class="co-header-left">
                            <div class="co-header-icon" style="color: #fbbf24; background: rgba(245,158,11,0.15); border: 1px solid rgba(245,158,11,0.25);">
                                <i class="fa-solid fa-graduation-cap"></i>
                            </div>
                            <div class="co-header-titles">
                                <h3 class="co-header-title">Related Courses</h3>
                                <p class="co-header-subtitle">Academic programs that lead to this career</p>
                            </div>
                        </div>
                        <div class="co-header-right">
                            <span class="co-count-badge" id="jobCourseCountBadge">
                                <i class="fa-solid fa-layer-group"></i> <span id="jobCourseCount">0</span> Selected
                            </span>
                            <button type="button" class="co-btn-clear-all" id="clearJobCoursesBtn" title="Clear all courses">
                                <i class="fa-solid fa-eraser"></i> Clear
                            </button>
                        </div>
                    </div>
                    <div class="co-search-box">
                        <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: #94a3b8; z-index: 2;"></i>
                        <input type="text" id="jobCourseSearch" class="co-search-input" placeholder="Search courses to link..." autocomplete="off">
                        <div class="co-dropdown" id="jobCourseDropdown">
                            <?php foreach ($allCourses as $c): ?>
                            <div class="co-dropdown-item" data-id="<?php echo $c['id']; ?>" data-name="<?php echo htmlspecialchars($c['course_name']); ?>">
                                <i class="fa-solid fa-book"></i>
                                <span class="co-di-name"><?php echo htmlspecialchars($c['course_name']); ?></span>
                                <span class="co-di-action"><i class="fa-solid fa-plus"></i> Add</span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="co-chips-container" id="jobCourseChipsContainer">
                        <div class="co-empty-state" id="jobCourseEmptyState">
                            <i class="fa-solid fa-link-slash"></i>
                            <p>No courses linked to this job yet.</p>
                        </div>
                        <!-- Chips go here -->
                    </div>
                </div>

                <!-- Hidden inputs for serialization -->
                <div id="jobCoursesInputsContainer"></div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-secondary" id="cancelEdit">Cancel</button>
            <button type="submit" class="btn-primary" form="editJobForm">Save Changes</button>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════ -->
<!-- ══════════════════════════════════════════════════════════ -->
<!-- View Job Modal                                             -->
<!-- ══════════════════════════════════════════════════════════ -->
<div class="modal" id="viewJobModal">
    <div class="modal-overlay"></div>
    <div class="modal-content">
        <div class="modal-header-custom">
            <div class="header-left-box">
                <div class="icon-box-header">
                    <i class="fa-solid fa-hard-hat"></i>
                </div>
                <div class="header-titles">
                    <h2>Job Details</h2>
                    <p>Career profile overview and affiliated academic programs</p>
                </div>
            </div>
            <button class="modal-close" id="closeViewModal"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <div class="view-modal-body">
            <!-- Job Hero Card -->
            <div class="job-hero-card">
                <span class="job-tag-label"><i class="fa-solid fa-briefcase"></i> Position Title</span>
                <h3 class="job-hero-title" id="viewJobTitle">—</h3>
                <p class="job-description-box" id="viewJobDescription">No description provided.</p>
            </div>

            <!-- Linked Courses Section -->
            <div class="linked-courses-card">
                <div class="card-header-row">
                    <h4 class="card-header-title">
                        <i class="fa-solid fa-graduation-cap" style="color: #fbbf24;"></i>
                        Linked Academic Programs & Courses
                    </h4>
                    <span class="courses-count-badge" id="viewJobCoursesCount">0 Courses</span>
                </div>
                <div class="view-courses-list" id="viewJobCourses">
                    <!-- Courses rendered dynamically -->
                </div>
            </div>
        </div>

        <div class="view-modal-footer">
            <button type="button" class="btn-close-view-modal" id="closeView">Close</button>
            <button type="button" class="btn-edit-job-modal" id="viewEditBtn">
                <i class="fa-solid fa-pen"></i> Edit Job
            </button>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════ -->
<!-- Delete Confirmation Modal                                  -->
<!-- ══════════════════════════════════════════════════════════ -->
<div class="modal" id="deleteModal">
    <div class="modal-overlay"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fa-solid fa-exclamation-triangle"></i> Confirm Delete</h2>
            <button class="modal-close" id="closeDeleteModal"><i class="fa-solid fa-times"></i></button>
        </div>
        <div class="modal-body">
            <div class="delete-confirm">
                <div class="delete-icon"><i class="fa-solid fa-trash-alt"></i></div>
                <p class="delete-message">Are you sure you want to delete this job?</p>
                <p class="delete-warning">This will also remove it from all linked courses. This action cannot be undone.</p>
                <div style="margin-top:1rem;background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.25);border-radius:10px;padding:.75rem 1rem;">
                    <strong id="deleteJobName" style="color:#f59e0b;font-size:1rem;"></strong>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-secondary" id="cancelDelete">Cancel</button>
            <button type="button" class="btn-danger" id="confirmDelete">Delete</button>
        </div>
    </div>
</div>

<!-- Remove Chip Confirmation Modal -->
<div class="modal" id="removeChipModal" style="z-index: 10050;">
    <div class="modal-overlay" id="removeChipOverlay"></div>
    <div class="modal-content" style="max-width: 440px; text-align: center; border-radius: 16px; padding: 24px; background: #0f172a; border: 1px solid rgba(255, 255, 255, 0.1); box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.8);">
        <div class="modal-body" style="padding: 0.75rem 0.5rem;">
            <div id="removeChipIcon" style="width: 58px; height: 58px; border-radius: 50%; background: rgba(245, 158, 11, 0.15); border: 1px solid rgba(245, 158, 11, 0.3); color: #fbbf24; font-size: 1.6rem; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.2rem;">
                <i class="fa-solid fa-link-slash"></i>
            </div>
            <h2 id="removeChipTitle" style="font-size: 1.25rem; font-weight: 700; color: #f8fafc; margin-bottom: 0.5rem; border-bottom: none;">Remove Item?</h2>
            <p id="removeChipMessage" style="color: #94a3b8; font-size: 0.92rem; margin-bottom: 1rem; line-height: 1.5;">Do you want to remove this item?</p>
            <div style="background: rgba(15, 23, 42, 0.7); border: 1px solid rgba(245, 158, 11, 0.25); border-radius: 10px; padding: 0.75rem 1rem; margin-bottom: 1.5rem; color: #fde68a; font-weight: 600; font-size: 0.9rem; word-break: break-word; text-align: center;" id="removeChipTargetName">
                Item Name
            </div>
            <div style="display: flex; gap: 0.75rem; justify-content: center;">
                <button type="button" class="btn-secondary" id="cancelRemoveChipBtn" style="flex: 1; justify-content: center; padding: 0.7rem;">Cancel</button>
                <button type="button" class="btn-danger" id="confirmRemoveChipBtn" style="flex: 1; justify-content: center; background: linear-gradient(135deg, #ef4444, #dc2626); color: white; border: none; font-weight: 600; padding: 0.7rem; border-radius: 8px; cursor: pointer;">Remove</button>
            </div>
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
document.addEventListener('DOMContentLoaded', function () {
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

    // ── Modal refs ────────────────────────────────────────────
    const addModal    = document.getElementById('addJobModal');
    const editModal   = document.getElementById('editJobModal');
    const viewModal   = document.getElementById('viewJobModal');
    const deleteModal = document.getElementById('deleteModal');

    // ── Multi-select state ────────────────────────────────────
    let addSelectedCourses = new Map(); // id => name (for Add Job modal)
    let selectedCourses    = new Map(); // id => name (for Edit Job modal)
    let selectedComps      = new Map(); // id => name

    // ── AJAX helper ───────────────────────────────────────────
    async function apiPost(formData) {
        const res = await fetch('manage_jobs.php', { method: 'POST', body: formData });
        return res.json();
    }

    // ── Open / Close modals ───────────────────────────────────
    document.getElementById('addJobBtn').addEventListener('click', () => {
        document.getElementById('addJobForm').reset();
        addSelectedCourses.clear();
        renderAddCourses();
        addModal.classList.add('active');
    });

    ['closeAddModal','cancelAdd'].forEach(id =>
        document.getElementById(id).addEventListener('click', () => {
            addModal.classList.remove('active');
            addSelectedCourses.clear();
            renderAddCourses();
        })
    );
    ['closeEditModal','cancelEdit'].forEach(id =>
        document.getElementById(id).addEventListener('click', () => editModal.classList.remove('active'))
    );
    ['closeViewModal','closeView'].forEach(id =>
        document.getElementById(id).addEventListener('click', () => viewModal.classList.remove('active'))
    );
    ['closeDeleteModal','cancelDelete'].forEach(id =>
        document.getElementById(id).addEventListener('click', () => deleteModal.classList.remove('active'))
    );

    document.querySelectorAll('.modal-overlay').forEach(o =>
        o.addEventListener('click', () => o.parentElement.classList.remove('active'))
    );

    function escHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // ── View button ───────────────────────────────────────────
    document.querySelectorAll('.btn-action.view').forEach(btn => {
        btn.addEventListener('click', async () => {
            const row = btn.closest('tr');
            const jobId = btn.dataset.id;
            document.getElementById('viewJobTitle').textContent       = row.dataset.title || '—';
            document.getElementById('viewJobDescription').textContent = row.dataset.desc  || 'No description provided for this job.';
            
            const coursesContainer = document.getElementById('viewJobCourses');
            const countBadge = document.getElementById('viewJobCoursesCount');
            coursesContainer.innerHTML = '<span style="font-size: 0.85rem; color: #94a3b8; padding: 0.5rem 0;">Loading linked courses...</span>';
            if (countBadge) countBadge.textContent = '...';
            
            viewModal.classList.add('active');
            // Store id for view→edit transition
            viewModal.dataset.editId = jobId;

            // Fetch linked courses
            const fd = new FormData();
            fd.append('action', 'get_job_courses');
            fd.append('id', jobId);
            try {
                const data = await apiPost(fd);
                if (data.success && data.courses) {
                    coursesContainer.innerHTML = '';
                    if (data.courses.length > 0) {
                        if (countBadge) countBadge.textContent = `${data.courses.length} Course${data.courses.length > 1 ? 's' : ''}`;
                        data.courses.forEach(courseName => {
                            const item = document.createElement('div');
                            item.style.cssText = 'display: flex; align-items: center; justify-content: space-between; padding: 0.55rem 0.75rem; background: rgba(15, 23, 42, 0.5); border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 8px; color: #e2e8f0; font-size: 0.88rem;';
                            item.innerHTML = `
                                <div style="display: flex; align-items: center; gap: 0.6rem;">
                                    <i class="fa-solid fa-graduation-cap" style="color: #fbbf24; font-size: 0.85rem;"></i>
                                    <span>${escHtml(courseName)}</span>
                                </div>
                                <i class="fa-solid fa-circle-check" style="color: #10b981; font-size: 0.75rem;"></i>
                            `;
                            coursesContainer.appendChild(item);
                        });
                    } else {
                        if (countBadge) countBadge.textContent = '0 Courses';
                        coursesContainer.innerHTML = '<p style="color: #64748b; font-style: italic; font-size: 0.85rem; margin: 0; padding: 0.5rem 0;">No academic courses linked yet.</p>';
                    }
                } else {
                    if (countBadge) countBadge.textContent = '0 Courses';
                    coursesContainer.innerHTML = '<p style="color: #ef4444; font-size: 0.85rem; margin: 0; padding: 0.5rem 0;">Failed to load courses.</p>';
                }
            } catch (e) {
                if (countBadge) countBadge.textContent = '0 Courses';
                coursesContainer.innerHTML = '<p style="color: #ef4444; font-size: 0.85rem; margin: 0; padding: 0.5rem 0;">Error loading courses.</p>';
            }
        });
    });
    document.getElementById('viewEditBtn').addEventListener('click', () => {
        viewModal.classList.remove('active');
        triggerEdit(viewModal.dataset.editId);
    });

    // ── Edit button ───────────────────────────────────────────
    document.querySelectorAll('.btn-action.edit').forEach(btn =>
        btn.addEventListener('click', () => triggerEdit(btn.dataset.id))
    );

    async function triggerEdit(jobId) {
        const fd = new FormData();
        fd.append('action', 'get_job');
        fd.append('id', jobId);
        try {
            const data = await apiPost(fd);
            if (data.success && data.job) {
                document.getElementById('editJobId').value          = data.job.id;
                document.getElementById('editJobTitle').value       = data.job.job_title || '';
                document.getElementById('editJobDescription').value = data.job.description || '';
                
                // Populate courses
                selectedCourses.clear();
                if (data.job.courses) {
                    data.job.courses.forEach(c => selectedCourses.set(c.course_id.toString(), c.course_name));
                }
                renderCourses();

                editModal.classList.add('active');
            } else {
                alert(data.message || 'Failed to load job data.');
            }
        } catch (e) {
            alert('Error loading job data.');
        }
    }

    // ── Multi-select UI Logic ─────────────────────────────────
    function initMultiSelect(type, stateMap, searchInputId, dropdownId, chipsContainerId, countSpanId, clearBtnId, renderFn) {
        const searchInput = document.getElementById(searchInputId);
        const dropdown = document.getElementById(dropdownId);
        const items = dropdown.querySelectorAll('.co-dropdown-item');
        const clearBtn = document.getElementById(clearBtnId);

        // Toggle dropdown on focus
        searchInput.addEventListener('focus', () => {
            dropdown.classList.add('active');
            filterDropdown();
        });

        // Hide dropdown on blur (with delay for click)
        searchInput.addEventListener('blur', () => {
            setTimeout(() => dropdown.classList.remove('active'), 200);
        });

        // Filter items
        function filterDropdown() {
            const val = searchInput.value.toLowerCase().trim();
            items.forEach(item => {
                const name = item.dataset.name.toLowerCase();
                const id = item.dataset.id;
                const isSelected = stateMap.has(id);
                
                if (isSelected) {
                    item.classList.add('selected');
                    item.querySelector('.co-di-action').innerHTML = '<i class="fa-solid fa-check"></i> Added';
                } else {
                    item.classList.remove('selected');
                    item.querySelector('.co-di-action').innerHTML = '<i class="fa-solid fa-plus"></i> Add';
                }

                if (name.includes(val)) {
                    item.style.display = 'flex';
                } else {
                    item.style.display = 'none';
                }
            });
        }
        searchInput.addEventListener('input', filterDropdown);

        // Click item
        items.forEach(item => {
            item.addEventListener('click', (e) => {
                e.preventDefault();
                const id = item.dataset.id;
                const name = item.dataset.name;
                
                if (stateMap.has(id)) {
                    stateMap.delete(id);
                } else {
                    stateMap.set(id, name);
                }
                
                renderFn();
                searchInput.value = '';
                searchInput.focus(); // keep open
                filterDropdown();
            });
        });

        // Clear all
        clearBtn.addEventListener('click', () => {
            stateMap.clear();
            renderFn();
            filterDropdown();
        });
    }

    function renderAddCourses() {
        const container = document.getElementById('addJobCourseChipsContainer');
        const emptyState = document.getElementById('addJobCourseEmptyState');
        const countSpan = document.getElementById('addJobCourseCount');
        
        if (countSpan) countSpan.textContent = addSelectedCourses.size;
        
        container.querySelectorAll('.co-chip').forEach(el => el.remove());
        
        if (addSelectedCourses.size > 0) {
            if (emptyState) emptyState.style.display = 'none';
            addSelectedCourses.forEach((name, id) => {
                const chip = document.createElement('div');
                chip.className = 'co-chip';
                chip.innerHTML = `
                    <i class="fa-solid fa-graduation-cap co-chip-icon"></i>
                    <span class="co-chip-text">${escHtml(name)}</span>
                    <button type="button" class="co-chip-remove" data-id="${id}" title="Remove course"><i class="fa-solid fa-xmark"></i></button>
                `;
                container.appendChild(chip);
                
                chip.querySelector('.co-chip-remove').addEventListener('click', function(e) {
                    e.preventDefault();
                    confirmRemoveChip('addCourse', id, name);
                });
            });
        } else {
            if (emptyState) emptyState.style.display = 'block';
        }
        updateHiddenInputs('offered_course_ids[]', addSelectedCourses, 'addJobCoursesInputsContainer');
    }

    function renderCourses() {
        const container = document.getElementById('jobCourseChipsContainer');
        const emptyState = document.getElementById('jobCourseEmptyState');
        const countSpan = document.getElementById('jobCourseCount');
        
        if (countSpan) countSpan.textContent = selectedCourses.size;
        
        container.querySelectorAll('.co-chip').forEach(el => el.remove());
        
        if (selectedCourses.size > 0) {
            if (emptyState) emptyState.style.display = 'none';
            selectedCourses.forEach((name, id) => {
                const chip = document.createElement('div');
                chip.className = 'co-chip';
                chip.innerHTML = `
                    <i class="fa-solid fa-graduation-cap co-chip-icon"></i>
                    <span class="co-chip-text">${escHtml(name)}</span>
                    <button type="button" class="co-chip-remove" data-id="${id}" title="Remove course"><i class="fa-solid fa-xmark"></i></button>
                `;
                container.appendChild(chip);
                
                chip.querySelector('.co-chip-remove').addEventListener('click', function(e) {
                    e.preventDefault();
                    confirmRemoveChip('course', id, name);
                });
            });
        } else {
            if (emptyState) emptyState.style.display = 'block';
        }
        updateHiddenInputs('offered_course_ids[]', selectedCourses, 'jobCoursesInputsContainer');
    }

    function updateHiddenInputs(inputName, stateMap, containerId) {
        const container = document.getElementById(containerId);
        container.innerHTML = '';
        stateMap.forEach((_, id) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = inputName;
            input.value = id;
            container.appendChild(input);
        });
    }

    // ── Remove Chip Modal Logic ───────────────────────────────
    const removeChipModal = document.getElementById('removeChipModal');
    let chipToRemove = { type: null, id: null };

    function confirmRemoveChip(type, id, name) {
        chipToRemove = { type, id };
        document.getElementById('removeChipTargetName').textContent = name;
        
        const titleEl = document.getElementById('removeChipTitle');
        const msgEl = document.getElementById('removeChipMessage');
        const iconEl = document.getElementById('removeChipIcon');

        if (type === 'course' || type === 'addCourse') {
            titleEl.textContent = 'Remove Course?';
            msgEl.textContent = 'Do you want to remove this course from this job?';
            iconEl.innerHTML = '<i class="fa-solid fa-graduation-cap"></i>';
            iconEl.style.color = '#fbbf24';
            iconEl.style.background = 'rgba(245, 158, 11, 0.15)';
            iconEl.style.borderColor = 'rgba(245, 158, 11, 0.3)';
        }
        
        removeChipModal.classList.add('active');
    }

    document.getElementById('cancelRemoveChipBtn').addEventListener('click', () => {
        removeChipModal.classList.remove('active');
        chipToRemove = { type: null, id: null };
    });
    
    document.getElementById('removeChipOverlay').addEventListener('click', () => {
        removeChipModal.classList.remove('active');
        chipToRemove = { type: null, id: null };
    });

    document.getElementById('confirmRemoveChipBtn').addEventListener('click', () => {
        if (chipToRemove.type === 'addCourse' && chipToRemove.id) {
            addSelectedCourses.delete(chipToRemove.id);
            renderAddCourses();
            document.getElementById('addJobCourseSearch').dispatchEvent(new Event('input'));
        } else if (chipToRemove.type === 'course' && chipToRemove.id) {
            selectedCourses.delete(chipToRemove.id);
            renderCourses();
            document.getElementById('jobCourseSearch').dispatchEvent(new Event('input'));
        }
        removeChipModal.classList.remove('active');
        chipToRemove = { type: null, id: null };
    });

    // Initialize multi-selects for Add and Edit modals
    initMultiSelect('addCourse', addSelectedCourses, 'addJobCourseSearch', 'addJobCourseDropdown', 'addJobCourseChipsContainer', 'addJobCourseCount', 'addClearJobCoursesBtn', renderAddCourses);
    initMultiSelect('course', selectedCourses, 'jobCourseSearch', 'jobCourseDropdown', 'jobCourseChipsContainer', 'jobCourseCount', 'clearJobCoursesBtn', renderCourses);

    // ── Delete button ─────────────────────────────────────────
    let deleteJobId = null;
    document.querySelectorAll('.btn-action.delete').forEach(btn => {
        btn.addEventListener('click', () => {
            const row = btn.closest('tr');
            deleteJobId = btn.dataset.id;
            document.getElementById('deleteJobName').textContent = row.dataset.title || '';
            deleteModal.classList.add('active');
        });
    });

    // ── Add form submit ───────────────────────────────────────
    document.getElementById('addJobForm').addEventListener('submit', async function (e) {
        e.preventDefault();
        const fd = new FormData(this);
        fd.append('action', 'add_job');
        try {
            const data = await apiPost(fd);
            if (data.success) {
                showStatusModal('Success', 'Job added successfully!', true, () => location.reload());
            } else {
                showStatusModal('Error', data.message || 'Failed to add job.', false);
            }
        } catch { showStatusModal('Error', 'Error adding job.', false); }
    });

    // ── Edit form submit ──────────────────────────────────────
    document.getElementById('editJobForm').addEventListener('submit', async function (e) {
        e.preventDefault();
        const fd = new FormData(this);
        fd.append('action', 'edit_job');
        try {
            const data = await apiPost(fd);
            if (data.success) {
                showStatusModal('Success', 'Job updated successfully!', true, () => location.reload());
            } else {
                showStatusModal('Error', data.message || 'Failed to update job.', false);
            }
        } catch { showStatusModal('Error', 'Error updating job.', false); }
    });

    // ── Confirm delete ────────────────────────────────────────
    document.getElementById('confirmDelete').addEventListener('click', async () => {
        if (!deleteJobId) return;
        const fd = new FormData();
        fd.append('action', 'delete_job');
        fd.append('id', deleteJobId);
        
        deleteModal.classList.remove('active');
        try {
            const data = await apiPost(fd);
            if (data.success) {
                showStatusModal('Success', 'Job deleted successfully.', true, () => location.reload());
            } else {
                showStatusModal('Error', data.message || 'Failed to delete job.', false);
            }
        } catch { showStatusModal('Error', 'Error deleting job.', false); }
    });

    // ── Search / filter ───────────────────────────────────────
    const searchInput = document.getElementById('searchInput');
    const clearBtn    = document.getElementById('clearFilter');

    function filterTable() {
        const term = searchInput.value.toLowerCase().trim();
        const rows = document.querySelectorAll('.jobs-table tbody tr:not(.no-results-row)');
        let visible = 0;
        // Remove old no-results row
        document.querySelector('.no-results-row')?.remove();

        rows.forEach(row => {
            const title = (row.dataset.title || '').toLowerCase();
            const desc  = (row.dataset.desc  || '').toLowerCase();
            const match = title.includes(term) || desc.includes(term);
            row.style.display = match ? '' : 'none';
            if (match) visible++;
        });

        document.getElementById('totalJobs').textContent = visible;
        document.getElementById('statTotal').textContent  = visible;

        if (visible === 0 && term) {
            const tbody = document.querySelector('.jobs-table tbody');
            const noRow = document.createElement('tr');
            noRow.className = 'no-results-row';
            noRow.innerHTML = `<td colspan="4"><i class="fa-solid fa-search"></i><br>No jobs match "<strong>${term}</strong>"</td>`;
            tbody.appendChild(noRow);
        }
    }

    searchInput.addEventListener('input', filterTable);
    document.getElementById('searchBtn').addEventListener('click', filterTable);
    clearBtn.addEventListener('click', () => {
        searchInput.value = '';
        filterTable();
        const total = document.querySelectorAll('.jobs-table tbody tr:not(.no-results-row)').length;
        document.getElementById('totalJobs').textContent = total;
        document.getElementById('statTotal').textContent  = total;
    });

    // ── Toast helper ──────────────────────────────────────────
    function showToast(msg, type = 'success') {
        const toast = document.createElement('div');
        toast.style.cssText = `
            position:fixed;bottom:2rem;right:2rem;z-index:9999;
            background:${type === 'success' ? 'linear-gradient(135deg,#22c55e,#16a34a)' : 'linear-gradient(135deg,#ef4444,#dc2626)'};
            color:#fff;padding:.85rem 1.4rem;border-radius:12px;
            font-weight:600;font-size:.9rem;box-shadow:0 8px 32px rgba(0,0,0,.3);
            display:flex;align-items:center;gap:.6rem;
            animation:slideInToast .3s ease;
        `;
        toast.innerHTML = `<i class="fa-solid fa-${type === 'success' ? 'check-circle' : 'times-circle'}"></i> ${msg}`;
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 2500);
    }
});

// ── Notification dropdown (same as other pages) ───────────────
document.getElementById('notificationBtn').addEventListener('click', function (e) {
    e.stopPropagation();
    const d = document.getElementById('notificationDropdown');
    d.style.display = d.style.display === 'none' ? 'block' : 'none';
});
document.addEventListener('click', function () {
    document.getElementById('notificationDropdown').style.display = 'none';
});
document.getElementById('notificationDropdown').addEventListener('click', e => e.stopPropagation());

function markAllRead(e) {
    e.preventDefault(); e.stopPropagation();
    fetch('api/notifications.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=mark_all_read'
    }).then(r => r.json()).then(data => {
        if (data.success) {
            document.querySelectorAll('.notification-item.unread').forEach(i => i.classList.remove('unread'));
            document.getElementById('notificationBadge').textContent = '0';
            document.querySelector('.mark-all-read')?.remove();
        }
    });
}
document.querySelectorAll('.notification-item').forEach(item => {
    item.addEventListener('click', function () {
        if (!this.classList.contains('unread')) return;
        const id = this.dataset.id;
        fetch('api/notifications.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=mark_read&id=' + id
        }).then(r => r.json()).then(data => {
            if (data.success) {
                this.classList.remove('unread');
                const badge = document.getElementById('notificationBadge');
                const cnt   = parseInt(badge.textContent);
                if (cnt > 0) badge.textContent = cnt - 1;
            }
        });
    });
});
</script>
<style>
@keyframes slideInToast {
    from { transform: translateY(20px); opacity: 0; }
    to   { transform: translateY(0);    opacity: 1; }
}
</style>
<?php require_once __DIR__ . '/includes/session_timeout_footer.php'; ?>
</body>
</html>
