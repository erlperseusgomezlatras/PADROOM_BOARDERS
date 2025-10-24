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
$perPage = 12;

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
    $args = $params;
    $args[] = $offset;
    $args[] = $perPage;
    $stmt->bind_param($types . "ii", ...$args);
  } else {
    $stmt->bind_param("ii", $offset, $perPage);
  }
  $stmt->execute();
  $res  = $stmt->get_result();
  $rows = $res->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
}

function full_name($t){
  return $t['last_name'].', '.$t['first_name'].(
    empty($t['middle_name'])?'':' '.strtoupper(substr($t['middle_name'],0,1)).'.'
  );
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
      --ink:#2D1B4E;
      --muted:#6c757d;
    }
    .main{ margin-top:60px; padding:24px 16px 28px 16px; transition:all .3s; }
    @media (min-width: 992px){ .main{ margin-left:250px; } }

    /* Page wrap center */
    .wrap{ max-width: 1200px; margin: 0 auto; }

    /* Header */
    .pagetitle h1{ color: var(--ink); }

    /* Search toolbar */
    .toolbar{ border-radius:14px; box-shadow:0 6px 20px rgba(0,0,0,.06); border:1px solid rgba(0,0,0,.06); }
    .toolbar .card-body{ padding:14px 16px; }

    /* Card grid */
    .grid{ display:grid; gap:16px; grid-template-columns: repeat(1, minmax(0,1fr)); }
    @media (min-width:576px){ .grid{ grid-template-columns: repeat(2, minmax(0,1fr)); } }
    @media (min-width:992px){ .grid{ grid-template-columns: repeat(3, minmax(0,1fr)); } }

    .tenant-card{
      border:1px solid rgba(0,0,0,.06);
      border-radius:14px;
      background:#fff;
      box-shadow:0 6px 18px rgba(0,0,0,.06);
      transition: box-shadow .25s ease, transform .25s ease;
      overflow:hidden;
    }
    .tenant-card:hover{ box-shadow:0 10px 26px rgba(0,0,0,.10); transform: translateY(-2px); }
    .tenant-card .card-body{ padding:16px 18px; }

    .name{ font-weight:700; color:var(--ink); }
    .meta{ color:var(--muted); }
    .chip{
      display:inline-flex; align-items:center; gap:.35rem;
      border:1px solid rgba(0,0,0,.08);
      padding:.22rem .55rem; border-radius:999px; font-size:.82rem; background:#fff;
    }
    .addr{ color: var(--muted); font-size:.9rem; }

    /* Buttons */
    .gradient-btn{
      background: linear-gradient(90deg, var(--pad-primary), var(--pad-accent));
      color:#fff; border:none; border-radius:8px;
      font-size:.85rem; padding:.38rem .8rem; transition:all .18s ease;
    }
    .gradient-btn:hover{ opacity:.92; transform: translateY(-1px); }
    .btn-outline-gradient{
      background:#fff; color:var(--pad-accent);
      border:1px solid var(--pad-accent); border-radius:8px;
      font-size:.85rem; padding:.38rem .8rem; transition:all .18s ease;
    }
    .btn-outline-gradient:hover{ background: linear-gradient(90deg, var(--pad-primary), var(--pad-accent)); color:#fff; transform: translateY(-1px); }

    /* Actions row */
    .actions{ display:flex; gap:.5rem; justify-content:flex-end; }

    /* Pagination spacing */
    .pager-wrap{ margin-top:18px; }
  </style>
</head>
<body class="d-flex flex-column min-vh-100">
  <?php include __DIR__ . '/../admin/topbar.php'; ?>
  <?php include __DIR__ . '/../admin/sidebar.php'; ?>

  <main id="main" class="main flex-grow-1">
    <div class="wrap">
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

      <!-- Search toolbar -->
      <div class="card toolbar mb-3">
        <div class="card-body">
          <form class="row g-2 justify-content-center" method="get">
            <div class="col-12 col-md-8">
              <input type="text" class="form-control" name="q" placeholder="Search name or contact…" value="<?= htmlspecialchars($q) ?>">
            </div>
            <div class="col-12 col-md-auto">
              <button class="btn btn-outline-gradient"><i class="bi bi-search me-1"></i>Search</button>
            </div>
          </form>
        </div>
      </div>

      <!-- Card grid -->
      <?php if (empty($rows)): ?>
        <div class="alert alert-light border text-center">No tenants found.</div>
      <?php else: ?>
        <div class="grid">
          <?php foreach ($rows as $t): ?>
            <div class="tenant-card">
              <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                  <div>
                    <div class="name mb-1"><i class="bi bi-person-circle me-1"></i><?= htmlspecialchars(full_name($t)) ?></div>
                    <div class="d-flex flex-wrap gap-1 mb-2">
                      <span class="chip"><i class="bi bi-gender-ambiguous"></i><?= htmlspecialchars($t['gender']) ?></span>
                      <span class="chip"><i class="bi bi-cake2"></i><?= (int)$t['age'] ?> yrs</span>
                      <?php if (!empty($t['civil_status'])): ?>
                        <span class="chip"><i class="bi bi-heart"></i><?= htmlspecialchars($t['civil_status']) ?></span>
                      <?php endif; ?>
                      <span class="chip"><i class="bi bi-telephone"></i><?= htmlspecialchars($t['contact_no']) ?></span>
                      <span class="chip"><i class="bi bi-calendar3"></i><?= date('M d, Y', strtotime($t['created_at'])) ?></span>
                    </div>
                    <?php if (!empty($t['address'])): ?>
                      <div class="addr"><i class="bi bi-geo-alt me-1"></i>
                        <?= htmlspecialchars($t['address']) ?>
                      </div>
                    <?php endif; ?>
                  </div>

                  <!-- Actions -->
                  <div class="actions">
                    <button class="gradient-btn"
                            data-bs-toggle="modal"
                            data-bs-target="#editModal"
                            data-tenant='<?= json_encode($t, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>'
                            title="Edit">
                      <i class="bi bi-pencil-square"></i>
                    </button>
                    <form method="post" onsubmit="return confirm('Archive this tenant?');">
                      <input type="hidden" name="action" value="archive">
                      <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                      <button class="btn-outline-gradient" title="Archive">
                        <i class="bi bi-archive"></i>
                      </button>
                    </form>
                  </div>
                </div>

                <?php if (!empty($t['emergency_name']) || !empty($t['emergency_contact'])): ?>
                  <hr class="my-3">
                  <div class="meta small">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    <strong>Emergency:</strong>
                    <?= htmlspecialchars($t['emergency_name'] ?: '—') ?> —
                    <?= htmlspecialchars($t['emergency_contact'] ?: '—') ?>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <!-- Pagination -->
        <?php if ($pages > 1): ?>
          <div class="pager-wrap">
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
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn gradient-btn">Save changes</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script>
    // Fill edit modal
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
