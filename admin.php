<?php
// admin.php - backend logic
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

function esc($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

/* ---------- Handle Login ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $uid = trim($_POST['userid'] ?? '');
    $pw  = trim($_POST['password'] ?? '');
    if ($uid === 'AD' && $pw === '123') {
        $_SESSION['admin'] = true;
        echo json_encode(['success'=>true]);
        exit;
    } else {
        echo json_encode(['success'=>false,'message'=>'Invalid credentials']);
        exit;
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_unset(); session_destroy();
    echo json_encode(['success'=>true]); exit;
}

if (isset($_GET['check']) && $_GET['check'] == '1') {
    echo json_encode(['logged' => (isset($_SESSION['admin']) && $_SESSION['admin']===true)]); exit;
}

if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) {
    if (isset($_GET['section'])) {
        echo '<div class="section-card"><p style="color:#ff8b8b">ACCESS DENIED — please login first.</p></div>'; exit;
    }
    echo json_encode(['success'=>false,'message'=>'not logged in']); exit;
}

/* ---------- Process Actions (AJAX JSON Responses) ---------- */
$action = $_REQUEST['action'] ?? null;
function returnSuccess() { echo json_encode(['success'=>true]); exit; }

/* BULK DELETE HANDLER */
if ($action === 'bulk_delete' && isset($_POST['ids']) && isset($_POST['type'])) {
    $raw_ids = explode(',', $_POST['ids']);
    $ids = array_map('intval', $raw_ids); // sanitize to integers
    $type = $_POST['type'];
    
    // Map type to table name
    $table = '';
    if ($type === 'applicants') $table = 'applicants';
    if ($type === 'leaves') $table = 'leaves';
    if ($type === 'requests') $table = 'requests';
    if ($type === 'work_hours') $table = 'work_hours';
    if ($type === 'users') $table = 'users';

    if ($table && count($ids) > 0) {
        $id_list = implode(',', $ids);
        // Direct query is safe here because we ran array_map('intval')
        $mysqli->query("DELETE FROM $table WHERE id IN ($id_list)");
    }
    returnSuccess();
}

/* INDIVIDUAL ACTIONS */
if ($action === 'delete_applicant' && isset($_REQUEST['id'])) {
    $stmt = $mysqli->prepare("DELETE FROM applicants WHERE id=?");
    $stmt->bind_param('i', $_REQUEST['id']); $stmt->execute();
    returnSuccess();
}
if ($action === 'delete_request' && isset($_REQUEST['id'])) {
    $stmt = $mysqli->prepare("DELETE FROM requests WHERE id=?");
    $stmt->bind_param('i', $_REQUEST['id']); $stmt->execute();
    returnSuccess();
}
if ($action === 'delete_work_hours' && isset($_REQUEST['id'])) {
    $stmt = $mysqli->prepare("DELETE FROM work_hours WHERE id=?");
    $stmt->bind_param('i', $_REQUEST['id']); $stmt->execute();
    returnSuccess();
}
if ($action === 'delete_user' && isset($_REQUEST['id'])) {
    $stmt = $mysqli->prepare("DELETE FROM users WHERE id=?");
    $stmt->bind_param('i', $_REQUEST['id']); $stmt->execute();
    returnSuccess();
}
if ($action === 'delete_leave' && isset($_REQUEST['id'])) {
    $stmt = $mysqli->prepare("DELETE FROM leaves WHERE id=?");
    $stmt->bind_param('i', $_REQUEST['id']); $stmt->execute();
    returnSuccess();
}

/* APPROVALS */
if ($action === 'approve_applicant') {
    $stmt = $mysqli->prepare("UPDATE applicants SET status='approved' WHERE id=?");
    $stmt->bind_param('i',$_REQUEST['id']); $stmt->execute();
    returnSuccess();
}
if ($action === 'decline_applicant') {
    $stmt = $mysqli->prepare("UPDATE applicants SET status='declined' WHERE id=?");
    $stmt->bind_param('i',$_REQUEST['id']); $stmt->execute();
    returnSuccess();
}
if ($action === 'approve_request' || $action === 'decline_request') {
    $status = ($action === 'approve_request') ? 'approved' : 'declined';
    $remark = $_POST['remark'] ?? '';
    $stmt = $mysqli->prepare("UPDATE requests SET status=?, note=? WHERE id=?");
    $stmt->bind_param('ssi', $status, $remark, $_REQUEST['id']); $stmt->execute();
    returnSuccess();
}

/* EDITS */
if ($action === 'edit_work_hours') {
    $stmt = $mysqli->prepare("UPDATE work_hours SET start_time=?, end_time=?, hours_worked=?, work_date=? WHERE id=?");
    $stmt->bind_param('ssdsi', $_POST['start_time'], $_POST['end_time'], $_POST['hours_worked'], $_POST['work_date'], $_POST['id']);
    $stmt->execute(); returnSuccess();
}
if ($action === 'edit_employee_full') {
    $stmt = $mysqli->prepare("UPDATE users SET total_salary=?, monthly_salary=?, salary_change=?, job_status=?, cm_remarks=? WHERE id=?");
    $stmt->bind_param('dddssi', $_POST['total_salary'], $_POST['monthly_salary'], $_POST['salary_change'], $_POST['job_status'], $_POST['cm_remarks'], $_POST['id']);
    $stmt->execute(); returnSuccess();
}
if ($action === 'edit_leave_full') {
    $stmt = $mysqli->prepare("UPDATE leaves SET leave_type=?, days_requested=?, reason=?, status=?, remarks=? WHERE id=?");
    $stmt->bind_param('sisssi', $_POST['leave_type'], $_POST['days_requested'], $_POST['reason'], $_POST['status'], $_POST['remarks'], $_POST['id']);
    $stmt->execute(); returnSuccess();
}
if ($action === 'edit_cm_remarks') {
    $stmt = $mysqli->prepare("UPDATE users SET cm_remarks=? WHERE id=?");
    $stmt->bind_param('si', $_POST['cm_remarks'], $_POST['employee_id']);
    $stmt->execute(); returnSuccess();
}
if ($action === 'edit_applicant_full') {
    $stmt = $mysqli->prepare("UPDATE applicants SET name=?, contact=?, applied_position=?, experience_years=?, status=? WHERE id=?");
    $stmt->bind_param('sssisi', $_POST['name'], $_POST['contact'], $_POST['applied_position'], $_POST['experience_years'], $_POST['status'], $_POST['id']);
    $stmt->execute(); returnSuccess();
}

/* ---------- View Logic ---------- */
$section = $_GET['section'] ?? '';

// --- DASHBOARD ---
if ($section === 'dashboard') {
    $new = intval($mysqli->query("SELECT COUNT(*) FROM applicants WHERE status='new'")->fetch_row()[0] ?? 0);
    $approved = intval($mysqli->query("SELECT COUNT(*) FROM applicants WHERE status='approved'")->fetch_row()[0] ?? 0);
    $declined = intval($mysqli->query("SELECT COUNT(*) FROM applicants WHERE status='declined'")->fetch_row()[0] ?? 0);
    $months = []; $counts = [];
    $res = $mysqli->query("SELECT DATE_FORMAT(created_at,'%Y-%m') as ym, COUNT(*) as cnt FROM applicants GROUP BY ym ORDER BY ym DESC LIMIT 6");
    $tmp = []; while($r = $res->fetch_assoc()) $tmp[$r['ym']] = intval($r['cnt']);
    $keys = array_reverse(array_keys($tmp)); foreach($keys as $k){ $months[] = $k; $counts[] = $tmp[$k]; }
    ?>
    <div class="page-title">Admin Dashboard</div>
    <div class="section-card chart-card" id="dashboard-content">
      <div style="display:flex;gap:20px;flex-wrap:wrap;">
        <div style="flex:1;min-width:260px">
          <canvas id="appPie" data-chart='<?= json_encode([
            "type"=>"pie", "data"=>["labels"=>["New","Approved","Declined"], "datasets"=>[["data"=>[$new,$approved,$declined],"backgroundColor"=>["#ff8c42","#06d6a0","#ef476f"]]]], "options"=>["responsive"=>true,"maintainAspectRatio"=>true]
          ]) ?>'></canvas>
        </div>
        <div style="flex:1;min-width:300px">
          <canvas id="appBar" data-chart='<?= json_encode([
            "type"=>"bar", "data"=>["labels"=>$months, "datasets"=>[["label"=>"Applicants","data"=>$counts,"backgroundColor"=>"#ff6b6b"]]], "options"=>["responsive"=>true,"maintainAspectRatio"=>true]
          ]) ?>'></canvas>
        </div>
      </div>
    </div>
    <?php exit;
}

// --- APPLICANTS ---
if ($section === 'applicants') {
    $res = $mysqli->query("SELECT * FROM applicants ORDER BY created_at DESC");
    $new = intval($mysqli->query("SELECT COUNT(*) FROM applicants WHERE status='new'")->fetch_row()[0] ?? 0);
    $approved = intval($mysqli->query("SELECT COUNT(*) FROM applicants WHERE status='approved'")->fetch_row()[0] ?? 0);
    $declined = intval($mysqli->query("SELECT COUNT(*) FROM applicants WHERE status='declined'")->fetch_row()[0] ?? 0);
    ?>
    <div class="page-title">Applicant Overview</div>
    <div class="section-card">
      <div style="margin-bottom:15px;display:flex;gap:10px;">
          <button onclick="downloadPDF('applicant-table', 'Applicants_Report')" class="action-btn" style="background:#007bff;border:none;">Click to Download PDF</button>
          <button onclick="bulkDelete('applicants')" class="action-btn" style="background:#dc3545;border:none;">Delete Selected</button>
      </div>
      <div style="display:flex;gap:18px;align-items:flex-start;">
        <div style="min-width:200px">
          <canvas id="apPie" data-chart='<?= json_encode(["type"=>"pie", "data"=>["labels"=>["New","Approved","Declined"], "datasets"=>[["data"=>[$new,$approved,$declined],"backgroundColor"=>["#ff8c42","#06d6a0","#ef476f"]]]], "options"=>["responsive"=>true]]) ?>'></canvas>
        </div>
        <div style="flex:1">
          <div class="table-frame" style="max-height:520px; overflow:auto;" id="applicant-table">
            <table>
              <thead>
                  <tr>
                      <th style="width:30px"><input type="checkbox" onclick="toggleAll(this)"></th>
                      <th>ID</th>
                      <th>Details (Editable)</th>
                      <th>Status</th>
                      <th>Action</th>
                  </tr>
              </thead>
              <tbody>
                <?php while($r = $res->fetch_assoc()): ?>
                <tr>
                  <td><input type="checkbox" class="row-select" value="<?= $r['id'] ?>"></td>
                  <td><?= esc($r['applicant_id']) ?></td>
                  <td colspan="2">
                    <form action="admin.php?action=edit_applicant_full" onsubmit="submitEditForm(event)" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
                        <input type="hidden" name="id" value="<?= intval($r['id']) ?>">
                        
                        <div style="display:flex;flex-direction:column;">
                            <label style="font-size:10px;color:#888;">Name</label>
                            <input type="text" name="name" value="<?= esc($r['name']) ?>" style="padding:4px;background:#111;border:1px solid #333;color:#fff;border-radius:4px;width:120px;">
                        </div>

                        <div style="display:flex;flex-direction:column;">
                            <label style="font-size:10px;color:#888;">Contact</label>
                            <input type="text" name="contact" value="<?= esc($r['contact']) ?>" style="padding:4px;background:#111;border:1px solid #333;color:#fff;border-radius:4px;width:100px;">
                        </div>

                        <div style="display:flex;flex-direction:column;">
                            <label style="font-size:10px;color:#888;">Position</label>
                            <input type="text" name="applied_position" value="<?= esc($r['applied_position']) ?>" style="padding:4px;background:#111;border:1px solid #333;color:#fff;border-radius:4px;width:100px;">
                        </div>

                        <div style="display:flex;flex-direction:column;">
                            <label style="font-size:10px;color:#888;">Exp (Yrs)</label>
                            <input type="number" name="experience_years" value="<?= intval($r['experience_years']) ?>" style="padding:4px;background:#111;border:1px solid #333;color:#fff;border-radius:4px;width:50px;">
                        </div>

                        <div style="display:flex;flex-direction:column;">
                            <label style="font-size:10px;color:#888;">Status</label>
                            <select name="status" style="padding:4px;background:#111;border:1px solid #333;color:#fff;border-radius:4px;">
                                <option <?= $r['status']=='new'?'selected':'' ?>>new</option>
                                <option <?= $r['status']=='approved'?'selected':'' ?>>approved</option>
                                <option <?= $r['status']=='declined'?'selected':'' ?>>declined</option>
                            </select>
                        </div>

                        <button type="submit" class="action-btn" style="padding:5px 10px;margin-top:15px">Save</button>
                    </form>
                  </td>
                  <td>
                    <button class="action-btn" onclick="deleteEntry('delete_applicant', <?= $r['id'] ?>)" style="background:#dc3545;border-color:#dc3545;">Delete</button>
                  </td>
                </tr>
                <?php endwhile; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
    <?php exit;
}

// --- LEAVES ---
if ($section === 'leaves') {
    $res = $mysqli->query("SELECT l.*, u.username FROM leaves l LEFT JOIN users u ON u.id = l.employee_id ORDER BY l.applied_at DESC");
    $pending = intval($mysqli->query("SELECT COUNT(*) FROM leaves WHERE status='pending'")->fetch_row()[0] ?? 0);
    $approved = intval($mysqli->query("SELECT COUNT(*) FROM leaves WHERE status='approved'")->fetch_row()[0] ?? 0);
    $declined = intval($mysqli->query("SELECT COUNT(*) FROM leaves WHERE status='declined'")->fetch_row()[0] ?? 0);
    $resm = $mysqli->query("SELECT DATE_FORMAT(applied_at,'%Y-%m') as ym, COUNT(*) as cnt FROM leaves GROUP BY ym ORDER BY ym DESC LIMIT 6");
    $tmp = []; while($r2 = $resm->fetch_assoc()) $tmp[$r2['ym']] = intval($r2['cnt']);
    $months = array_reverse(array_keys($tmp)); $counts = array_reverse(array_values($tmp));
    ?>
    <div class="page-title">Leave Applications</div>
    <div class="section-card">
      <div style="margin-bottom:15px;display:flex;gap:10px;">
          <button onclick="downloadPDF('leaves-content', 'Leaves_Report')" class="action-btn" style="background:#007bff;border:none;">Click to Download PDF</button>
          <button onclick="bulkDelete('leaves')" class="action-btn" style="background:#dc3545;border:none;">Delete Selected</button>
      </div>
      <div id="leaves-content">
        <div style="display:flex;gap:18px;flex-wrap:wrap;margin-bottom:20px;">
          <div style="min-width:240px;max-width:300px">
            <canvas id="leavePie" data-chart='<?= json_encode(["type"=>"pie", "data"=>["labels"=>["Pending","Approved","Declined"], "datasets"=>[["data"=>[$pending,$approved,$declined],"backgroundColor"=>["#ffd60a","#06d6a0","#ef476f"]]]], "options"=>["responsive"=>true]]) ?>'></canvas>
          </div>
          <div style="flex:1;min-width:320px;max-width:500px">
            <canvas id="leaveBar" data-chart='<?= json_encode(["type"=>"bar", "data"=>["labels"=>$months, "datasets"=>[["label"=>"Leaves","data"=>$counts,"backgroundColor"=>"#4ecdc4"]]], "options"=>["responsive"=>true]]) ?>'></canvas>
          </div>
        </div>
        <div class="table-frame">
          <table>
            <thead>
                <tr>
                    <th style="width:30px"><input type="checkbox" onclick="toggleAll(this)"></th>
                    <th>ID/Emp</th>
                    <th>Details (Editable)</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
              <?php while($r = $res->fetch_assoc()): ?>
              <tr>
                <td><input type="checkbox" class="row-select" value="<?= $r['id'] ?>"></td>
                <td style="vertical-align:top;width:150px;">
                    <strong>ID:</strong> <?= intval($r['id']) ?><br>
                    <strong>User:</strong> <?= esc($r['username'] ?: 'Emp-'.$r['employee_id']) ?><br>
                    <small><?= substr($r['applied_at'],0,10) ?></small>
                </td>
                <td style="vertical-align:top;">
                   <form action="admin.php?action=edit_leave_full" onsubmit="submitEditForm(event)" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
                      <input type="hidden" name="id" value="<?= intval($r['id']) ?>">
                      <div style="display:flex;flex-direction:column;">
                        <label style="font-size:10px;color:#888;">Type</label>
                        <select name="leave_type" style="padding:4px;background:#111;border:1px solid #333;color:#fff;border-radius:4px;">
                            <?php foreach(['Annual','Sick','Casual','Maternity','Other'] as $opt): ?>
                                <option <?= ($r['leave_type']==$opt)?'selected':'' ?>><?= $opt ?></option>
                            <?php endforeach; ?>
                        </select>
                      </div>
                      <div style="display:flex;flex-direction:column;">
                        <label style="font-size:10px;color:#888;">Days</label>
                        <input type="number" name="days_requested" value="<?= intval($r['days_requested']) ?>" style="width:50px;padding:4px;background:#111;border:1px solid #333;color:#fff;border-radius:4px;">
                      </div>
                      <div style="display:flex;flex-direction:column;flex:1;">
                        <label style="font-size:10px;color:#888;">Reason</label>
                        <input type="text" name="reason" value="<?= esc($r['reason']) ?>" style="width:100%;padding:4px;background:#111;border:1px solid #333;color:#fff;border-radius:4px;">
                      </div>
                      <div style="display:flex;flex-direction:column;">
                        <label style="font-size:10px;color:#888;">Status</label>
                         <select name="status" style="padding:4px;background:#111;border:1px solid #333;color:#fff;border-radius:4px;">
                            <?php foreach(['pending','approved','declined'] as $s): ?>
                                <option <?= ($r['status']==$s)?'selected':'' ?>><?= $s ?></option>
                            <?php endforeach; ?>
                        </select>
                      </div>
                      <div style="display:flex;flex-direction:column;flex:1;">
                         <label style="font-size:10px;color:#888;">Remarks</label>
                         <input type="text" name="remarks" value="<?= esc($r['remarks']) ?>" placeholder="Admin remarks" style="width:100%;padding:4px;background:#111;border:1px solid #333;color:#fff;border-radius:4px;">
                      </div>
                      <button type="submit" class="action-btn" style="padding:5px 10px;margin-top:15px">Save</button>
                   </form>
                </td>
                <td style="vertical-align:middle;">
                   <button class="action-btn" onclick="deleteEntry('delete_leave', <?= $r['id'] ?>)" style="background:#dc3545;padding:5px 10px;">Delete</button>
                </td>
              </tr>
              <?php endwhile; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <?php exit;
}

// --- REQUESTS ---
if ($section === 'requests') {
    $res = $mysqli->query("SELECT r.*, u.username as from_user FROM requests r LEFT JOIN users u ON u.id=r.from_user_id ORDER BY r.created_at DESC");
    ?>
    <div class="page-title">Request Overview</div>
    <div class="section-card">
      <div style="margin-bottom:15px;display:flex;gap:10px;">
          <button onclick="downloadPDF('req-table', 'Requests_Report')" class="action-btn" style="background:#007bff;border:none;">Click to Download PDF</button>
          <button onclick="bulkDelete('requests')" class="action-btn" style="background:#dc3545;border:none;">Delete Selected</button>
      </div>
      <div class="table-frame" id="req-table">
        <table>
          <thead>
              <tr>
                  <th style="width:30px"><input type="checkbox" onclick="toggleAll(this)"></th>
                  <th>ID</th>
                  <th>From</th>
                  <th>To</th>
                  <th>Subject</th>
                  <th>Details</th>
                  <th>Status</th>
                  <th>Remark/Action</th>
              </tr>
          </thead>
          <tbody>
            <?php while($r = $res->fetch_assoc()): ?>
            <tr>
              <td><input type="checkbox" class="row-select" value="<?= $r['id'] ?>"></td>
              <td><?= intval($r['id']) ?></td>
              <td><?= esc($r['from_user'] ?: 'User-'.$r['from_user_id']) ?></td>
              <td><?= esc($r['to_role']) ?></td>
              <td><?= esc($r['subject']) ?></td>
              <td><?= esc($r['details']) ?></td>
              <td><?= esc($r['status']) ?></td>
              <td>
                <div style="display:flex;gap:4px;align-items:center;">
                <?php if ($r['status'] === 'pending'): ?>
                  <form action="admin.php?action=approve_request" onsubmit="submitEditForm(event)" style="display:inline-block;margin:0">
                    <input type="hidden" name="id" value="<?= intval($r['id']) ?>">
                    <input name="remark" placeholder="Remark" value="<?= esc($r['note'] ?? '') ?>" style="padding:6px;border-radius:6px;background:#0b0b0b;border:1px solid #222;color:#fff;width:80px">
                    <button class="action-btn" type="submit">Approve</button>
                  </form>
                  <form action="admin.php?action=decline_request" onsubmit="submitEditForm(event)" style="display:inline-block;margin:0">
                    <input type="hidden" name="id" value="<?= intval($r['id']) ?>">
                    <input name="remark" placeholder="Remark" value="<?= esc($r['note'] ?? '') ?>" style="padding:6px;border-radius:6px;background:#0b0b0b;border:1px solid #222;color:#fff;width:80px">
                    <button class="action-btn" type="submit">Decline</button>
                  </form>
                <?php else: ?>
                  <span style="color:#999;font-size:13px;margin-right:10px;">Processed</span>
                <?php endif; ?>
                 <button class="action-btn" onclick="deleteEntry('delete_request', <?= $r['id'] ?>)" style="background:#dc3545;">Delete</button>
                </div>
              </td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php exit;
}

// --- WORK HOURS ---
if ($section === 'work_hours') {
  $res = $mysqli->query("SELECT w.*, u.username FROM work_hours w LEFT JOIN users u ON u.id = w.employee_id ORDER BY w.work_date DESC, w.employee_id");
    ?>
    <div class="page-title">Work Hours (All Employees)</div>
    <div class="section-card">
      <div style="margin-bottom:15px;display:flex;gap:10px;">
          <button onclick="downloadPDF('work-table', 'WorkHours_Report')" class="action-btn" style="background:#007bff;border:none;">Click to Download PDF</button>
          <button onclick="bulkDelete('work_hours')" class="action-btn" style="background:#dc3545;border:none;">Delete Selected</button>
      </div>
      <div class="table-frame" id="work-table">
        <table>
          <thead>
              <tr>
                  <th style="width:30px"><input type="checkbox" onclick="toggleAll(this)"></th>
                  <th>Emp ID</th>
                  <th>Date</th>
                  <th>Start Time</th>
                  <th>End Time</th>
                  <th>Hours</th>
                  <th>Edit</th>
              </tr>
          </thead>
          <tbody>
            <?php while($r = $res->fetch_assoc()): ?>
            <tr>
              <td><input type="checkbox" class="row-select" value="<?= $r['id'] ?>"></td>
              <td><?= intval($r['employee_id']) ?></td>
              <td><?= esc($r['work_date'] ?? '-') ?></td>
              <td><?= esc($r['start_time'] ?? '-') ?></td>
              <td><?= esc($r['end_time'] ?? '-') ?></td>
              <td><?= number_format(floatval($r['hours_worked'] ?? 0), 2) ?> h</td>
              <td>
                <div style="display:flex;gap:4px;align-items:center">
                    <form action="admin.php?action=edit_work_hours" onsubmit="submitEditForm(event)" style="display:inline;margin:0">
                      <input type="hidden" name="id" value="<?= intval($r['id']) ?>">
                      <input type="hidden" name="work_date" value="<?= esc($r['work_date']) ?>">
                      <input name="start_time" placeholder="Start" value="<?= esc($r['start_time'] ?? '') ?>" style="padding:4px;border-radius:4px;background:#0b0b0b;border:1px solid #222;color:#fff;width:60px;font-size:12px">
                      <input name="end_time" placeholder="End" value="<?= esc($r['end_time'] ?? '') ?>" style="padding:4px;border-radius:4px;background:#0b0b0b;border:1px solid #222;color:#fff;width:60px;font-size:12px">
                      <input name="hours_worked" type="number" placeholder="Hrs" value="<?= number_format(floatval($r['hours_worked'] ?? 0), 2) ?>" step="0.25" style="padding:4px;border-radius:4px;background:#0b0b0b;border:1px solid #222;color:#fff;width:50px;font-size:12px">
                      <button class="action-btn" type="submit" style="padding:4px 8px;font-size:12px">Save</button>
                    </form>
                    <button class="action-btn" onclick="deleteEntry('delete_work_hours', <?= $r['id'] ?>)" style="background:#dc3545;padding:4px 8px;font-size:12px">Del</button>
                </div>
              </td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php exit;
}

// --- EMPLOYEE OVERVIEW ---
if ($section === 'employee_overview') {
      $res = $mysqli->query("SELECT u.id,u.total_salary,u.monthly_salary,u.salary_change,u.job_status,u.cm_remarks FROM users u WHERE u.id BETWEEN 1 AND 5 ORDER BY u.id");
      ?>
      <div class="page-title">Employee Overview</div>
      <div class="section-card">
        <div style="margin-bottom:15px;display:flex;gap:10px;">
            <button onclick="downloadPDF('emp-table', 'Employee_Overview')" class="action-btn" style="background:#007bff;border:none;">Click to Download PDF</button>
            <button onclick="bulkDelete('users')" class="action-btn" style="background:#dc3545;border:none;">Delete Selected</button>
        </div>
        <div class="table-frame" id="emp-table">
          <table>
            <thead>
                <tr>
                    <th style="width:30px"><input type="checkbox" onclick="toggleAll(this)"></th>
                    <th>ID</th>
                    <th>Financials & Status (Editable)</th>
                    <th>CM Remarks</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
              <?php while($r = $res->fetch_assoc()): ?>
              <tr>
                <td><input type="checkbox" class="row-select" value="<?= $r['id'] ?>"></td>
                <td style="vertical-align:top;padding-top:15px;"><?= intval($r['id']) ?></td>
                <td>
                   <form action="admin.php?action=edit_employee_full" onsubmit="submitEditForm(event)" id="form-<?= $r['id'] ?>">
                     <input type="hidden" name="id" value="<?= intval($r['id']) ?>">
                     <div style="margin-bottom:5px;">
                        <span style="display:inline-block;width:90px;font-size:12px;color:#888;">Total:</span>
                        <input type="number" name="total_salary" value="<?= floatval($r['total_salary']) ?>" style="width:100px;background:#111;border:1px solid #333;color:#fff;padding:2px;">
                     </div>
                     <div style="margin-bottom:5px;">
                        <span style="display:inline-block;width:90px;font-size:12px;color:#888;">Monthly:</span>
                        <input type="number" name="monthly_salary" value="<?= floatval($r['monthly_salary']) ?>" style="width:100px;background:#111;border:1px solid #333;color:#fff;padding:2px;">
                     </div>
                     <div style="margin-bottom:5px;">
                        <span style="display:inline-block;width:90px;font-size:12px;color:#888;">Yearly (Calc):</span>
                        <span style="color:#aaa;"><?= number_format(floatval($r['monthly_salary'] * 12),0) ?></span>
                     </div>
                     <div style="margin-bottom:5px;">
                        <span style="display:inline-block;width:90px;font-size:12px;color:#888;">Change:</span>
                        <input type="number" step="0.01" name="salary_change" value="<?= floatval($r['salary_change']) ?>" style="width:100px;background:#111;border:1px solid #333;color:#fff;padding:2px;">
                     </div>
                     <div>
                        <span style="display:inline-block;width:90px;font-size:12px;color:#888;">Status:</span>
                        <select name="job_status" style="width:106px;background:#111;border:1px solid #333;color:#fff;padding:2px;">
                           <option <?= $r['job_status']=='active'?'selected':'' ?>>active</option>
                           <option <?= $r['job_status']=='inactive'?'selected':'' ?>>inactive</option>
                           <option <?= $r['job_status']=='probation'?'selected':'' ?>>probation</option>
                        </select>
                     </div>
                </td>
                <td style="vertical-align:top;">
                   <textarea name="cm_remarks" style="width:100%;height:80px;background:#111;border:1px solid #333;color:#fff;padding:4px;resize:vertical;"><?= esc($r['cm_remarks']) ?></textarea>
                </td>
                <td style="vertical-align:middle;text-align:center;">
                   <button type="submit" class="action-btn" style="margin-bottom:4px;width:100%">Save</button>
                   </form>
                   <button class="action-btn" onclick="deleteEntry('delete_user', <?= $r['id'] ?>)" style="background:#dc3545;width:100%;">Delete</button>
                </td>
              </tr>
              <?php endwhile; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php exit;
}

// --- CM REMARKS ---
if ($section === 'cm_remarks') {
      $res = $mysqli->query("SELECT u.id, u.username, u.job_status, u.salary_change, u.cm_remarks FROM users u WHERE u.id BETWEEN 1 AND 5 ORDER BY u.username");
      ?>
      <div class="page-title">Remarks from Company Manager</div>
      <div class="section-card">
        <div style="margin-bottom:15px;display:flex;gap:10px;">
            <button onclick="downloadPDF('cm-table', 'Manager_Remarks')" class="action-btn" style="background:#007bff;border:none;">Click to Download PDF</button>
            <button onclick="bulkDelete('users')" class="action-btn" style="background:#dc3545;border:none;">Delete Selected</button>
        </div>
        <div class="table-frame" id="cm-table">
          <table>
            <thead>
                <tr>
                    <th style="width:30px"><input type="checkbox" onclick="toggleAll(this)"></th>
                    <th>ID</th>
                    <th>Username</th>
                    <th>Status</th>
                    <th>Change</th>
                    <th>CM Remarks</th>
                    <th>Edit</th>
                </tr>
            </thead>
            <tbody>
              <?php while($r = $res->fetch_assoc()): ?>
              <tr>
                <td><input type="checkbox" class="row-select" value="<?= $r['id'] ?>"></td>
                <td><?= intval($r['id']) ?></td>
                <td><?= esc($r['username']) ?></td>
                <td><?= esc($r['job_status'] ?? 'active') ?></td>
                <td><?= number_format(floatval($r['salary_change'] ?? 0),2) ?></td>
                <td><?= nl2br(esc($r['cm_remarks'] ?? '-')) ?></td>
                <td>
                  <form action="admin.php?action=edit_cm_remarks" onsubmit="submitEditForm(event)" style="display:inline;margin:0">
                    <input type="hidden" name="employee_id" value="<?= intval($r['id']) ?>">
                    <textarea name="cm_remarks" placeholder="Remarks" style="padding:4px;border-radius:4px;background:#0b0b0b;border:1px solid #222;color:#fff;width:180px;height:40px;font-size:11px;resize:none"><?= esc($r['cm_remarks'] ?? '') ?></textarea>
                    <button class="action-btn" type="submit" style="padding:4px 10px;font-size:12px;margin-bottom:4px">Save</button>
                  </form>
                  <br>
                  <button class="action-btn" onclick="deleteEntry('delete_user', <?= $r['id'] ?>)" style="background:#dc3545;padding:4px 10px;font-size:12px;margin-top:4px;">Del</button>
                </td>
              </tr>
              <?php endwhile; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php exit;
}

echo '<div class="empty-note">Select a menu item from left.</div>';
exit;
?>