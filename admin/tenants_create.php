<?php
require_once __DIR__ . '/../inclusions/require_login.php';
require_once __DIR__ . '/../inclusions/connection.php';

$BASE = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

$msg = "";
$err = "";
$errors = [];
$old = $_POST ?? [];

function val($arr, $k){ return htmlspecialchars(trim($arr[$k] ?? ''), ENT_QUOTES, 'UTF-8'); }
function nonempty($s){ return isset($s) && trim($s) !== ''; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $first_name   = trim($_POST['first_name'] ?? '');
  $middle_name  = trim($_POST['middle_name'] ?? '');
  $last_name    = trim($_POST['last_name'] ?? '');
  $gender       = trim($_POST['gender'] ?? '');
  $age          = (int)($_POST['age'] ?? 0);
  $civil_status = trim($_POST['civil_status'] ?? '');
  $contact_no   = trim($_POST['contact_no'] ?? '');

  // PSGC address parts
  $region_code    = trim($_POST['region_code'] ?? '');
  $region_name    = trim($_POST['region_name'] ?? '');
  $province_code  = trim($_POST['province_code'] ?? '');
  $province_name  = trim($_POST['province_name'] ?? '');
  $city_code      = trim($_POST['city_code'] ?? '');
  $city_name      = trim($_POST['city_name'] ?? '');
  $brgy_code      = trim($_POST['brgy_code'] ?? '');
  $brgy_name      = trim($_POST['brgy_name'] ?? '');
  $street_details = trim($_POST['street_details'] ?? '');
  $zip_code       = trim($_POST['zip_code'] ?? '');

  $emergency_name    = trim($_POST['emergency_name'] ?? '');
  $emergency_contact = trim($_POST['emergency_contact'] ?? '');

  // Validation rules
  $nameRX   = '/^[a-zA-Z\s.\'-]{2,60}$/u';
  $phoneRX  = '/^09\d{9}$/';
  $genders  = ['Male','Female','Other'];
  $statuses = ['', 'Single','Married','Widowed','Divorced'];

  if ($first_name === '' || !preg_match($nameRX, $first_name)) $errors['first_name'] = "Enter a valid first name.";
  if ($middle_name !== '' && !preg_match($nameRX, $middle_name)) $errors['middle_name'] = "Middle name can only contain letters and spaces.";
  if ($last_name === '' || !preg_match($nameRX, $last_name)) $errors['last_name'] = "Enter a valid last name.";
  if (!in_array($gender, $genders, true)) $errors['gender'] = "Select a valid gender.";
  if ($age < 18 || $age > 120) $errors['age'] = "Age must be 18–120.";
  if (!in_array($civil_status, $statuses, true)) $errors['civil_status'] = "Select a valid civil status.";
  if ($contact_no === '' || !preg_match($phoneRX, $contact_no)) $errors['contact_no'] = "Use 11-digit PH mobile (09xxxxxxxxx).";

  // Address required
  if ($region_code==='' || $region_name==='')      $errors['region']   = "Select a region.";
  if ($province_code==='' || $province_name==='')  $errors['province'] = "Select a province.";
  if ($city_code==='' || $city_name==='')          $errors['city']     = "Select a city/municipality.";
  if ($brgy_code==='' || $brgy_name==='')          $errors['barangay'] = "Select a barangay.";

  if ($zip_code !== '' && !preg_match('/^\d{4}$/', $zip_code)) $errors['zip_code'] = "ZIP must be 4 digits.";

  // Emergency: both or none
  if (nonempty($emergency_name) || nonempty($emergency_contact)) {
    if (!nonempty($emergency_name) || !preg_match($nameRX, $emergency_name))
      $errors['emergency_name'] = "Enter a valid emergency contact name.";
    if (!nonempty($emergency_contact) || !preg_match($phoneRX, $emergency_contact))
      $errors['emergency_contact'] = "Enter a valid emergency mobile number.";
  }

  if (empty($errors)) {
    // Build address string
    $parts = [];
    if ($street_details !== '') $parts[] = $street_details;
    $parts[] = $brgy_name;
    $parts[] = $city_name;
    $parts[] = $province_name;
    $parts[] = $region_name;
    if ($zip_code !== '') $parts[] = $zip_code;
    $address = implode(', ', $parts);

    $stmt = $conn->prepare("
      INSERT INTO tenants
        (first_name, middle_name, last_name, gender, age, civil_status,
         contact_no, emergency_name, emergency_contact, address, created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?, NOW())
    ");
    if (!$stmt) {
      $err = "Error preparing statement: " . $conn->error;
    } else {
      $stmt->bind_param(
        'ssssisssss',
        $first_name, $middle_name, $last_name, $gender, $age, $civil_status,
        $contact_no, $emergency_name, $emergency_contact, $address
      );
      if ($stmt->execute()) {
        $msg = "Tenant successfully added!";
        $old = [];
      } else {
        $err = "Error saving tenant: " . $conn->error;
      }
      $stmt->close();
    }
  } else {
    $err = "Please fix the highlighted fields.";
  }
}

// Recently added
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

    .recent-item{ display:flex; justify-content:space-between; align-items:center; padding:.6rem .2rem; border-bottom:1px dashed rgba(0,0,0,.08); }
    .recent-item:last-child{ border-bottom:none; }
    .recent-name{ font-weight:500; }
    .recent-date{ color:#6c757d; font-size:.9rem; }

    .gradient-btn {
      background: linear-gradient(90deg, var(--pad-primary), var(--pad-accent));
      color:#fff; border:none; border-radius:.5rem;
      box-shadow:0 6px 18px rgba(54,30,92,.25);
    }
    .gradient-btn:hover { opacity:.9; color:#fff; }
    .btn-outline-gradient {
      background:transparent; border:1px solid var(--pad-accent);
      color:var(--pad-accent); border-radius:.5rem;
    }
    .btn-outline-gradient:hover {
      background: linear-gradient(90deg, var(--pad-primary), var(--pad-accent)); color:#fff;
    }
    .form-actions{ text-align:center; }
    .form-control:focus, .form-select:focus{ border-color:#6141A6; box-shadow:0 0 0 .2rem rgba(97,65,166,.15); }
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

    <?php if ($msg): ?><div class="alert alert-success text-center"><?= $msg ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger text-center"><?= $err ?></div><?php endif; ?>

    <div class="grid-wrap">
      <div class="row g-4">
        <!-- Form -->
        <div class="col-lg-7">
          <div class="card card-v">
            <div class="card-header d-flex align-items-center">
              <h2 class="h5 mb-0 title-v"><i class="bi bi-person-plus me-2"></i>Add New Tenant</h2>
            </div>
            <div class="card-body">
              <form method="POST" autocomplete="off" novalidate id="tenant-form">
                <div class="row g-3">
                  <!-- Names -->
                  <div class="col-md-4">
                    <label class="form-label">First Name <span class="text-danger">*</span></label>
                    <input type="text" name="first_name" class="form-control" value="<?= val($old,'first_name') ?>" required>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">Middle Name</label>
                    <input type="text" name="middle_name" class="form-control" value="<?= val($old,'middle_name') ?>">
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">Last Name <span class="text-danger">*</span></label>
                    <input type="text" name="last_name" class="form-control" value="<?= val($old,'last_name') ?>" required>
                  </div>

                  <!-- Demographics -->
                  <div class="col-md-4">
                    <label class="form-label">Gender <span class="text-danger">*</span></label>
                    <select name="gender" class="form-select" required>
                      <option value="">-- Select --</option>
                      <?php foreach (['Male','Female','Other'] as $g): ?>
                        <option value="<?= $g ?>" <?= (val($old,'gender')===$g)?'selected':'' ?>><?= $g ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">Age <span class="text-danger">*</span></label>
                    <input type="number" name="age" class="form-control" value="<?= val($old,'age') ?>" min="18" max="120" required>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">Civil Status</label>
                    <select name="civil_status" class="form-select">
                      <option value="">-- Select --</option>
                      <?php foreach (['Single','Married','Widowed','Divorced'] as $s): ?>
                        <option value="<?= $s ?>" <?= (val($old,'civil_status')===$s)?'selected':'' ?>><?= $s ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <!-- Contact -->
                  <div class="col-md-6">
                    <label class="form-label">Contact No. <span class="text-danger">*</span></label>
                    <input type="text" name="contact_no" class="form-control" value="<?= val($old,'contact_no') ?>" placeholder="09XXXXXXXXX" required>
                  </div>

                  <!-- Emergency -->
                  <div class="col-md-6">
                    <label class="form-label">Emergency Contact Name</label>
                    <input type="text" name="emergency_name" class="form-control" value="<?= val($old,'emergency_name') ?>">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Emergency Contact No.</label>
                    <input type="text" name="emergency_contact" class="form-control" value="<?= val($old,'emergency_contact') ?>" placeholder="09XXXXXXXXX">
                  </div>

                  <!-- Address -->
                  <div class="col-12"><hr></div>
                  <div class="col-12"><h6 class="mb-2">Address (Philippines)</h6></div>

                  <div class="col-md-6">
                    <label class="form-label">Region <span class="text-danger">*</span></label>
                    <select id="region" class="form-select" required></select>
                    <input type="hidden" name="region_code" id="region_code" value="<?= val($old,'region_code') ?>">
                    <input type="hidden" name="region_name" id="region_name" value="<?= val($old,'region_name') ?>">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Province <span class="text-danger">*</span></label>
                    <select id="province" class="form-select" required disabled></select>
                    <input type="hidden" name="province_code" id="province_code" value="<?= val($old,'province_code') ?>">
                    <input type="hidden" name="province_name" id="province_name" value="<?= val($old,'province_name') ?>">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">City/Municipality <span class="text-danger">*</span></label>
                    <select id="city" class="form-select" required disabled></select>
                    <input type="hidden" name="city_code" id="city_code" value="<?= val($old,'city_code') ?>">
                    <input type="hidden" name="city_name" id="city_name" value="<?= val($old,'city_name') ?>">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Barangay <span class="text-danger">*</span></label>
                    <select id="barangay" class="form-select" required disabled></select>
                    <input type="hidden" name="brgy_code" id="brgy_code" value="<?= val($old,'brgy_code') ?>">
                    <input type="hidden" name="brgy_name" id="brgy_name" value="<?= val($old,'brgy_name') ?>">
                  </div>

                  <div class="col-md-8">
                    <label class="form-label">Street / Zone / Purok (Optional)</label>
                    <input type="text" name="street_details" class="form-control" value="<?= val($old,'street_details') ?>" placeholder="e.g. Zone 8 210 Zayas Oroham">
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">ZIP Code</label>
                    <input type="text" name="zip_code" class="form-control" value="<?= val($old,'zip_code') ?>" placeholder="e.g. 9000" pattern="\d{4}">
                  </div>

                  <div class="col-12 form-actions mt-3">
                    <button type="submit" class="btn gradient-btn px-4"><i class="bi bi-check2-circle me-1"></i> Save Tenant</button>
                    <button type="reset" class="btn btn-outline-gradient px-4 ms-2"><i class="bi bi-eraser me-1"></i> Clear</button>
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
  <script>
    // Client validation: emergency pair + HTML5 validity
    (function(){
      const form = document.getElementById('tenant-form');
      form.addEventListener('submit', function (e) {
        const en = form.querySelector('[name="emergency_name"]');
        const ep = form.querySelector('[name="emergency_contact"]');
        const hasName = en.value.trim() !== '';
        const hasPhone= ep.value.trim() !== '';

        // both or none
        if ((hasName && !hasPhone) || (!hasName && hasPhone)) {
          if (!hasName) en.classList.add('is-invalid');
          if (!hasPhone) ep.classList.add('is-invalid');
          e.preventDefault(); e.stopPropagation();
        }

        if (!form.checkValidity()) { e.preventDefault(); e.stopPropagation(); }
        form.classList.add('was-validated');
      }, false);
    })();

    // ===== PSGC API Cascading Dropdowns =====
    // Docs: https://psgc.gitlab.io/api/
    const PSGC = 'https://psgc.gitlab.io/api';

    const elRegion   = document.getElementById('region');
    const elProv     = document.getElementById('province');
    const elCity     = document.getElementById('city');
    const elBrgy     = document.getElementById('barangay');

    const hRegionCode= document.getElementById('region_code');
    const hRegionName= document.getElementById('region_name');
    const hProvCode  = document.getElementById('province_code');
    const hProvName  = document.getElementById('province_name');
    const hCityCode  = document.getElementById('city_code');
    const hCityName  = document.getElementById('city_name');
    const hBrgyCode  = document.getElementById('brgy_code');
    const hBrgyName  = document.getElementById('brgy_name');

    function opt(val, text){ const o=document.createElement('option'); o.value=val; o.textContent=text; return o; }
    function setDisabled(sel, disabled){ sel.disabled = disabled; if(disabled){ sel.innerHTML=''; } }

    async function fetchJSON(url){
      const r = await fetch(url, {headers:{'Accept':'application/json'}});
      if (!r.ok) throw new Error('Network error');
      return r.json();
    }

    async function loadRegions(){
      setDisabled(elRegion, false);
      elRegion.innerHTML = '';
      elRegion.appendChild(opt('', '-- Select Region --'));
      const data = await fetchJSON(`${PSGC}/regions/`);
      data.sort((a,b)=> (a.regionCode||'').localeCompare(b.regionCode||'')); // by region code
      data.forEach(x => elRegion.appendChild(opt(x.code, `${x.regionCode} — ${x.name}`)));
      if (hRegionCode.value) elRegion.value = hRegionCode.value;
      elRegion.dispatchEvent(new Event('change'));
    }

    async function loadProvinces(regionCode){
      setDisabled(elProv, true); setDisabled(elCity, true); setDisabled(elBrgy, true);
      elProv.innerHTML = ''; elCity.innerHTML=''; elBrgy.innerHTML='';
      hProvCode.value=''; hProvName.value=''; hCityCode.value=''; hCityName.value=''; hBrgyCode.value=''; hBrgyName.value='';

      if(!regionCode){ return; }
      elProv.appendChild(opt('', '-- Select Province --'));
      const data = await fetchJSON(`${PSGC}/regions/${regionCode}/provinces/`);
      data.sort((a,b)=> a.name.localeCompare(b.name));
      data.forEach(x => elProv.appendChild(opt(x.code, x.name)));
      setDisabled(elProv, false);
      if (hProvCode.value) elProv.value = hProvCode.value;
      elProv.dispatchEvent(new Event('change'));
    }

    async function loadCities(provinceCode){
      setDisabled(elCity, true); setDisabled(elBrgy, true);
      elCity.innerHTML=''; elBrgy.innerHTML='';
      hCityCode.value=''; hCityName.value=''; hBrgyCode.value=''; hBrgyName.value='';

      if(!provinceCode){ return; }
      elCity.appendChild(opt('', '-- Select City/Municipality --'));
      const data = await fetchJSON(`${PSGC}/provinces/${provinceCode}/cities-municipalities/`);
      data.sort((a,b)=> a.name.localeCompare(b.name));
      data.forEach(x => elCity.appendChild(opt(x.code, x.name)));
      setDisabled(elCity, false);
      if (hCityCode.value) elCity.value = hCityCode.value;
      elCity.dispatchEvent(new Event('change'));
    }

    async function loadBarangays(cityCode){
      setDisabled(elBrgy, true);
      elBrgy.innerHTML='';
      hBrgyCode.value=''; hBrgyName.value='';

      if(!cityCode){ return; }
      elBrgy.appendChild(opt('', '-- Select Barangay --'));
      const data = await fetchJSON(`${PSGC}/cities-municipalities/${cityCode}/barangays/`);
      data.sort((a,b)=> a.name.localeCompare(b.name));
      data.forEach(x => elBrgy.appendChild(opt(x.code, x.name)));
      setDisabled(elBrgy, false);
      if (hBrgyCode.value) elBrgy.value = hBrgyCode.value;
    }

    // Event binding
    elRegion.addEventListener('change', () => {
      const code = elRegion.value;
      const name = elRegion.selectedOptions[0]?.textContent?.split('—').slice(1).join('—').trim() || '';
      hRegionCode.value = code || '';
      hRegionName.value = name || '';
      loadProvinces(code);
    });

    elProv.addEventListener('change', () => {
      const code = elProv.value;
      const name = elProv.selectedOptions[0]?.textContent || '';
      hProvCode.value = code || '';
      hProvName.value = name || '';
      loadCities(code);
    });

    elCity.addEventListener('change', () => {
      const code = elCity.value;
      const name = elCity.selectedOptions[0]?.textContent || '';
      hCityCode.value = code || '';
      hCityName.value = name || '';
      loadBarangays(code);
    });

    elBrgy.addEventListener('change', () => {
      const code = elBrgy.value;
      const name = elBrgy.selectedOptions[0]?.textContent || '';
      hBrgyCode.value = code || '';
      hBrgyName.value = name || '';
    });

    // Initial load
    (async function init(){
      try{
        await loadRegions();
      }catch(e){
        console.warn('PSGC load failed', e);
        elRegion.innerHTML = '<option value="">Unable to load regions (check internet)</option>';
      }
    })();
  </script>
</body>
</html>
