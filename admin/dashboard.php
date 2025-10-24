<?php
// admin/dashboard.php
require_once __DIR__ . '/../inclusions/require_login.php';
require_once __DIR__ . '/../inclusions/connection.php';

$BASE = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

/* ---------- helpers ---------- */
function has_col(mysqli $c,$t,$col){
  $q="SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?";
  $s=$c->prepare($q);
  if($s){
    $s->bind_param("ss",$t,$col);
    $s->execute(); $s->bind_result($n); $s->fetch(); $s->close();
    return (int)$n>0;
  }
  return false;
}
$housesHasArchive = has_col($conn,'houses','is_archived');
$archCond = $housesHasArchive ? " AND h.is_archived=0" : "";

/* ---------- month range (for income) ---------- */
$ymFrom = $_GET['from'] ?? date('Y').'-01';
$ymTo   = $_GET['to']   ?? date('Y-m');

$fromTs = strtotime($ymFrom.'-01');
$toTs   = strtotime(date('Y-m-01', strtotime($ymTo.'-01')));
if ($fromTs === false || $toTs === false || $fromTs > $toTs) {
  $fromTs = strtotime(date('Y').'-01-01');
  $toTs   = strtotime(date('Y-m-01'));
}
function month_list($from,$to){
  $out=[]; $cur=$from;
  while($cur <= $to){ $out[] = date('Y-m',$cur); $cur = strtotime('+1 month',$cur); }
  return $out;
}
$ymKeys   = month_list($fromTs,$toTs);
$labels12 = array_map(fn($ym)=>date('M Y',strtotime($ym.'-01')),$ymKeys);

/* ---------- KPIs ---------- */
$kpi = ['houses'=>0,'floors'=>0,'rooms'=>0,'vacant'=>0,'occupied'=>0];

$q = "SELECT COUNT(*) FROM houses h WHERE 1=1".($housesHasArchive?" AND h.is_archived=0":"");
if($res = $conn->query($q)){ $kpi['houses'] = (int)$res->fetch_row()[0]; $res->close(); }

$q = "SELECT COUNT(*) FROM floors f JOIN houses h ON h.id=f.house_id WHERE 1=1".$archCond;
if($res = $conn->query($q)){ $kpi['floors'] = (int)$res->fetch_row()[0]; $res->close(); }

$q = "SELECT r.status, COUNT(*)
      FROM rooms r
      JOIN floors f ON f.id=r.floor_id
      JOIN houses h ON h.id=f.house_id
      WHERE 1=1".$archCond."
      GROUP BY r.status";
if($res=$conn->query($q)){
  while($rw=$res->fetch_row()){
    $kpi['rooms'] += (int)$rw[1];
    if($rw[0]==='vacant')   $kpi['vacant']   = (int)$rw[1];
    if($rw[0]==='occupied') $kpi['occupied'] = (int)$rw[1];
  }
  $res->close();
}

/* ---------- Room status % + trend vs last month ---------- */
$totalRooms = max(1,$kpi['rooms']);
$todayStr = date('Y-m-d');
$prevStr  = date('Y-m-d', strtotime('-1 month'));

$sqlOcc = "
  SELECT COUNT(DISTINCT r.id)
  FROM rooms r
  JOIN floors f ON f.id=r.floor_id
  JOIN houses h ON h.id=f.house_id
  LEFT JOIN renters ren
    ON ren.room_id=r.id
   AND ren.status='active'
   AND ren.start_date<=?
   AND ren.due_date>=?
  WHERE 1=1".$archCond."
    AND ren.id IS NOT NULL
";
$occNow = 0;
if($stmt = $conn->prepare($sqlOcc)){
  $p1 = $todayStr; $p2 = $todayStr;
  $stmt->bind_param("ss",$p1,$p2);
  $stmt->execute(); $stmt->bind_result($occNow); $stmt->fetch(); $stmt->close();
}

$occPrev = 0;
if($stmt = $conn->prepare($sqlOcc)){
  $p3 = $prevStr; $p4 = $prevStr;
  $stmt->bind_param("ss",$p3,$p4);
  $stmt->execute(); $stmt->bind_result($occPrev); $stmt->fetch(); $stmt->close();
}
$occPctNow  = round(100.0*$occNow/$totalRooms,1);
$occPctPrev = round(100.0*$occPrev/$totalRooms,1);
$occDelta   = $occPctNow - $occPctPrev;

/* ---------- Chart A: Monthly income (range) + total ---------- */
$incomeMap = array_fill_keys($ymKeys, 0.0);
$fromDate = date('Y-m-01',$fromTs);
$toDate   = date('Y-m-t',$toTs);
$sqlInc = "SELECT DATE_FORMAT(payment_date,'%Y-%m') ym, SUM(amount) amt
           FROM payments
           WHERE payment_date BETWEEN ? AND ?
           GROUP BY ym";
if($stmt=$conn->prepare($sqlInc)){
  $fp=$fromDate; $tp=$toDate;
  $stmt->bind_param("ss",$fp,$tp);
  $stmt->execute();
  $res=$stmt->get_result();
  while($r=$res->fetch_assoc()){
    if(isset($incomeMap[$r['ym']])) $incomeMap[$r['ym']] = (float)$r['amt'];
  }
  $stmt->close();
}
$monthlyIncome = array_values($incomeMap);
$totalIncome   = array_sum($monthlyIncome);

/* ---------- Chart B: Pie (status mix) ---------- */
$pieLabels = ['Vacant','Occupied','Maintenance'];
$pieVals   = [0,0,0];
$q = "SELECT r.status, COUNT(*)
      FROM rooms r
      JOIN floors f ON f.id=r.floor_id
      JOIN houses h ON h.id=f.house_id
      WHERE 1=1".$archCond."
      GROUP BY r.status";
if($res=$conn->query($q)){
  while($rw=$res->fetch_row()){
    if($rw[0]==='vacant') $pieVals[0]=(int)$rw[1];
    elseif($rw[0]==='occupied') $pieVals[1]=(int)$rw[1];
    else $pieVals[2]=(int)$rw[1];
  }
  $res->close();
}

/* ---------- Chart C: Line (new renters per month) ---------- */
$rentersMap = array_fill_keys($ymKeys, 0);
$sqlR="SELECT DATE_FORMAT(start_date,'%Y-%m') ym, COUNT(*) c
       FROM renters
       WHERE start_date BETWEEN ? AND ?
       GROUP BY ym";
if($stmt=$conn->prepare($sqlR)){
  $fp=$fromDate; $tp=$toDate;
  $stmt->bind_param("ss",$fp,$tp);
  $stmt->execute(); $res=$stmt->get_result();
  while($r=$res->fetch_assoc()){ if(isset($rentersMap[$r['ym']])) $rentersMap[$r['ym']] = (int)$r['c']; }
  $stmt->close();
}
$rentersLine = array_values($rentersMap);

/* ---------- Chart D: Bar H (income by house) ---------- */
$barHLabels=[]; $barHVals=[];
$q="SELECT h.name, COALESCE(SUM(p.amount),0) amt
   FROM houses h
   LEFT JOIN floors f ON f.house_id=h.id
   LEFT JOIN rooms r  ON r.floor_id=f.id
   LEFT JOIN renters ren ON ren.room_id=r.id
   LEFT JOIN payments p ON p.renter_id=ren.id
   WHERE 1=1".$archCond."
   GROUP BY h.id,h.name
   ORDER BY amt DESC LIMIT 8";
if($res=$conn->query($q)){
  while($r=$res->fetch_assoc()){ $barHLabels[]=$r['name']; $barHVals[]=(float)$r['amt']; }
  $res->close();
}

/* ---------- Chart E: Area (new tenants per month) ---------- */
$tenMap = array_fill_keys($ymKeys, 0);
$sql="SELECT DATE_FORMAT(created_at,'%Y-%m') ym, COUNT(*) c
      FROM tenants
      WHERE created_at BETWEEN ? AND ?
      GROUP BY ym";
if($stmt=$conn->prepare($sql)){
  $fp=$fromDate.' 00:00:00'; $tp=$toDate.' 23:59:59';
  $stmt->bind_param("ss",$fp,$tp);
  $stmt->execute(); $res=$stmt->get_result();
  while($r=$res->fetch_assoc()){ if(isset($tenMap[$r['ym']])) $tenMap[$r['ym']] = (int)$r['c']; }
  $stmt->close();
}
$tenArea = array_values($tenMap);

/* ---------- Chart F: Combo (income vs occupancy % by house) ---------- */
$comboLabels=[]; $comboCols=[]; $comboLine=[];
$q="SELECT h.id, h.name,
           SUM(r.status='occupied') occ,
           COUNT(r.id) total
    FROM houses h
    JOIN floors f ON f.house_id=h.id
    JOIN rooms r  ON r.floor_id=f.id
    WHERE 1=1".$archCond."
    GROUP BY h.id,h.name ORDER BY h.name";
$occ=[];
if($res=$conn->query($q)){
  while($r=$res->fetch_assoc()){
    $rate = $r['total']>0 ? round($r['occ']*100.0/$r['total'],1) : 0.0;
    $occ[$r['id']] = [$r['name'],$rate];
  }
  $res->close();
}
$sql="SELECT h.id, COALESCE(SUM(p.amount),0) inc
      FROM houses h
      JOIN floors f ON f.house_id=h.id
      JOIN rooms r  ON r.floor_id=f.id
      JOIN renters ren ON ren.room_id=r.id
      LEFT JOIN payments p ON p.renter_id=ren.id
      WHERE p.payment_date BETWEEN ? AND ?
      GROUP BY h.id";
if($stmt=$conn->prepare($sql)){
  $fp=$fromDate; $tp=$toDate;
  $stmt->bind_param("ss",$fp,$tp);
  $stmt->execute(); $res=$stmt->get_result();
  $inc=[]; while($r=$res->fetch_assoc()){ $inc[$r['id']] = (float)$r['inc']; }
  $stmt->close();
  foreach($occ as $hid=>$arr){ $comboLabels[]=$arr[0]; $comboLine[]=$arr[1]; $comboCols[]=$inc[$hid]??0; }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Dashboard - Padroom</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="<?= $BASE ?>/../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="<?= $BASE ?>/../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="<?= $BASE ?>/../assets/css/style.css" rel="stylesheet">
  <style>
    :root{ --pad-primary:#361E5C; --pad-accent:#6141A6; }
    .main{ margin-top:60px; padding:20px 20px 0 20px; }
    @media (min-width: 992px){ .main{ margin-left:250px; } }
    .kcard{ background:#fff; border:1px solid rgba(0,0,0,.06); border-radius:14px; padding:1rem 1.25rem; box-shadow:0 8px 20px rgba(0,0,0,.06); }
    .title{ color:#2D1B4E; font-weight:700; }
    .muted{ color:#6c757d; }
    .trend-up{ color:#20c997; }
    .trend-down{ color:#dc3545; }
    .trend-flat{ color:#6c757d; }
    .chart-card{ height: 320px; }
    .chart-card > canvas{ height: 100% !important; width: 100% !important; }
    .range-chip{ background:#f4f1fb; border:1px solid rgba(97,65,166,.25); border-radius:12px; padding:.4rem .6rem; }
  </style>
</head>
<body class="d-flex flex-column min-vh-100">
  <?php include __DIR__ . '/../admin/topbar.php'; ?>
  <?php include __DIR__ . '/../admin/sidebar.php'; ?>

  <main id="main" class="main flex-grow-1">
    <div class="pagetitle mb-3">
      <h1 class="h4">Dashboard</h1>
      <nav><ol class="breadcrumb"><li class="breadcrumb-item"><a href="#">Home</a></li><li class="breadcrumb-item active">Dashboard</li></ol></nav>
    </div>

    <!-- KPIs + Range -->
    <div class="row g-3 mb-3">
      <div class="col-lg-4">
        <div class="kcard">
          <div class="title mb-1"><i class="bi bi-buildings me-2"></i>Property Summary</div>
          <div class="muted">Houses: <b><?= $kpi['houses'] ?></b> • Floors: <b><?= $kpi['floors'] ?></b> • Rooms: <b><?= $kpi['rooms'] ?></b></div>
        </div>
      </div>
      <div class="col-lg-4">
        <div class="kcard">
          <div class="title mb-1"><i class="bi bi-door-closed me-2"></i>Room Status</div>
          <div class="muted mb-1">Vacant: <b class="text-success"><?= $kpi['vacant'] ?></b> • Occupied: <b class="text-danger"><?= $kpi['occupied'] ?></b></div>
          <div>
            <span class="range-chip me-2">Now: <b><?= $occNow ?></b> / <?= $totalRooms ?> (<?= $occPctNow ?>%)</span>
            <?php
              $trendIcon = $occDelta > 0 ? 'bi-arrow-up-right' : ($occDelta < 0 ? 'bi-arrow-down-right' : 'bi-dash-lg');
              $trendClass= $occDelta > 0 ? 'trend-up' : ($occDelta < 0 ? 'trend-down' : 'trend-flat');
              $deltaTxt  = ($occDelta>0?'+':'').number_format($occDelta,1).'%';
            ?>
            <span class="<?= $trendClass ?>"><i class="bi <?= $trendIcon ?>"></i> <?= $deltaTxt ?> vs last month</span>
          </div>
        </div>
      </div>
      <div class="col-lg-4">
        <div class="kcard">
          <div class="d-flex justify-content-between align-items-center">
            <div>
              <div class="title mb-1"><i class="bi bi-cash-coin me-2"></i>Total Earnings</div>
              <div class="muted">₱ <b><?= number_format($totalIncome,2) ?></b></div>
            </div>
            <form method="get" class="d-flex align-items-center gap-2">
              <div>
                <label class="small text-muted">From</label>
                <input type="month" name="from" class="form-control form-control-sm" value="<?= htmlspecialchars(date('Y-m',$fromTs)) ?>">
              </div>
              <div>
                <label class="small text-muted">To</label>
                <input type="month" name="to" class="form-control form-control-sm" value="<?= htmlspecialchars(date('Y-m',$toTs)) ?>">
              </div>
              <button class="btn btn-sm" style="background:linear-gradient(90deg,var(--pad-primary),var(--pad-accent));color:#fff;border:none;">
                <i class="bi bi-funnel me-1"></i>Apply
              </button>
            </form>
          </div>
        </div>
      </div>
    </div>

    <!-- charts -->
    <div class="row g-3">
      <div class="col-lg-6">
        <div class="card h-100">
          <div class="card-header"><strong>Monthly Income (<?= date('M Y',$fromTs) ?> → <?= date('M Y',$toTs) ?>)</strong></div>
          <div class="card-body chart-card"><canvas id="chIncomeCol"></canvas></div>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="card h-100">
          <div class="card-header"><strong>Room Status Mix</strong></div>
          <div class="card-body chart-card"><canvas id="chPie"></canvas></div>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="card h-100">
          <div class="card-header"><strong>New Renters per Month</strong></div>
          <div class="card-body chart-card"><canvas id="chLine"></canvas></div>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="card h-100">
          <div class="card-header"><strong>Income by House</strong></div>
          <div class="card-body chart-card"><canvas id="chBarH"></canvas></div>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="card h-100">
          <div class="card-header"><strong>New Tenants per Month</strong></div>
          <div class="card-body chart-card"><canvas id="chArea"></canvas></div>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="card h-100">
          <div class="card-header"><strong>Income vs Occupancy % by House</strong></div>
          <div class="card-body chart-card"><canvas id="chCombo"></canvas></div>
        </div>
      </div>
    </div>
  </main>

  <?php include __DIR__ . '/../admin/footer.php'; ?>
  <script src="<?= $BASE ?>/../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <script>
    const c1='#6141A6', c2='#361E5C', c3='#8b6fe0', c4='#d0b3ff', c5='#20c997', c6='#ffc107', c7='#dc3545';

    const labels  = <?= json_encode($labels12) ?>;
    const income  = <?= json_encode($monthlyIncome) ?>;

    const pieL    = <?= json_encode($pieLabels) ?>;
    const pieV    = <?= json_encode($pieVals) ?>;

    const renters = <?= json_encode($rentersLine) ?>;
    const barHL   = <?= json_encode($barHLabels) ?>;
    const barHV   = <?= json_encode($barHVals) ?>;

    const areaV   = <?= json_encode($tenArea) ?>;
    const comboL  = <?= json_encode($comboLabels) ?>;
    const comboC  = <?= json_encode($comboCols) ?>;
    const comboP  = <?= json_encode($comboLine) ?>;

    const common = { maintainAspectRatio:false, plugins:{ legend:{ position:'bottom' } }, scales:{ y:{ beginAtZero:true } } };

    new Chart(chIncomeCol, {
      type:'bar',
      data:{ labels, datasets:[{ label:'₱ Income', data: income, backgroundColor:c1, borderRadius:6 }] },
      options: common
    });

    new Chart(chPie, {
      type:'pie',
      data:{ labels: pieL, datasets:[{ data: pieV, backgroundColor:[c5,c7,'#6c757d'] }] },
      options:{ maintainAspectRatio:false, plugins:{ legend:{ position:'bottom' } } }
    });

    new Chart(chLine, {
      type:'line',
      data:{ labels, datasets:[{ label:'New Renters', data: renters, borderColor:c2, pointRadius:3, tension:.3 }] },
      options: common
    });

    new Chart(chBarH, {
      type:'bar',
      data:{ labels: barHL, datasets:[{ label:'₱ Income', data: barHV, backgroundColor:c3, borderRadius:6 }] },
      options:{ maintainAspectRatio:false, indexAxis:'y', plugins:{ legend:{ position:'bottom' } }, scales:{ x:{ beginAtZero:true } } }
    });

    new Chart(chArea, {
      type:'line',
      data:{ labels, datasets:[{ label:'New Tenants', data: areaV, borderColor:c1, backgroundColor:'rgba(97,65,166,.22)', fill:true, tension:.35 }] },
      options: common
    });

    new Chart(chCombo, {
      type:'bar',
      data:{
        labels: comboL,
        datasets:[
          { type:'bar',  label:'₱ Income', data: comboC, backgroundColor:c4, borderColor:c1, borderWidth:1, borderRadius:6, yAxisID:'y' },
          { type:'line', label:'Occupancy %', data: comboP, borderColor:c2, tension:.3, pointRadius:3, yAxisID:'y1' }
        ]
      },
      options:{
        maintainAspectRatio:false,
        plugins:{ legend:{ position:'bottom' } },
        scales:{
          y:{ beginAtZero:true, title:{ display:true, text:'₱' } },
          y1:{ beginAtZero:true, position:'right', ticks:{ callback:v=>v+'%' }, title:{ display:true, text:'Occupancy %' } }
        }
      }
    });
  </script>
</body>
</html>
