<?php
// admin/houses_archive.php  (LIST VERSION)
require_once __DIR__ . '/../inclusions/require_login.php';
require_once __DIR__ . '/../inclusions/connection.php';

$BASE = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$msg = "";
$err = "";
$schema_notice = "";

/* Ensure archive columns on houses */
function ensure_house_archive_columns(mysqli $conn, &$schema_notice) {
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
      $schema_notice = "Archive fields added to houses (is_archived, archived_at).";
    } else {
      $schema_notice = "Failed adding archive fields: " . $conn->error;
    }
  }
}
ensure_house_archive_columns($conn, $schema_notice);

/* Actions: restore / purge */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  $hid    = (int)($_POST['house_id'] ?? 0);

  if ($hid > 0) {
    if ($action === 'restore') {
      $stmt = $conn->prepare("UPDATE houses SET is_archived=0, archived_at=NULL WHERE id=?");
      if ($stmt) {
        $stmt->bind_param("i", $hid);
        if ($stmt->execute()) $msg = "House restored.";
        else $err = "Restore failed: " . $conn->error;
        $stmt->close();
      } else { $err = "DB error: " . $conn->error; }
    } elseif ($action === 'purge') {
      // Block if any occupied room
      $chk = $conn->prepare("SELECT COUNT(*)
                               FROM rooms r
                               JOIN floors f ON r.floor_id=f.id
                              WHERE f.house_id=? AND r.status='occupied'");
      if ($chk) {
        $chk->bind_param("i", $hid);
        $chk->execute();
        $chk->bind_result($occ);
        $chk->fetch();
        $chk->close();
        if ((int)$occ > 0) {
          $err = "Cannot delete: at least one room is occupied.";
        } else {
          $del = $conn->prepare("DELETE FROM houses WHERE id=?");
          if ($del) {
            $del->bind_param("i", $hid);
            if ($del->execute()) $msg = "House deleted.";
            else $err = "Delete failed: " . $conn->error;
            $del->close();
          } else { $err = "DB error: " . $conn->error; }
        }
      } else { $err = "DB error: " . $conn->error; }
    }
  }
}

/* Search + pagination (archived only) */
$q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 12;

$where = "COALESCE(h.is_archived,0)=1";
$params = [];
$types  = "";

if ($q !== '') {
  $where .= " AND (h.name LIKE ? OR h.address LIKE ?)";
  $like = "%$q%";
  $params = [$like, $like];
  $types  = "ss";
}

/* Count */
$sqlCount = "SELECT COUNT(*) FROM houses h WHERE $where";
$stmt = $conn->prepare($sqlCount);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$stmt->bind_result($total);
$stmt->fetch();
$stmt->close();

$pages  = max(1, (int)ceil(($total ?? 0) / $perPage));
$offset = ($page - 1) * $perPage;

/* Rows + stats */
$sql = "
  SELECT h.id, h.name, h.address, h.archived_at,
         (SELECT COUNT(*) FROM floors f WHERE f.house_id = h.id) AS floors_count,
         (SELECT COUNT(*) FROM rooms r JOIN floors f ON r.floor_id=f.id WHERE f.house_id = h.id) AS rooms_count,
         (SELECT COUNT(*) FROM rooms r JOIN floors f ON r.floor_id=f.id WHERE f.house_id = h.id AND r.status='vacant') AS vacant_count,
         (SELECT COUNT(*) FROM rooms r JOIN floors f ON r.floor_id=f.id WHERE f.house_id = h.id AND r.status='occupied') AS occupied_count
  FROM houses h
  WHERE $where
  ORDER BY h.archived_at DESC, h.id DESC
  LIMIT ?, ?";

$stmt = $conn->prepare($sql);
if ($types) {
  $args = $params; $args[] = $offset; $args[] = $perPage;
  $stmt->bind_param($types . "ii", ...$args);
} else {
  $stmt->bind_param("ii", $offset, $perPage);
}
$stmt->execute();
$res  = $stmt->get_result();
$rows = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Archived Houses - Padroom</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="<?= $BASE ?>/../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="<?= $BASE ?>/../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="<?= $BASE ?>/../assets/css/style.css" rel="stylesheet">
  <style>
    :root{
      --pad-primary:#361E5C; --pad-accent:#6141A6; --pad-deep:#2A184B; --pad-text:#FFF;
      --chip1:#6f42c1; --chip2:#20c997; --chip3:#17a2b8; --chip4:#dc3545;
    }
    .main{ margin-top:60px; padding:20px 20px 0 20px; transition:all .3s; }
    @media (min-width: 992px){ .main{ margin-left:250px; } }

    /* Gradient buttons */
    .btn-grad{
      background: linear-gradient(90deg, var(--pad-primary), var(--pad-accent));
      color:#fff; border:none; border-radius:.55rem;
      box-shadow:0 6px 18px rgba(54,30,92,.25);
    }
    .btn-grad:hover{ opacity:.95; color:#fff; }
    .btn-grad-danger{
      background: linear-gradient(90deg, #ff6b6b, var(--chip4));
      color:#fff; border:none; border-radius:.55rem;
      box-shadow:0 6px 18px rgba(220,53,69,.25);
    }
    .btn-grad-danger:hover{ opacity:.95; color:#fff; }
    .btn-grad:disabled, .btn-grad-danger:disabled{ opacity:.55; cursor:not-allowed; }

    /* Table look */
    .card-v{ background:#fff; border-radius:12px; box-shadow:0 10px 24px rgba(0,0,0,.08); border:1px solid #eee; }
    .card-v .card-header{ background:#fff; border-bottom:1px solid rgba(0,0,0,.06); }
    .title-v{ color:#2D1B4E; font-weight:700; }
    .search-wrap{ max-width:520px; margin:0 auto; }

    .table thead th{ font-weight:600; }
    .chip{
      display:inline-flex; align-items:center; gap:.35rem; padding:.12rem .45rem;
      border-radius:999px; font-size:.78rem; font-weight:700; color:#fff;
      box-shadow:0 6px 16px rgba(0,0,0,.08); white-space:nowrap;
    }
    .chip-floors{ background:linear-gradient(90deg, var(--chip1), var(--pad-accent)); }
    .chip-rooms{  background:linear-gradient(90deg, var(--chip2), var(--chip3)); }
    .chip-vac{    background:linear-gradient(90deg, #28a745, var(--chip2)); }
    .chip-occ{    background:linear-gradient(90deg, #ff6b6b, var(--chip4)); }
    .addr{ max-width:420px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  </style>
</head>
<body class="d-flex flex-column min-vh-100">
  <?php include __DIR__ . '/../admin/topbar.php'; ?>
  <?php include __DIR__ . '/../admin/sidebar.php'; ?>

  <main id="main" class="main flex-grow-1">
    <div class="pagetitle mb-3 text-center">
      <h1 class="h4 mb-1">Houses</h1>
      <nav>
        <ol class="breadcrumb justify-content-center">
          <li class="breadcrumb-item"><a href="<?= $BASE ?>/dashboard.php">Dashboard</a></li>
          <li class="breadcrumb-item">House</li>
          <li class="breadcrumb-item active">Archives</li>
        </ol>
      </nav>
    </div>

    <?php if ($schema_notice): ?><div class="alert alert-info text-center"><?= htmlspecialchars($schema_notice) ?></div><?php endif; ?>
    <?php if ($msg): ?><div class="alert alert-success text-center"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger text-center"><?= htmlspecialchars($err) ?></div><?php endif; ?>

    <div class="card card-v">
      <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
        <h2 class="h5 mb-0 title-v"><i class="bi bi-archive me-2"></i>Archived Houses</h2>
        <form class="search-wrap w-100 w-md-auto" method="get">
          <div class="input-group">
            <input type="text" class="form-control" name="q" placeholder="Search name or address…" value="<?= htmlspecialchars($q) ?>">
            <button class="btn btn-grad" type="submit"><i class="bi bi-search"></i></button>
          </div>
        </form>
      </div>

      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th style="width:50px;">#</th>
                <th>Name</th>
                <th>Address</th>
                <th>Floors</th>
                <th>Rooms</th>
                <th>Vacant</th>
                <th>Occupied</th>
                <th>Archived On</th>
                <th class="text-end" style="width:210px;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($rows)): ?>
                <tr><td colspan="9" class="text-center text-muted py-4">No archived houses.</td></tr>
              <?php else: ?>
                <?php foreach ($rows as $i => $h): ?>
                  <tr>
                    <td><?= ($offset + $i + 1) ?></td>
                    <td class="fw-semibold"><?= htmlspecialchars($h['name']) ?></td>
                    <td><div class="addr"><i class="bi bi-geo-alt me-1 text-muted"></i><?= htmlspecialchars($h['address'] ?: '—') ?></div></td>
                    <td><span class="chip chip-floors"><i class="bi bi-building me-1"></i><?= (int)$h['floors_count'] ?></span></td>
                    <td><span class="chip chip-rooms"><i class="bi bi-door-closed me-1"></i><?= (int)$h['rooms_count'] ?></span></td>
                    <td><span class="chip chip-vac"><i class="bi bi-check2-circle me-1"></i><?= (int)$h['vacant_count'] ?></span></td>
                    <td><span class="chip chip-occ"><i class="bi bi-person-fill-lock me-1"></i><?= (int)$h['occupied_count'] ?></span></td>
                    <td><?= $h['archived_at'] ? date('M d, Y', strtotime($h['archived_at'])) : '—' ?></td>
                    <td class="text-end">
                      <form method="post" class="d-inline">
                        <input type="hidden" name="action" value="restore">
                        <input type="hidden" name="house_id" value="<?= (int)$h['id'] ?>">
                        <button class="btn btn-grad btn-sm me-1" type="submit">
                          <i class="bi bi-arrow-counterclockwise me-1"></i>Restore
                        </button>
                      </form>
                      <form method="post" class="d-inline" onsubmit="return confirm('Permanently delete this house and all its floors/rooms? This cannot be undone.');">
                        <input type="hidden" name="action" value="purge">
                        <input type="hidden" name="house_id" value="<?= (int)$h['id'] ?>">
                        <button class="btn btn-grad-danger btn-sm"
                                type="submit"
                                title="<?= $h['occupied_count']>0 ? 'Cannot delete while rooms are occupied' : 'Delete permanently' ?>"
                                <?= $h['occupied_count']>0 ? 'disabled' : '' ?>>
                          <i class="bi bi-trash me-1"></i>Delete
                        </button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <?php if ($pages > 1): ?>
      <div class="card-footer">
        <nav>
          <ul class="pagination justify-content-center mb-0">
            <?php
              $mk = function($p) use ($q) {
                $prm = ['page'=>$p];
                if ($q !== '') $prm['q'] = $q;
                return '?' . http_build_query($prm);
              };
            ?>
            <li class="page-item <?= $page<=1?'disabled':'' ?>">
              <a class="page-link" href="<?= $mk(max(1,$page-1)) ?>">Prev</a>
            </li>
            <?php for ($p=1;$p<=$pages;$p++): ?>
              <li class="page-item <?= $p==$page?'active':'' ?>">
                <a class="page-link" href="<?= $mk($p) ?>"><?= $p ?></a>
              </li>
            <?php endfor; ?>
            <li class="page-item <?= $page>=$pages?'disabled':'' ?>">
              <a class="page-link" href="<?= $mk(min($pages,$page+1)) ?>">Next</a>
            </li>
          </ul>
        </nav>
      </div>
      <?php endif; ?>
    </div>
  </main>

  <?php include __DIR__ . '/../admin/footer.php'; ?>
  <script src="<?= $BASE ?>/../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>
