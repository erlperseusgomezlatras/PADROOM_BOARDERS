<?php
require_once __DIR__ . '/../inclusions/require_login.php';
require_once __DIR__ . '/../inclusions/connection.php';

$BASE = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$msg = "";
$err = "";
$schema_notice = "";

/** 🧰 Ensure archive columns exist **/
function ensure_archive_columns(mysqli $conn, &$schema_notice) {
  $sql = "SELECT COUNT(*) 
            FROM INFORMATION_SCHEMA.COLUMNS 
           WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = 'tenants'
             AND COLUMN_NAME = 'is_archived'";
  $res = $conn->query($sql);
  $need = true;
  if ($res) { $row = $res->fetch_row(); $need = ((int)$row[0] === 0); $res->close(); }

  if ($need) {
    $alter = "ALTER TABLE tenants 
                ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER address,
                ADD COLUMN archived_at DATETIME NULL AFTER is_archived";
    if ($conn->query($alter)) {
      $schema_notice = "Archive fields were missing and have been added (is_archived, archived_at).";
    } else {
      $schema_notice = "Failed to add archive fields: " . $conn->error;
    }
  }
}
ensure_archive_columns($conn, $schema_notice);

/* ---------- Actions: restore, purge ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  $id = (int)($_POST['id'] ?? 0);

  if ($id > 0) {
    if ($action === 'restore') {
      $stmt = $conn->prepare("UPDATE tenants SET is_archived = 0, archived_at = NULL WHERE id = ?");
      $stmt->bind_param("i", $id);
      if ($stmt->execute()) $msg = "Tenant restored successfully.";
      else $err = "Restore failed: " . $conn->error;
      $stmt->close();
    } elseif ($action === 'purge') {
      $stmt = $conn->prepare("DELETE FROM tenants WHERE id = ?");
      $stmt->bind_param("i", $id);
      if ($stmt->execute()) $msg = "Tenant permanently deleted.";
      else $err = "Delete failed: " . $conn->error;
      $stmt->close();
    }
  }
}

/* ---------- Search + pagination ---------- */
$q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;

$where = "is_archived = 1";
$params = [];
$types  = "";

if ($q !== '') {
  $where .= " AND (CONCAT(first_name,' ',last_name) LIKE ? OR CONCAT(last_name,' ',first_name) LIKE ? OR contact_no LIKE ?)";
  $like = "%$q%";
  $params = [$like, $like, $q.'%'];
  $types  = "sss";
}

/* Count */
$sqlCount = "SELECT COUNT(*) FROM tenants WHERE $where";
$stmt = $conn->prepare($sqlCount);
if ($types) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$stmt->bind_result($total);
$stmt->fetch();
$stmt->close();

$pages = max(1, (int)ceil(($total ?? 0) / $perPage));
$offset = ($page - 1) * $perPage;

/* Rows */
$sql = "SELECT id, first_name, middle_name, last_name, gender, age, civil_status, contact_no, address, created_at, archived_at
        FROM tenants
        WHERE $where
        ORDER BY archived_at DESC, created_at DESC
        LIMIT ?, ?";
$stmt = $conn->prepare($sql);
if ($types) {
  $args = $params;
  $args[] = $offset;
  $args[] = $perPage;
  $stmt->bind_param($types . "ii", ...$args);
} else {
  $stmt->bind_param("ii", $offset, $perPage);
}
$stmt->execute();
$res = $stmt->get_result();
$rows = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Archived Tenants - Padroom</title>
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
    }
    .main{ margin-top:60px; padding:20px 20px 0 20px; transition:all .3s; }
    @media (min-width: 992px){ .main{ margin-left:250px; } }

    .card-v{ background:#fff; border-radius:12px; box-shadow:0 10px 24px rgba(0,0,0,.08); }
    .card-v .card-header{ background:#fff; border-bottom:1px solid rgba(0,0,0,.06); }
    .title-v{ color:#2D1B4E; font-weight:600; }

    .table thead th{ font-weight:600; }
    .actions .btn{ padding:.25rem .5rem; }
    .search-wrap{ max-width:520px; margin:0 auto; }
    .small-muted{ color:#6c757d; }

    .gradient-btn {
      background: linear-gradient(90deg, var(--pad-primary), var(--pad-accent));
      color:#fff;
      border:none;
      border-radius:.4rem;
      box-shadow:0 6px 18px rgba(54,30,92,.25);
    }
    .gradient-btn:hover { opacity:.9; color:#fff; }

    .btn-outline-gradient {
      background:transparent;
      border:1px solid var(--pad-accent);
      color:var(--pad-accent);
      border-radius:.4rem;
    }
    .btn-outline-gradient:hover {
      background: linear-gradient(90deg, var(--pad-primary), var(--pad-accent));
      color:#fff;
    }

    .form-control:focus, .form-select:focus {
      border-color: #6141A6;
      box-shadow: 0 0 0 .2rem rgba(97,65,166,.15);
    }
  </style>
</head>
<body class="d-flex flex-column min-vh-100">
  <?php include __DIR__ . '/../admin/topbar.php'; ?>
  <?php include __DIR__ . '/../admin/sidebar.php'; ?>

  <main id="main" class="main flex-grow-1">
    <div class="pagetitle mb-3 text-center">
      <h1 class="h4 mb-1">Tenants</h1>
      <nav>
        <ol class="breadcrumb justify-content-center">
          <li class="breadcrumb-item"><a href="<?= $BASE ?>/dashboard.php">Dashboard</a></li>
          <li class="breadcrumb-item active">Archives</li>
        </ol>
      </nav>
    </div>

    <?php if ($schema_notice): ?><div class="alert alert-info text-center"><?= htmlspecialchars($schema_notice) ?></div><?php endif; ?>
    <?php if ($msg): ?><div class="alert alert-success text-center"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger text-center"><?= htmlspecialchars($err) ?></div><?php endif; ?>

    <div class="card card-v">
      <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
        <h2 class="h5 mb-0 title-v"><i class="bi bi-archive me-2"></i>Archived Tenants</h2>
        <form class="search-wrap w-100 w-md-auto" method="get">
          <div class="input-group">
            <input type="text" class="form-control" name="q" placeholder="Search name or contact…" value="<?= htmlspecialchars($q) ?>">
            <button class="btn btn-outline-gradient" type="submit"><i class="bi bi-search"></i></button>
          </div>
        </form>
      </div>

      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th style="width:46px;">#</th>
                <th>Name</th>
                <th>Contact</th>
                <th>Gender</th>
                <th>Age</th>
                <th>Created</th>
                <th>Archived</th>
                <th class="text-end" style="width:180px;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($rows)): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">No archived tenants.</td></tr>
              <?php else: ?>
                <?php foreach ($rows as $i => $t): ?>
                  <tr>
                    <td><?= ($offset + $i + 1) ?></td>
                    <td>
                      <div class="fw-semibold">
                        <?= htmlspecialchars($t['last_name'] . ', ' . $t['first_name'] . ($t['middle_name'] ? ' ' . strtoupper(substr($t['middle_name'],0,1)).'.' : '')) ?>
                      </div>
                      <?php if ($t['address']): ?>
                        <div class="small-muted small overflow-hidden" style="max-width:420px; text-overflow:ellipsis; white-space:nowrap;">
                          <?= htmlspecialchars($t['address']) ?>
                        </div>
                      <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($t['contact_no']) ?></td>
                    <td><?= htmlspecialchars($t['gender']) ?></td>
                    <td><?= (int)$t['age'] ?></td>
                    <td><?= date('M d, Y', strtotime($t['created_at'])) ?></td>
                    <td><?= $t['archived_at'] ? date('M d, Y', strtotime($t['archived_at'])) : '-' ?></td>
                    <td class="text-end actions">
                      <form method="post" class="d-inline">
                        <input type="hidden" name="action" value="restore">
                        <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                        <button class="btn btn-sm gradient-btn"><i class="bi bi-arrow-counterclockwise"></i> Restore</button>
                      </form>
                      <form method="post" class="d-inline" onsubmit="return confirm('Permanently delete this tenant? This cannot be undone.');">
                        <input type="hidden" name="action" value="purge">
                        <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                        <button class="btn btn-sm btn-outline-gradient"><i class="bi bi-trash"></i></button>
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
                $params = ['page'=>$p];
                if ($q !== '') $params['q'] = $q;
                return '?' . http_build_query($params);
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
