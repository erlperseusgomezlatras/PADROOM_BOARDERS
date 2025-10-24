<?php
// admin/rooms_edit.php
require_once __DIR__ . '/../inclusions/require_login.php';
require_once __DIR__ . '/../inclusions/connection.php';

$BASE = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$msg = "";
$err = "";

/* ===========================================================
   Ensure houses has archive fields (is_archived, archived_at)
=========================================================== */
function ensure_house_archive_columns(mysqli $conn, &$msgOut, &$errOut) {
  $q = "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'houses'
          AND COLUMN_NAME = 'is_archived'";
  $need = true;
  if ($res = $conn->query($q)) {
    $row = $res->fetch_row();
    $need = ((int)$row[0] === 0);
    $res->close();
  }
  if ($need) {
    $alter = "ALTER TABLE houses
                ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER notes,
                ADD COLUMN archived_at DATETIME NULL AFTER is_archived";
    if ($conn->query($alter)) {
      $msgOut = trim($msgOut . " Archive fields added on houses.");
    } else {
      $errOut = "Failed to add archive fields on houses: " . $conn->error;
    }
  }
}
ensure_house_archive_columns($conn, $msg, $err);

/* ===========================================================
   Actions
=========================================================== */

/* A) Bulk update rates (vacant only) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_update') {
  $room_ids = $_POST['room_id'] ?? [];
  $rates    = $_POST['rate'] ?? [];

  if (!empty($room_ids) && is_array($room_ids)) {
    $conn->begin_transaction();
    try {
      foreach ($room_ids as $i => $rid) {
        $room_id = (int)$rid;
        $rate    = (float)($rates[$i] ?? 0);

        if ($room_id <= 0 || $rate < 0) continue;

        // must be vacant
        $chk = $conn->prepare("SELECT status FROM rooms WHERE id=?");
        if (!$chk) throw new Exception($conn->error);
        $chk->bind_param("i", $room_id);
        $chk->execute();
        $chk->bind_result($status);
        $chk->fetch();
        $chk->close();
        if ($status !== 'vacant') continue;

        // close previous open rate
        $close = $conn->prepare("UPDATE room_rate_history
                                   SET effective_to = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                                 WHERE room_id=? AND effective_to IS NULL");
        if (!$close) throw new Exception($conn->error);
        $close->bind_param("i", $room_id);
        if (!$close->execute()) throw new Exception($conn->error);
        $close->close();

        // insert new
        $ins = $conn->prepare("INSERT INTO room_rate_history
                                (room_id, monthly_rate, effective_from, notes)
                               VALUES (?, ?, CURDATE(), 'bulk update')");
        if (!$ins) throw new Exception($conn->error);
        $ins->bind_param("id", $room_id, $rate);
        if (!$ins->execute()) throw new Exception($conn->error);
        $ins->close();
      }
      $conn->commit();
      $msg = trim("All room rates updated successfully. " . $msg);
    } catch (Exception $e) {
      $conn->rollback();
      $err = "Failed to update rates: " . $e->getMessage();
    }
  } else {
    $err = "No rooms selected.";
  }
}

/* B) Update capacity + notes for one room */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_meta') {
  $room_id  = (int)($_POST['room_id'] ?? 0);
  $notes    = trim($_POST['notes'] ?? '');
  $cap_raw  = trim($_POST['capacity'] ?? '');
  $has_cap  = ($cap_raw !== '');
  $capacity = $has_cap ? (int)$cap_raw : null;

  if ($room_id <= 0) {
    $err = "Invalid room.";
  } else {
    if ($has_cap) {
      $stmt = $conn->prepare("UPDATE rooms SET capacity=?, notes=? WHERE id=?");
      if ($stmt) { $stmt->bind_param("isi", $capacity, $notes, $room_id); }
      else { $err = "DB error: " . $conn->error; }
    } else {
      $stmt = $conn->prepare("UPDATE rooms SET capacity=NULL, notes=? WHERE id=?");
      if ($stmt) { $stmt->bind_param("si", $notes, $room_id); }
      else { $err = "DB error: " . $conn->error; }
    }

    if (!$err) {
      if ($stmt->execute()) $msg = "Room details updated.";
      else $err = "Failed to update room: " . $conn->error;
      $stmt->close();
    }
  }
}

/* C) Archive house (only when no occupied rooms) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'archive_house') {
  $hid = (int)($_POST['house_id'] ?? 0);
  if ($hid > 0) {
    // Count occupied for this house
    $q = "SELECT COUNT(*)
            FROM rooms r
            JOIN floors f ON r.floor_id = f.id
           WHERE f.house_id = ? AND r.status='occupied'";
    $s = $conn->prepare($q);
    if (!$s) { $err = "DB error: " . $conn->error; }
    else {
      $s->bind_param("i", $hid);
      $s->execute();
      $s->bind_result($occ);
      $s->fetch();
      $s->close();

      if ((int)$occ > 0) {
        $err = "Cannot archive: at least one room is occupied.";
      } else {
        $u = $conn->prepare("UPDATE houses SET is_archived=1, archived_at=NOW() WHERE id=?");
        if (!$u) { $err = "DB error: " . $conn->error; }
        else {
          $u->bind_param("i", $hid);
          if ($u->execute()) $msg = "House archived.";
          else $err = "Failed to archive: " . $conn->error;
          $u->close();
        }
      }
    }
  }
}

/* ===========================================================
   Fetch houses (skip archived), with stats
=========================================================== */
$houses = [];
$sql = "
  SELECT h.id, h.name, h.address, h.created_at, COALESCE(h.is_archived,0) AS is_archived,
         (SELECT COUNT(*) FROM floors f WHERE f.house_id = h.id) AS floors_count,
         (SELECT COUNT(*) FROM rooms r JOIN floors f ON r.floor_id=f.id WHERE f.house_id = h.id) AS rooms_count,
         (SELECT COUNT(*) FROM rooms r JOIN floors f ON r.floor_id=f.id WHERE f.house_id = h.id AND r.status='vacant') AS vacant_count,
         (SELECT COUNT(*) FROM rooms r JOIN floors f ON r.floor_id=f.id WHERE f.house_id = h.id AND r.status='occupied') AS occupied_count
  FROM houses h
  WHERE COALESCE(h.is_archived,0) = 0
  ORDER BY h.created_at DESC";
if ($res = $conn->query($sql)) {
  while ($row = $res->fetch_assoc()) $houses[] = $row;
  $res->close();
}

/* ===========================================================
   Floors + Rooms + Current Rate
=========================================================== */
$houseRooms = [];
foreach ($houses as $h) {
  $hid = (int)$h['id'];
  $houseRooms[$hid] = [];

  $floorStmt = $conn->prepare("SELECT id, floor_label FROM floors WHERE house_id = ? ORDER BY sort_order");
  $floorStmt->bind_param("i", $hid);
  $floorStmt->execute();
  $fres = $floorStmt->get_result();

  while ($f = $fres->fetch_assoc()) {
    $floor_id = (int)$f['id'];
    $floor = ['id'=>$floor_id, 'floor_label'=>$f['floor_label'], 'rooms'=>[]];

    $roomSql = "
      SELECT r.id, r.room_label, r.status, r.capacity, r.notes,
             (
               SELECT rr.monthly_rate
               FROM room_rate_history rr
               WHERE rr.room_id = r.id
               ORDER BY 
                 CASE WHEN rr.effective_to IS NULL THEN 0 ELSE 1 END ASC,
                 rr.effective_from DESC
               LIMIT 1
             ) AS current_rate
      FROM rooms r
      WHERE r.floor_id = ?
      ORDER BY r.id";
    $roomStmt = $conn->prepare($roomSql);
    $roomStmt->bind_param("i", $floor_id);
    $roomStmt->execute();
    $rres = $roomStmt->get_result();

    while ($r = $rres->fetch_assoc()) {
      $floor['rooms'][] = [
        'id'        => (int)$r['id'],
        'room_label'=> $r['room_label'],
        'status'    => $r['status'],
        'capacity'  => is_null($r['capacity']) ? null : (int)$r['capacity'],
        'notes'     => $r['notes'],
        'rate'      => $r['current_rate'] !== null ? (float)$r['current_rate'] : null
      ];
    }
    $rres->close();
    $roomStmt->close();

    $houseRooms[$hid][] = $floor;
  }
  $fres->close();
  $floorStmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Manage Room Rates - Padroom</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="<?= $BASE ?>/../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="<?= $BASE ?>/../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="<?= $BASE ?>/../assets/css/style.css" rel="stylesheet">
  <style>
    :root{
      --pad-primary:#361E5C;
      --pad-accent:#6141A6;
      --pad-deep:#2A184B;
      --pad-text:#FFF;
      --good:#17a2b8; /* info teal */
      --ok:#28a745;   /* green */
      --warn:#ffc107; /* amber */
      --bad:#dc3545;  /* red */
    }
    .main{ margin-top:60px; padding:20px 20px 0 20px; transition:all .3s; }
    @media (min-width: 992px){ .main{ margin-left:250px; } }

    /* Buttons */
    .gradient-btn{
      background: linear-gradient(90deg, var(--pad-primary), var(--pad-accent));
      color:#fff; border:none; border-radius:.6rem;
      box-shadow:0 6px 18px rgba(54,30,92,.25);
    }
    .gradient-btn:hover{ opacity:.95; color:#fff; }
    .btn-ghost{
      background:transparent; border:1px solid rgba(97,65,166,.35);
      color:var(--pad-accent); border-radius:.6rem;
    }
    .btn-ghost:hover{
      background:linear-gradient(90deg, rgba(97,65,166,.15), rgba(54,30,92,.18));
      color:#fff;
    }
    .btn-ghost[disabled]{ opacity:.5; cursor:not-allowed; }

    /* House card */
    .house-card{
      cursor:pointer; transition:.2s;
      border:1px solid #eee; border-radius:14px; background:#fff;
      box-shadow:0 8px 24px rgba(0,0,0,.06);
    }
    .house-card:hover{ transform:translateY(-2px); box-shadow:0 10px 28px rgba(0,0,0,.08); }
    .house-head{
      display:flex; align-items:flex-start; justify-content:space-between; gap:.75rem;
    }
    .house-name{ margin:0; font-weight:700; color:#2D1B4E; }
    .house-address{ color:#6c757d; }

    /* Stat chips */
    .chip{
      display:inline-flex; align-items:center; gap:.35rem;
      padding:.25rem .5rem; border-radius:999px; font-size:.85rem; font-weight:600; color:#fff;
      box-shadow:0 8px 20px rgba(0,0,0,.08);
    }
    .chip-floors{ background:linear-gradient(90deg, #6f42c1, #6141A6); }
    .chip-rooms{  background:linear-gradient(90deg, #20c997, #17a2b8); }
    .chip-vac{    background:linear-gradient(90deg, #28a745, #20c997); }
    .chip-occ{    background:linear-gradient(90deg, #ff6b6b, #dc3545); }

    /* Floor + room cards */
    .floor-section{ background:#f9f9ff; border:1px solid #eee; border-radius:12px; padding:1rem; margin-top:.75rem; }
    .floor-title{
      font-weight:700; margin-bottom:.75rem;
      background:linear-gradient(90deg, rgba(97,65,166,.12), rgba(54,30,92,.14));
      padding:.4rem .6rem; border-radius:8px; color:#2D1B4E;
    }
    .room-box{
      background:#fff;
      border:1px solid #e9e6f3;
      border-radius:10px;
      padding:.65rem .7rem;
      display:flex; align-items:center; justify-content:space-between; gap:.6rem;
      min-height:90px; transition:.2s;
      box-shadow:0 6px 16px rgba(0,0,0,.05);
    }
    .room-box.vacant:hover{ background:linear-gradient(180deg, rgba(97,65,166,.05), rgba(32,201,151,.06)); }
    .room-box.occupied{ background:linear-gradient(180deg, rgba(220,53,69,.06), rgba(255,107,107,.05)); }
    .room-left{ min-width:120px; }
    .room-label{ font-weight:800; }
    .room-occupied{ color:var(--bad); font-size:.9rem; }
    .room-vacant{ color:var(--ok); font-size:.9rem; }
    .room-center{ text-align:center; flex:1; padding:0 .5rem; }
    .room-center .cap{ font-weight:700; }
    .room-center .note{
      font-size:.85rem; color:#6c757d; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    }
    .rate-input{ width:120px; }
  </style>
</head>
<body class="d-flex flex-column min-vh-100">
  <?php include __DIR__ . '/../admin/topbar.php'; ?>
  <?php include __DIR__ . '/../admin/sidebar.php'; ?>

  <main id="main" class="main flex-grow-1">
    <div class="pagetitle mb-4 text-center">
      <h1 class="h4 mb-1">Manage Room Rates</h1>
      <nav>
        <ol class="breadcrumb justify-content-center">
          <li class="breadcrumb-item"><a href="<?= $BASE ?>/dashboard.php">Dashboard</a></li>
          <li class="breadcrumb-item active">Room Rates</li>
        </ol>
      </nav>
    </div>

    <?php if ($msg): ?><div class="alert alert-success text-center"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger text-center"><?= htmlspecialchars($err) ?></div><?php endif; ?>

    <!-- House Cards -->
    <div class="row g-3 mb-4">
      <?php if (empty($houses)): ?>
        <div class="col-12"><div class="alert alert-info text-center">No houses yet. Create one in <strong>Manage House</strong>.</div></div>
      <?php else: ?>
        <?php foreach ($houses as $h): ?>
          <div class="col-md-6 col-lg-4">
            <div class="house-card p-3">
              <div class="house-head">
                <div style="flex:1;">
                  <h5 class="house-name"><?= htmlspecialchars($h['name']) ?></h5>
                  <?php if (!empty($h['address'])): ?>
                    <div class="house-address small"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($h['address']) ?></div>
                  <?php endif; ?>
                </div>
                <div class="d-flex align-items-start gap-2">
                  <!-- Archive button (disabled if occupied > 0) -->
                  <form method="post" onsubmit="return confirm('Archive this house? Floors and rooms remain, but the house is hidden from active view.');">
                    <input type="hidden" name="action" value="archive_house">
                    <input type="hidden" name="house_id" value="<?= (int)$h['id'] ?>">
                    <button class="btn btn-ghost btn-sm"
                            type="submit"
                            title="<?= $h['occupied_count']>0 ? 'Cannot archive while rooms are occupied' : 'Archive this house' ?>"
                            <?= $h['occupied_count']>0 ? 'disabled' : '' ?>>
                      <i class="bi bi-archive me-1"></i> Archive
                    </button>
                  </form>
                </div>
              </div>

              <div class="mt-3 d-flex flex-wrap gap-2">
                <span class="chip chip-floors"><i class="bi bi-building me-1"></i><?= (int)$h['floors_count'] ?> Floors</span>
                <span class="chip chip-rooms"><i class="bi bi-door-closed me-1"></i><?= (int)$h['rooms_count'] ?> Rooms</span>
                <span class="chip chip-vac"><i class="bi bi-check2-circle me-1"></i><?= (int)$h['vacant_count'] ?> Vacant</span>
                <span class="chip chip-occ"><i class="bi bi-person-fill-lock me-1"></i><?= (int)$h['occupied_count'] ?> Occupied</span>
              </div>

              <div class="mt-3 text-end">
                <button class="btn gradient-btn btn-sm"
                        type="button"
                        data-target-house="<?= (int)$h['id'] ?>">
                  <i class="bi bi-chevron-down me-1"></i> View Floors & Rooms
                </button>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- Floors and Rooms (hidden by default) -->
    <?php foreach ($houses as $h): ?>
      <form method="POST" id="house-<?= (int)$h['id'] ?>" class="house-floors mb-3" style="display:none;">
        <input type="hidden" name="action" value="bulk_update">
        <?php foreach ($houseRooms[$h['id']] as $floor): ?>
          <div class="floor-section">
            <div class="floor-title"><?= htmlspecialchars($floor['floor_label']) ?></div>
            <div class="row g-2">
              <?php foreach ($floor['rooms'] as $r): ?>
                <div class="col-12 col-sm-6 col-md-4">
                  <div class="room-box <?= $r['status'] === 'vacant' ? 'vacant' : 'occupied' ?>">
                    <div class="room-left">
                      <div class="room-label"><?= htmlspecialchars($r['room_label']) ?></div>
                      <div class="<?= $r['status'] === 'vacant' ? 'room-vacant' : 'room-occupied' ?>">
                        <?= $r['status'] === 'vacant' ? 'Vacant' : 'Occupied' ?>
                      </div>
                    </div>

                    <div class="room-center">
                      <div class="cap">
                        <?= is_null($r['capacity']) ? '—' : ((int)$r['capacity']) . ' person' . ((int)$r['capacity'] === 1 ? '' : 's') . ' capacity' ?>
                      </div>
                      <div class="note"><?= $r['notes'] ? htmlspecialchars($r['notes']) : '—' ?></div>
                    </div>

                    <div class="d-flex flex-column align-items-end gap-2">
                      <!-- One edit button for capacity+notes -->
                      <button type="button"
                              class="btn btn-sm btn-ghost"
                              data-bs-toggle="modal"
                              data-bs-target="#metaModal"
                              data-room='<?= json_encode([
                                'id' => (int)$r['id'],
                                'label' => $r['room_label'],
                                'capacity' => $r['capacity'],
                                'notes' => $r['notes']
                              ], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>'>
                        <i class="bi bi-pencil me-1"></i>Edit
                      </button>

                      <?php if ($r['status'] === 'vacant'): ?>
                        <div class="d-flex align-items-center gap-2">
                          <input type="hidden" name="room_id[]" value="<?= (int)$r['id'] ?>">
                          <input type="number" step="0.01" min="0" name="rate[]"
                                 class="form-control form-control-sm rate-input"
                                 value="<?= htmlspecialchars($r['rate'] ?? 0) ?>">
                        </div>
                      <?php else: ?>
                        <div class="text-end">
                          <div class="small text-muted">Rate</div>
                          <div class="fw-semibold">
                            <?= $r['rate'] !== null ? number_format($r['rate'], 2) : '—' ?>
                          </div>
                        </div>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
        <div class="text-end mt-3">
          <button type="submit" class="btn gradient-btn px-4">
            <i class="bi bi-save me-2"></i>Save All Changes
          </button>
        </div>
      </form>
    <?php endforeach; ?>
  </main>

  <?php include __DIR__ . '/../admin/footer.php'; ?>
  <script src="<?= $BASE ?>/../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

  <!-- Modal: Capacity + Notes -->
  <div class="modal fade" id="metaModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <form method="post" class="modal-content">
        <input type="hidden" name="action" value="update_meta">
        <input type="hidden" name="room_id" id="meta-room-id">
        <div class="modal-header">
          <h5 class="modal-title" id="meta-title">Edit Room</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Capacity (people)</label>
            <input type="number" min="0" class="form-control" name="capacity" id="meta-capacity" placeholder="Leave blank for unknown">
          </div>
          <div class="mb-0">
            <label class="form-label">Notes</label>
            <textarea class="form-control" rows="3" name="notes" id="meta-notes" placeholder="Add any note…"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancel</button>
          <button class="btn gradient-btn" type="submit"><i class="bi bi-check2-circle me-1"></i> Save</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    // Show/hide floors for a clicked house (one open at a time)
    document.querySelectorAll('[data-target-house]').forEach(btn => {
      btn.addEventListener('click', () => {
        const houseId = btn.getAttribute('data-target-house');
        const section = document.getElementById('house-' + houseId);
        document.querySelectorAll('.house-floors').forEach(s => {
          if (s !== section) s.style.display = 'none';
        });
        section.style.display = (section.style.display === 'none' || section.style.display === '') ? 'block' : 'none';
      });
    });

    // Fill capacity/notes modal
    const metaModal = document.getElementById('metaModal');
    metaModal?.addEventListener('show.bs.modal', (event) => {
      const btn = event.relatedTarget;
      const data = btn?.getAttribute('data-room');
      if (!data) return;
      const r = JSON.parse(data);

      document.getElementById('meta-room-id').value = r.id;
      document.getElementById('meta-title').textContent = 'Edit ' + r.label;
      document.getElementById('meta-capacity').value = (r.capacity ?? '') === null ? '' : (r.capacity ?? '');
      document.getElementById('meta-notes').value = r.notes ?? '';
    });
  </script>
</body>
</html>
