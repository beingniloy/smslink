<?php
session_start();

$isInstalled = file_exists(__DIR__ . '/../config/installed.lock');

if ($isInstalled && !isset($_GET['force'])) {
    header('Location: ../dashboard/');
    exit;
}

$step = $_GET['step'] ?? 1;
$error = '';
$success = '';

$reqMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($reqMethod === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'setup_db') {
        $dbHost = trim($_POST['db_host'] ?? '127.0.0.1');
        $dbName = trim($_POST['db_name'] ?? 'sms');
        $dbUser = trim($_POST['db_user'] ?? 'root');
        $dbPass = $_POST['db_pass'] ?? '';

        try {
            // First try direct connection to existing database (standard for cPanel / shared hosting)
            try {
                $pdo = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]);
            } catch (PDOException $eDirect) {
                // If direct connection fails (e.g. database missing on local dev), try creating database
                $tmpPdo = new PDO("mysql:host={$dbHost};charset=utf8mb4", $dbUser, $dbPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                ]);
                $tmpPdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");

                $pdo = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]);
            }

            // Save credentials
            $credContent = "<?php\nreturn " . var_export([
                'host' => $dbHost,
                'dbname' => $dbName,
                'user' => $dbUser,
                'pass' => $dbPass
            ], true) . ";\n";

            file_put_contents(__DIR__ . '/../config/db_credentials.php', $credContent);

            require_once __DIR__ . '/../config/database.php';
            ensureTablesExist($pdo);

            header('Location: ./?step=2');
            exit;
        } catch (Exception $e) {
            $error = "Database Error: " . $e->getMessage();
        }
    }

    if ($action === 'setup_admin') {
        $username = trim($_POST['username'] ?? 'admin');
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        if (empty($username) || empty($password)) {
            $error = "Username and password are required.";
        } elseif ($password !== $confirm) {
            $error = "Passwords do not match.";
        } else {
            try {
                require_once __DIR__ . '/../config/database.php';
                $pdo = getDbConnection();

                $passHash = password_hash($password, PASSWORD_DEFAULT);

                // Clear existing users and insert new admin
                $pdo->exec("TRUNCATE TABLE users;");
                $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, email, role, created_at) VALUES (?, ?, ?, 'admin', NOW())");
                $stmt->execute([$username, $passHash, $email]);

                // Seed default system settings with auto-detected base URL
                $detectedUrl = getAutoDetectedBaseUrl();
                $stmtSet = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('app_name', 'SMSLink'), ('app_url', ?), ('theme_color', '#057d77'), ('theme_color_hover', '#035c57') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                $stmtSet->execute([$detectedUrl]);

                // Create installed.lock
                file_put_contents(__DIR__ . '/../config/installed.lock', date('Y-m-d H:i:s'));

                // Auto login admin
                $_SESSION['tf_auth'] = true;

                header('Location: ./?step=3');
                exit;
            } catch (Exception $e) {
                $error = "Account Creation Error: " . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-900">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SMSLink - Installation Wizard</title>
<link rel="icon" type="image/png" href="https://cdn.niloy.io/projects/smslink/favicon.png">
<link rel="shortcut icon" type="image/png" href="https://cdn.niloy.io/projects/smslink/favicon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Fira+Code:wght@400;500&display=swap" rel="stylesheet">
<style>
:root {
  --primary:#057d77;
  --primary-hover:#04635e;
  --primary-light:#e6f2f2;
  --bg-sidebar:#0b1320;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{min-height:100vh;display:flex;align-items:center;justify-content:center;background:var(--bg-sidebar);font-family:'Inter',sans-serif;padding:20px;color:#0f172a}

.setup-card{width:100%;max-width:480px;background:#FFF;border-radius:24px;padding:36px;box-shadow:0 25px 50px -12px rgba(0,0,0,0.3)}
.brand-head{text-align:center;margin-bottom:28px}
.brand-name{font-size:26px;font-weight:800;letter-spacing:-0.03em;color:#0f172a}
.brand-name span{color:var(--primary);font-weight:400}
.brand-sub{font-size:13px;color:#64748b;margin-top:4px}

.step-bar{display:flex;align-items:center;justify-content:space-between;margin-bottom:28px;position:relative}
.step-bar::before{content:'';position:absolute;top:50%;left:0;right:0;height:2px;background:#e2e8f0;z-index:0;transform:translateY(-50%)}
.step-item{position:relative;z-index:1;width:32px;height:32px;border-radius:50%;background:#e2e8f0;color:#64748b;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px}
.step-item.active{background:var(--primary);color:#FFF;box-shadow:0 0 0 4px rgba(5,125,119,0.2)}
.step-item.done{background:#10b981;color:#FFF}

.form-group{margin-bottom:18px}
.label{display:block;font-size:12px;font-weight:600;color:#334155;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:6px}
.input{width:100%;padding:11px 14px;background:#FFF;border:1px solid #cbd5e1;border-radius:10px;color:#0f172a;font-size:14px;outline:none;transition:all 0.15s}
.input:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(5,125,119,0.18)}

.btn{width:100%;padding:12px;border-radius:10px;border:none;background:var(--primary);color:#FFF;font-weight:600;font-size:14px;cursor:pointer;transition:all 0.15s;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}
.btn:hover{background:var(--primary-hover)}

.alert-error{background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;padding:12px;border-radius:10px;font-size:13px;margin-bottom:20px}
</style>
</head>
<body>

<div class="setup-card">
  <div class="brand-head">
    <div style="display:flex;align-items:center;justify-content:center;margin-bottom:8px">
      <img src="https://cdn.niloy.io/projects/smslink/logo.png" alt="SMSLink" style="height:42px;max-width:220px;object-fit:contain;display:block" onerror="this.style.display='none';this.nextElementSibling.style.display='block';">
      <div class="brand-name brand-text-fallback" style="display:none">SMS<span>Link</span></div>
    </div>
    <div class="brand-sub">Installation Wizard</div>
  </div>

  <div class="step-bar">
    <div class="step-item <?php echo $step >= 1 ? ($step > 1 ? 'done' : 'active') : ''; ?>">1</div>
    <div class="step-item <?php echo $step >= 2 ? ($step > 2 ? 'done' : 'active') : ''; ?>">2</div>
    <div class="step-item <?php echo $step >= 3 ? 'done active' : ''; ?>">3</div>
  </div>

  <?php if (!empty($error)): ?>
  <div class="alert-error"><?php echo htmlspecialchars($error); ?></div>
  <?php endif; ?>

  <?php if ($step == 1): ?>
  <!-- STEP 1: MYSQL DATABASE SETUP -->
  <form method="POST">
    <input type="hidden" name="action" value="setup_db">
    <h3 style="font-size:16px;font-weight:700;margin-bottom:16px;color:#0f172a">Step 1: MySQL Database Connection</h3>
    
    <div class="form-group">
      <label class="label">Database Host</label>
      <input type="text" name="db_host" class="input" value="127.0.0.1" required>
    </div>

    <div class="form-group">
      <label class="label">Database Name</label>
      <input type="text" name="db_name" class="input" value="sms" required>
    </div>

    <div class="form-group">
      <label class="label">Database User</label>
      <input type="text" name="db_user" class="input" value="root" required>
    </div>

    <div class="form-group" style="margin-bottom:24px">
      <label class="label">Database Password</label>
      <input type="password" name="db_pass" class="input" placeholder="Leave empty if none">
    </div>

    <button type="submit" class="btn">Test & Connect Database →</button>
  </form>

  <?php elseif ($step == 2): ?>
  <!-- STEP 2: ADMIN ACCOUNT CREATION -->
  <form method="POST">
    <input type="hidden" name="action" value="setup_admin">
    <h3 style="font-size:16px;font-weight:700;margin-bottom:16px;color:#0f172a">Step 2: Create Admin Account</h3>
    
    <div class="form-group">
      <label class="label">Admin Username</label>
      <input type="text" name="username" class="input" value="admin" required autofocus>
    </div>

    <div class="form-group">
      <label class="label">Email Address (Optional)</label>
      <input type="email" name="email" class="input" placeholder="admin@example.com">
    </div>

    <div class="form-group">
      <label class="label">Password</label>
      <input type="password" name="password" class="input" placeholder="••••••••" required>
    </div>

    <div class="form-group" style="margin-bottom:24px">
      <label class="label">Confirm Password</label>
      <input type="password" name="confirm_password" class="input" placeholder="••••••••" required>
    </div>

    <button type="submit" class="btn">Complete Installation & Save →</button>
  </form>

  <?php elseif ($step == 3): ?>
  <!-- STEP 3: SETUP COMPLETE -->
  <div style="text-align:center">
    <div style="width:56px;height:56px;background:#ecfdf5;color:#10b981;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:28px;margin:0 auto 16px;font-weight:800">✓</div>
    <h3 style="font-size:20px;font-weight:800;color:#0f172a;margin-bottom:6px">Installation Complete!</h3>
    <p style="font-size:13px;color:#64748b;margin-bottom:24px">MySQL Database and Admin Account have been configured successfully.</p>
    <a href="../dashboard/#status" class="btn">Go to Dashboard →</a>
  </div>
  <?php endif; ?>

</div>

</body>
</html>
