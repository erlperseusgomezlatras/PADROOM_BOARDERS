<?php
require_once __DIR__ . '/../inclusions/require_login.php';
require_once __DIR__ . '/../inclusions/connection.php';

$BASE = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

$msg = "";
$err = "";

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

  if ($first_name && $last_name && $gender && $age > 0 && $contact_no) {
    $stmt = $conn->prepare("
      INSERT INTO tenants
        (first_name, middle_name, last_name, gender, age, civil_status,
         contact_no, emergency_name, emergency_contact, address, created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?, NOW())
    ");
    $stmt->bind_param(
      'ssssisssss',
      $first_name, $middle_name, $last_name, $gender, $age, $civil_status,
      $contact_no, $emergency_name, $emergency_contact, $address
    );
    if ($stmt->execute()) {
      $msg = "Tenant successfully added!";
    } else {
      $err = "Error saving tenant: " . $conn->error;
    }
    $stmt->close();
  } else {
    $err = "Please fill in all required fields.";
  }
}

// Fetch recent tenants (latest 10)
$recent = [];
if ($res = $conn->query("SELECT id, first_name, middle_name, last_name, created_at FROM tenants ORDER BY created_at DESC LIMIT 10")) {
  while ($row = $res->fetch_assoc()) $recent[] = $row;
  $res->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Create Tenant - Padroom</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="<?= $BASE ?>/../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="<?= $BASE ?>/../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="<?= $BASE ?>/../assets/css/style.css" rel="stylesheet">
  <style>
    :root{
      --pad-primary:#361E5C;
      --pad-accent:#6141A6;
      --pad-deep:#2A184B;
      --pad-text:#FFFFFF;
    }
    .main{ margin-top:60px; padding:20px 20px 0 20px; transition:all .3s; }
    @media (min-width: 992px){ .main{ margin-left:250px; } }

    .grid-wrap{ max-width: 1100px; margin: 0 auto; }
    .card-v{ background:#fff; border-radius:12px; box-shadow:0 10px 24px rgba(0,0,0,.08); }
    .card-v .card-header{ background:#fff; border-bottom:1px solid rgba(0,0,0,.06); }
    .title-v{ color:#2D1B4E; font-weight:600; }

    /* Recent list tweaks */
    .recent-item{ display:flex; justify-content:space-between; align-items:center; padding:.6rem .2rem; border-bottom:1px dashed rgba(0,0,0,.08); }
    .recent-item:last-child{ border-bottom:none; }
    .recent-name{ font-weight:500; }
    .recent-date{ color:#6c757d; font-size:.9rem; }

    /* Buttons: gradient theme */
    .gradient-btn {
      background: linear-gradient(90deg, var(--pad-primary), var(--pad-accent));
      color:#fff; border:none; border-radius:.5rem;
      box-shadow:0 6px 18px rgba(54,30,92,.25);
    }
    .gradient-btn:hover { opacity:.9; color:#fff; }
    .btn-outline-gradient {
      background:transparent;
      border:1px solid var(--pad-accent);
      color:var(--pad-accent);
      border-radius:.5rem;
    }
    .btn-outline-gradient:hover {
      background: linear-gradient(90deg, var(--pad-primary), var(--pad-accent));
      color:#fff;
    }

    /* Submit button center on small screens */
    .form-actions{ text-align:center; }

    /* Optional: nicer input focus */
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
    <div class="pagetitle mb-4 text-center">
      <h1 class="h4 mb-1">Tenant Management</h1>
      <nav>
        <ol class="breadcrumb justify-content-center">
          <li class="breadcrumb-item"><a href="<?= $BASE ?>/dashboard.php">Dashboard</a></li>
          <li class="breadcrumb-item">Tenants</li>
          <li class="breadcrumb-item active">Create</li>
        </ol>
      </nav>
    </div>

    <?php if ($msg): ?><div class="alert alert-success text-center"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger text-center"><?= htmlspecialchars($err) ?></div><?php endif; ?>

    <div class="grid-wrap">
      <div class="row g-4">
        <!-- Form -->
        <div class="col-lg-7">
          <div class="card card-v">
            <div class="card-header d-flex align-items-center">
              <h2 class="h5 mb-0 title-v"><i class="bi bi-person-plus me-2"></i>Add New Tenant</h2>
            </div>
            <div class="card-body">
              <form method="POST" autocomplete="off">
                <div class="row g-3">
                  <div class="col-md-4">
                    <label class="form-label">First Name <span class="text-danger">*</span></label>
                    <input type="text" name="first_name" class="form-control" required>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">Middle Name</label>
                    <input type="text" name="middle_name" class="form-control">
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">Last Name <span class="text-danger">*</span></label>
                    <input type="text" name="last_name" class="form-control" required>
                  </div>

                  <div class="col-md-4">
                    <label class="form-label">Gender <span class="text-danger">*</span></label>
                    <select name="gender" class="form-select" required>
                      <option value="">-- Select --</option>
                      <option>Male</option>
                      <option>Female</option>
                      <option>Other</option>
                    </select>
                  </div>

                  <div class="col-md-4">
                    <label class="form-label">Age <span class="text-danger">*</span></label>
                    <input type="number" name="age" class="form-control" min="1" required>
                  </div>

                  <div class="col-md-4">
                    <label class="form-label">Civil Status</label>
                    <select name="civil_status" class="form-select">
                      <option value="">-- Select --</option>
                      <option>Single</option>
                      <option>Married</option>
                      <option>Widowed</option>
                      <option>Divorced</option>
                    </select>
                  </div>

                  <div class="col-md-6">
                    <label class="form-label">Contact No. <span class="text-danger">*</span></label>
                    <input type="text" name="contact_no" class="form-control" required>
                  </div>

                  <div class="col-md-6">
                    <label class="form-label">Emergency Contact Name</label>
                    <input type="text" name="emergency_name" class="form-control">
                  </div>

                  <div class="col-md-6">
                    <label class="form-label">Emergency Contact No.</label>
                    <input type="text" name="emergency_contact" class="form-control">
                  </div>

                  <div class="col-12">
                    <label class="form-label">Address</label>
                    <textarea name="address" class="form-control" rows="2"></textarea>
                  </div>

                  <div class="col-12 form-actions mt-2">
                    <button type="submit" class="btn gradient-btn px-4">
                      <i class="bi bi-check2-circle me-1"></i> Save Tenant
                    </button>
                    <button type="reset" class="btn btn-outline-gradient px-4 ms-2">
                    <i class="bi bi-eraser me-1"></i> Clear
                    </button>
                  </div>
                  
                </div>
              </form>
            </div>
          </div>
        </div>

        <!-- Recently Added -->
        <div class="col-lg-5">
          <div class="card card-v">
            <div class="card-header d-flex align-items-center justify-content-between">
              <h2 class="h5 mb-0 title-v"><i class="bi bi-clock-history me-2"></i>Recently Added</h2>
              <!-- optional refresh without adding another button; list updates on submit anyway -->
            </div>
            <div class="card-body">
              <?php if (empty($recent)): ?>
                <p class="text-muted mb-0">No tenants added yet.</p>
              <?php else: ?>
                <?php foreach ($recent as $t): ?>
                  <div class="recent-item">
                    <div class="recent-name">
                      <?= htmlspecialchars($t['last_name'] . ', ' . $t['first_name'] . ($t['middle_name'] ? ' ' . strtoupper(substr($t['middle_name'],0,1)).'.' : '')) ?>
                    </div>
                    <div class="recent-date">
                      <?= date('M d, Y', strtotime($t['created_at'])) ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>

  <?php include __DIR__ . '/../admin/footer.php'; ?>
  <script src="<?= $BASE ?>/../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>
