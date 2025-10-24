<?php
// admin/tenants_manage.php
require_once __DIR__ . '/../inclusions/require_login.php';
require_once __DIR__ . '/../inclusions/connection.php';

$BASE = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$msg = "";
$err = "";
$schema_notice = "";

/* ------------------------------------------------------------------
   Ensure tenants has archive columns (is_archived, archived_at)
-------------------------------------------------------------------*/
function ensure_archive_columns(mysqli $conn, &$schema_notice) {
  $need = true;
  if ($res = $conn->query("
      SELECT COUNT(*)
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'tenants'
        AND COLUMN_NAME = 'is_archived'
  ")) {
    $row  = $res->fetch_row();
    $need = ((int)$row[0] === 0);
    $res->close();
  }
  if ($need) {
    $alter = "ALTER TABLE tenants
                ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER address,
                ADD COLUMN archived_at DATETIME NULL AFTER is_archived";
    if ($conn->query($alter)) {
      $schema_notice = "Archive fields added automatically.";
    } else {
      $schema_notice = "Failed to add archive fields: " . $conn->error;
    }
  }
}
ensure_archive_columns($conn, $schema_notice);

/* ------------------------------------------------------------------
   UPDATE (Edit modal)
-------------------------------------------------------------------*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
  $id                = (int)($_POST['id'] ?? 0);
  $first_name        = trim($_POST['first_name'] ?? '');
  $middle_name       = trim($_POST['middle_name'] ?? '');
  $last_name         = trim($_POST['last_name'] ?? '');
  $gender            = trim($_POST['gender'] ?? '');
  $age               = (int)($_POST['age'] ?? 0);
  $civil_status      = trim($_POST['civil_status'] ?? '');
  $contact_no        = trim($_POST['contact_no'] ?? '');
  $emergency_name    = trim($_POST['emergency_name'] ?? '');
  $emergency_contact = trim($_POST['emergency_contact'] ?? '');
  $address           = trim($_POST['address'] ?? '');

  if ($id && $first_name && $last_name && $gender && $age > 0 && $contact_no) {
    $stmt = $conn->prepare("
      UPDATE tenants SET
        first_name=?, middle_name=?, last_name=?,
        gender=?, age=?, civil_status=?, contact_no=?,
        emergency_name=?, emergency_contact=?, address=?
      WHERE id=? AND is_archived=0
    ");
    if ($stmt) {
      $stmt->bind_param(
        'ssssisssssi',
        $first_name, $middle_name, $last_name,
        $gender, $age, $civil_status, $contact_no,
        $emergency_name, $emergency_contact, $address, $id
      );
      if ($stmt->execute()) {
        $msg = "Tenant updated successfully.";
      } else {
        $err = "Update failed: " . $conn->error;
      }
      $stmt->close();
    } else {
      $err = "Update failed (prep): " . $conn->error;
    }
  } else {
    $err = "Please complete required fields for update.";
  }
}

/* ------------------------------------------------------------------
   ARCHIVE (blocked if tenant has an active rental)
-------------------------------------------------------------------*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'archive') {
  $id = (int)($_POST['id'] ?? 0);
  if ($id) {
    // Block archive if the tenant has any active renter record
    $active = 0;
    if ($chk = $conn->prepare("SELECT COUNT(*) FROM renters WHERE tenant_id=? AND status='active'")) {
      $chk->bind_param('i', $id);
      $chk->execute();
      $chk->bind_result($active);
      $chk->fetch();
      $chk->close();
    }
    if ($active > 0) {
      $err = "Cannot archive: tenant has an active rental.";
    } else {
      $stmt = $conn->prepare("UPDATE tenants SET is_archived=1, archived_at=NOW() WHERE id=?");
      if ($stmt) {
        $stmt->bind_param('i', $id);
        if ($stmt->execute()) {
          $msg = "Tenant archived successfully.";
        } else {
          $err = "Archive failed: " . $conn->error;
        }
        $stmt->close();
      } else {
        $err = "Archive failed (prep): " . $conn->error;
      }
    }
  }
}

/* ------------------------------------------------------------------
   Listing with search + pagination (only non-archived)
-------------------------------------------------------------------*/
$q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;

$where  = "is_archived = 0";
$params = [];
$types  = "";

if ($q !== '') {
  $where .= " AND (CONCAT(first_name,' ',last_name) LIKE ? OR CONCAT(last_name,' ',first_name) LIKE ? OR contact_no LIKE ?)";
  $like   = "%$q%";
  $params = [$like, $like, $q . '%'];
  $types  = "sss";
}

/* Count */
$total = 0;
$sqlCount = "SELECT COUNT(*) FROM tenants WHERE $where";
if ($stmt = $conn->prepare($sqlCount)) {
  if ($types) { $stmt->bind_param($types, ...$params); }
  $stmt->execute();
  $stmt->bind_result($total);
  $stmt->fetch();
  $stmt->close();
}
$pages  = max(1, (int)ceil($total / $perPage));
$offset = ($page - 1) * $perPage;

/* Fetch rows */
$rows = [];
$sql = "SELECT id, first_name, middle_name, last_name, gender, age, civil_status, contact_no,
               emergency_name, emergency_contact, address, created_at
        FROM tenants
        WHERE $where
        ORDER BY created_at DESC
        LIMIT ?, ?";
if ($stmt = $conn->prepare($sql)) {
  if ($types) {
    $args = $params;           // ['like','like','like']
    $args[] = $offset;         // + offset
    $args[] = $perPage;        // + limit
    $stmt->bind_param($types . "ii", ...$args);
  } else {
    $stmt->bind_param("ii", $offset, $perPage);
  }
  $stmt->execute();
  $res  = $stmt->get_result();
  $rows = $res->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Manage Tenants - Padroom</title>
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

    .gradient-btn{
      background: linear-gradient(90deg, var(--pad-primary), var(--pad-accent));
      color:#fff; border:none; border-radius:.5rem;
      box-shadow:0 6px 18px rgba(54,30,92,.25);
    }
    .gradient-btn:hover{ opacity:.9; color:#fff; }
    .btn-outline-gradient{
      background:transparent; border:1px solid var(--pad-accent);
      color:var(--pad-accent); border-radius:.5rem;
    }
    .btn-outline-gradient:hover{
      background: linear-gradient(90deg, var(--pad-primary), var(--pad-accent)); color:#fff;
    }
    .actions .btn{ padding:.25rem .5rem; }
    .search-wrap{ max-width:520px; margin:0 auto; }
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
          <li class="breadcrumb-item active">Manage</li>
        </ol>
      </nav>
    </div>

    <?php if ($schema_notice): ?>
      <div class="alert alert-info text-center"><?= htmlspecialchars($schema_notice) ?></div>
    <?php endif; ?>
    <?php if ($msg): ?>
      <div class="alert alert-success text-center"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>
    <?php if ($err): ?>
      <div class="alert alert-danger text-center"><?= htmlspecialchars($err) ?></div>
    <?php endif; ?>

    <div class="card card-v">
      <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
        <h2 class="h5 mb-0 title-v"><i class="bi bi-people me-2"></i>Manage Tenants</h2>
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
                <th>Gender</th>
                <th>Age</th>
                <th>Contact</th>
                <th>Created</th>
                <th class="text-end" style="width:160px;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($rows)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No tenants found.</td></tr>
              <?php else: ?>
                <?php foreach ($rows as $i => $t): ?>
                  <tr>
                    <td><?= ($offset + $i + 1) ?></td>
                    <td>
                      <div class="fw-semibold">
                        <?= htmlspecialchars($t['last_name'] . ', ' . $t['first_name'] . ($t['middle_name'] ? ' ' . strtoupper(substr($t['middle_name'],0,1)).'.' : '')) ?>
                      </div>
                      <?php if ($t['address']): ?>
                        <div class="text-muted small overflow-hidden" style="max-width:420px; text-overflow:ellipsis; white-space:nowrap;">
                          <?= htmlspecialchars($t['address']) ?>
                        </div>
                      <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($t['gender']) ?></td>
                    <td><?= (int)$t['age'] ?></td>
                    <td><?= htmlspecialchars($t['contact_no']) ?></td>
                    <td><?= date('M d, Y', strtotime($t['created_at'])) ?></td>
                    <td class="text-end actions">
                      <button class="btn btn-sm gradient-btn"
                              data-bs-toggle="modal"
                              data-bs-target="#editModal"
                              data-tenant='<?= json_encode($t, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>'
                              title="Edit">
                        <i class="bi bi-pencil"></i>
                      </button>
                      <form method="post" class="d-inline" onsubmit="return confirm('Archive this tenant?');">
                        <input type="hidden" name="action" value="archive">
                        <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                        <button class="btn btn-sm btn-outline-gradient" title="Archive">
                          <i class="bi bi-archive"></i>
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

  <!-- Edit Modal -->
  <div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content">
        <form method="post" autocomplete="off">
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="id" id="edit-id">

          <div class="modal-header">
            <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Edit Tenant</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>

          <div class="modal-body">
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label">First Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="first_name" id="edit-first_name" required>
              </div>
              <div class="col-md-4">
                <label class="form-label">Middle Name</label>
                <input type="text" class="form-control" name="middle_name" id="edit-middle_name">
              </div>
              <div class="col-md-4">
                <label class="form-label">Last Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="last_name" id="edit-last_name" required>
              </div>

              <div class="col-md-4">
                <label class="form-label">Gender <span class="text-danger">*</span></label>
                <select class="form-select" name="gender" id="edit-gender" required>
                  <option>Male</option>
                  <option>Female</option>
                  <option>Other</option>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label">Age <span class="text-danger">*</span></label>
                <input type="number" class="form-control" name="age" id="edit-age" min="1" required>
              </div>
              <div class="col-md-4">
                <label class="form-label">Civil Status</label>
                <select class="form-select" name="civil_status" id="edit-civil_status">
                  <option value="">-- Select --</option>
                  <option>Single</option>
                  <option>Married</option>
                  <option>Widowed</option>
                  <option>Divorced</option>
                </select>
              </div>

              <div class="col-md-6">
                <label class="form-label">Contact No. <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="contact_no" id="edit-contact_no" required>
              </div>
              <div class="col-md-6">
                <label class="form-label">Emergency Contact Name</label>
                <input type="text" class="form-control" name="emergency_name" id="edit-emergency_name">
              </div>
              <div class="col-md-6">
                <label class="form-label">Emergency Contact No.</label>
                <input type="text" class="form-control" name="emergency_contact" id="edit-emergency_contact">
              </div>
              <div class="col-12">
                <label class="form-label">Address</label>
                <textarea class="form-control" rows="2" name="address" id="edit-address"></textarea>
              </div>
            </div>
          </div>

          <div class="modal-footer">
            <button type="button" class="btn btn-outline-gradient" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn gradient-btn">Save changes</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script>
    // Fill edit modal with row data
    const editModal = document.getElementById('editModal');
    editModal?.addEventListener('show.bs.modal', event => {
      const btn  = event.relatedTarget;
      const data = btn?.getAttribute('data-tenant');
      if (!data) return;
      const t = JSON.parse(data);

      document.getElementById('edit-id').value             = t.id;
      document.getElementById('edit-first_name').value     = t.first_name ?? '';
      document.getElementById('edit-middle_name').value    = t.middle_name ?? '';
      document.getElementById('edit-last_name').value      = t.last_name ?? '';
      document.getElementById('edit-gender').value         = t.gender ?? 'Male';
      document.getElementById('edit-age').value            = t.age ?? 0;
      document.getElementById('edit-civil_status').value   = t.civil_status ?? '';
      document.getElementById('edit-contact_no').value     = t.contact_no ?? '';
      document.getElementById('edit-emergency_name').value = t.emergency_name ?? '';
      document.getElementById('edit-emergency_contact').value = t.emergency_contact ?? '';
      document.getElementById('edit-address').value        = t.address ?? '';
    });
  </script>
</body>
</html>
