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

/** 30-day proration due-date calculator */
function compute_due_date(string $start_date, float $monthly_rate, float $total_paid): string {
  if ($monthly_rate <= 0) return $start_date;
  $months    = (int) floor($total_paid / $monthly_rate);
  $remainder = $total_paid - ($months * $monthly_rate);
  $daily     = $monthly_rate / 30.0;
  $extra     = (int) floor($remainder / $daily);

  $dt = new DateTime($start_date);
  if ($months > 0)   $dt->modify("+{$months} month");
  if ($extra  > 0)   $dt->modify("+{$extra} day");
  return $dt->format('Y-m-d');
}

/* ---------------- Actions ---------------- */
/** Add payment (prorated by 30-day month) */
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

    if (!$ok) { $err="Renter not found."; }
    elseif ($rstatus!=='active') { $err="Cannot add payment to non-active renter."; }
    elseif ($rate<=0) { $err="Invalid monthly rate."; }
    else {
      $new_total = (float)$total + $amount;
      $new_due   = compute_due_date($start_date, (float)$rate, $new_total);

      // store months paid for reference (can be 0 if less than one month)
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

/** Complete / checkout renter (vacates room) */
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

        // Only vacate if they were the active occupant
        if ($rstatus==='active') {
          $stmt = $conn->prepare("UPDATE rooms SET status='vacant' WHERE id=?");
          $stmt->bind_param("i", $room_id);
          if (!$stmt->execute()) throw new Exception($stmt->error);
          $stmt->close();
        }

        $conn->commit();
        $msg = "Renter marked completed and room vacated.";
      }catch(Exception $e){
        $conn->rollback();
        $err = "Complete failed → ".$e->getMessage();
      }
    }
  }
}

/* ---------------- Filters & Search ---------------- */
$filter = $_GET['filter'] ?? 'active'; // all | active | soon | overdue | completed
$search = trim($_GET['q'] ?? '');

$hasHouseArchive = has_column($conn,'houses','is_archived');

$where   = [];
$params  = [];
$types   = "";

if     ($filter === 'active')    $where[] = "ren.status='active' AND DATEDIFF(ren.due_date, CURDATE()) > 7";
elseif ($filter === 'soon')      $where[] = "ren.status='active' AND DATEDIFF(ren.due_date, CURDATE()) BETWEEN 0 AND 7";
elseif ($filter === 'overdue')   $where[] = "ren.status='active' AND ren.due_date < CURDATE()";
elseif ($filter === 'completed') $where[] = "ren.status='completed'";
else                             $where[] = "(ren.status IN ('active','completed'))";

if ($hasHouseArchive) $where[] = "h.is_archived = 0";

if ($search !== '') {
  $where[] = "(CONCAT(t.last_name, ', ', t.first_name) LIKE ? OR r.room_label LIKE ? OR h.name LIKE ?)";
  $s = "%$search%";
  $params[] = $s; $params[] = $s; $params[] = $s;
  $types   .= "sss";
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
$res   = $stmt->get_result();
$rows  = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

function full_name($r){
  return $r['last_name'].', '.$r['first_name'].(
    empty($r['middle_name'])?'':' '.strtoupper(substr($r['middle_name'],0,1)).'.'
  );
}

/** breakdown helper (months + extra days) */
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
    :root{
      --pad-primary:#361E5C; --pad-accent:#6141A6; --pad-deep:#2A184B;
      --pad-text:#FFFFFF;
    }
    .main{ margin-top:60px; padding:20px 20px 0 20px; transition:all .3s; }
    @media (min-width: 992px){ .main{ margin-left:250px; } }

    .gradient-btn{
      background: linear-gradient(90deg, var(--pad-primary), var(--pad-accent));
      color:#fff; border:none; border-radius:10px;
      box-shadow:0 6px 18px rgba(54,30,92,.25);
    }
    .gradient-btn:hover{ opacity:.93; color:#fff; }

    .btn-soft{
      background:#f4f1fb; border:1px solid rgba(97,65,166,.25); color:#432d7a; border-radius:10px;
    }
    .btn-soft:hover{ background:#ece6fa; }

    /* Equal action button sizes */
    .btn-action{ min-width: 140px; padding:.5rem .9rem; }

    .filter .nav-link{ color:#432d7a; }
    .filter .nav-link.active{
      color:#fff; background: linear-gradient(90deg, var(--pad-primary), var(--pad-accent));
      border-radius:999px;
    }

    .card-renter{
      border:1px solid rgba(0,0,0,.06);
      border-radius:14px; box-shadow:0 10px 24px rgba(0,0,0,.06);
    }
    .title{ color:#2D1B4E; font-weight:600; }
    .muted{ color:#6c757d; }

    .chip{ border:1px solid rgba(0,0,0,.08); padding:.25rem .5rem; border-radius:999px; background:#fff; font-size:.85rem; }
    .countdown{ font-variant-numeric: tabular-nums; }
    .overdue{ color:#dc3545; font-weight:600; }
    .soon{ color:#c17a00; font-weight:600; }

    .stack-sm{ gap:.5rem; }
    @media (max-width: 576px){
      .stack-sm{ flex-direction:column; align-items:stretch !important; }
      .btn-action{ width:100%; }
    }
  </style>
</head>
<body class="d-flex flex-column min-vh-100">
  <?php include __DIR__ . '/../admin/topbar.php'; ?>
  <?php include __DIR__ . '/../admin/sidebar.php'; ?>

  <main id="main" class="main flex-grow-1">
    <div class="pagetitle text-center mb-3">
      <h1 class="h4 mb-1">Renter Status</h1>
      <nav>
        <ol class="breadcrumb justify-content-center">
          <li class="breadcrumb-item"><a href="<?= $BASE ?>/dashboard.php">Dashboard</a></li>
          <li class="breadcrumb-item active">Status</li>
        </ol>
      </nav>
    </div>

    <?php if (!empty($msg)): ?><div class="alert alert-success text-center"><?= $msg ?></div><?php endif; ?>
    <?php if (!empty($err)): ?><div class="alert alert-danger text-center"><?= $err ?></div><?php endif; ?>

    <!-- Filters + Search -->
    <div class="card mb-3">
      <div class="card-body d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <ul class="nav filter gap-1">
          <?php $tabs = ['all'=>'All','active'=>'Active','soon'=>'Due Soon','overdue'=>'Overdue','completed'=>'Completed'];
          foreach($tabs as $k=>$label): ?>
            <li class="nav-item">
              <a class="nav-link px-3 <?= $filter===$k?'active':'' ?>" href="?filter=<?= $k ?>&q=<?= urlencode($search) ?>"><?= $label ?></a>
            </li>
          <?php endforeach; ?>
        </ul>
        <form class="d-flex gap-2" method="get">
          <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
          <input type="text" class="form-control" name="q" placeholder="Search tenant / house / room" value="<?= htmlspecialchars($search) ?>">
          <button class="btn gradient-btn"><i class="bi bi-search me-1"></i>Search</button>
        </form>
      </div>
    </div>

    <!-- Results -->
    <?php if (empty($rows)): ?>
      <div class="alert alert-light border text-center">No renters found for this filter.</div>
    <?php else: ?>
      <div class="d-flex flex-column gap-3">
        <?php foreach ($rows as $r): ?>
          <?php
            $name   = full_name($r);
            $house  = $r['house_name'];
            $addr   = $r['address'];
            $floor  = $r['floor_label'];
            $room   = $r['room_label'];
            $due    = $r['due_date'];
            $rate   = (float)$r['monthly_rate'];
            $paid   = (float)$r['total_paid'];
            [$mPaid, $dPaid] = paid_breakdown($paid, max(0.01,$rate));

            $cap   = isset($r['capacity']) ? (int)$r['capacity'] : null;
            $notes = $r['notes'] ?? '';
            $days  = (int)((strtotime($due) - strtotime(date('Y-m-d'))) / 86400);
            $bucket = ($r['renter_status']==='completed') ? 'completed' :
                      ($days < 0 ? 'overdue' : ($days <= 7 ? 'soon' : 'active'));
          ?>
          <div class="card card-renter">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                <!-- Left details -->
                <div>
                  <div class="title">
                    <i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($name) ?>
                  </div>

                  <div class="mt-1">
                    <span class="chip"><i class="bi bi-house me-1"></i><?= htmlspecialchars($house) ?></span>
                    <span class="chip"><i class="bi bi-building-up me-1"></i><?= htmlspecialchars($floor) ?></span>
                    <span class="chip"><i class="bi bi-door-closed me-1"></i><?= htmlspecialchars($room) ?></span>
                  </div>

                  <?php if ($addr): ?>
                    <div class="muted small mt-1"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($addr) ?></div>
                  <?php endif; ?>

                  <?php if ($cap!==null || $notes): ?>
                    <div class="small mt-2">
                      <?php if ($cap!==null): ?><span class="chip me-1"><?= $cap ?> person<?= $cap>1?'s':'' ?> capacity</span><?php endif; ?>
                      <?php if ($notes): ?><span class="chip"><?= htmlspecialchars($notes) ?></span><?php endif; ?>
                    </div>
                  <?php endif; ?>
                </div>

                <!-- Right side: status + actions -->
                <div class="text-end" style="min-width:280px;">
                  <div class="mb-2">
                    <span class="chip me-1"><i class="bi bi-cash-coin me-1"></i>Rate: ₱<?= number_format($rate,2) ?>/mo</span>
                    <span class="chip"><i class="bi bi-wallet2 me-1"></i>Paid: ₱<?= number_format($paid,2) ?> (<?= $mPaid ?> mo <?= $dPaid ?> d)</span>
                  </div>
                  <div class="mb-1">
                    <span class="chip">
                      <i class="bi bi-hourglass-split me-1"></i>
                      <span class="countdown <?= $bucket==='overdue'?'overdue':'' ?> <?= $bucket==='soon'?'soon':'' ?>" data-duedate="<?= htmlspecialchars($due) ?>"></span>
                    </span>
                    <span class="chip ms-1"><i class="bi bi-calendar2-event me-1"></i>Start: <?= htmlspecialchars($r['start_date']) ?></span>
                    <span class="chip ms-1"><i class="bi bi-calendar2-check me-1"></i>Due: <?= htmlspecialchars($due) ?></span>
                  </div>

                  <?php if ($r['renter_status']==='active'): ?>
                    <div class="d-flex justify-content-end align-items-center stack-sm mt-2">
                      <form method="post" class="d-flex align-items-center gap-2">
                        <input type="hidden" name="action" value="add_payment">
                        <input type="hidden" name="renter_id" value="<?= (int)$r['renter_id'] ?>">
                        <input type="number" name="pay_amount" step="0.01" min="0" class="form-control" style="max-width:160px" placeholder="Amount">
                        <button class="btn gradient-btn btn-action"><i class="bi bi-plus-circle me-1"></i>Add Payment</button>
                      </form>
                      <form method="post">
                        <input type="hidden" name="action" value="complete">
                        <input type="hidden" name="renter_id" value="<?= (int)$r['renter_id'] ?>">
                        <button class="btn btn-soft btn-action ms-2"><i class="bi bi-box-arrow-right me-1"></i>Complete</button>
                      </form>
                    </div>
                  <?php else: ?>
                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle mt-2">completed</span>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </main>

  <?php include __DIR__ . '/../admin/footer.php'; ?>
  <script src="<?= $BASE ?>/../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script>
    // Live countdown
    function updateCountdown(el){
      const dueStr = el.getAttribute('data-duedate'); // yyyy-mm-dd
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
    tick();
    setInterval(tick, 1000);
  </script>
</body>
</html>
