<?php
// hr.php - backend for hr.html
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

/* ---------- ensure tables exist ---------- */
$createApplicants = "CREATE TABLE IF NOT EXISTS applicants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    applicant_id VARCHAR(60) DEFAULT '',
    name VARCHAR(200) DEFAULT '',
    contact VARCHAR(100) DEFAULT '',
    applied_position VARCHAR(200) DEFAULT '',
    experience_years INT DEFAULT 0,
    status VARCHAR(50) DEFAULT 'new',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$mysqli->query($createApplicants);

$createLeaves = "CREATE TABLE IF NOT EXISTS leaves (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT DEFAULT 0,
    leave_type VARCHAR(80) DEFAULT '',
    reason TEXT,
    remaining_leave INT DEFAULT 0,
    days_requested INT DEFAULT 1,
    status VARCHAR(50) DEFAULT 'pending',
    remarks TEXT,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$mysqli->query($createLeaves);

// Ensure days_requested column exists
$res = $mysqli->query("SHOW COLUMNS FROM leaves LIKE 'days_requested'");
if ($res && $res->num_rows == 0) {
    $mysqli->query("ALTER TABLE leaves ADD COLUMN days_requested INT DEFAULT 1");
}

$createRequests = "CREATE TABLE IF NOT EXISTS requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    from_user_id INT DEFAULT 0,
    to_role VARCHAR(80) DEFAULT '',
    subject VARCHAR(255) DEFAULT '',
    details TEXT,
    status VARCHAR(50) DEFAULT 'pending',
    note TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$mysqli->query($createRequests);

$createUsers = "CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) DEFAULT '',
    role VARCHAR(50) DEFAULT '',
    email VARCHAR(150) DEFAULT '',
    total_salary DECIMAL(12,2) DEFAULT 0,
    monthly_salary DECIMAL(12,2) DEFAULT 0,
    job_status VARCHAR(50) DEFAULT 'active',
    salary_change DECIMAL(10,2) DEFAULT 0,
    cm_remarks TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$mysqli->query($createUsers);

// Ensure HR-specific user columns exist
$res = $mysqli->query("SHOW COLUMNS FROM users LIKE 'cm_remarks'");
if ($res && $res->num_rows == 0) { $mysqli->query("ALTER TABLE users ADD COLUMN cm_remarks TEXT NULL"); }
$res = $mysqli->query("SHOW COLUMNS FROM users LIKE 'job_status'");
if ($res && $res->num_rows == 0) { $mysqli->query("ALTER TABLE users ADD COLUMN job_status VARCHAR(50) DEFAULT 'active'"); }
$res = $mysqli->query("SHOW COLUMNS FROM users LIKE 'salary_change'");
if ($res && $res->num_rows == 0) { $mysqli->query("ALTER TABLE users ADD COLUMN salary_change DECIMAL(10,2) DEFAULT 0"); }
$res = $mysqli->query("SHOW COLUMNS FROM users LIKE 'total_salary'");
if ($res && $res->num_rows == 0) { $mysqli->query("ALTER TABLE users ADD COLUMN total_salary DECIMAL(12,2) DEFAULT 0"); }
$res = $mysqli->query("SHOW COLUMNS FROM users LIKE 'monthly_salary'");
if ($res && $res->num_rows == 0) { $mysqli->query("ALTER TABLE users ADD COLUMN monthly_salary DECIMAL(12,2) DEFAULT 0"); }

/* Initialize salary data for employees 1-5 */
/* UPDATED: Loop reduced to 5 to prevent creating extra users if DB is fresh */
for ($i = 1; $i <= 5; $i++) {
    $is_target_group = true; 
    
    // Default values
    $username = "employee" . $i; // Use strictly employee1 to employee5 etc
    if ($i==1) $username = "employee0"; // adjust for specific naming preference if needed
    
    $role = 'employee';
    $status = 'active';
    $change = 0;
    
    // Target group data
    $total = 485000;
    $monthly = 40000;

    $res = $mysqli->query("SELECT id FROM users WHERE id = {$i}");
    if ($res && $res->num_rows == 0) {
        $stmt = $mysqli->prepare("INSERT INTO users (id, username, role, total_salary, monthly_salary, job_status, salary_change) VALUES (?,?,?,?,?,?,?)");
        $stmt->bind_param('issddsd', $i, $username, $role, $total, $monthly, $status, $change);
        $stmt->execute();
    } else {
        // Force update for 1-5 to ensure data is correct as per request
        $stmt = $mysqli->prepare("UPDATE users SET total_salary=?, monthly_salary=?, job_status=?, salary_change=? WHERE id=?");
        $stmt->bind_param('ddsdi', $total, $monthly, $status, $change, $i);
        $stmt->execute();
    }
}

/* ---------- handle login (AJAX POST) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $uid = trim($_POST['userid'] ?? '');
    $pw  = trim($_POST['password'] ?? '');
    // HR credentials: HR / 123
    if ($uid === 'HR' && $pw === '123') {
        $_SESSION['hr'] = true;
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
    echo json_encode(['logged' => (isset($_SESSION['hr']) && $_SESSION['hr']===true)]);
    exit;
}

/* ---------- Process actions (POST/GET) ---------- */
$action = $_REQUEST['action'] ?? null;

// Handle HR applying for leave
if ($action === 'apply_leave' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $employee_id = intval($_POST['employee_id'] ?? 2); // Default to HR ID
    $leave_type = $_POST['leave_type'] ?? '';
    $days = intval($_POST['days_requested'] ?? 1);
    if ($days < 1) $days = 1;
    if ($days > 5) $days = 5;
    $reason = $_POST['reason'] ?? '';
    
    // Stats: Total 35. 
    $stmt = $mysqli->prepare("INSERT INTO leaves (employee_id, leave_type, days_requested, reason, status) VALUES (?, ?, ?, ?, 'pending')");
    if ($stmt) {
        $stmt->bind_param('isis', $employee_id, $leave_type, $days, $reason);
        $stmt->execute();
    }
    
    // Redirect back to the fragment view using PHP_SELF to avoid 404s
    $self = basename($_SERVER['PHP_SELF']);
    header("Location: $self?section=leave_apply");
    exit;
}

/* Require session for fragments */
if (!isset($_SESSION['hr']) || $_SESSION['hr'] !== true) {
    if (isset($_GET['section']) || isset($_REQUEST['action'])) {
        echo '<div class="section-card"><p style="color:#ff8b8b">ACCESS DENIED — please login first.</p></div>';
        exit;
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success'=>false,'message'=>'not logged in']);
        exit;
    }
}

// Action Processing
if ($action === 'approve_applicant' && isset($_REQUEST['id'])) {
    $id = intval($_REQUEST['id']);
    $stmt = $mysqli->prepare("UPDATE applicants SET status='approved' WHERE id=?");
    $stmt->bind_param('i',$id); $stmt->execute();
}
if ($action === 'decline_applicant' && isset($_REQUEST['id'])) {
    $id = intval($_REQUEST['id']);
    $stmt = $mysqli->prepare("UPDATE applicants SET status='declined' WHERE id=?");
    $stmt->bind_param('i',$id); $stmt->execute();
}

if (($action === 'approve_leave' || $action === 'decline_leave') && (isset($_REQUEST['id']) || isset($_POST['id']))) {
    $id = intval($_REQUEST['id'] ?? $_POST['id']);
    $remark = $_POST['remark'] ?? null;
    $status = ($action === 'approve_leave') ? 'approved' : 'declined';
    $stmt = $mysqli->prepare("UPDATE leaves SET status=?, remarks=? WHERE id=?");
    $stmt->bind_param('ssi', $status, $remark, $id);
    $stmt->execute();
}

if (($action === 'approve_request' || $action === 'decline_request') && (isset($_REQUEST['id']) || isset($_POST['id']))) {
    $id = intval($_REQUEST['id'] ?? $_POST['id']);
    $remark = $_POST['remark'] ?? null;
    $status = ($action === 'approve_request') ? 'approved' : 'declined';
    $stmt = $mysqli->prepare("UPDATE requests SET status=?, note=? WHERE id=?");
    $stmt->bind_param('ssi', $status, $remark, $id);
    $stmt->execute();
}

if ($action === 'submit_request' && isset($_POST['subject'])) {
    $subject = $_POST['subject'] ?? '';
    $details = $_POST['details'] ?? '';
    $stmt = $mysqli->prepare("INSERT INTO requests (from_user_id, to_role, subject, details, status) VALUES (?, ?, ?, ?, ?)");
    $user_id = 1; // HR user id
    $to_role = 'admin';
    $status = 'pending';
    $stmt->bind_param('issss', $user_id, $to_role, $subject, $details, $status);
    $stmt->execute();
    // Redirect to prevent resubmission
    $self = basename($_SERVER['PHP_SELF']);
    header("Location: $self?section=request-admin");
    exit;
}

/* ---------- Serve fragments for each section ---------- */
$section = $_GET['section'] ?? '';

if ($section === 'dashboard') {
    // UPDATED: Count only Employees 1-5 to match overview, ignoring potential old data 6-10
    $emp_count = intval($mysqli->query("SELECT COUNT(*) FROM users WHERE role='employee' AND id BETWEEN 1 AND 5")->fetch_row()[0] ?? 0);
    
    $new_applicants = intval($mysqli->query("SELECT COUNT(*) FROM applicants WHERE status='new'")->fetch_row()[0] ?? 0);
    $pending_leaves = intval($mysqli->query("SELECT COUNT(*) FROM leaves WHERE status='pending'")->fetch_row()[0] ?? 0);
    
    // Stats for charts
    $approved_applicants = intval($mysqli->query("SELECT COUNT(*) FROM applicants WHERE status='approved'")->fetch_row()[0] ?? 0);
    $declined_applicants = intval($mysqli->query("SELECT COUNT(*) FROM applicants WHERE status='declined'")->fetch_row()[0] ?? 0);
    $approved_leaves = intval($mysqli->query("SELECT COUNT(*) FROM leaves WHERE status='approved'")->fetch_row()[0] ?? 0);
    $declined_leaves = intval($mysqli->query("SELECT COUNT(*) FROM leaves WHERE status='declined'")->fetch_row()[0] ?? 0);

    // monthly applicants data
    $months = []; $counts = [];
    $res = $mysqli->query("SELECT DATE_FORMAT(created_at,'%Y-%m') as ym, COUNT(*) as cnt FROM applicants GROUP BY ym ORDER BY ym DESC LIMIT 6");
    $tmp = [];
    while($r = $res->fetch_assoc()) $tmp[$r['ym']] = intval($r['cnt']);
    $keys = array_reverse(array_keys($tmp));
    foreach($keys as $k){ $months[] = $k; $counts[] = $tmp[$k]; }

    // monthly leaves data
    $leave_months = []; $leave_counts = [];
    $res = $mysqli->query("SELECT DATE_FORMAT(applied_at,'%Y-%m') as ym, COUNT(*) as cnt FROM leaves GROUP BY ym ORDER BY ym DESC LIMIT 6");
    $tmp = [];
    while($r = $res->fetch_assoc()) $tmp[$r['ym']] = intval($r['cnt']);
    $keys = array_reverse(array_keys($tmp));
    foreach($keys as $k){ $leave_months[] = $k; $leave_counts[] = $tmp[$k]; }
    ?>
    <div class="page-title">HR Dashboard</div>
    <div class="section-card">
      <div style="display:flex;gap:20px;flex-wrap:wrap;">
        <div style="flex:1;min-width:200px;padding:18px;background:#111;border-radius:8px;border:1px solid #222;">
          <div style="font-size:24px;font-weight:700;color:#ff6b6b;"><?= $emp_count ?></div>
          <div style="color:#999;margin-top:6px;">Total Employees</div>
        </div>
        <div style="flex:1;min-width:200px;padding:18px;background:#111;border-radius:8px;border:1px solid #222;">
          <div style="font-size:24px;font-weight:700;color:#ff6b6b;"><?= $new_applicants + $approved_applicants + $declined_applicants ?></div>
          <div style="color:#999;margin-top:6px;">Total Applicants</div>
        </div>
        <div style="flex:1;min-width:200px;padding:18px;background:#111;border-radius:8px;border:1px solid #222;">
          <div style="font-size:24px;font-weight:700;color:#ff6b6b;"><?= $pending_leaves ?></div>
          <div style="color:#999;margin-top:6px;">Pending Leaves</div>
        </div>
      </div>
    </div>

    <div class="section-card chart-card">
      <div style="display:flex;gap:20px;flex-wrap:wrap;">
        <div style="flex:1;min-width:260px">
          <canvas id="applicantPie" data-chart='<?= json_encode([
            "type"=>"pie",
            "data"=>["labels"=>["New","Approved","Declined"], "datasets"=>[["data"=>[$new_applicants,$approved_applicants,$declined_applicants],"backgroundColor"=>["#ff8c42","#06d6a0","#ef476f"]]]],
            "options"=>["responsive"=>true,"maintainAspectRatio"=>true]
          ]) ?>'></canvas>
        </div>

        <div style="flex:1;min-width:260px">
          <canvas id="leavePie" data-chart='<?= json_encode([
            "type"=>"pie",
            "data"=>["labels"=>["Pending","Approved","Declined"], "datasets"=>[["data"=>[$pending_leaves,$approved_leaves,$declined_leaves],"backgroundColor"=>["#ffd60a","#06d6a0","#ef476f"]]]],
            "options"=>["responsive"=>true,"maintainAspectRatio"=>true]
          ]) ?>'></canvas>
        </div>
      </div>
    </div>

    <div class="section-card chart-card">
      <div style="display:flex;gap:20px;flex-wrap:wrap;">
        <div style="flex:1;min-width:300px">
          <canvas id="applicantBar" data-chart='<?= json_encode([
            "type"=>"bar",
            "data"=>["labels"=>$months, "datasets"=>[["label"=>"Applications","data"=>$counts,"backgroundColor"=>"#ff6b6b"]]],
            "options"=>["responsive"=>true,"maintainAspectRatio"=>true]
          ]) ?>'></canvas>
        </div>

        <div style="flex:1;min-width:300px">
          <canvas id="leaveBar" data-chart='<?= json_encode([
            "type"=>"bar",
            "data"=>["labels"=>$leave_months, "datasets"=>[["label"=>"Leave Applications","data"=>$leave_counts,"backgroundColor"=>"#4ecdc4"]]],
            "options"=>["responsive"=>true,"maintainAspectRatio"=>true]
          ]) ?>'></canvas>
        </div>
      </div>
    </div>
    <?php
    exit;
}

if ($section === 'applicants') {
    $res = $mysqli->query("SELECT * FROM applicants ORDER BY created_at DESC");
    ?>
    <div class="page-title">Applicant Overview</div>
    <div class="section-card">
      <div class="table-frame">
        <table>
          <thead><tr><th>ID</th><th>Name</th><th>Contact</th><th>Position</th><th>Experience</th><th>Status</th><th>Action</th></tr></thead>
          <tbody>
            <?php while($r = $res->fetch_assoc()): ?>
            <tr>
              <td><?= esc($r['applicant_id'] ?? $r['id']) ?></td>
              <td><?= esc($r['name'] ?? '') ?></td>
              <td><?= esc($r['contact'] ?? '') ?></td>
              <td><?= esc($r['applied_position'] ?? '') ?></td>
              <td><?= intval($r['experience_years'] ?? 0) ?></td>
              <td><?= esc($r['status'] ?? '') ?></td>
              <td>
                <?php if (($r['status'] ?? '') === 'new'): ?>
                  <a class="api-link action-btn" data-confirm="true" href="hr.php?action=approve_applicant&id=<?= intval($r['id']) ?>">Approve</a>
                  <a class="api-link action-btn" data-confirm="true" href="hr.php?action=decline_applicant&id=<?= intval($r['id']) ?>">Decline</a>
                <?php else: ?>
                  <span style="color:#999;font-size:13px;">Processed: <?= esc($r['status']) ?></span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php
    exit;
}

if ($section === 'leaves') {
    $res = $mysqli->query("SELECT l.*, u.username FROM leaves l LEFT JOIN users u ON u.id = l.employee_id ORDER BY l.applied_at DESC");
    ?>
    <div class="page-title">Leave Applications</div>
    <div class="section-card">
      <div class="table-frame">
        <table>
          <thead><tr><th>ID</th><th>Employee</th><th>Type</th><th>Days</th><th>Reason</th><th>Status</th><th>Remark / Action</th></tr></thead>
          <tbody>
            <?php while($r = $res->fetch_assoc()): ?>
            <tr>
              <td><?= intval($r['id']) ?></td>
              <td><?= esc($r['username'] ?? ('Employee-'.$r['employee_id'])) ?></td>
              <td><?= esc($r['leave_type'] ?? '') ?></td>
              <td><?= intval($r['days_requested'] ?? 1) ?></td>
              <td><?= esc($r['reason'] ?? '') ?></td>
              <td><?= esc($r['status'] ?? '') ?></td>
              <td>
                <?php if (($r['status'] ?? '') === 'pending'): ?>
                  <form method="post" action="hr.php?action=approve_leave" class="remark-form" style="display:inline-block;margin-right:6px">
                    <input type="hidden" name="id" value="<?= intval($r['id']) ?>">
                    <input name="remark" placeholder="Remark (optional)" value="<?= esc($r['remarks'] ?? '') ?>" style="padding:6px;border-radius:6px;background:#0b0b0b;border:1px solid #222;color:#fff;width:160px">
                    <button class="action-btn" type="submit">Approve</button>
                  </form>

                  <form method="post" action="hr.php?action=decline_leave" class="remark-form" style="display:inline-block">
                    <input type="hidden" name="id" value="<?= intval($r['id']) ?>">
                    <input name="remark" placeholder="Remark (optional)" value="<?= esc($r['remarks'] ?? '') ?>" style="padding:6px;border-radius:6px;background:#0b0b0b;border:1px solid #222;color:#fff;width:160px">
                    <button class="action-btn" type="submit">Decline</button>
                  </form>
                <?php else: ?>
                  <div style="color:#999;font-size:13px;">Processed: <?= esc($r['status']) ?></div>
                  <div style="color:#bbb;margin-top:6px;font-size:13px;">Remark: <?= esc($r['remarks'] ?? '-') ?></div>
                <?php endif; ?>
              </td>
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
    $res = $mysqli->query("SELECT r.*, u.username as from_user FROM requests r LEFT JOIN users u ON u.id=r.from_user_id ORDER BY r.created_at DESC");
    ?>
    <div class="page-title">Request Overview</div>
    <div class="section-card">
      <div class="table-frame">
        <table>
          <thead><tr><th>ID</th><th>From</th><th>To Role</th><th>Subject</th><th>Details</th><th>Status</th><th>Remark/Action</th></tr></thead>
          <tbody>
            <?php while($r = $res->fetch_assoc()): ?>
            <tr>
              <td><?= intval($r['id']) ?></td>
              <td><?= esc($r['from_user'] ?? ('User-'.$r['from_user_id'])) ?></td>
              <td><?= esc($r['to_role'] ?? '') ?></td>
              <td><?= esc($r['subject'] ?? '') ?></td>
              <td><?= esc($r['details'] ?? '') ?></td>
              <td><?= esc($r['status'] ?? '') ?></td>
              <td>
                <?php if (($r['status'] ?? '') === 'pending'): ?>
                  <form method="post" action="hr.php?action=approve_request" class="remark-form" style="display:inline-block;margin-right:6px">
                    <input type="hidden" name="id" value="<?= intval($r['id']) ?>">
                    <input name="remark" placeholder="Remark" value="<?= esc($r['note'] ?? '') ?>" style="padding:6px;border-radius:6px;background:#0b0b0b;border:1px solid #222;color:#fff;width:160px">
                    <button class="action-btn" type="submit">Approve</button>
                  </form>

                  <form method="post" action="hr.php?action=decline_request" class="remark-form" style="display:inline-block">
                    <input type="hidden" name="id" value="<?= intval($r['id']) ?>">
                    <input name="remark" placeholder="Remark" value="<?= esc($r['note'] ?? '') ?>" style="padding:6px;border-radius:6px;background:#0b0b0b;border:1px solid #222;color:#fff;width:160px">
                    <button class="action-btn" type="submit">Decline</button>
                  </form>
                <?php else: ?>
                  <div style="color:#999;font-size:13px;">Processed: <?= esc($r['status']) ?></div>
                  <div style="color:#bbb;margin-top:6px;font-size:13px;">Note: <?= esc($r['note'] ?? '-') ?></div>
                <?php endif; ?>
              </td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php
    exit;
}

if ($section === 'request-admin') {
    $res = $mysqli->query("SELECT * FROM requests WHERE from_user_id=1 AND to_role='admin' ORDER BY created_at DESC");
    ?>
    <div class="page-title">Request to Admin</div>
    <div class="section-card">
      <h3 style="margin-top:0;color:#fff;">Submit Request to Admin</h3>
      <form method="post" action="hr.php?action=submit_request" class="request-form" style="margin-bottom:24px;">
        <div class="form-group">
          <label>Subject</label>
          <input type="text" name="subject" placeholder="Enter request subject" required>
        </div>
        <div class="form-group">
          <label>Details</label>
          <textarea name="details" placeholder="Enter request details" required></textarea>
        </div>
        <button type="submit" class="submit-btn">Submit Request</button>
      </form>
    </div>

    <div class="section-card">
      <h3 style="margin-top:0;color:#fff;">Your Requests</h3>
      <div class="table-frame">
        <table>
          <thead><tr><th>ID</th><th>Subject</th><th>Details</th><th>Status</th><th>Note</th><th>Date</th></tr></thead>
          <tbody>
            <?php while($r = $res->fetch_assoc()): ?>
            <tr>
              <td><?= intval($r['id']) ?></td>
              <td><?= esc($r['subject']) ?></td>
              <td><?= esc($r['details']) ?></td>
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

if ($section === 'leave_apply') {
    $emp_id = 2; // HR user id
    $total_leaves = 35;
    $taken_leaves = $mysqli->query("SELECT COALESCE(SUM(days_requested), 0) as total FROM leaves WHERE employee_id={$emp_id} AND status IN ('approved', 'pending')")->fetch_assoc()['total'] ?? 0;
    $remaining_leaves = $total_leaves - intval($taken_leaves);
    if ($remaining_leaves < 0) $remaining_leaves = 0;

    $leaves_res = $mysqli->query("SELECT * FROM leaves WHERE employee_id={$emp_id} ORDER BY applied_at DESC");
    ?>
    <div class="page-title">Leave Application</div>

    <div class="section-card">
      <div style="display:flex;gap:20px;flex-wrap:wrap;">
        <div style="flex:1;min-width:300px;padding:18px;background:#0d0d0d;border-radius:8px;border:1px solid #222;">
          <div style="font-size:28px;font-weight:800;color:#ff4b4b;"><?= intval($total_leaves) ?></div>
          <div style="color:#999;margin-top:6px;letter-spacing:1px">TOTAL LEAVE DAYS</div>
        </div>
        <div style="flex:1;min-width:300px;padding:18px;background:#0d0d0d;border-radius:8px;border:1px solid #222;">
          <div style="font-size:28px;font-weight:800;color:#ff4b4b;"><?= intval($remaining_leaves) ?></div>
          <div style="color:#999;margin-top:6px;letter-spacing:1px">REMAINING LEAVE</div>
        </div>
      </div>
    </div>

    <div class="section-card">
      <h3 style="margin-top:0;color:#fff;">Apply for Leave</h3>
      <form method="post" action="hr.php" class="request-form" style="max-width:600px;">
        <input type="hidden" name="action" value="apply_leave">
        <input type="hidden" name="employee_id" value="<?= intval($emp_id) ?>">

        <div class="form-group">
          <label>Leave Type</label>
          <select name="leave_type" required style="background:#0b0b0b;border:1px solid #222;color:#fff;padding:8px;border-radius:6px;">
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

        <button type="submit" class="submit-btn" style="background:#ff4b4b;border-color:#ff4b4b;">Submit Leave Request</button>
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

if ($section === 'employee_overview') {
    // Filter for Employees 1-5 only
    $res = $mysqli->query("SELECT u.id,u.total_salary,u.monthly_salary,u.salary_change,u.job_status,u.cm_remarks FROM users u WHERE u.id BETWEEN 1 AND 5 ORDER BY u.id");
    ?>
    <div class="page-title">Employee Overview</div>
    <div class="section-card">
      <div class="table-frame">
        <table>
          <thead><tr><th>Employee ID</th><th>Total Salary</th><th>Monthly Salary</th><th>Yearly Salary</th><th>Salary Change</th><th>Job Status</th><th>CM Remarks</th></tr></thead>
          <tbody>
            <?php while($r = $res->fetch_assoc()): ?>
            <tr>
              <td><?= intval($r['id']) ?></td>
              <td><?= number_format(floatval($r['total_salary']),0) ?> BDT</td>
              <td><?= number_format(floatval($r['monthly_salary']),0) ?> BDT</td>
              <td><?= number_format(floatval($r['monthly_salary'] * 12),0) ?> BDT</td>
              <td><?= esc($r['salary_change'] ?? '0') ?></td>
              <td><?= esc($r['job_status']) ?></td>
              <td><?= esc(substr($r['cm_remarks'] ?? '-',0,120)) ?></td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php
    exit;
}

if ($section === 'cm_remarks') {
    // Queries 'users' table directly for IDs 1-5
    $res = $mysqli->query("SELECT id as employee_id, job_status, monthly_salary, total_salary, salary_change, cm_remarks FROM users WHERE id BETWEEN 1 AND 5 ORDER BY id");
    ?>
    <div class="page-title">Remarks from Company Manager</div>
    <div class="section-card">
      <div class="table-frame">
        <table>
          <thead><tr><th>Employee ID</th><th>Job Status</th><th>Monthly</th><th>Yearly Bonus</th><th>Salary Change</th><th>CM Remarks</th></tr></thead>
          <tbody>
            <?php while($r = $res->fetch_assoc()): 
                // Calculate Yearly Bonus: Total Salary - (Monthly * 12)
                $yearly_bonus = floatval($r['total_salary']) - (floatval($r['monthly_salary']) * 12);
            ?>
            <tr>
              <td><?= intval($r['employee_id']) ?></td>
              <td><?= esc($r['job_status'] ?? 'active') ?></td>
              <td>$<?= number_format(floatval($r['monthly_salary'] ?? 0),0) ?></td>
              <td>$<?= number_format($yearly_bonus,0) ?></td>
              <td><?= number_format(floatval($r['salary_change'] ?? 0),2) ?></td>
              <td><?= nl2br(esc($r['cm_remarks'] ?? '-')) ?></td>
            </tr>
            <?php endwhile; ?>
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