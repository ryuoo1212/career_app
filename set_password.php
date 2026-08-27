<?php
/**
 * set_password.php — Forced password change for admin-created accounts.
 *
 * Reached automatically on first login when must_change_password = 1.
 * Handles students, counselors, and admins in a single unified page.
 */
require_once 'config.php';
require_once 'system_config.php';

// ── Determine who is logged in ────────────────────────────────────────────────
$userType = null; // 'student' | 'counselor' | 'admin'
$userId   = null;
$userName = '';
$backUrl  = 'login.php';

if (isset($_SESSION['admin_id']) && isset($_SESSION['is_admin'])) {
    $userType = 'admin';
    $userId   = (int) $_SESSION['admin_id'];
    $userName = $_SESSION['admin_name'] ?? 'Administrator';
    $backUrl  = 'admin_login.php';
} elseif (isset($_SESSION['counselor_id'])) {
    $userType = 'counselor';
    $userId   = (int) $_SESSION['counselor_id'];
    $userName = $_SESSION['counselor_name'] ?? 'Counselor';
    $backUrl  = 'login.php';
} elseif (isset($_SESSION['student_id'])) {
    $userType = 'student';
    $userId   = (int) ($_SESSION['student_db_id'] ?? 0);
    $userName = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
    $backUrl  = 'login.php';
}

// Not logged in at all — send to login page
if ($userType === null || $userId === 0) {
    header('Location: login.php');
    exit();
}

// ── Verify the flag is actually set (prevents bookmarking this page later) ────
$tableMap  = ['student' => 'students', 'counselor' => 'counselors', 'admin' => 'admins'];
$tableName = $tableMap[$userType];

$flagCheck = $mysqli->prepare("SELECT must_change_password FROM {$tableName} WHERE id = ? LIMIT 1");
$flagCheck->bind_param('i', $userId);
$flagCheck->execute();
$flagRow = $flagCheck->get_result()->fetch_assoc();
$flagCheck->close();

if (empty($flagRow['must_change_password'])) {
    // Flag already cleared — go to the correct dashboard
    if ($userType === 'admin')      { header('Location: admin_dashboard.php'); }
    elseif ($userType === 'counselor') { header('Location: counselor_dashboard.php'); }
    else                            { header('Location: dashboard.php'); }
    exit();
}

// ── Handle form submission ────────────────────────────────────────────────────
$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPassword     = $_POST['newPassword']     ?? '';
    $confirmPassword = $_POST['confirmPassword'] ?? '';

    if ($newPassword !== $confirmPassword) {
        $errorMsg = 'Passwords do not match.';
    } elseif (strlen($newPassword) < 8) {
        $errorMsg = 'Password must be at least 8 characters.';
    } elseif (!preg_match('/[A-Z]/', $newPassword)
           || !preg_match('/[a-z]/', $newPassword)
           || !preg_match('/\d/',    $newPassword)
           || !preg_match('/[!@#$%^&*()\-_=+\[\]{};\':"\\\\|,.<>\/?]/', $newPassword)) {
        $errorMsg = 'Password must include uppercase, lowercase, a number, and a special character.';
    } else {
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $upd  = $mysqli->prepare("UPDATE {$tableName} SET password = ?, must_change_password = 0 WHERE id = ?");
        $upd->bind_param('si', $hash, $userId);
        $ok = $upd->execute();
        $upd->close();

        if ($ok) {
            if ($userType === 'admin')         { header('Location: admin_dashboard.php?pw=changed'); }
            elseif ($userType === 'counselor') { header('Location: counselor_dashboard.php?pw=changed'); }
            else                               { header('Location: dashboard.php?pw=changed'); }
            exit();
        } else {
            $errorMsg = 'Could not update your password. Please try again.';
        }
    }
}

$systemName = getSystemConfig('short_name') ?: APP_NAME;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create New Password &middot; <?php echo htmlspecialchars($systemName); ?></title>
    <link rel="stylesheet" href="style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --sp-bg:           #070c18;
            --sp-card-bg:      rgba(15, 23, 42, 0.82);
            --sp-border:       rgba(255, 255, 255, 0.08);
            --sp-border-focus: rgba(251, 191, 36, 0.6);
            --sp-gold:         #fbbf24;
            --sp-gold-dark:    #d97706;
            --sp-text:         #f8fafc;
            --sp-muted:        #94a3b8;
        }

        body.set-password-page {
            margin: 0; padding: 0; min-height: 100vh;
            background: var(--sp-bg);
            font-family: 'Inter', sans-serif;
            color: var(--sp-text);
            display: flex; flex-direction: column;
        }

        body.set-password-page::before {
            content: '';
            position: fixed; inset: 0; z-index: 0; pointer-events: none;
            background:
                radial-gradient(ellipse 80% 60% at 20% 10%, rgba(251,191,36,.07) 0%, transparent 60%),
                radial-gradient(ellipse 60% 50% at 80% 80%, rgba(56,189,248,.06) 0%, transparent 55%);
        }

        /* Navbar */
        .sp-navbar {
            position: relative; z-index: 10;
            display: flex; align-items: center; justify-content: space-between;
            padding: 18px 32px;
            border-bottom: 1px solid var(--sp-border);
            background: rgba(7,12,24,.6);
            backdrop-filter: blur(12px);
        }
        .sp-navbar .nav-logo {
            display: flex; align-items: center; gap: 10px;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 1.1rem; font-weight: 700; color: var(--sp-text);
        }

        /* Main */
        .sp-container {
            flex: 1; display: flex; align-items: center; justify-content: center;
            padding: 48px 16px; position: relative; z-index: 5;
        }

        /* Card */
        .sp-card {
            width: 100%; max-width: 440px;
            background: var(--sp-card-bg);
            border: 1px solid var(--sp-border);
            border-radius: 20px; padding: 40px 36px;
            box-shadow: 0 24px 80px rgba(0,0,0,.45), 0 0 0 1px rgba(255,255,255,.04) inset;
            backdrop-filter: blur(24px);
            animation: sp-slideup .45s cubic-bezier(.16,1,.3,1) both;
        }
        @keyframes sp-slideup {
            from { opacity:0; transform:translateY(24px); }
            to   { opacity:1; transform:translateY(0); }
        }

        /* Icon badge */
        .sp-badge {
            width: 56px; height: 56px; border-radius: 16px; margin: 0 auto 20px;
            display: flex; align-items: center; justify-content: center;
            background: linear-gradient(135deg, var(--sp-gold), var(--sp-gold-dark));
            font-size: 1.5rem; color: #0f172a;
            box-shadow: 0 8px 24px rgba(251,191,36,.3);
        }

        /* Header */
        .sp-header { text-align: center; margin-bottom: 28px; }
        .sp-header h1 {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 1.5rem; font-weight: 800; color: var(--sp-text); margin: 0 0 8px;
        }
        .sp-header p { color: var(--sp-muted); font-size: .9rem; margin: 0; line-height: 1.5; }
        .sp-username {
            display: inline-block; margin-top: 8px;
            color: var(--sp-gold); font-weight: 600; font-size: .85rem;
        }

        /* Alert */
        .sp-alert {
            padding: 12px 16px; border-radius: 10px;
            font-size: .875rem; margin-bottom: 20px;
            display: flex; align-items: flex-start; gap: 10px;
        }
        .sp-alert.error   { background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.3); color:#fca5a5; }
        .sp-alert i { flex-shrink: 0; margin-top: 2px; }

        /* Form */
        .sp-group { margin-bottom: 20px; }
        .sp-group label {
            display: block; font-size: .78rem; font-weight: 600;
            color: var(--sp-muted); text-transform: uppercase; letter-spacing: .05em; margin-bottom: 8px;
        }
        .sp-input-wrap { position: relative; }
        .sp-input-wrap input {
            width: 100%; padding: 13px 44px 13px 16px;
            background: rgba(255,255,255,.05);
            border: 1px solid var(--sp-border);
            border-radius: 10px; color: var(--sp-text);
            font-size: .95rem; font-family: inherit;
            box-sizing: border-box;
            transition: border-color .2s, box-shadow .2s;
        }
        .sp-input-wrap input:focus {
            outline: none;
            border-color: var(--sp-border-focus);
            box-shadow: 0 0 0 3px rgba(251,191,36,.1);
        }
        .sp-input-wrap input::placeholder { color: rgba(148,163,184,.5); }
        .sp-toggle { position: absolute; right: 14px; top: 50%; transform: translateY(-50%); color: var(--sp-muted); cursor: pointer; font-size: .9rem; background: none; border: none; padding: 0; transition: color .2s; }
        .sp-toggle:hover { color: var(--sp-gold); }

        /* Requirements */
        .sp-reqs {
            margin-top: 10px; padding: 12px 14px;
            background: rgba(255,255,255,.03);
            border: 1px solid var(--sp-border); border-radius: 10px;
        }
        .sp-reqs p { font-size:.75rem; color:var(--sp-muted); margin:0 0 8px; font-weight:600; text-transform:uppercase; letter-spacing:.04em; }
        .sp-reqs ul { list-style:none; margin:0; padding:0; display:grid; grid-template-columns:1fr 1fr; gap:5px; }
        .sp-reqs li { font-size:.8rem; color:var(--sp-muted); display:flex; align-items:center; gap:6px; transition:color .2s; }
        .sp-reqs li i { font-size:.55rem; }
        .sp-reqs li.met { color:#4ade80; }

        /* Submit */
        .sp-btn {
            width: 100%; padding: 14px; margin-top: 8px;
            background: linear-gradient(135deg, var(--sp-gold), var(--sp-gold-dark));
            color: #0f172a; font-weight: 800; font-size: 1rem;
            border: none; border-radius: 10px; cursor: pointer;
            font-family: 'Plus Jakarta Sans', sans-serif;
            transition: opacity .2s, transform .15s, box-shadow .2s;
            box-shadow: 0 4px 20px rgba(251,191,36,.25);
        }
        .sp-btn:hover  { opacity:.92; transform:translateY(-1px); box-shadow:0 8px 28px rgba(251,191,36,.35); }
        .sp-btn:active { transform:translateY(0); }
        .sp-btn:disabled { opacity:.5; cursor:not-allowed; transform:none; }

        /* Footer note */
        .sp-note { text-align:center; margin-top:20px; font-size:.8rem; color:var(--sp-muted); }
        .sp-note i { color:var(--sp-gold); margin-right:4px; }
    </style>
</head>
<body class="set-password-page">

    <nav class="sp-navbar">
        <div style="width:80px;"></div>
        <div class="nav-logo">
            <?php echo getSystemLogo('logo-icon'); ?>
            <span><?php echo htmlspecialchars($systemName); ?></span>
        </div>
        <div style="width:80px;"></div>
    </nav>

    <div class="sp-container">
        <div class="sp-card">

            <div class="sp-badge"><i class="fa-solid fa-key"></i></div>

            <div class="sp-header">
                <h1>Create Your Password</h1>
                <p>For security, you must set a new password before accessing your account.</p>
                <?php if (trim($userName) !== ''): ?>
                <span class="sp-username">
                    <i class="fa-solid fa-user" style="font-size:.7rem;margin-right:4px;"></i>
                    <?php echo htmlspecialchars($userName); ?>
                </span>
                <?php endif; ?>
            </div>

            <?php if ($errorMsg !== ''): ?>
            <div class="sp-alert error" id="sp-error-box">
                <i class="fa-solid fa-circle-exclamation"></i>
                <span><?php echo htmlspecialchars($errorMsg); ?></span>
            </div>
            <?php endif; ?>

            <form id="spForm" method="post" action="set_password.php" novalidate>

                <div class="sp-group">
                    <label for="newPassword">New Password</label>
                    <div class="sp-input-wrap">
                        <input type="password" id="newPassword" name="newPassword"
                               placeholder="Create a strong password" required autocomplete="new-password">
                        <button type="button" class="sp-toggle" onclick="togglePw('newPassword',this)" tabindex="-1">
                            <i class="fa-solid fa-eye"></i>
                        </button>
                    </div>
                    <div class="sp-reqs">
                        <p>Must contain:</p>
                        <ul>
                            <li id="r-len"><i class="fa-solid fa-circle"></i> 8+ characters</li>
                            <li id="r-upper"><i class="fa-solid fa-circle"></i> Uppercase</li>
                            <li id="r-lower"><i class="fa-solid fa-circle"></i> Lowercase</li>
                            <li id="r-num"><i class="fa-solid fa-circle"></i> Number</li>
                            <li id="r-special"><i class="fa-solid fa-circle"></i> Special character</li>
                        </ul>
                    </div>
                </div>

                <div class="sp-group">
                    <label for="confirmPassword">Confirm Password</label>
                    <div class="sp-input-wrap">
                        <input type="password" id="confirmPassword" name="confirmPassword"
                               placeholder="Re-enter your password" required autocomplete="new-password">
                        <button type="button" class="sp-toggle" onclick="togglePw('confirmPassword',this)" tabindex="-1">
                            <i class="fa-solid fa-eye"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="sp-btn" id="spSubmit">
                    <i class="fa-solid fa-lock-open" style="margin-right:6px;"></i>Set Password &amp; Continue
                </button>
            </form>

            <p class="sp-note">
                <i class="fa-solid fa-shield-halved"></i>
                This is a one-time step. You won't see this page again after saving.
            </p>
        </div>
    </div>

    <script>
    (function () {
        'use strict';

        window.togglePw = function (id, btn) {
            var inp = document.getElementById(id);
            var ico = btn.querySelector('i');
            if (inp.type === 'password') {
                inp.type = 'text';
                ico.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                inp.type = 'password';
                ico.classList.replace('fa-eye-slash', 'fa-eye');
            }
        };

        var rules = {
            'r-len':     function (v) { return v.length >= 8; },
            'r-upper':   function (v) { return /[A-Z]/.test(v); },
            'r-lower':   function (v) { return /[a-z]/.test(v); },
            'r-num':     function (v) { return /\d/.test(v); },
            'r-special': function (v) { return /[!@#$%^&*()\-_=+\[\]{};':"\\|,.<>/?]/.test(v); }
        };

        document.getElementById('newPassword').addEventListener('input', function () {
            var v = this.value;
            Object.keys(rules).forEach(function (id) {
                document.getElementById(id).classList.toggle('met', rules[id](v));
            });
        });

        document.getElementById('spForm').addEventListener('submit', function (e) {
            var np = document.getElementById('newPassword').value;
            var cp = document.getElementById('confirmPassword').value;
            var allMet = Object.values(rules).every(function (fn) { return fn(np); });

            function showErr(msg) {
                e.preventDefault();
                var box = document.getElementById('sp-error-box');
                if (!box) {
                    box = document.createElement('div');
                    box.id = 'sp-error-box';
                    box.className = 'sp-alert error';
                    box.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i><span></span>';
                    document.getElementById('spForm').before(box);
                }
                box.querySelector('span').textContent = msg;
                box.style.display = 'flex';
                box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }

            if (!allMet) { showErr('Please meet all password requirements.'); return; }
            if (np !== cp) { showErr('Passwords do not match.'); return; }

            var btn = document.getElementById('spSubmit');
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin" style="margin-right:6px;"></i>Saving…';
        });
    })();
    </script>

    <?php include 'includes/public_footer.php'; ?>
</body>
</html>
