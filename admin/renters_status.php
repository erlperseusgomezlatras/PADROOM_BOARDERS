<?php
// admin/renters_status.php
require_once __DIR__ . '/../inclusions/require_login.php';
require_once __DIR__ . '/../inclusions/connection.php';

$BASE = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$msg = "";
$err = "";

/* ---------------- Helpers ---------------- */
function has_column(mysqli $conn, $table, $col){
  $sql = "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("ss", $table, $col);
  $stmt->execute(); $stmt->bind_result($n); $stmt->fetch(); $stmt->close();
  return (int)$n > 0;
}

/* 30-day proration (months + extra days) */
function compute_due_date(string $start_date, float $monthly_rate, float $total_paid): string {
  if ($monthly_rate <= 0) return $start_date;
  $months    = (int) floor($total_paid / $monthly_rate);
  $remainder = $total_paid - ($months * $monthly_rate);
  $daily     = $monthly_rate / 30.0;
  $extra     = (int) floor($remainder / $daily);

  $dt = new DateTime($start_date);
  if ($months > 0) $dt->modify("+{$months} month");
  if ($extra  > 0) $dt->modify("+{$extra} day");
  return $dt->format('Y-m-d');
}

/* ---------------- Actions ---------------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='add_payment') {
  $renter_id = (int)($_POST['renter_id'] ?? 0);
  $amount    = (float)($_POST['pay_amount'] ?? 0);

  if ($renter_id<=0 || $amount<=0){ $err="Invalid renter / amount."; }
  else {
    $stmt = $conn->prepare("SELECT start_date, due_date, monthly_rate, total_paid, room_id, status FROM renters WHERE id=?");
    $stmt->bind_param("i", $renter_id);
    $stmt->execute();
    $stmt->bind_result($start_date, $due, $rate, $total, $room_id, $rstatus);
    $ok = $stmt->fetch(); $stmt->close();

    if (!$ok)                $err="Renter not found.";
    elseif ($rstatus!=='active') $err="Cannot add payment to non-active renter.";
    elseif ($rate<=0)        $err="Invalid monthly rate.";
    else {
      $new_total   = (float)$total + $amount;
      $new_due     = compute_due_date($start_date, (float)$rate, $new_total);
      $months_paid = (int) floor($amount / (float)$rate);

      try{
        $conn->begin_transaction();

        $stmt = $conn->prepare("UPDATE renters SET due_date=?, total_paid=? WHERE id=?");
        $stmt->bind_param("sdi", $new_due, $new_total, $renter_id);
        if (!$stmt->execute()) throw new Exception($stmt->error);
        $stmt->close();

        $stmt = $conn->prepare("INSERT INTO payments (renter_id, room_id, amount, months_paid) VALUES (?,?,?,?)");
        $stmt->bind_param("iidi", $renter_id, $room_id, $amount, $months_paid);
        if (!$stmt->execute()) throw new Exception($stmt->error);
        $stmt->close();

        $conn->commit();
        $msg = "Payment recorded. New due date: <strong>$new_due</strong>.";
      }catch(Exception $e){
        $conn->rollback();
        $err = "Payment failed → ".$e->getMessage();
      }
    }
  }
}

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='complete') {
  $renter_id = (int)($_POST['renter_id'] ?? 0);
  if ($renter_id<=0){ $err="Invalid renter."; }
  else {
    $stmt = $conn->prepare("SELECT room_id, status FROM renters WHERE id=?");
    $stmt->bind_param("i", $renter_id);
    $stmt->execute(); $stmt->bind_result($room_id, $rstatus);
    $ok = $stmt->fetch(); $stmt->close();

    if (!$ok) { $err="Renter not found."; }
    else {
      try{
        $conn->begin_transaction();

        $stmt = $conn->prepare("UPDATE renters SET status='completed' WHERE id=?");
        $stmt->bind_param("i", $renter_id);
        if (!$stmt->execute()) throw new Exception($stmt->error);
        $stmt->close();

        if ($rstatus==='active') {
          $stmt = $conn->prepare("UPDATE rooms SET status='vacant' WHERE id=?");
          $stmt->bind_param("i", $room_id);
          if (!$stmt->execute()) throw new Exception($stmt->error);
          $stmt->close();
        }

        $conn->commit();
        $msg = "Renter ended and room marked as vacant.";
      }catch(Exception $e){
        $conn->rollback();
        $err = "End failed → ".$e->getMessage();
      }
    }
  }
}

/* ---------------- Filters & Search ---------------- */
/* Dropdown buckets: all | soon (<7d) | overdue */
$bucket = $_GET['bucket'] ?? 'all';
$search = trim($_GET['q'] ?? '');
$hasHouseArchive = has_column($conn,'houses','is_archived');

$where=[]; $params=[]; $types="";
if     ($bucket==='soon')    $where[]="ren.status='active' AND DATEDIFF(ren.due_date, CURDATE()) BETWEEN 0 AND 7";
elseif ($bucket==='overdue') $where[]="ren.status='active' AND ren.due_date < CURDATE()";
else                         $where[]="(ren.status IN ('active','completed'))";

if ($hasHouseArchive) $where[]="h.is_archived=0";

if ($search!==''){
  $where[]="(CONCAT(t.last_name, ', ', t.first_name) LIKE ? OR r.room_label LIKE ? OR h.name LIKE ?)";
  $s="%$search%"; $params[]=$s; $params[]=$s; $params[]=$s; $types.="sss";
}
$wsql = $where ? ("WHERE ".implode(" AND ", $where)) : "";

/* ---------------- Query ---------------- */
$sql = "
  SELECT ren.id AS renter_id, ren.tenant_id, ren.room_id, ren.start_date, ren.due_date, ren.monthly_rate,
         ren.total_paid, ren.status AS renter_status,
         t.first_name, t.middle_name, t.last_name,
         r.room_label, r.capacity, r.notes,
         f.floor_label, h.name AS house_name, h.address
  FROM renters ren
  JOIN tenants t ON t.id=ren.tenant_id
  JOIN rooms r   ON r.id=ren.room_id
  JOIN floors f  ON f.id=r.floor_id
  JOIN houses h  ON h.id=f.house_id
  $wsql
  ORDER BY
    (ren.status='active' AND ren.due_date < CURDATE()) DESC,
    (ren.status='active' AND DATEDIFF(ren.due_date, CURDATE()) BETWEEN 0 AND 7) DESC,
    ren.due_date ASC, ren.id DESC
";
$stmt = $conn->prepare($sql);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
$rows = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

function full_name($r){
  return $r['last_name'].', '.$r['first_name'].(
    empty($r['middle_name'])?'':' '.strtoupper(substr($r['middle_name'],0,1)).'.'
  );
}
function paid_breakdown(float $total_paid, float $rate): array {
  if ($rate<=0) return [0,0];
  $months  = (int) floor($total_paid / $rate);
  $rem     = $total_paid - ($months * $rate);
  $days    = (int) floor($rem / ($rate/30.0));
  return [$months, $days];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Renter Status - Padroom</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="<?= $BASE ?>/../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="<?= $BASE ?>/../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="<?= $BASE ?>/../assets/css/style.css" rel="stylesheet">
  <style>
    :root{ --pad-primary:#361E5C; --pad-accent:#6141A6; --pad-text:#fff; }
    .main{ margin-top:60px; padding:24px 16px 28px 16px; transition:all .3s; }
    @media (min-width: 992px){ .main{ margin-left:250px; } }

    /* Center everything in a tidy column */
    .page-wrap{ max-width: 1080px; margin: 0 auto; }

    /* Toolbar spacing */
    .toolbar{ margin: 0 auto 20px auto; }
    .toolbar .card-body{ padding: 14px 16px; }

    /* Cards: consistent inner padding + breathing room */
    .results-stack{ display:flex; flex-direction:column; gap:18px; margin-top:14px; }
    .card-renter{
      border:1px solid rgba(0,0,0,.06); border-radius:14px;
      box-shadow:0 6px 18px rgba(0,0,0,.06);
      transition: box-shadow .25s ease, transform .25s ease;
      background:#fff;
    }
    .card-renter:hover{ box-shadow:0 10px 26px rgba(0,0,0,.1); transform:translateY(-1px); }
    .card-renter .card-body{ padding: 16px 18px; } /* <— normalized padding */

    /* Compact, consistent buttons */
    .gradient-btn{
      background: linear-gradient(90deg,var(--pad-primary),var(--pad-accent));
      color:#fff; border:none; border-radius:8px;
      font-size:.85rem; padding:.38rem .8rem; transition:all .18s ease;
    }
    .gradient-btn:hover{ opacity:.92; transform: translateY(-1px); }
    .btn-soft{
      background:#f4f1fb; border:1px solid rgba(97,65,166,.25); color:#432d7a;
      border-radius:8px; font-size:.85rem; padding:.38rem .8rem; transition:all .18s ease;
    }
    .btn-soft:hover{ background:#ece6fa; transform: translateY(-1px); }

    .chip{ background:#fff; border:1px solid rgba(0,0,0,.08); padding:.22rem .55rem; border-radius:999px; font-size:.82rem; }
    .countdown{ font-variant-numeric: tabular-nums; font-weight:600; }
    .overdue{ color:#dc3545; }
    .soon{ color:#c17a00; }

    .form-control:focus, .form-select:focus{
      border-color:#6141A6; box-shadow:0 0 0 .2rem rgba(97,65,166,.15);
    }
  </style>
</head>
<body class="d-flex flex-column min-vh-100">
  <?php include __DIR__ . '/../admin/topbar.php'; ?>
  <?php include __DIR__ . '/../admin/sidebar.php'; ?>

  <main id="main" class="main flex-grow-1">
    <div class="page-wrap">
      <div class="pagetitle text-center mb-3">
        <h1 class="h4 mb-1">Renter Status</h1>
        <nav>
          <ol class="breadcrumb justify-content-center">
            <li class="breadcrumb-item"><a href="<?= $BASE ?>/dashboard.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Status</li>
          </ol>
        </nav>
      </div>

      <?php if ($msg): ?><div class="alert alert-success text-center"><?= $msg ?></div><?php endif; ?>
      <?php if ($err): ?><div class="alert alert-danger text-center"><?= $err ?></div><?php endif; ?>

      <!-- Centered Toolbar -->
      <div class="card toolbar" style="max-width: 980px;">
        <div class="card-body">
          <form class="row g-2 justify-content-center align-items-center" method="get">
            <div class="col-12 col-md-auto">
              <select name="bucket" class="form-select">
                <?php
                  $opts = ['all'=>'All renters','soon'=>'Due Soon (≤ 7 days)','overdue'=>'Overdue'];
                  foreach($opts as $k=>$label){
                    $sel = $bucket===$k ? 'selected' : '';
                    echo "<option value=\"$k\" $sel>$label</option>";
                  }
                ?>
              </select>
            </div>
            <div class="col-12 col-md">
              <input type="text" name="q" class="form-control" placeholder="Search tenant / house / room" value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-12 col-md-auto">
              <button class="gradient-btn"><i class="bi bi-search me-1"></i>Search</button>
            </div>
          </form>
        </div>
      </div>

      <!-- Results -->
      <?php if (empty($rows)): ?>
        <div class="alert alert-light border text-center mt-3">No renters found for this filter.</div>
      <?php else: ?>
        <div class="results-stack">
          <?php foreach ($rows as $r): ?>
            <?php
              $name   = full_name($r);
              $due    = $r['due_date'];
              $rate   = (float)$r['monthly_rate'];
              $paid   = (float)$r['total_paid'];
              [$mPaid,$dPaid] = paid_breakdown($paid, max(0.01,$rate));
              $days  = (int)((strtotime($due) - strtotime(date('Y-m-d'))) / 86400);
              $bucketClass = ($r['renter_status']==='completed') ? '' :
                             ($days < 0 ? 'overdue' : ($days <= 7 ? 'soon' : ''));
            ?>
            <div class="card card-renter">
              <div class="card-body d-flex justify-content-between align-items-start flex-wrap gap-2">
                <!-- Left -->
                <div>
                  <div class="fw-semibold mb-1"><i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($name) ?></div>
                  <div class="mb-1">
                    <span class="chip"><i class="bi bi-house me-1"></i><?= htmlspecialchars($r['house_name']) ?></span>
                    <span class="chip"><i class="bi bi-building-up me-1"></i><?= htmlspecialchars($r['floor_label']) ?></span>
                    <span class="chip"><i class="bi bi-door-closed me-1"></i><?= htmlspecialchars($r['room_label']) ?></span>
                  </div>
                  <?php if (!empty($r['address'])): ?>
                    <div class="small text-muted"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($r['address']) ?></div>
                  <?php endif; ?>
                  <?php if (!is_null($r['capacity']) || !empty($r['notes'])): ?>
                    <div class="small mt-1">
                      <?php if (!is_null($r['capacity'])): ?><span class="chip me-1"><?= (int)$r['capacity'] ?> pax</span><?php endif; ?>
                      <?php if (!empty($r['notes'])): ?><span class="chip"><?= htmlspecialchars($r['notes']) ?></span><?php endif; ?>
                    </div>
                  <?php endif; ?>
                </div>

                <!-- Right -->
                <div class="text-end" style="min-width:280px;">
                  <div class="mb-2">
                    <span class="chip"><i class="bi bi-cash-coin me-1"></i>₱<?= number_format($rate,2) ?>/mo</span>
                    <span class="chip"><i class="bi bi-wallet2 me-1"></i>₱<?= number_format($paid,2) ?> (<?= $mPaid ?> mo <?= $dPaid ?> d)</span>
                  </div>
                  <div class="mb-2">
                    <span class="chip">
                      <i class="bi bi-hourglass-split me-1"></i>
                      <span class="countdown <?= $bucketClass ?>" data-duedate="<?= htmlspecialchars($due) ?>"></span>
                    </span>
                    <span class="chip ms-1"><i class="bi bi-calendar2-event me-1"></i>Start: <?= htmlspecialchars($r['start_date']) ?></span>
                    <span class="chip ms-1"><i class="bi bi-calendar2-check me-1"></i>Due: <?= htmlspecialchars($due) ?></span>
                  </div>

                  <?php if ($r['renter_status']==='active'): ?>
                    <div class="d-flex justify-content-end align-items-center gap-2">
                      <form method="post" class="d-flex align-items-center gap-2" onsubmit="return validatePayment(this)">
                        <input type="hidden" name="action" value="add_payment">
                        <input type="hidden" name="renter_id" value="<?= (int)$r['renter_id'] ?>">
                        <input type="number" name="pay_amount" step="0.01" min="0" class="form-control form-control-sm" style="max-width:150px" placeholder="Amount">
                        <button class="gradient-btn btn-sm"><i class="bi bi-plus-circle me-1"></i>Add</button>
                      </form>
                      <button type="button" class="btn-soft btn-sm" data-renter="<?= (int)$r['renter_id'] ?>" onclick="confirmEnd(this)">
                        <i class="bi bi-box-arrow-right me-1"></i>End
                      </button>
                    </div>
                  <?php else: ?>
                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle mt-2">completed</span>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </main>

  <!-- End Confirmation Modal -->
  <div class="modal fade" id="endModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
      <div class="modal-content rounded-3">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2 text-danger"></i>End Renter</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">End this renter and mark the room as vacant?</div>
        <div class="modal-footer d-flex justify-content-between">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <form method="post" id="endForm" class="m-0">
            <input type="hidden" name="action" value="complete">
            <input type="hidden" name="renter_id" id="endRenterId">
            <button class="gradient-btn">Yes, End</button>
          </form>
        </div>
      </div>
    </div>
  </div>

  <?php include __DIR__ . '/../admin/footer.php'; ?>
  <script src="<?= $BASE ?>/../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script>
    // Countdown (to end of due day 23:59:59)
    function updateCountdown(el){
      const dueStr = el.getAttribute('data-duedate');
      if (!dueStr) return;
      const end = new Date(dueStr + 'T23:59:59');
      const now = new Date();
      let diff = Math.floor((end - now)/1000);
      if (isNaN(diff)) return;

      if (diff < 0) {
        el.textContent = 'Overdue';
        el.classList.add('overdue');
        return;
      }
      const days = Math.floor(diff/86400); diff%=86400;
      const hrs  = Math.floor(diff/3600);  diff%=3600;
      const mins = Math.floor(diff/60);
      const secs = diff%60;
      el.textContent = `${days}d ${String(hrs).padStart(2,'0')}:${String(mins).padStart(2,'0')}:${String(secs).padStart(2,'0')}`;
    }
    function tick(){ document.querySelectorAll('.countdown').forEach(updateCountdown); }
    tick(); setInterval(tick, 1000);

    function validatePayment(form){
      const amt = parseFloat(form.querySelector('[name="pay_amount"]').value || '0');
      if (!amt || amt <= 0){
        const fld = form.querySelector('[name="pay_amount"]');
        fld.focus(); fld.classList.add('is-invalid');
        setTimeout(()=> fld.classList.remove('is-invalid'), 1500);
        return false;
      }
      return true;
    }

    const endModal = new bootstrap.Modal(document.getElementById('endModal'));
    function confirmEnd(btn){
      document.getElementById('endRenterId').value = btn.getAttribute('data-renter');
      endModal.show();
    }
  </script>
</body>
</html>
