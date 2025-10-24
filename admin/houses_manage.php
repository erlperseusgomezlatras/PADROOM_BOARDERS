<?php
// admin/house_manage.php
require_once __DIR__ . '/../inclusions/require_login.php';
require_once __DIR__ . '/../inclusions/connection.php';

$BASE = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

$msg = "";
$err = "";
$errors = [];

// helper to safely fetch int
function int_or_zero($v){ return max(0, (int)($v ?? 0)); }
function val($arr, $k){ return htmlspecialchars(trim($arr[$k] ?? ''), ENT_QUOTES, 'UTF-8'); }

// Defaults for sticky form after validation errors
$old = $_POST ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $house_name = trim($_POST['house_name'] ?? '');
  $floors_cnt = int_or_zero($_POST['floors_cnt'] ?? 0);
  $rooms_arr  = $_POST['rooms_per_floor'] ?? []; // array of strings/ints

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

  // Basic validation
  if ($house_name === '') $errors['house_name'] = "House name is required.";
  if ($floors_cnt < 1)    $errors['floors_cnt'] = "At least 1 floor is required.";

  // Address validation (required hierarchy)
  if ($region_code===''   || $region_name==='')   $errors['region']   = "Select a region.";
  if ($province_code==='' || $province_name==='') $errors['province'] = "Select a province.";
  if ($city_code===''     || $city_name==='')     $errors['city']     = "Select a city/municipality.";
  if ($brgy_code===''     || $brgy_name==='')     $errors['barangay'] = "Select a barangay.";
  if ($zip_code !== '' && !preg_match('/^\d{4}$/', $zip_code)) $errors['zip_code'] = "ZIP must be 4 digits.";

  if (empty($errors)) {
    // Unique name check (houses.name has UNIQUE index)
    $stmt = $conn->prepare("SELECT COUNT(*) FROM houses WHERE name=?");
    $stmt->bind_param("s", $house_name);
    $stmt->execute();
    $stmt->bind_result($dup);
    $stmt->fetch();
    $stmt->close();

    if ($dup > 0) {
      $err = "House name already exists. Choose another.";
    } else {
      // Normalize rooms per floor
      $rooms_per_floor = [];
      for ($i=1; $i <= $floors_cnt; $i++) {
        $rooms_per_floor[$i] = max(1, (int)($rooms_arr[$i] ?? 1));
      }

      // Compose address string
      $addr_parts = [];
      if ($street_details !== '') $addr_parts[] = $street_details;
      $addr_parts[] = $brgy_name;
      $addr_parts[] = $city_name;
      $addr_parts[] = $province_name;
      $addr_parts[] = $region_name;
      if ($zip_code !== '') $addr_parts[] = $zip_code;
      $house_addr = implode(', ', $addr_parts);

      // Create House -> Floors -> Rooms in a transaction
      try {
        $conn->begin_transaction();

        // 1) House
        $stmtH = $conn->prepare("INSERT INTO houses (name, address, notes) VALUES (?, ?, NULL)");
        $stmtH->bind_param("ss", $house_name, $house_addr);
        if (!$stmtH->execute()) throw new Exception($conn->error);
        $house_id = $stmtH->insert_id;
        $stmtH->close();

        // 2) Floors + 3) Rooms
        $stmtF = $conn->prepare("INSERT INTO floors (house_id, floor_label, sort_order) VALUES (?, ?, ?)");
        $stmtR = $conn->prepare("INSERT INTO rooms (floor_id, room_label, capacity, status, notes) VALUES (?, ?, NULL, 'vacant', NULL)");

        for ($i = 1; $i <= $floors_cnt; $i++) {
          $label = "Floor $i";
          $sort  = $i;
          $stmtF->bind_param("isi", $house_id, $label, $sort);
          if (!$stmtF->execute()) throw new Exception($conn->error);
          $floor_id = $stmtF->insert_id;

          $room_count = $rooms_per_floor[$i];
          for ($r = 1; $r <= $room_count; $r++) {
            // e.g., 101, 102 ... 201, 202 ...
            $room_number = sprintf('%d%02d', $i, $r);
            $room_label  = "Room $room_number";
            $stmtR->bind_param("is", $floor_id, $room_label);
            if (!$stmtR->execute()) throw new Exception($conn->error);
          }
        }

        $stmtF->close();
        $stmtR->close();

        $conn->commit();

        $total_rooms = array_sum($rooms_per_floor);
        $msg = "House <strong>" . htmlspecialchars($house_name) . "</strong> created with <strong>$floors_cnt</strong> floor(s) and <strong>$total_rooms</strong> room(s).";
        // Clear POST defaults after success
        $old = [];
      } catch (Exception $e) {
        $conn->rollback();
        $err = "Failed to create house: " . $e->getMessage();
      }
    }
  } else {
    $err = "Please fix the highlighted fields.";
  }
}

// For the right panel: latest 8 houses with counts
$latest = [];
$sqlLatest = "
  SELECT h.id, h.name, h.address,
         (SELECT COUNT(*) FROM floors f WHERE f.house_id=h.id) AS floors_count,
         (SELECT COUNT(*) FROM rooms r JOIN floors f ON r.floor_id=f.id WHERE f.house_id=h.id) AS rooms_count,
         h.created_at
  FROM houses h
  ORDER BY h.created_at DESC
  LIMIT 8";
if ($res = $conn->query($sqlLatest)) {
  while ($row = $res->fetch_assoc()) $latest[] = $row;
  $res->close();
}

// Prefill values (when validation fails)
$pref_name   = val($old,'house_name');
$pref_floors = (int)($old['floors_cnt'] ?? 1);
if ($pref_floors < 1) $pref_floors = 1;
$pref_rooms  = $old['rooms_per_floor'] ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Manage House - Padroom</title>
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

    .grid-wrap{ max-width: 1200px; margin: 0 auto; }
    .card-v{ background:#fff; border-radius:12px; box-shadow:0 10px 24px rgba(0,0,0,.08); }
    .card-v .card-header{ background:#fff; border-bottom:1px solid rgba(0,0,0,.06); }
    .title-v{ color:#2D1B4E; font-weight:600; }

    .gradient-btn {
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

    .form-control:focus, .form-select:focus{
      border-color:#6141A6; box-shadow:0 0 0 .2rem rgba(97,65,166,.15);
    }
    .small-muted{ color:#6c757d; }
    .is-invalid + .invalid-feedback { display:block; }
  </style>
</head>
<body class="d-flex flex-column min-vh-100">
  <?php include __DIR__ . '/../admin/topbar.php'; ?>
  <?php include __DIR__ . '/../admin/sidebar.php'; ?>

  <main id="main" class="main flex-grow-1">
    <div class="pagetitle mb-4 text-center">
      <h1 class="h4 mb-1">House Management</h1>
      <nav>
        <ol class="breadcrumb justify-content-center">
          <li class="breadcrumb-item"><a href="<?= $BASE ?>/dashboard.php">Dashboard</a></li>
          <li class="breadcrumb-item active">Manage House</li>
        </ol>
      </nav>
    </div>

    <?php if ($msg): ?><div class="alert alert-success text-center"><?= $msg ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger text-center"><?= htmlspecialchars($err) ?></div><?php endif; ?>

    <div class="grid-wrap">
      <div class="row g-4">
        <!-- Create House -->
        <div class="col-lg-7">
          <div class="card card-v">
            <div class="card-header">
              <h2 class="h5 mb-0 title-v"><i class="bi bi-house-add me-2"></i>Add House</h2>
            </div>
            <div class="card-body">
              <form method="POST" autocomplete="off" id="house-form" novalidate>
                <div class="row g-3">
                  <div class="col-md-6">
                    <label class="form-label">House Name <span class="text-danger">*</span></label>
                    <input type="text" name="house_name" class="form-control <?= isset($errors['house_name'])?'is-invalid':'' ?>" value="<?= $pref_name ?>" required>
                    <div class="invalid-feedback"><?= $errors['house_name'] ?? 'Please enter a house name.' ?></div>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Floors <span class="text-danger">*</span></label>
                    <input type="number" name="floors_cnt" id="floors_cnt" class="form-control <?= isset($errors['floors_cnt'])?'is-invalid':'' ?>" min="1" value="<?= (int)$pref_floors ?>" required>
                    <div class="invalid-feedback"><?= $errors['floors_cnt'] ?? 'At least 1 floor.' ?></div>
                  </div>

                  <!-- Address (PSGC) -->
                  <div class="col-12"><hr class="my-2"></div>
                  <div class="col-12"><h6 class="mb-2">House Address (Philippines)</h6></div>

                  <div class="col-md-6">
                    <label class="form-label">Region <span class="text-danger">*</span></label>
                    <select id="region" class="form-select <?= isset($errors['region'])?'is-invalid':'' ?>" required></select>
                    <div class="invalid-feedback"><?= $errors['region'] ?? 'Select a region.' ?></div>
                    <input type="hidden" name="region_code" id="region_code" value="<?= val($old,'region_code') ?>">
                    <input type="hidden" name="region_name" id="region_name" value="<?= val($old,'region_name') ?>">
                  </div>

                  <div class="col-md-6">
                    <label class="form-label">Province <span class="text-danger">*</span></label>
                    <select id="province" class="form-select <?= isset($errors['province'])?'is-invalid':'' ?>" required disabled></select>
                    <div class="invalid-feedback"><?= $errors['province'] ?? 'Select a province.' ?></div>
                    <input type="hidden" name="province_code" id="province_code" value="<?= val($old,'province_code') ?>">
                    <input type="hidden" name="province_name" id="province_name" value="<?= val($old,'province_name') ?>">
                  </div>

                  <div class="col-md-6">
                    <label class="form-label">City/Municipality <span class="text-danger">*</span></label>
                    <select id="city" class="form-select <?= isset($errors['city'])?'is-invalid':'' ?>" required disabled></select>
                    <div class="invalid-feedback"><?= $errors['city'] ?? 'Select a city/municipality.' ?></div>
                    <input type="hidden" name="city_code" id="city_code" value="<?= val($old,'city_code') ?>">
                    <input type="hidden" name="city_name" id="city_name" value="<?= val($old,'city_name') ?>">
                  </div>

                  <div class="col-md-6">
                    <label class="form-label">Barangay <span class="text-danger">*</span></label>
                    <select id="barangay" class="form-select <?= isset($errors['barangay'])?'is-invalid':'' ?>" required disabled></select>
                    <div class="invalid-feedback"><?= $errors['barangay'] ?? 'Select a barangay.' ?></div>
                    <input type="hidden" name="brgy_code" id="brgy_code" value="<?= val($old,'brgy_code') ?>">
                    <input type="hidden" name="brgy_name" id="brgy_name" value="<?= val($old,'brgy_name') ?>">
                  </div>

                  <div class="col-md-8">
                    <label class="form-label">Street / Zone / Purok (Optional)</label>
                    <input type="text" name="street_details" class="form-control" value="<?= val($old,'street_details') ?>" placeholder="e.g. Zone 8 210 Zayas Oroham">
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">ZIP Code</label>
                    <input type="text" name="zip_code" class="form-control <?= isset($errors['zip_code'])?'is-invalid':'' ?>" value="<?= val($old,'zip_code') ?>" placeholder="e.g. 9000" pattern="\d{4}">
                    <div class="invalid-feedback"><?= $errors['zip_code'] ?? 'ZIP must be 4 digits.' ?></div>
                  </div>

                  <!-- Dynamic rooms-per-floor -->
                  <div class="col-12">
                    <hr>
                    <div class="d-flex align-items-center justify-content-between">
                      <h6 class="mb-0">Rooms per floor</h6>
                      <button type="button" id="fill-rooms" class="btn btn-sm btn-outline-gradient">Fill all with 6</button>
                    </div>
                    <div id="rooms-per-floor" class="row g-2 mt-1"></div>
                  </div>

                  <div class="col-12 text-center mt-2">
                    <button type="submit" class="btn gradient-btn px-4">
                      <i class="bi bi-check2-circle me-1"></i> Save House
                    </button>
                  </div>
                </div>
              </form>
            </div>
          </div>
        </div>

        <!-- Latest Houses -->
        <div class="col-lg-5">
          <div class="card card-v">
            <div class="card-header">
              <h2 class="h5 mb-0 title-v"><i class="bi bi-clock-history me-2"></i>Recently Added Houses</h2>
            </div>
            <div class="card-body">
              <?php if (empty($latest)): ?>
                <p class="text-muted mb-0">No houses added yet.</p>
              <?php else: ?>
                <?php foreach ($latest as $h): ?>
                  <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                    <div>
                      <div class="fw-semibold"><?= htmlspecialchars($h['name']) ?></div>
                      <div class="small-muted small">
                        <?= (int)$h['floors_count'] ?> floor(s), <?= (int)$h['rooms_count'] ?> room(s)
                        <?php if (!empty($h['address'])): ?> • <?= htmlspecialchars($h['address']) ?><?php endif; ?>
                      </div>
                    </div>
                    <div class="small-muted"><?= date('M d, Y', strtotime($h['created_at'])) ?></div>
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
    (function(){
      const floorsInput = document.getElementById('floors_cnt');
      const roomsWrap   = document.getElementById('rooms-per-floor');
      const fillBtn     = document.getElementById('fill-rooms');

      // Prefill from POST if available (PHP rendered array)
      const prefill = <?php
        $pf = [];
        for($i=1; $i<= $pref_floors; $i++){
          $pf[$i] = (int)($pref_rooms[$i] ?? 6);
        }
        echo json_encode($pf);
      ?>;

      function renderRoomsPerFloor() {
        const n = Math.max(1, parseInt(floorsInput.value || "1", 10));
        roomsWrap.innerHTML = "";
        for (let i = 1; i <= n; i++) {
          const val = prefill[i] ?? 6;
          const col = document.createElement('div');
          col.className = 'col-6 col-md-4 col-lg-3';
          col.innerHTML = `
            <label class="form-label small mb-1">Floor ${i} rooms</label>
            <input type="number" name="rooms_per_floor[${i}]" class="form-control" min="1" value="${val}">
          `;
          roomsWrap.appendChild(col);
        }
      }

      floorsInput.addEventListener('input', renderRoomsPerFloor);
      fillBtn.addEventListener('click', () => {
        const inputs = roomsWrap.querySelectorAll('input[type="number"]');
        inputs.forEach(inp => inp.value = 6);
      });

      // initial render
      renderRoomsPerFloor();
    })();

    // ===== PSGC API Cascading Dropdowns (same as tenants) =====
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

    // Bind changes
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
