<?php
// admin/manage_renters.php
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
    if (!$found) {
      $err = "Room not found.";
    } elseif ($rstatus !== 'vacant') {
      $err = "Selected room is not vacant.";
    } else {
      $rate = current_rate($conn, $room_id);
      if (!$rate || $rate <= 0) {
        $err = "No valid monthly rate set for that room.";
      } else {
        $months = (int)floor($amount / $rate);
        if ($months < 1) {
          $err = "Amount (₱".number_format($amount,2).") is less than rate (₱".number_format($rate,2).").";
        } else {
          $start = date('Y-m-d');
          $due   = date('Y-m-d', strtotime("+$months month", strtotime($start)));
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

            // payments (with room_id)
            $stmt = $conn->prepare("
              INSERT INTO payments (renter_id, room_id, amount, months_paid)
              VALUES (?, ?, ?, ?)
            ");
            $months_paid = $months;
            $stmt->bind_param("iidi", $renter_id, $room_id, $amount, $months_paid);
            if (!$stmt->execute()) throw new Exception("payments: ".$stmt->error);
            $stmt->close();

            // set room occupied
            $stmt = $conn->prepare("UPDATE rooms SET status='occupied' WHERE id=?");
            $stmt->bind_param("i", $room_id);
            if (!$stmt->execute()) throw new Exception("rooms: ".$stmt->error);
            $stmt->close();

            $conn->commit();
            $msg = "Assigned. Covered <strong>$months</strong> month(s). Due: <strong>$due</strong>.";
          } catch (Exception $e) {
            $conn->rollback();
            $err = "Assign failed → ".$e->getMessage();
          }
        }
      }
    }
  }
}

/* Add payment (extend due) from room tile form */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='add_payment') {
  $renter_id = (int)($_POST['renter_id'] ?? 0);
  $amount    = (float)($_POST['pay_amount'] ?? 0);
  if ($renter_id <= 0 || $amount <= 0) {
    $err = "Invalid renter/amount.";
  } else {
    $stmt = $conn->prepare("SELECT due_date, monthly_rate, total_paid, room_id FROM renters WHERE id=? AND status='active'");
    $stmt->bind_param("i", $renter_id);
    $stmt->execute();
    $stmt->bind_result($due, $rate, $total, $room_id);
    $ok = $stmt->fetch(); $stmt->close();

    if (!$ok) { $err = "Active renter not found."; }
    elseif ($rate <= 0) { $err = "Invalid monthly rate."; }
    else {
      $months = (int)floor($amount / (float)$rate);
      if ($months < 1) { $err = "Amount (₱".number_format($amount,2).") < rate (₱".number_format($rate,2).")."; }
      else {
        $new_due   = date('Y-m-d', strtotime("+$months month", strtotime($due)));
        $new_total = (float)$total + $amount;

        try{
          $conn->begin_transaction();

          $stmt = $conn->prepare("UPDATE renters SET due_date=?, total_paid=? WHERE id=?");
          $stmt->bind_param("sdi", $new_due, $new_total, $renter_id);
          if (!$stmt->execute()) throw new Exception("renters: ".$stmt->error);
          $stmt->close();

          $stmt = $conn->prepare("INSERT INTO payments (renter_id, room_id, amount, months_paid) VALUES (?, ?, ?, ?)");
          $months_paid = $months;
          $stmt->bind_param("iidi", $renter_id, $room_id, $amount, $months_paid);
          if (!$stmt->execute()) throw new Exception("payments: ".$stmt->error);
          $stmt->close();

          $conn->commit();
          $msg = "Payment added. +$months month(s). New due: <strong>$new_due</strong>.";
        } catch (Exception $e) {
          $conn->rollback();
          $err = "Payment failed → ".$e->getMessage();
        }
      }
    }
  }
}

/* ---------------- Data for form & cards ---------------- */

// Tenants for assignment
$tenants = [];
if ($res = $conn->query("SELECT id, first_name, middle_name, last_name FROM tenants ORDER BY last_name, first_name")) {
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

// For each house, we’ll lazy-load floors/rooms via one query now (simple + fast enough here)
$houseDetails = []; // house_id => ['floors'=>[...], 'rooms'=>[ room rows with renter+tenant if any ]]
if (!empty($houses)) {
  // get floors
  $ids = implode(',', array_map('intval', array_column($houses,'id')));
  $floors = [];
  if ($res = $conn->query("SELECT id, house_id, floor_label, sort_order FROM floors WHERE house_id IN ($ids) ORDER BY house_id, sort_order")) {
    while ($r = $res->fetch_assoc()) $floors[] = $r;
    $res->close();
  }
  foreach ($houses as $h) $houseDetails[$h['id']] = ['floors'=>[], 'rooms'=>[]];
  foreach ($floors as $f) $houseDetails[$f['house_id']]['floors'][] = $f;

  // get rooms + renter + tenant
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
  // bucket rooms per house
  // need map floor->house
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
      --pad-primary:#361E5C; --pad-accent:#6141A6; --pad-deep:#2A184B;
      --pad-text:#FFFFFF;
    }
    .main{ margin-top:60px; padding:20px 20px 0 20px; transition:all .3s; }
    @media (min-width: 992px){ .main{ margin-left:250px; } }

    .gradient-btn{
      background: linear-gradient(90deg, var(--pad-primary), var(--pad-accent));
      color:#fff; border:none; border-radius:10px; padding:.5rem 1rem;
      box-shadow:0 6px 18px rgba(54,30,92,.25);
    }
    .gradient-btn:hover{ opacity:.93; color:#fff; }

    .chip{ border:1px solid rgba(0,0,0,.08); padding:.25rem .55rem; border-radius:999px; font-size:.85rem; background:#fff; }
    .house-card{ cursor:pointer; border:1px solid rgba(0,0,0,.06); border-radius:14px; box-shadow:0 10px 24px rgba(0,0,0,.06); }
    .house-card:hover{ box-shadow:0 14px 28px rgba(0,0,0,.10); }
    .house-head .title{ font-weight:600; color:#2D1B4E; }
    .muted{ color:#6c757d; }

    .floor-title{ font-weight:600; margin-top:.35rem; }
    .room-tile{ border:1px solid rgba(0,0,0,.08); border-radius:12px; padding:.75rem; background:#fff; position:relative; }
    .room-tile .badge{ position:absolute; top:.5rem; right:.5rem; }
    .room-center{ text-align:center; color:#4a4a4a; font-size:.92rem; }
    .room-center .cap{ font-weight:600; }
    .room-center .notes{ color:#6c757d; }
    .countdown{ font-variant-numeric: tabular-nums; }
    .overdue{ color:#dc3545; font-weight:600; }

    .form-control:focus, .form-select:focus{
      border-color:#6141A6; box-shadow:0 0 0 .2rem rgba(97,65,166,.15);
    }
  </style>
</head>
<body class="d-flex flex-column min-vh-100">
  <?php include __DIR__ . '/../admin/topbar.php'; ?>
  <?php include __DIR__ . '/../admin/sidebar.php'; ?>

  <main id="main" class="main flex-grow-1">
    <div class="pagetitle mb-3 text-center">
      <h1 class="h4 mb-1">Manage Renters</h1>
      <nav>
        <ol class="breadcrumb justify-content-center">
          <li class="breadcrumb-item"><a href="<?= $BASE ?>/dashboard.php">Dashboard</a></li>
          <li class="breadcrumb-item active">Renters</li>
        </ol>
      </nav>
    </div>

    <?php if ($msg): ?><div class="alert alert-success text-center"><?= $msg ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger text-center"><?= $err ?></div><?php endif; ?>

    <!-- Assign row -->
    <form method="post" class="card shadow-sm mb-4">
      <input type="hidden" name="action" value="assign">
      <div class="card-body row g-3 align-items-end">
        <div class="col-lg-5">
          <label class="form-label">Tenant</label>
          <select name="tenant_id" class="form-select">
            <option value="">Select</option>
            <?php foreach ($tenants as $t):
              $name = $t['last_name'].', '.$t['first_name'].($t['middle_name']?' '.strtoupper(substr($t['middle_name'],0,1)).'.':''); ?>
              <option value="<?= (int)$t['id'] ?>"><?= htmlspecialchars($name) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-lg-5">
          <label class="form-label">Vacant Room</label>
          <select name="room_id" class="form-select">
            <option value="">Select</option>
            <?php foreach ($vacantRooms as $r): ?>
              <option value="<?= (int)$r['id'] ?>">
                <?= htmlspecialchars($r['house_name'].' · '.$r['floor_label'].' · '.$r['room_label']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-lg-2">
          <label class="form-label">Initial Payment (₱)</label>
          <input type="number" name="amount" class="form-control" min="0" step="0.01">
        </div>
        <div class="col-12 text-center">
          <button class="gradient-btn"><i class="bi bi-check2-circle me-1"></i>Assign</button>
        </div>
      </div>
    </form>

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
        <div class="house-head d-flex align-items-center justify-content-between p-3">
          <div>
            <div class="title"><?= htmlspecialchars($h['name']) ?></div>
            <?php if (!empty($h['address'])): ?>
              <div class="muted small"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($h['address']) ?></div>
            <?php endif; ?>
          </div>
          <div class="d-flex gap-2">
            <span class="chip"><i class="bi bi-door-closed me-1"></i><?= $total ?> rooms</span>
            <span class="chip"><i class="bi bi-emoji-smile me-1"></i><?= $vac ?> vacant</span>
            <span class="chip"><i class="bi bi-person-check me-1"></i><?= $occ ?> occupied</span>
          </div>
        </div>

        <div class="house-body px-3 pb-3" style="display:none;">
          <?php foreach ($floors as $f):
            $floorRooms = array_values(array_filter($rooms, fn($rr)=> (int)$rr['floor_id']===(int)$f['id']));
          ?>
            <div class="mb-2">
              <div class="floor-title"><?= htmlspecialchars($f['floor_label']) ?></div>
              <div class="row g-2 mt-1">
                <?php foreach ($floorRooms as $rm): ?>
                  <div class="col-12 col-sm-6 col-md-4 col-xl-3">
                    <div class="room-tile">
                      <div class="d-flex align-items-center justify-content-between mb-1">
                        <div class="fw-semibold"><?= htmlspecialchars($rm['room_label']) ?></div>
                        <?php if ($rm['status']==='vacant'): ?>
                          <span class="badge bg-success-subtle text-success border border-success-subtle">vacant</span>
                        <?php elseif ($rm['status']==='occupied'): ?>
                          <span class="badge bg-primary-subtle text-primary border border-primary-subtle">occupied</span>
                        <?php else: ?>
                          <span class="badge bg-warning-subtle text-warning border border-warning-subtle">maintenance</span>
                        <?php endif; ?>
                      </div>

                      <!-- center info: capacity + notes -->
                      <?php if (!is_null($rm['capacity']) || !empty($rm['notes'])): ?>
                        <div class="room-center mb-2">
                          <?php if (!is_null($rm['capacity'])): ?>
                            <div class="cap"><?= (int)$rm['capacity'] ?> person<?= (int)$rm['capacity']>1?'s':'' ?> capacity</div>
                          <?php endif; ?>
                          <?php if (!empty($rm['notes'])): ?>
                            <div class="notes"><?= htmlspecialchars($rm['notes']) ?></div>
                          <?php endif; ?>
                        </div>
                      <?php endif; ?>

                      <?php if ($rm['status']==='vacant'): ?>
                        <?php $rate = current_rate($conn,(int)$rm['id']); ?>
                        <div class="small muted">Rate: <?= $rate ? '₱'.number_format($rate,2).'/mo' : '—' ?></div>
                      <?php elseif ($rm['status']==='occupied' && $rm['renter_id']): ?>
                        <div class="small">
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
                        <form method="post" class="mt-2 d-flex gap-2 align-items-center">
                          <input type="hidden" name="action" value="add_payment">
                          <input type="hidden" name="renter_id" value="<?= (int)$rm['renter_id'] ?>">
                          <input type="number" class="form-control form-control-sm" name="pay_amount" min="0" step="0.01" placeholder="Amount">
                          <button class="btn btn-sm gradient-btn">Add Payment</button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>

  </main>

  <?php include __DIR__ . '/../admin/footer.php'; ?>
  <script src="<?= $BASE ?>/../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script>
    // Toggle open/close on house cards
    document.querySelectorAll('.house-card').forEach(card=>{
      const body = card.querySelector('.house-body');
      card.querySelector('.house-head').addEventListener('click', ()=>{
        body.style.display = (body.style.display==='none' || !body.style.display) ? 'block' : 'none';
      });
    });

    // Live countdowns (red when overdue)
    function updateCountdown(el){
      const dueStr = el.getAttribute('data-duedate'); // yyyy-mm-dd
      if (!dueStr) return;
      const end = new Date(dueStr + 'T23:59:59'); // end of due day
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

      el.textContent = `${days}d ${hrs.toString().padStart(2,'0')}:${mins.toString().padStart(2,'0')}:${secs.toString().padStart(2,'0')}`;
      el.classList.toggle('overdue', end < now);
    }
    function tickAll(){
      document.querySelectorAll('.countdown').forEach(updateCountdown);
    }
    tickAll();
    setInterval(tickAll, 1000);
  </script>
</body>
</html>
