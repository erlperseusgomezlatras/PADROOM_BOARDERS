<?php
// admin/dashboard.php
require_once __DIR__ . '/../inclusions/require_login.php';
require_once __DIR__ . '/../inclusions/connection.php';

$BASE = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

/* ---------------- Helpers ---------------- */
function has_col(mysqli $c,$t,$col){
  $q="SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?";
  $s=$c->prepare($q); if(!$s) return false;
  $s->bind_param("ss",$t,$col);
  $s->execute(); $s->bind_result($n); $s->fetch(); $s->close();
  return (int)$n>0;
}
function qs(array $overrides=[]): string {
  $params = $_GET;
  foreach($overrides as $k=>$v){ $params[$k]=$v; }
  return '?' . http_build_query($params);
}

$housesHasArchive = has_col($conn,'houses','is_archived');
$archCond = $housesHasArchive ? " AND h.is_archived=0" : "";

/* ---------------- DATE RANGE (DAY-LEVEL) ---------------- */
$hasFrom = isset($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from']);
$hasTo   = isset($_GET['to'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']);

if ($hasFrom) { $fromDate = $_GET['from']; } else { $fromDate = date('Y-m-01'); }
if ($hasTo)   { $toDate   = $_GET['to'];   } else { $toDate   = date('Y-m-t'); }

$fromTs = strtotime($fromDate);
$toTs   = strtotime($toDate);

if ($fromTs !== false && $toTs !== false && $fromTs > $toTs) {
  [$fromTs, $toTs] = [$toTs, $fromTs];
}

$days = [];
$cur = $fromTs;
while ($cur <= $toTs) {
  $days[] = date('Y-m-d', $cur);
  $cur = strtotime('+1 day', $cur);
}
$labelsDaily = array_map(fn($d)=>date('M d', strtotime($d)), $days);
$daysDiff = count($days);
$userSet  = $hasFrom || $hasTo;

/* ---------------- KPI COUNTERS ---------------- */
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
$totalRooms = max(1,$kpi['rooms']);

/* ---------------- OCCUPANCY ---------------- */
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
    AND ren.id IS NOT NULL";
$occNow=$occPrev=0;
if($st=$conn->prepare($sqlOcc)){ $st->bind_param("ss",$todayStr,$todayStr); $st->execute(); $st->bind_result($occNow); $st->fetch(); $st->close(); }
if($st=$conn->prepare($sqlOcc)){ $st->bind_param("ss",$prevStr,$prevStr);   $st->execute(); $st->bind_result($occPrev); $st->fetch(); $st->close(); }
$occPctNow  = round(100.0*$occNow/$totalRooms,1);
$occPctPrev = round(100.0*$occPrev/$totalRooms,1);
$occDelta   = $occPctNow - $occPctPrev;

/* ---------------- DAILY INCOME ---------------- */
$incomeMap = array_fill_keys($days, 0.0);
$sqlInc = "SELECT DATE(payment_date) d, SUM(amount) amt
           FROM payments
           WHERE payment_date BETWEEN ? AND ?
           GROUP BY d";
if($stmt=$conn->prepare($sqlInc)){
  $stmt->bind_param("ss",$fromDate,$toDate);
  $stmt->execute(); $res=$stmt->get_result();
  while($r=$res->fetch_assoc()){
    if(isset($incomeMap[$r['d']])) $incomeMap[$r['d']] = (float)$r['amt'];
  }
  $stmt->close();
}
$dailyIncome = array_values($incomeMap);
$totalIncome = array_sum($dailyIncome);

/* ---------------- PIE CHART (Vacant vs Occupied) ---------------- */
$pieLabels = ['Vacant','Occupied'];
$pieVals   = [$kpi['vacant'], $kpi['occupied']];

/* ---------------- FEEDS ---------------- */
$pp = 5;
$p_pay = max(1,(int)($_GET['p_pay'] ?? 1));
$p_rent= max(1,(int)($_GET['p_rent']?? 1));
$p_ten = max(1,(int)($_GET['p_ten'] ?? 1));

$total_pay=0;
if($res=$conn->query("SELECT COUNT(*) FROM payments")){ $total_pay=(int)$res->fetch_row()[0]; $res->close(); }
$pay_pages = max(1,(int)ceil($total_pay/$pp));
$pay_rows=[];
$sql="SELECT p.amount, p.payment_date, rm.room_label, h.name house_name
      FROM payments p
      JOIN renters r ON r.id=p.renter_id
      JOIN rooms rm  ON rm.id=r.room_id
      JOIN floors f  ON f.id=rm.floor_id
      JOIN houses h  ON h.id=f.house_id
      ORDER BY p.payment_date DESC, p.id DESC
      LIMIT ?,?";
if($st=$conn->prepare($sql)){
  $off = ($p_pay-1)*$pp;
  $st->bind_param("ii",$off,$pp);
  $st->execute();
  $res=$st->get_result();
  while($row=$res->fetch_assoc()) $pay_rows[]=$row;
  $st->close();
}

$total_rent=0;
if($res=$conn->query("SELECT COUNT(*) FROM renters")){ $total_rent=(int)$res->fetch_row()[0]; $res->close(); }
$rent_pages=max(1,(int)ceil($total_rent/$pp));
$rent_rows=[];
$sql="SELECT r.start_date, rm.room_label, h.name house_name
      FROM renters r
      JOIN rooms rm ON rm.id=r.room_id
      JOIN floors f ON f.id=rm.floor_id
      JOIN houses h ON h.id=f.house_id
      ORDER BY r.start_date DESC, r.id DESC
      LIMIT ?,?";
if($st=$conn->prepare($sql)){
  $off = ($p_rent-1)*$pp;
  $st->bind_param("ii",$off,$pp);
  $st->execute();
  $res=$st->get_result();
  while($row=$res->fetch_assoc()) $rent_rows[]=$row;
  $st->close();
}

$total_ten=0;
if($res=$conn->query("SELECT COUNT(*) FROM tenants")){ $total_ten=(int)$res->fetch_row()[0]; $res->close(); }
$ten_pages=max(1,(int)ceil($total_ten/$pp));
$ten_rows=[];
$sql="SELECT CONCAT(last_name,', ',first_name) name, created_at
      FROM tenants
      ORDER BY created_at DESC, id DESC
      LIMIT ?,?";
if($st=$conn->prepare($sql)){
  $off = ($p_ten-1)*$pp;
  $st->bind_param("ii",$off,$pp);
  $st->execute();
  $res=$st->get_result();
  while($row=$res->fetch_assoc()) $ten_rows[]=$row;
  $st->close();
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
    :root{ --pad-primary:#361E5C; --pad-accent:#6141A6; --muted:#6c757d; }
    .main{ margin-top:60px; padding:20px 20px 0 20px; }
    @media (min-width: 992px){ .main{ margin-left:250px; } }
    .kcard{background:linear-gradient(180deg,#ffffff 0%, #f8f7fc 100%);border:1px solid rgba(0,0,0,.06);border-radius:14px;padding:1rem 1.25rem;box-shadow:0 8px 20px rgba(0,0,0,.06);height:100%;display:flex;flex-direction:column;justify-content:space-between;}
    .kpi-row .col-lg-4{display:flex;}
    .kpi-row .col-lg-4 > .kcard{flex:1;}
    .title{color:#2D1B4E;font-weight:700;}
    .badge-soft{background:#f4f1fb;color:#422d79;border:1px solid rgba(97,65,166,.2);border-radius:999px;padding:.25rem .55rem;font-weight:600;}
    .pill{display:inline-flex;align-items:center;gap:.35rem;background:linear-gradient(90deg,#6141A6,#361E5C);color:#fff;border-radius:999px;padding:.35rem .7rem;font-weight:600;box-shadow:0 6px 18px rgba(54,30,92,.25);}
    .chart-card{height:340px;}
    .chart-card > canvas{height:100% !important;width:100% !important;}
    .feed{background:#fff;border:1px solid rgba(0,0,0,.06);border-radius:14px;box-shadow:0 8px 20px rgba(0,0,0,.06);}
    .feed-header{padding:.75rem 1rem;border-bottom:1px solid rgba(0,0,0,.06);font-weight:600;}
    .feed-item{padding:.75rem 1rem;border-bottom:1px dashed rgba(0,0,0,.06);display:flex;gap:.75rem;align-items:flex-start;}
    .feed-item:last-child{border-bottom:none;}
    .feed-icon{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#6f56c5,#9478f1);color:#fff;box-shadow:0 6px 16px rgba(97,65,166,.25);font-size:16px;}
    .feed-time{color:#999;font-size:.85rem;}
    .feed-footer{padding:.5rem 1rem;border-top:1px solid rgba(0,0,0,.06);display:flex;justify-content:space-between;align-items:center;}
    .range-form{display:flex;flex-wrap:wrap;gap:.5rem .75rem;align-items:end;justify-content:flex-end;}
    .range-apply{background:linear-gradient(90deg,var(--pad-primary),var(--pad-accent));color:#fff;border:none;border-radius:.6rem;padding:.4rem .9rem;box-shadow:0 6px 16px rgba(54,30,92,.25);}
    .range-apply:hover{opacity:.95;color:#fff;}
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

    <!-- KPI Cards -->
    <div class="row g-3 mb-3 kpi-row">
      <div class="col-lg-4">
        <div class="kcard">
          <div class="title mb-1"><i class="bi bi-buildings me-2"></i>Property Summary</div>
          <div>Houses <span class="badge-soft ms-1"><?= $kpi['houses'] ?></span> • Floors <span class="badge-soft ms-1"><?= $kpi['floors'] ?></span> • Rooms <span class="badge-soft ms-1"><?= $kpi['rooms'] ?></span></div>
        </div>
      </div>
      <div class="col-lg-4">
        <div class="kcard">
          <div class="title mb-1"><i class="bi bi-door-closed me-2"></i>Room Status</div>
          <div>Vacant <span class="badge-soft ms-1"><?= $kpi['vacant'] ?></span> • Occupied <span class="badge-soft ms-1"><?= $kpi['occupied'] ?></span></div>
          <?php
            $trendIcon = $occDelta > 0 ? 'bi-arrow-up-right' : ($occDelta < 0 ? 'bi-arrow-down-right' : 'bi-dash-lg');
            $trendClass= $occDelta > 0 ? 'text-success' : ($occDelta < 0 ? 'text-danger' : 'text-muted');
            $deltaTxt  = ($occDelta>0?'+':'').number_format($occDelta,1).'%';
          ?>
          <div class="mt-2">
            <span class="pill">Now: <?= $occPctNow ?>%</span>
            <span class="ms-2 <?= $trendClass ?>"><i class="bi <?= $trendIcon ?>"></i> <?= $deltaTxt ?> vs last month</span>
          </div>
        </div>
      </div>
      <div class="col-lg-4">
        <div class="kcard">
          <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div>
              <div class="title mb-1"><i class="bi bi-cash-coin me-2"></i>Total Earnings</div>
              <div>₱ <b><?= number_format($totalIncome,2) ?></b></div>
            </div>
            <form method="get" class="range-form">
              <div>
                <label class="small text-muted">From</label>
                <input type="date" name="from" class="form-control form-control-sm" value="<?= htmlspecialchars($fromDate) ?>">
              </div>
              <div>
                <label class="small text-muted">To</label>
                <input type="date" name="to" class="form-control form-control-sm" value="<?= htmlspecialchars($toDate) ?>">
              </div>
              <button class="range-apply"><i class="bi bi-funnel me-1"></i>Apply</button>
            </form>
          </div>
          <div class="small mt-1 text-muted">
            Range: <?= date('M d, Y',$fromTs) ?> → <?= date('M d, Y',$toTs) ?> (<?= $daysDiff ?> days)
          </div>
        </div>
      </div>
    </div>

    <!-- Charts -->
    <div class="row g-3 mb-3">
      <div class="col-lg-8">
        <div class="card h-100">
          <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
            <strong>Daily Income</strong>
            <div class="text-muted small d-flex flex-column text-end">
              <span><?= date('M d, Y',$fromTs) ?> → <?= date('M d, Y',$toTs) ?></span>
              <span><?= $daysDiff ?> days total</span>
            </div>
          </div>
          <div class="card-body chart-card"><canvas id="chIncomeCol"></canvas></div>
        </div>
      </div>
      <div class="col-lg-4">
        <div class="card h-100">
          <div class="card-header"><strong>Room Status Mix</strong></div>
          <div class="card-body chart-card"><canvas id="chPie"></canvas></div>
        </div>
      </div>
    </div>

    <!-- Feeds -->
    <div class="row g-3">
      <div class="col-lg-4">
        <div class="feed h-100">
          <div class="feed-header"><i class="bi bi-currency-exchange me-2"></i>Latest Payments</div>
          <?php if (empty($pay_rows)): ?>
            <div class="p-3 text-muted">No payments yet.</div>
          <?php else: foreach($pay_rows as $i=>$r): ?>
            <div class="feed-item">
              <div class="feed-icon"><i class="bi bi-cash-coin"></i></div>
              <div>
                <div><b>₱<?= number_format((float)$r['amount'],2) ?></b> • <?= htmlspecialchars($r['house_name'].' · '.$r['room_label']) ?></div>
                <div class="feed-time"><i class="bi bi-clock me-1"></i><?= date('M d, Y', strtotime($r['payment_date'])) ?></div>
              </div>
            </div>
          <?php endforeach; endif; ?>
          <div class="feed-footer">
            <a class="btn btn-sm btn-outline-secondary <?= $p_pay<=1?'disabled':'' ?>" href="<?= $p_pay<=1?'#':qs(['p_pay'=>$p_pay-1]) ?>">Prev</a>
            <span class="small text-muted">Page <?= $p_pay ?> / <?= $pay_pages ?></span>
            <a class="btn btn-sm btn-outline-secondary <?= $p_pay>=$pay_pages?'disabled':'' ?>" href="<?= $p_pay>=$pay_pages?'#':qs(['p_pay'=>$p_pay+1]) ?>">Next</a>
          </div>
        </div>
      </div>

      <div class="col-lg-4">
        <div class="feed h-100">
          <div class="feed-header"><i class="bi bi-door-open me-2"></i>New Renters</div>
          <?php if (empty($rent_rows)): ?>
            <div class="p-3 text-muted">No renters yet.</div>
          <?php else: foreach($rent_rows as $r): ?>
            <div class="feed-item">
              <div class="feed-icon"><i class="bi bi-person-plus"></i></div>
              <div>
                <div><b><?= htmlspecialchars($r['house_name'].' · '.$r['room_label']) ?></b></div>
                <div class="feed-time"><i class="bi bi-calendar-event me-1"></i>Start: <?= date('M d, Y', strtotime($r['start_date'])) ?></div>
              </div>
            </div>
          <?php endforeach; endif; ?>
          <div class="feed-footer">
            <a class="btn btn-sm btn-outline-secondary <?= $p_rent<=1?'disabled':'' ?>" href="<?= $p_rent<=1?'#':qs(['p_rent'=>$p_rent-1]) ?>">Prev</a>
            <span class="small text-muted">Page <?= $p_rent ?> / <?= $rent_pages ?></span>
            <a class="btn btn-sm btn-outline-secondary <?= $p_rent>=$rent_pages?'disabled':'' ?>" href="<?= $p_rent>=$rent_pages?'#':qs(['p_rent'=>$p_rent+1]) ?>">Next</a>
          </div>
        </div>
      </div>

      <div class="col-lg-4">
        <div class="feed h-100">
          <div class="feed-header"><i class="bi bi-people me-2"></i>New Tenants</div>
          <?php if (empty($ten_rows)): ?>
            <div class="p-3 text-muted">No tenants yet.</div>
          <?php else: foreach($ten_rows as $r): ?>
            <div class="feed-item">
              <div class="feed-icon"><i class="bi bi-person-badge"></i></div>
              <div>
                <div><b><?= htmlspecialchars($r['name']) ?></b></div>
                <div class="feed-time"><i class="bi bi-calendar-check me-1"></i><?= date('M d, Y', strtotime($r['created_at'])) ?></div>
              </div>
            </div>
          <?php endforeach; endif; ?>
          <div class="feed-footer">
            <a class="btn btn-sm btn-outline-secondary <?= $p_ten<=1?'disabled':'' ?>" href="<?= $p_ten<=1?'#':qs(['p_ten'=>$p_ten-1]) ?>">Prev</a>
            <span class="small text-muted">Page <?= $p_ten ?> / <?= $ten_pages ?></span>
            <a class="btn btn-sm btn-outline-secondary <?= $p_ten>=$ten_pages?'disabled':'' ?>" href="<?= $p_ten>=$ten_pages?'#':qs(['p_ten'=>$p_ten+1]) ?>">Next</a>
          </div>
        </div>
      </div>
    </div>
  </main>

  <?php include __DIR__ . '/../admin/footer.php'; ?>
  <script src="<?= $BASE ?>/../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <script>
    const c1='#6141A6', c3='#20c997', c4='#dc3545';

    const labels  = <?= json_encode($labelsDaily) ?>;
    const income  = <?= json_encode($dailyIncome) ?>;
    const pieL    = <?= json_encode($pieLabels) ?>;
    const pieV    = <?= json_encode($pieVals) ?>;

    // Daily Income Line Chart
    new Chart(document.getElementById('chIncomeCol'), {
      type:'line',
      data:{
        labels,
        datasets:[{
          label:'₱ Daily Income',
          data: income,
          borderColor:c1,
          backgroundColor:'rgba(97,65,166,0.2)',
          tension:0.3,
          fill:true,
          pointRadius:4
        }]
      },
      options:{
        maintainAspectRatio:false,
        plugins:{ legend:{ position:'bottom' } },
        scales:{
          y:{ beginAtZero:true, title:{ display:true, text:'Amount (₱)' } },
          x:{ title:{ display:true, text:'Days' } }
        }
      }
    });

    // Pie Chart
    new Chart(document.getElementById('chPie'), {
      type:'pie',
      data:{ labels: pieL, datasets:[{ data: pieV, backgroundColor:[c3,c4] }] },
      options:{ maintainAspectRatio:false, plugins:{ legend:{ position:'bottom' } } }
    });
  </script>
</body>
</html>
