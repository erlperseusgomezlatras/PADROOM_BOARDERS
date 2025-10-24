<?php
// admin/manage_renters.php
require_once __DIR__ . '/../inclusions/require_login.php';
require_once __DIR__ . '/../inclusions/connection.php';

$BASE = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$msg = $_GET['msg'] ?? "";
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

function current_rate(mysqli $conn, int $room_id): ?float {
  $today = date('Y-m-d');
  $stmt = $conn->prepare("
    SELECT monthly_rate
    FROM room_rate_history
    WHERE room_id=? AND effective_from<=?
    ORDER BY effective_from DESC, id DESC
    LIMIT 1
  ");
  $stmt->bind_param("is", $room_id, $today);
  $stmt->execute(); $stmt->bind_result($rate);
  $ok = $stmt->fetch();
  $stmt->close();
  return $ok ? (float)$rate : null;
}

/** 30-day proration due-date calculator */
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

function redirect_self_with($msg){
  $q = http_build_query(['msg'=>$msg]);
  header("Location: ".$_SERVER['PHP_SELF'].'?'.$q);
  exit;
}

/* --------------- Actions ------------------ */

/* Assign a tenant to a vacant room (creates renter + payment, sets room occupied) */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='assign') {
  $tenant_id = (int)($_POST['tenant_id'] ?? 0);
  $room_id   = (int)($_POST['room_id'] ?? 0);
  $amount    = (float)($_POST['amount'] ?? 0);

  if (!$tenant_id || !$room_id || $amount <= 0) {
    $err = "Select tenant, vacant room, and valid initial payment.";
  } else {
    // verify room is vacant
    $stmt = $conn->prepare("SELECT status FROM rooms WHERE id=?");
    $stmt->bind_param("i", $room_id);
    $stmt->execute(); $stmt->bind_result($rstatus);
    $found = $stmt->fetch(); $stmt->close();

    if (!$found)            { $err = "Room not found."; }
    elseif ($rstatus!=='vacant'){ $err = "Selected room is not vacant."; }
    else {
      $rate = current_rate($conn, $room_id);
      if (!$rate || $rate <= 0) {
        $err = "No valid monthly rate set for that room.";
      } else {
        $start = date('Y-m-d');
        $due   = compute_due_date($start, (float)$rate, $amount);
        $months_paid = (int)floor($amount / (float)$rate);
        try {
          $conn->begin_transaction();

          // renters
          $stmt = $conn->prepare("
            INSERT INTO renters (tenant_id, room_id, start_date, due_date, monthly_rate, total_paid, status)
            VALUES (?, ?, ?, ?, ?, ?, 'active')
          ");
          $stmt->bind_param("iissdd", $tenant_id, $room_id, $start, $due, $rate, $amount);
          if (!$stmt->execute()) throw new Exception("renters: ".$stmt->error);
          $renter_id = $stmt->insert_id;
          $stmt->close();

          // payments
          $stmt = $conn->prepare("
            INSERT INTO payments (renter_id, room_id, amount, months_paid)
            VALUES (?, ?, ?, ?)
          ");
          $stmt->bind_param("iidi", $renter_id, $room_id, $amount, $months_paid);
          if (!$stmt->execute()) throw new Exception("payments: ".$stmt->error);
          $stmt->close();

          // set room occupied
          $stmt = $conn->prepare("UPDATE rooms SET status='occupied' WHERE id=?");
          $stmt->bind_param("i", $room_id);
          if (!$stmt->execute()) throw new Exception("rooms: ".$stmt->error);
          $stmt->close();

          $conn->commit();
          redirect_self_with("Assigned successfully. Due: {$due}");
        } catch (Exception $e) {
          $conn->rollback();
          $err = "Assign failed → ".$e->getMessage();
        }
      }
    }
  }
}

/* Add payment (prorated 30-day) */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='add_payment') {
  $renter_id = (int)($_POST['renter_id'] ?? 0);
  $amount    = (float)($_POST['pay_amount'] ?? 0);
  if ($renter_id <= 0 || $amount <= 0) {
    $err = "Invalid renter/amount.";
  } else {
    $stmt = $conn->prepare("SELECT start_date, monthly_rate, total_paid, room_id FROM renters WHERE id=? AND status='active'");
    $stmt->bind_param("i", $renter_id);
    $stmt->execute();
    $stmt->bind_result($start_date, $rate, $total, $room_id);
    $ok = $stmt->fetch(); $stmt->close();

    if (!$ok) { $err = "Active renter not found."; }
    elseif ($rate <= 0) { $err = "Invalid monthly rate."; }
    else {
      $new_total = (float)$total + $amount;
      $new_due   = compute_due_date($start_date, (float)$rate, $new_total);
      $months_paid = (int)floor($amount / (float)$rate);

      try{
        $conn->begin_transaction();

        $stmt = $conn->prepare("UPDATE renters SET due_date=?, total_paid=? WHERE id=?");
        $stmt->bind_param("sdi", $new_due, $new_total, $renter_id);
        if (!$stmt->execute()) throw new Exception("renters: ".$stmt->error);
        $stmt->close();

        $stmt = $conn->prepare("INSERT INTO payments (renter_id, room_id, amount, months_paid) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iidi", $renter_id, $room_id, $amount, $months_paid);
        if (!$stmt->execute()) throw new Exception("payments: ".$stmt->error);
        $stmt->close();

        $conn->commit();
        redirect_self_with("Payment added. New due: {$new_due}");
      } catch (Exception $e) {
        $conn->rollback();
        $err = "Payment failed → ".$e->getMessage();
      }
    }
  }
}

/* ---------------- Data for form & cards ---------------- */

// Tenants for assignment
$tenants = [];
if ($res = $conn->query("SELECT id, first_name, middle_name, last_name FROM tenants WHERE COALESCE(is_archived,0)=0 ORDER BY last_name, first_name")) {
  while ($row = $res->fetch_assoc()) $tenants[] = $row;
  $res->close();
}

// Vacant rooms for assignment (exclude archived houses if that column exists)
$vacantRooms = [];
$hasHouseArchive = has_column($conn,'houses','is_archived');
$sqlVacant = "
  SELECT r.id, r.room_label, f.floor_label, h.name AS house_name
  FROM rooms r
  JOIN floors f ON r.floor_id=f.id
  JOIN houses h ON f.house_id=h.id
  WHERE r.status='vacant' " . ($hasHouseArchive ? "AND h.is_archived=0 " : "") . "
  ORDER BY h.name, f.sort_order, r.room_label
";
if ($res = $conn->query($sqlVacant)) {
  $vacantRooms = $res->fetch_all(MYSQLI_ASSOC);
  $res->close();
}

// Houses (exclude archived)
$houses = [];
$sqlH = $hasHouseArchive
  ? "SELECT id, name, address FROM houses WHERE is_archived=0 ORDER BY name"
  : "SELECT id, name, address FROM houses ORDER BY name";
if ($res = $conn->query($sqlH)) {
  $houses = $res->fetch_all(MYSQLI_ASSOC);
  $res->close();
}

// For each house, load floors & rooms + active renter
$houseDetails = []; // house_id => ['floors'=>[...], 'rooms'=>[ room rows with renter+tenant if any ]]
if (!empty($houses)) {
  $ids = implode(',', array_map('intval', array_column($houses,'id')));
  $floors = [];
  if ($res = $conn->query("SELECT id, house_id, floor_label, sort_order FROM floors WHERE house_id IN ($ids) ORDER BY house_id, sort_order")) {
    while ($r = $res->fetch_assoc()) $floors[] = $r;
    $res->close();
  }
  foreach ($houses as $h) $houseDetails[$h['id']] = ['floors'=>[], 'rooms'=>[]];
  foreach ($floors as $f) $houseDetails[$f['house_id']]['floors'][] = $f;

  $rooms = [];
  if ($res = $conn->query("
    SELECT r.id, r.floor_id, r.room_label, r.capacity, r.status, r.notes,
           ren.id AS renter_id, ren.start_date, ren.due_date, ren.status AS rent_status,
           t.first_name, t.middle_name, t.last_name
    FROM rooms r
    JOIN floors f ON r.floor_id=f.id
    JOIN houses h ON f.house_id=h.id
    LEFT JOIN renters ren ON ren.room_id=r.id AND ren.status='active'
    LEFT JOIN tenants t ON t.id=ren.tenant_id
    WHERE h.id IN ($ids)
    ORDER BY f.house_id, f.sort_order, r.room_label
  ")) {
    while ($r = $res->fetch_assoc()) $rooms[] = $r;
    $res->close();
  }
  $floorHouse = [];
  foreach ($floors as $f) $floorHouse[$f['id']] = $f['house_id'];
  foreach ($rooms as $rm) {
    $hid = $floorHouse[$rm['floor_id']] ?? null;
    if ($hid) $houseDetails[$hid]['rooms'][] = $rm;
  }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Manage Renters - Padroom</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="<?= $BASE ?>/../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="<?= $BASE ?>/../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="<?= $BASE ?>/../assets/css/style.css" rel="stylesheet">
  <style>
    :root{
      --pad-primary:#361E5C; --pad-accent:#6141A6; --pad-deep:#2A184B; --pad-text:#FFFFFF;
      --ink:#1f1f2b; --muted:#6c757d;
    }
    .main{ margin-top:60px; padding:20px 20px 40px 20px; transition:all .3s; }
    @media (min-width: 992px){ .main{ margin-left:250px; } }

    /* Header actions */
    .action-bar{ display:flex; gap:.6rem; justify-content:center; margin-bottom:1rem; }
    .btn-grad{
      background:linear-gradient(90deg,var(--pad-primary),var(--pad-accent));
      color:#fff; border:none; border-radius:12px; padding:.6rem 1rem; font-weight:700;
      box-shadow:0 8px 22px rgba(54,30,92,.25);
    }
    .btn-grad:hover{ opacity:.95; color:#fff; }
    .btn-outline{
      border:1px solid rgba(97,65,166,.35); color:#432d7a; background:#fff; border-radius:12px; padding:.6rem 1rem; font-weight:700;
    }
    .btn-outline:hover{ background:linear-gradient(90deg,rgba(97,65,166,.12),rgba(54,30,92,.14)); color:#2d1b4e; }

    /* House cards */
    .house-card{
      border:1px solid rgba(0,0,0,.06); border-radius:16px; background:#fff;
      box-shadow:0 12px 28px rgba(0,0,0,.06); overflow:hidden;
    }
    .house-head{ display:flex; align-items:center; justify-content:space-between; gap:.75rem; padding:1rem 1rem 0.6rem 1rem; cursor:pointer; }
    .house-title{ font-size:1.1rem; font-weight:800; color:#2D1B4E; }
    .house-sub{ color:var(--muted); }
    .chip{ display:inline-flex; align-items:center; gap:.4rem; padding:.28rem .6rem; border-radius:999px; font-size:.82rem; background:#f5f3ff; color:#4a3a86; border:1px solid rgba(97,65,166,.25); }
    .chev{
      width:30px; height:30px; border-radius:50%; display:grid; place-items:center;
      transition:transform .25s ease; background:#f4f1fb; color:#6246af;
    }
    .chev.rot{ transform:rotate(180deg); }

    .house-body{ display:none; padding:0 1rem 1rem 1rem; }

    /* Floor & rooms */
    .floor-title{
      font-weight:800; color:#2D1B4E; margin:.8rem 0 .4rem 0;
      background:linear-gradient(90deg,rgba(97,65,166,.12),rgba(54,30,92,.14)); padding:.4rem .6rem; border-radius:10px;
    }
    .room-tile{
      border:1px solid rgba(0,0,0,.08); border-radius:12px; padding:.9rem; background:#fff;
      box-shadow:0 8px 20px rgba(0,0,0,.05); min-height:116px;
    }
    .room-header{ display:flex; align-items:center; justify-content:space-between; margin-bottom:.35rem; }
    .room-title{ font-weight:800; color:var(--ink); }

    /* Gradient status pills */
    .pill{
      display:inline-flex; align-items:center; gap:.35rem; padding:.2rem .6rem; border-radius:999px;
      font-size:.78rem; font-weight:800; color:#fff; box-shadow:0 6px 14px rgba(22,24,57,.10);
    }
    .pill-occupied{ background:linear-gradient(135deg,#4f46e5,#7c3aed); }
    .pill-vacant  { background:linear-gradient(135deg,#10b981,#22c55e); }
    .pill-maint   { background:linear-gradient(135deg,#f59e0b,#f97316); }

    .detail-sm{ color:#4a4a4a; font-size:.92rem; }
    .detail-sm .muted{ color:#6c757d; }

    /* Add payment controls - compact */
    .input-compact{ height:36px; font-size:.92rem; }
    .btn-compact{ padding:.44rem .76rem; border-radius:10px; font-weight:700; line-height:1; }
    .room-actions{ display:flex; gap:.5rem; align-items:center; margin-top:.55rem; }
    @media (max-width:576px){ .room-actions{ flex-direction:column; align-items:stretch; } .btn-compact{ width:100%; } }

    .countdown{ font-variant-numeric:tabular-nums; }
    .overdue{ color:#dc3545; font-weight:700; }
  </style>
</head>
<body class="d-flex flex-column min-vh-100">
  <?php include __DIR__ . '/../admin/topbar.php'; ?>
  <?php include __DIR__ . '/../admin/sidebar.php'; ?>

  <main id="main" class="main flex-grow-1">
    <div class="pagetitle text-center mb-2">
      <h1 class="h4 mb-1">Manage Renters</h1>
      <nav>
        <ol class="breadcrumb justify-content-center">
          <li class="breadcrumb-item"><a href="<?= $BASE ?>/dashboard.php">Dashboard</a></li>
          <li class="breadcrumb-item active">Renters</li>
        </ol>
      </nav>
    </div>

    <?php if ($msg): ?><div class="alert alert-success text-center"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger text-center"><?= htmlspecialchars($err) ?></div><?php endif; ?>

    <!-- Actions -->
    <div class="action-bar">
      <button class="btn-grad" data-bs-toggle="modal" data-bs-target="#assignModal"><i class="bi bi-person-plus me-1"></i>Assign renter</button>
      <a class="btn-outline" href="<?= $BASE ?>/tenants_create.php"><i class="bi bi-person-add me-1"></i>Create tenant</a>
    </div>

    <!-- House cards -->
    <?php foreach ($houses as $h):
      $hid = (int)$h['id'];
      $floors = $houseDetails[$hid]['floors'] ?? [];
      $rooms  = $houseDetails[$hid]['rooms'] ?? [];

      $total = count($rooms);
      $vac   = 0; $occ = 0;
      foreach ($rooms as $r) { if ($r['status']==='vacant') $vac++; elseif ($r['status']==='occupied') $occ++; }
    ?>
      <div class="house-card mb-3" data-house="<?= $hid ?>">
        <div class="house-head">
          <div>
            <div class="house-title"><?= htmlspecialchars($h['name']) ?></div>
            <?php if (!empty($h['address'])): ?>
              <div class="house-sub small"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($h['address']) ?></div>
            <?php endif; ?>
          </div>
          <div class="d-flex align-items-center gap-2">
            <span class="chip"><i class="bi bi-door-closed"></i><?= $total ?> rooms</span>
            <span class="chip"><i class="bi bi-emoji-smile"></i><?= $vac ?> vacant</span>
            <span class="chip"><i class="bi bi-person-check"></i><?= $occ ?> occupied</span>
            <span class="chev"><i class="bi bi-chevron-down"></i></span>
          </div>
        </div>

        <div class="house-body">
          <?php foreach ($floors as $f):
            $floorRooms = array_values(array_filter($rooms, fn($rr)=> (int)$rr['floor_id']===(int)$f['id']));
          ?>
            <div class="floor-title"><?= htmlspecialchars($f['floor_label']) ?></div>
            <div class="row g-2 mt-1">
              <?php foreach ($floorRooms as $rm): ?>
                <div class="col-12 col-sm-6 col-md-4 col-xl-3">
                  <div class="room-tile">
                    <div class="room-header">
                      <div class="room-title"><?= htmlspecialchars($rm['room_label']) ?></div>
                      <?php if ($rm['status']==='vacant'): ?>
                        <span class="pill pill-vacant"><i class="bi bi-check2-circle"></i>vacant</span>
                      <?php elseif ($rm['status']==='occupied'): ?>
                        <span class="pill pill-occupied"><i class="bi bi-person-check"></i>occupied</span>
                      <?php else: ?>
                        <span class="pill pill-maint"><i class="bi bi-tools"></i>maintenance</span>
                      <?php endif; ?>
                    </div>

                    <?php if ($rm['status']==='vacant'): ?>
                      <?php $rate = current_rate($conn,(int)$rm['id']); ?>
                      <div class="detail-sm muted">Rate: <?= $rate ? '₱'.number_format($rate,2).'/mo' : '—' ?></div>

                    <?php elseif ($rm['status']==='occupied' && $rm['renter_id']): ?>
                      <div class="detail-sm">
                        <div class="mb-1">
                          <i class="bi bi-person-circle me-1"></i>
                          <?= htmlspecialchars(
                            ($rm['last_name']??'').', '.($rm['first_name']??'')
                            .(empty($rm['middle_name'])?'':' '.strtoupper(substr($rm['middle_name'],0,1)).'.')
                          ) ?>
                        </div>
                        <div>
                          <i class="bi bi-hourglass-split me-1"></i>
                          <span class="countdown" data-duedate="<?= htmlspecialchars($rm['due_date']) ?>"></span>
                        </div>
                      </div>
                      <form method="post" class="room-actions">
                        <input type="hidden" name="action" value="add_payment">
                        <input type="hidden" name="renter_id" value="<?= (int)$rm['renter_id'] ?>">
                        <input type="number" class="form-control form-control-sm input-compact" name="pay_amount" min="0" step="0.01" placeholder="Amount">
                        <button class="btn-grad btn-compact" type="submit"><i class="bi bi-plus-circle me-1"></i>Add Payment</button>
                      </form>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>

  </main>

  <?php include __DIR__ . '/../admin/footer.php'; ?>

  <script src="<?= $BASE ?>/../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script>
    // Expand / collapse with rotating chevron
    document.querySelectorAll('.house-card').forEach(card=>{
      const head = card.querySelector('.house-head');
      const body = card.querySelector('.house-body');
      const chev = card.querySelector('.chev');
      head.addEventListener('click', ()=>{
        const open = body.style.display === 'block';
        document.querySelectorAll('.house-body').forEach(b=>b.style.display='none');
        document.querySelectorAll('.chev').forEach(c=>c.classList.remove('rot'));
        if (!open){ body.style.display='block'; chev.classList.add('rot'); }
      });
    });

    // Live countdowns (red when overdue)
    function updateCountdown(el){
      const dueStr = el.getAttribute('data-duedate');
      if (!dueStr) return;
      const end = new Date(dueStr + 'T23:59:59');
      const now = new Date();
      let diff = Math.floor((end - now)/1000);
      if (isNaN(diff)) return;

      if (diff < 0) {
        el.textContent = 'Overdue';
        el.classList.add('overdue'); return;
      }
      const days = Math.floor(diff/86400); diff%=86400;
      const hrs  = Math.floor(diff/3600);  diff%=3600;
      const mins = Math.floor(diff/60);
      const secs = diff%60;
      el.textContent = `${days}d ${String(hrs).padStart(2,'0')}:${String(mins).padStart(2,'0')}:${String(secs).padStart(2,'0')}`;
    }
    function tickAll(){ document.querySelectorAll('.countdown').forEach(updateCountdown); }
    tickAll(); setInterval(tickAll, 1000);
  </script>

  <!-- Assign Modal -->
  <div class="modal fade" id="assignModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <form method="post" class="modal-content">
        <input type="hidden" name="action" value="assign">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>Assign renter to room</h5>
          <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-lg-6">
              <label class="form-label">Tenant</label>
              <select name="tenant_id" class="form-select" required>
                <option value="">Select</option>
                <?php foreach ($tenants as $t):
                  $name = $t['last_name'].', '.$t['first_name'].($t['middle_name']?' '.strtoupper(substr($t['middle_name'],0,1)).'.':''); ?>
                  <option value="<?= (int)$t['id'] ?>"><?= htmlspecialchars($name) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-lg-6">
              <label class="form-label">Vacant Room</label>
              <select name="room_id" class="form-select" required>
                <option value="">Select</option>
                <?php foreach ($vacantRooms as $r): ?>
                  <option value="<?= (int)$r['id'] ?>">
                    <?= htmlspecialchars($r['house_name'].' · '.$r['floor_label'].' · '.$r['room_label']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-lg-6">
              <label class="form-label">Initial Payment (₱)</label>
              <input type="number" name="amount" class="form-control" min="0" step="0.01" required>
              <div class="form-text">Due date will be prorated using a 30-day month.</div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-outline" type="button" data-bs-dismiss="modal">Cancel</button>
          <button class="btn-grad" type="submit"><i class="bi bi-check2-circle me-1"></i>Assign</button>
        </div>
      </form>
    </div>
  </div>
</body>
</html>
