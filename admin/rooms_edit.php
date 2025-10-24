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
      --ink:#1f2241;
      --muted:#6b7280;
      --bg-soft:#f6f7fb;

      --primary:#4b3fb7;       /* refined primary */
      --primary-2:#7d6bff;     /* accent */
      --success:#22a06b;
      --danger:#e3455b;
      --warning:#e7a100;

      --border:#e7e7f1;
      --card:#ffffff;
    }

    body{ background: var(--bg-soft); }
    .main{ margin-top:60px; padding:24px 16px 32px; transition:all .3s; }
    @media (min-width: 992px){ .main{ margin-left:250px; } }

    /* Page title */
    .pagetitle h1{ color: var(--ink); font-weight:700; }

    /* Alerts center */
    .alert{ max-width: 980px; margin: 0 auto 12px; }

    /* Buttons */
    .btn-primary-soft{
      background: linear-gradient(90deg, var(--primary), var(--primary-2));
      color:#fff; border:0; border-radius:10px; padding:.44rem .9rem;
      box-shadow:0 10px 22px rgba(75,63,183,.18);
      transition: transform .15s ease, opacity .15s ease;
    }
    .btn-primary-soft:hover{ opacity:.93; transform:translateY(-1px); color:#fff; }

    .btn-outline-primary-soft{
      background:#fff; color:var(--primary);
      border:1px solid var(--primary-2); border-radius:10px; padding:.44rem .9rem;
      transition: all .15s ease;
    }
    .btn-outline-primary-soft:hover{
      background: linear-gradient(90deg, var(--primary), var(--primary-2));
      color:#fff; box-shadow:0 10px 22px rgba(75,63,183,.18);
    }

    .btn-ghost{
      background:#fff; border:1px solid var(--border); color:var(--ink);
      padding:.35rem .7rem; border-radius:10px;
    }
    .btn-ghost:hover{ background:#f2f1fe; border-color:#d6d4ff; color:var(--primary); }
    .btn-ghost[disabled]{ opacity:.55; cursor:not-allowed; }

    /* House card */
    .house-card{
      border:1px solid var(--border); border-radius:14px; background:var(--card);
      box-shadow:0 8px 24px rgba(22,24,57,.06);
      transition: box-shadow .2s ease, transform .2s ease;
      overflow:hidden;
    }
    .house-card:hover{ transform: translateY(-2px); box-shadow:0 14px 28px rgba(22,24,57,.08); }
    .house-head{
      display:flex; align-items:flex-start; justify-content:space-between; gap:.75rem;
      border-bottom:1px dashed var(--border); padding-bottom:.45rem;
    }
    .house-name{ margin:0; font-weight:800; color:var(--ink); }
    .house-address{ color:var(--muted); font-size:.9rem; }

    /* Stat pills */
    .pill{
      display:inline-flex; align-items:center; gap:.4rem;
      padding:.28rem .6rem; border-radius:999px; font-size:.82rem; font-weight:700; color:#fff;
      box-shadow:0 10px 22px rgba(0,0,0,.06);
    }
    .pill-floors{ background:linear-gradient(90deg,#6f6eea,#8e86ff); }
    .pill-rooms{  background:linear-gradient(90deg,#20c997,#14a6bf); }
    .pill-vac{    background:linear-gradient(90deg,#1dbf73,#22a06b); }
    .pill-occ{    background:linear-gradient(90deg,#ff7a7a,#e3455b); }

    /* Floor + room sections */
    .floor-section{
      background:#fff; border:1px solid var(--border); border-radius:12px;
      padding:1rem; margin-top:.85rem;
    }
    .floor-title{
      font-weight:800; color:var(--ink);
      background:linear-gradient(90deg,rgba(125,107,255,.12),rgba(75,63,183,.12));
      border:1px solid var(--border); padding:.42rem .6rem; border-radius:8px;
      display:inline-block; margin-bottom:.8rem;
    }

    .room-box{
      background:#fff; border:1px solid var(--border); border-radius:12px;
      padding:.7rem .75rem; display:flex; align-items:center; justify-content:space-between; gap:.7rem;
      min-height:92px; transition: box-shadow .2s ease, transform .2s ease, background .2s ease;
      box-shadow:0 6px 16px rgba(22,24,57,.04);
    }
    .room-box.vacant:hover{ background:linear-gradient(180deg, rgba(34,160,107,.06), rgba(125,107,255,.05)); transform:translateY(-1px); }
    .room-box.occupied{ background:linear-gradient(180deg, rgba(227,69,91,.06), rgba(255,122,122,.05)); }

    .room-left { min-width:122px; }
    .room-label{ font-weight:800; color:var(--ink); }
    .room-occupied{ color:var(--danger); font-weight:700; font-size:.9rem; }
    .room-vacant{ color:var(--success); font-weight:700; font-size:.9rem; }

    .room-center{ text-align:center; flex:1; padding:0 .5rem; }
    .room-center .cap{ font-weight:700; color:var(--ink); }
    .room-center .note{ font-size:.85rem; color:var(--muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

    .rate-label{ font-size:.78rem; color:var(--muted); }
    .rate-val{ font-weight:800; color:var(--ink); }
    .rate-input{ width:128px; }

    /* Form controls focus */
    .form-control:focus, .form-select:focus{
      border-color:#c9c5ff; box-shadow:0 0 0 .18rem rgba(125,107,255,.15);
    }
  </style>
</head>
<body class="d-flex flex-column min-vh-100">
  <?php include __DIR__ . '/../admin/topbar.php'; ?>
  <?php include __DIR__ . '/../admin/sidebar.php'; ?>

  <main id="main" class="main flex-grow-1">
    <div class="pagetitle mb-3 text-center">
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
    <div class="container-xxl px-0">
      <div class="row g-3 mb-4">
        <?php if (empty($houses)): ?>
          <div class="col-12"><div class="alert alert-info text-center">No houses yet. Create one in <strong>Manage House</strong>.</div></div>
        <?php else: ?>
          <?php foreach ($houses as $h): ?>
            <div class="col-md-6 col-lg-4">
              <div class="house-card p-3">
                <div class="house-head">
                  <div style="flex:1;">
                    <h5 class="house-name mb-1"><?= htmlspecialchars($h['name']) ?></h5>
                    <?php if (!empty($h['address'])): ?>
                      <div class="house-address"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($h['address']) ?></div>
                    <?php endif; ?>
                  </div>
                  <form method="post" onsubmit="return confirm('Archive this house? It will be hidden but floors and rooms remain.');">
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

                <div class="mt-3 d-flex flex-wrap gap-2">
                  <span class="pill pill-floors"><i class="bi bi-building me-1"></i><?= (int)$h['floors_count'] ?> Floors</span>
                  <span class="pill pill-rooms"><i class="bi bi-door-closed me-1"></i><?= (int)$h['rooms_count'] ?> Rooms</span>
                  <span class="pill pill-vac"><i class="bi bi-check2-circle me-1"></i><?= (int)$h['vacant_count'] ?> Vacant</span>
                  <span class="pill pill-occ"><i class="bi bi-person-fill-lock me-1"></i><?= (int)$h['occupied_count'] ?> Occupied</span>
                </div>

                <div class="mt-3 text-end">
                  <button class="btn btn-primary-soft btn-sm"
                          type="button"
                          data-target-house="<?= (int)$h['id'] ?>">
                    <i class="bi bi-layers me-1"></i> Floors & Rooms
                  </button>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- Floors and Rooms (hidden by default) -->
    <div class="container-xxl px-0">
    <?php foreach ($houses as $h): ?>
      <form method="POST" id="house-<?= (int)$h['id'] ?>" class="house-floors mb-4" style="display:none;">
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
                      <button type="button"
                              class="btn btn-outline-primary-soft btn-sm"
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
                          <div class="rate-label">Rate</div>
                          <div class="rate-val">
                            <?= $r['rate'] !== null ? '₱'.number_format($r['rate'], 2) : '—' ?>
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
          <button type="submit" class="btn btn-primary-soft px-4">
            <i class="bi bi-save me-2"></i>Save All Changes
          </button>
        </div>
      </form>
    <?php endforeach; ?>
    </div>
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
          <button class="btn btn-ghost" type="button" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary-soft" type="submit"><i class="bi bi-check2-circle me-1"></i> Save</button>
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
