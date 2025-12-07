<?php
// em.php - Employee module backend with integrated database setup
session_start();

/* ---------- DB connection ---------- */
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'fairhire_plus';

$mysqli = new mysqli($db_host, $db_user, $db_pass);
if ($mysqli->connect_errno) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'DB connect error: '.$mysqli->connect_error]);
    exit;
}
$mysqli->query("CREATE DATABASE IF NOT EXISTS `{$db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
$mysqli->select_db($db_name);
$mysqli->set_charset('utf8mb4');

// helper esc
function esc($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

/* ---------- AUTO-SETUP: Ensure tables exist and add missing columns ---------- */
function setupDatabase($mysqli) {
    // Create work_hours table
    $mysqli->query("CREATE TABLE IF NOT EXISTS work_hours (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT DEFAULT 1,
        start_time TIME,
        end_time TIME,
        hours_worked DECIMAL(5,2),
        work_date DATE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    
    // Create leaves table
    $mysqli->query("CREATE TABLE IF NOT EXISTS leaves (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT DEFAULT 1,
        leave_type VARCHAR(50) DEFAULT '',
        days_requested INT DEFAULT 0,
        reason TEXT,
        status VARCHAR(50) DEFAULT 'pending',
        note TEXT,
        applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    
    // Create requests table
    $mysqli->query("CREATE TABLE IF NOT EXISTS requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        from_user_id INT DEFAULT 0,
        to_role VARCHAR(80) DEFAULT '',
        subject VARCHAR(255) DEFAULT '',
        details TEXT,
        status VARCHAR(50) DEFAULT 'pending',
        note TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    
    // Create users table
    $mysqli->query("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) DEFAULT '',
        role VARCHAR(50) DEFAULT '',
        email VARCHAR(150) DEFAULT '',
        total_salary DECIMAL(10,2) DEFAULT 0,
        monthly_salary DECIMAL(10,2) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    
    // Add missing columns to users table
    $result = $mysqli->query("SHOW COLUMNS FROM users LIKE 'total_salary'");
    if ($result->num_rows == 0) {
        $mysqli->query("ALTER TABLE users ADD COLUMN total_salary DECIMAL(10,2) DEFAULT 0");
    }
    
    $result = $mysqli->query("SHOW COLUMNS FROM users LIKE 'monthly_salary'");
    if ($result->num_rows == 0) {
        $mysqli->query("ALTER TABLE users ADD COLUMN monthly_salary DECIMAL(10,2) DEFAULT 0");
    }
    
    // Add missing columns to leaves table
    $result = $mysqli->query("SHOW COLUMNS FROM leaves LIKE 'days_requested'");
    if ($result->num_rows == 0) {
        $mysqli->query("ALTER TABLE leaves ADD COLUMN days_requested INT DEFAULT 0");
    }
    
    // Add missing columns to work_hours table
    $result = $mysqli->query("SHOW COLUMNS FROM work_hours LIKE 'hours_worked'");
    if ($result->num_rows == 0) {
        $mysqli->query("ALTER TABLE work_hours ADD COLUMN hours_worked DECIMAL(5,2) DEFAULT 0");
    }

    // Ensure Company Manager related user columns exist: cm_remarks, job_status, salary_change
    $result = $mysqli->query("SHOW COLUMNS FROM users LIKE 'cm_remarks'");
    if ($result && $result->num_rows == 0) {
      $mysqli->query("ALTER TABLE users ADD COLUMN cm_remarks TEXT NULL");
    }
    $result = $mysqli->query("SHOW COLUMNS FROM users LIKE 'job_status'");
    if ($result && $result->num_rows == 0) {
      $mysqli->query("ALTER TABLE users ADD COLUMN job_status VARCHAR(50) DEFAULT 'active'");
    }
    $result = $mysqli->query("SHOW COLUMNS FROM users LIKE 'salary_change'");
    if ($result && $result->num_rows == 0) {
      $mysqli->query("ALTER TABLE users ADD COLUMN salary_change DECIMAL(10,2) DEFAULT 0");
    }
    
    // Insert sample employee data if empty
    $count = $mysqli->query("SELECT COUNT(*) FROM users")->fetch_row()[0];
    if ($count == 0) {
      $mysqli->query("INSERT INTO users (id, username, role, email, total_salary, monthly_salary, job_status, salary_change) VALUES 
        (1, 'John Doe', 'employee', 'john@example.com', 485000, 40000, 'active', 0),
        (2, 'admin', 'admin', 'admin@example.com', 0, 0, 'active', 0),
        (3, 'hr', 'hr', 'hr@example.com', 0, 0, 'active', 0),
        (4, 'cm', 'company_manager', 'cm@example.com', 0, 0, 'active', 0)");
    }
    
    // Insert sample work hours if empty
    $check = $mysqli->query("SELECT COUNT(*) FROM work_hours")->fetch_row()[0];
    if ($check == 0) {
        for ($i = 0; $i < 6; $i++) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $hours = 8 + (rand(0, 4) / 2);
            $mysqli->query("INSERT INTO work_hours (employee_id, start_time, end_time, hours_worked, work_date) 
                VALUES (1, '09:00:00', '17:30:00', $hours, '$date')");
        }
    }
}

// Run setup on every page load (checks are automatic)
setupDatabase($mysqli);

// Ensure employee id 1 has requested salary values (monthly 40,000 BDT, yearly 480,000 BDT, total 485,000 BDT)
$mysqli->query("UPDATE users SET monthly_salary=40000, total_salary=485000 WHERE id=1 AND role='employee'");

/* ---------- handle login (AJAX POST) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $uid = $_POST['userid'] ?? '';
    $pw  = $_POST['password'] ?? '';
    // EM credentials: EM / 123
    if ($uid === 'EM' && $pw === '123') {
        $_SESSION['em'] = true;
        $_SESSION['em_id'] = 1; // demo employee id
        header('Content-Type: application/json');
        echo json_encode(['success'=>true]);
        exit;
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success'=>false,'message'=>'Invalid credentials']);
        exit;
    }
}

/* ---------- logout ---------- */
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_unset();
    session_destroy();
    echo json_encode(['success'=>true]);
    exit;
}

/* If check param => return whether logged in */
if (isset($_GET['check']) && $_GET['check'] == '1') {
    header('Content-Type: application/json');
    echo json_encode(['logged' => (isset($_SESSION['em']) && $_SESSION['em']===true)]);
    exit;
}

/* Require session for fragments/actions */
if (!isset($_SESSION['em']) || $_SESSION['em'] !== true) {
    if (isset($_GET['section']) || isset($_REQUEST['action'])) {
        echo '<div class="section-card"><p style="color:#ff8b8b">ACCESS DENIED — please login first.</p></div>';
        exit;
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success'=>false,'message'=>'not logged in']);
        exit;
    }
}

$emp_id = $_SESSION['em_id'] ?? 1;

/* ---------- Process actions ---------- */
$action = $_REQUEST['action'] ?? null;

if ($action === 'apply_leave' && isset($_POST['leave_type'])) {
    $leave_type = $_POST['leave_type'] ?? '';
    $days_requested = intval($_POST['days_requested'] ?? 1);
    $reason = $_POST['reason'] ?? '';
    
    // Validate not more than 5 days
    if ($days_requested > 5) $days_requested = 5;
    if ($days_requested < 1) $days_requested = 1;
    
    $stmt = $mysqli->prepare("INSERT INTO leaves (employee_id, leave_type, days_requested, reason, status) VALUES (?, ?, ?, ?, ?)");
    $status = 'pending';
    $stmt->bind_param('isiss', $emp_id, $leave_type, $days_requested, $reason, $status);
    $stmt->execute();
}

if ($action === 'send_request' && isset($_POST['subject'])) {
    $to_role = $_POST['to_role'] ?? 'admin';
    $subject = $_POST['subject'] ?? '';
    $details = $_POST['details'] ?? '';
    $stmt = $mysqli->prepare("INSERT INTO requests (from_user_id, to_role, subject, details, status) VALUES (?, ?, ?, ?, ?)");
    $status = 'pending';
    $stmt->bind_param('issss', $emp_id, $to_role, $subject, $details, $status);
    $stmt->execute();
}

if ($action === 'add_work_hours' && isset($_POST['work_date'])) {
    $work_date = $_POST['work_date'] ?? date('Y-m-d');
    $start_time = $_POST['start_time'] ?? '09:00';
    $end_time = $_POST['end_time'] ?? '17:00';
    
    // Calculate hours worked
  // Create full datetime objects for accurate diff (handles minutes correctly)
  try {
    $sdt = new DateTime($work_date . ' ' . $start_time);
    $edt = new DateTime($work_date . ' ' . $end_time);
    $seconds = $edt->getTimestamp() - $sdt->getTimestamp();
    if ($seconds < 0) $seconds = 0;
    $hours_worked = round($seconds / 3600, 2);
  } catch (Exception $e) {
    $hours_worked = 0;
  }
    
    // Check if entry exists for this date
    $check = $mysqli->query("SELECT id FROM work_hours WHERE employee_id=$emp_id AND work_date='$work_date'")->fetch_assoc();
    
    if ($check) {
        // Update existing entry
        $stmt = $mysqli->prepare("UPDATE work_hours SET start_time=?, end_time=?, hours_worked=? WHERE employee_id=? AND work_date=?");
        $stmt->bind_param('ssdis', $start_time, $end_time, $hours_worked, $emp_id, $work_date);
        $stmt->execute();
    } else {
        // Insert new entry
        $stmt = $mysqli->prepare("INSERT INTO work_hours (employee_id, start_time, end_time, hours_worked, work_date) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param('issds', $emp_id, $start_time, $end_time, $hours_worked, $work_date);
        $stmt->execute();
    }
}

/* ---------- Serve fragments for each section ---------- */
$section = $_GET['section'] ?? '';

if ($section === 'dashboard') {
    // Get user salary info
    $res = $mysqli->query("SELECT total_salary, monthly_salary FROM users WHERE id=$emp_id");
    $user = $res->fetch_assoc() ?? [];
    $total_salary = floatval($user['total_salary'] ?? 0);
    if ($total_salary == 0) $total_salary = 600000; // demo: 600k/year
    $monthly_salary = $total_salary / 12;
    $yearly_salary = $total_salary;

    // Get work hours for today
    $today_res = $mysqli->query("SELECT start_time, end_time FROM work_hours WHERE employee_id=$emp_id AND work_date=CURDATE() LIMIT 1");
    $today_work = $today_res->fetch_assoc() ?? [];
    $start_time = $today_work['start_time'] ?? '09:00:00';
    $end_time = $today_work['end_time'] ?? '17:00:00';

    // Calculate today hours using timestamps for accuracy
    try {
      $sdt = new DateTime(date('Y-m-d') . ' ' . $start_time);
      $edt = new DateTime(date('Y-m-d') . ' ' . $end_time);
      $secs = $edt->getTimestamp() - $sdt->getTimestamp();
      if ($secs < 0) $secs = 0;
      $today_hours = round($secs / 3600, 2);
    } catch (Exception $e) {
      $today_hours = 0;
    }

    // Get daily hours this month
    $daily_res = $mysqli->query("SELECT COALESCE(SUM(hours_worked), 0) as total FROM work_hours WHERE employee_id=$emp_id AND MONTH(work_date)=MONTH(CURDATE()) AND YEAR(work_date)=YEAR(CURDATE())");
    $monthly_hours = floatval($daily_res->fetch_assoc()['total'] ?? 0);

    // Get yearly hours
    $yearly_res = $mysqli->query("SELECT COALESCE(SUM(hours_worked), 0) as total FROM work_hours WHERE employee_id=$emp_id AND YEAR(work_date)=YEAR(CURDATE())");
    $yearly_hours = floatval($yearly_res->fetch_assoc()['total'] ?? 0);

    // Get total leaves and remaining leaves
    $total_leaves = 35; // 35 days total
    $taken_leaves = $mysqli->query("SELECT COALESCE(SUM(days_requested), 0) as total FROM leaves WHERE employee_id=$emp_id AND status IN ('approved', 'pending')")->fetch_assoc()['total'] ?? 0;
    $remaining_leaves = $total_leaves - intval($taken_leaves);
    if ($remaining_leaves < 0) $remaining_leaves = 0;

    // Monthly work hours (last 6 months)
    $months = []; $work_data = [];
    $res = $mysqli->query("SELECT DATE_FORMAT(work_date,'%Y-%m') as ym, SUM(hours_worked) as total FROM work_hours WHERE employee_id=$emp_id GROUP BY ym ORDER BY ym DESC LIMIT 6");
    $tmp = [];
    while($r = $res->fetch_assoc()) $tmp[$r['ym']] = floatval($r['total']);
    $keys = array_reverse(array_keys($tmp));
    foreach($keys as $k){ $months[] = $k; $work_data[] = $tmp[$k]; }
    ?>
    <div class="page-title">Dashboard</div>

    <div class="section-card">
      <h3 style="margin-top:0;color:#fff;">Monthly Work Hours Trend</h3>
      <canvas id="workChart" data-chart='<?= json_encode([
        "type"=>"bar",
        "data"=>["labels"=>$months, "datasets"=>[["label"=>"Work Hours","data"=>$work_data,"backgroundColor"=>"#4b9eff"]]],
        "options"=>["responsive"=>true,"maintainAspectRatio"=>true]
      ]) ?>'></canvas>
    </div>
    <?php
    exit;
}

if ($section === 'work_hours') {
    // Get work hours history
    $wh_res = $mysqli->query("SELECT * FROM work_hours WHERE employee_id=$emp_id ORDER BY work_date DESC LIMIT 30");
    
    // Get today's entry
    $today_res = $mysqli->query("SELECT start_time, end_time, hours_worked FROM work_hours WHERE employee_id=$emp_id AND work_date=CURDATE() LIMIT 1");
    $today_work = $today_res->fetch_assoc() ?? [];
    $today_start = $today_work['start_time'] ?? '09:00';
    $today_end = $today_work['end_time'] ?? '17:00';
    $today_hours = floatval($today_work['hours_worked'] ?? 0);
    ?>
    <div class="page-title">Work Hours</div>
    
    <div class="section-card">
      <h3 style="margin-top:0;color:#fff;">Add/Update Work Hours</h3>
      <form method="post" action="em.php" class="request-form" style="max-width:600px;">
        <input type="hidden" name="action" value="add_work_hours">
        
        <div class="form-group">
          <label>Date</label>
          <input type="date" name="work_date" value="<?= date('Y-m-d') ?>" required>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <div class="form-group">
            <label>Start Time</label>
            <input type="time" name="start_time" value="<?= substr($today_start, 0, 5) ?>" required>
          </div>

          <div class="form-group">
            <label>End Time</label>
            <input type="time" name="end_time" value="<?= substr($today_end, 0, 5) ?>" required>
          </div>
        </div>

        <div class="form-group">
          <label>Hours Worked (Today)</label>
          <input id="todayHoursInput" type="text" value="<?= number_format($today_hours, 2) ?> hours" disabled style="background:#0a0a0a;color:#4b9eff;font-weight:600;">
        </div>

        <button type="submit" class="submit-btn">Save Work Hours</button>
      </form>
    </div>

    <div class="section-card">
      <h3 style="margin-top:0;color:#fff;">Work Hours History</h3>
      <div class="table-frame">
        <table>
          <thead><tr><th>Date</th><th>Start Time</th><th>End Time</th><th>Hours Worked</th></tr></thead>
          <tbody>
            <?php while($r = $wh_res->fetch_assoc()): ?>
            <tr>
              <td><?= esc($r['work_date']) ?></td>
              <td><?= esc($r['start_time']) ?></td>
              <td><?= esc($r['end_time']) ?></td>
              <td><?= number_format(floatval($r['hours_worked']), 1) ?> h</td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php
    exit;
}

if ($section === 'leave_apply') {
    // Get leave stats
    $total_leaves = 35;
    $taken_leaves = $mysqli->query("SELECT COALESCE(SUM(days_requested), 0) as total FROM leaves WHERE employee_id=$emp_id AND status IN ('approved', 'pending')")->fetch_assoc()['total'] ?? 0;
    $remaining_leaves = $total_leaves - intval($taken_leaves);
    if ($remaining_leaves < 0) $remaining_leaves = 0;

    // Get past leaves
    $leaves_res = $mysqli->query("SELECT * FROM leaves WHERE employee_id=$emp_id ORDER BY applied_at DESC");
    ?>
    <div class="page-title">Leave Application</div>
    
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-value"><?= $total_leaves ?></div>
        <div class="stat-label">Total Leave Days</div>
      </div>
      <div class="stat-card">
        <div class="stat-value"><?= $remaining_leaves ?></div>
        <div class="stat-label">Remaining Leave</div>
      </div>
    </div>

    <div class="section-card">
      <h3 style="margin-top:0;color:#fff;">Apply for Leave</h3>
      <form method="post" action="em.php" class="request-form" style="max-width:600px;">
        <input type="hidden" name="action" value="apply_leave">
        
        <div class="form-group">
          <label>Leave Type</label>
          <select name="leave_type" required>
            <option>Annual</option>
            <option>Sick</option>
            <option>Casual</option>
            <option>Maternity</option>
            <option>Others</option>
          </select>
        </div>

        <div class="form-group">
          <label>Asking Leave (Max 5 days)</label>
          <input type="number" name="days_requested" min="1" max="5" value="1" required>
          <small style="color:#999;display:block;margin-top:4px;">* Maximum 5 days at a single time</small>
        </div>

        <div class="form-group">
          <label>Reason</label>
          <textarea name="reason" required placeholder="Enter reason for leave"></textarea>
        </div>

        <button type="submit" class="submit-btn">Submit Leave Request</button>
      </form>
    </div>

    <div class="section-card">
      <h3 style="margin-top:0;color:#fff;">Your Leave History</h3>
      <div class="table-frame">
        <table>
          <thead><tr><th>ID</th><th>Type</th><th>Days</th><th>Reason</th><th>Status</th><th>Date</th></tr></thead>
          <tbody>
            <?php while($r = $leaves_res->fetch_assoc()): ?>
            <tr>
              <td><?= intval($r['id']) ?></td>
              <td><?= esc($r['leave_type']) ?></td>
              <td><?= intval($r['days_requested']) ?></td>
              <td><?= esc(substr($r['reason'], 0, 30)) ?></td>
              <td><span style="background:<?= $r['status']=='pending'?'#331100':($r['status']=='approved'?'#003300':'#330000') ?>;padding:4px 8px;border-radius:4px;"><?= esc($r['status']) ?></span></td>
              <td><?= substr($r['applied_at'], 0, 10) ?></td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php
    exit;
}

if ($section === 'requests') {
    $req_res = $mysqli->query("SELECT * FROM requests WHERE from_user_id=$emp_id ORDER BY created_at DESC");
    ?>
    <div class="page-title">Requests</div>
    
    <div class="section-card">
      <h3 style="margin-top:0;color:#fff;">Send Request</h3>
      <form method="post" action="em.php" class="request-form" style="max-width:600px;">
        <input type="hidden" name="action" value="send_request">
        
        <div class="form-group">
          <label>Send To</label>
          <select name="to_role" required>
            <option value="admin">Admin</option>
            <option value="hr">HR Manager</option>
          </select>
        </div>

        <div class="form-group">
          <label>Subject</label>
          <input type="text" name="subject" required placeholder="Enter request subject">
        </div>

        <div class="form-group">
          <label>Details</label>
          <textarea name="details" required placeholder="Enter request details"></textarea>
        </div>

        <button type="submit" class="submit-btn">Send Request</button>
      </form>
    </div>

    <div class="section-card">
      <h3 style="margin-top:0;color:#fff;">Your Requests</h3>
      <div class="table-frame">
        <table>
          <thead><tr><th>ID</th><th>To</th><th>Subject</th><th>Status</th><th>Note</th><th>Date</th></tr></thead>
          <tbody>
            <?php while($r = $req_res->fetch_assoc()): ?>
            <tr>
              <td><?= intval($r['id']) ?></td>
              <td><?= esc($r['to_role']) ?></td>
              <td><?= esc($r['subject']) ?></td>
              <td><span style="background:<?= $r['status']=='pending'?'#331100':($r['status']=='approved'?'#003300':'#330000') ?>;padding:4px 8px;border-radius:4px;"><?= esc($r['status']) ?></span></td>
              <td><?= esc($r['note'] ?? '-') ?></td>
              <td><?= substr($r['created_at'], 0, 10) ?></td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php
    exit;
}

if ($section === 'compensation_summary') {
  // Show compensation summary only for the logged-in employee (do not allow viewing other users)
  $emp_id = intval($emp_id);
  if ($emp_id <= 0) {
    echo '<div class="section-card"><p style="color:#ff8b8b">ACCESS DENIED — invalid employee.</p></div>';
    exit;
  }
  // Fetch compensation from `compensation` table for the logged-in employee.
  // If present, compute Total = monthly*12 + yearly_bonus.
  $emp_id = intval($emp_id);
  if ($emp_id <= 0) {
    echo '<div class="section-card"><p style="color:#ff8b8b">ACCESS DENIED — invalid employee.</p></div>';
    exit;
  }

  // Try compensation table first
  $compRes = $mysqli->query("SELECT monthly_salary, yearly_bonus, updated_at FROM compensation WHERE employee_id={$emp_id} LIMIT 1");
  $comp = $compRes ? $compRes->fetch_assoc() : null;

  // Fetch user meta (username, job status, remarks, fallback salary fields)
  $userRes = $mysqli->query("SELECT id, username, role, total_salary, monthly_salary as user_monthly, job_status, salary_change, cm_remarks FROM users WHERE id={$emp_id} LIMIT 1");
  $user = $userRes ? $userRes->fetch_assoc() : null;

  // If compensation row exists, use it. Otherwise fall back to `users` table values.
  if ($comp) {
    $monthly = floatval($comp['monthly_salary'] ?? 0);
    $yearly_bonus = floatval($comp['yearly_bonus'] ?? 0);
    $yearly = round($monthly * 12, 2);
    $total = round($yearly + $yearly_bonus, 2);
    $username = $user['username'] ?? ($user['id'] ? 'Employee' : 'Employee');
    $salary_change = floatval($user['salary_change'] ?? 0);
    $job_status = $user['job_status'] ?? 'active';
    $cm_remarks = $user['cm_remarks'] ?? '-';
  } else {
    // No compensation table row — fallback to user row or other employee record
    if (!$user || ($user['role'] ?? '') !== 'employee') {
      // try to find the first employee row
      $empFallback = $mysqli->query("SELECT id, username, total_salary, monthly_salary, job_status, salary_change, cm_remarks FROM users WHERE role='employee' LIMIT 1");
      $user = $empFallback ? $empFallback->fetch_assoc() : $user;
      if ($user) $emp_id = intval($user['id']);
    }
    if (!$user) {
      echo '<div class="section-card"><p style="color:#ff8b8b">No compensation data available for this account.</p></div>';
      exit;
    }
    // Use fields from users table
    $monthly = floatval($user['monthly_salary'] ?? $user['user_monthly'] ?? 0);
    $total = floatval($user['total_salary'] ?? 0);
    if ($monthly <= 0 && $total > 0) $monthly = round($total / 12, 2);
    if ($total <= 0 && $monthly > 0) $total = round($monthly * 12, 2);
    $yearly = round($monthly * 12, 2);
    $username = $user['username'] ?? 'Employee';
    $salary_change = floatval($user['salary_change'] ?? 0);
    $job_status = $user['job_status'] ?? 'active';
    $cm_remarks = $user['cm_remarks'] ?? '-';
  }
  // At this point we have these variables set for output:
  // $username, $total, $monthly, $yearly, $salary_change, $job_status, $cm_remarks
    ?>
    <div class="page-title">Compensation Summary</div>
    <div class="section-card">
      <div class="table-frame">
        <table>
          <thead><tr><th>Employee ID</th><th>Total Salary</th><th>Monthly Salary</th><th>Yearly Salary</th><th>Salary Change</th><th>Job Status</th><th>CM Remarks</th></tr></thead>
          <tbody>
            <tr>
              <td><?= intval($emp_id) ?></td>
              <td>$<?= number_format(floatval($total ?? 0), 0) ?></td>
              <td>$<?= number_format(floatval($monthly ?? 0), 0) ?></td>
              <td>$<?= number_format(floatval($yearly ?? 0), 0) ?></td>
              <td><?= number_format(floatval($salary_change ?? 0), 2) ?></td>
              <td><?= esc($job_status ?? 'active') ?></td>
              <td><?= nl2br(esc($cm_remarks ?? '-')) ?></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <?php
    exit;
}

echo '<div class="empty-note">Select a menu item from left.</div>';
exit;
?>
