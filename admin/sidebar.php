<?php
$BASE = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$current = basename($_SERVER['PHP_SELF']);
function active($files){ return in_array($GLOBALS['current'], (array)$files) ? '' : 'collapsed'; }
?>
<style>
  :root{
    --sidebar-bg:#2D1B4E;
    --sidebar-border:#3E2B64;
    --sidebar-text:#FFFFFF;
    --sidebar-muted:#CBB9E8;
    --sidebar-hover:rgba(255,255,255,.1);
    --sidebar-active:rgba(255,255,255,.15);
  }

  #sidebar{
    background:var(--sidebar-bg);
    border-right:1px solid var(--sidebar-border);
    position:fixed;
    top:60px;
    left:0;
    width:250px;
    height:calc(100vh - 60px);
    z-index:1035;
    display:flex;
    flex-direction:column;
    transition:left .25s ease;
  }

  @media (min-width: 992px){
    body:not(.toggle-sidebar) #main{ margin-left:250px; }
    body.toggle-sidebar #sidebar{ left:-260px; }
    body.toggle-sidebar #main{ margin-left:0; }
  }

  @media (max-width: 991.98px){
    #sidebar{ left:-260px; }
    body.toggle-sidebar #sidebar{ left:0; }
    #main{ margin-left:0 !important; }
  }

  #sidebar .sidebar-nav{ display:flex; flex-direction:column; height:100%; padding:.75rem; margin:0; }
  #sidebar .sidebar-brand{
    display:flex; align-items:center; gap:.6rem;
    padding:.35rem .25rem .8rem;
    margin:0 0 .5rem 0;
    border-bottom:1px solid var(--sidebar-border);
  }
  #sidebar .sidebar-brand img{ height:34px; border-radius:6px; }
  #sidebar .sidebar-brand span{ color:var(--sidebar-text); font-weight:600; }

  .nav-stack{ flex:1; display:flex; flex-direction:column; gap:6px; }
  .push-bottom{ margin-top:auto; }
  #sidebar .nav-item{ list-style:none; }

  #sidebar .nav-link{
    display:flex; align-items:center; gap:.55rem;
    color:var(--sidebar-text);
    background:transparent!important;
    border:none!important;
    padding:.5rem .25rem!important;
    border-radius:8px;
    text-decoration:none;
    transition:background .2s, transform .2s;
  }
  #sidebar .nav-link i{ color:var(--sidebar-muted); font-size:1.1rem; }
  #sidebar .nav-link:hover{ background:var(--sidebar-hover); transform:translateX(4px); }
  #sidebar .nav-link:not(.collapsed){ background:var(--sidebar-active); }

  #sidebar .nav-content{
    margin:.15rem 0 .35rem 1.6rem;
    padding-left:.4rem;
    border-left:1px dashed var(--sidebar-border);
  }
  #sidebar .nav-content a{
    display:flex;
    align-items:center;
    gap:.45rem;
    color:var(--sidebar-text);
    padding:.35rem 0!important;
    background:transparent!important;
    border-radius:6px;
    opacity:.9;
    transition:background .2s, transform .2s, opacity .2s;
  }
  #sidebar .nav-content a:hover{
    opacity:1;
    background:var(--sidebar-hover);
    transform:translateX(4px);
  }
</style>

<aside id="sidebar" class="sidebar">
  <ul class="sidebar-nav" id="sidebar-nav">

    <!-- Brand -->
    <li class="sidebar-brand">
      <img src="<?= $BASE ?>/../assets/img/logo.png" alt="Padroom">
      <span>Padroom</span>
    </li>

    <div class="nav-stack">
      <!-- Dashboard -->
      <li class="nav-item">
        <a class="nav-link <?= $current==='dashboard.php'?'':'collapsed' ?>" href="<?= $BASE ?>/dashboard.php">
          <i class="bi bi-grid"></i><span>Dashboard</span>
        </a>
      </li>

      <!-- Tenants -->
      <li class="nav-item">
        <a class="nav-link <?= active(['tenants_create.php','tenants_manage.php','tenants_archive.php']) ?>"
           data-bs-target="#tenants-nav" data-bs-toggle="collapse" href="#">
          <i class="bi bi-people"></i><span>Tenants</span><i class="bi bi-chevron-down ms-auto"></i>
        </a>
        <ul id="tenants-nav"
            class="nav-content collapse <?= active(['tenants_create.php','tenants_manage.php','tenants_archive.php'])==''?'show':'' ?>"
            data-bs-parent="#sidebar-nav">
          <li><a href="<?= $BASE ?>/tenants_create.php" class="<?= $current==='tenants_create.php'?'active':'' ?>"><i class="bi bi-circle"></i><span>Add Tenants</span></a></li>
          <li><a href="<?= $BASE ?>/tenants_manage.php" class="<?= $current==='tenants_manage.php'?'active':'' ?>"><i class="bi bi-circle"></i><span>Manage Tenants</span></a></li>
          <li><a href="<?= $BASE ?>/tenants_archive.php" class="<?= $current==='tenants_archive.php'?'active':'' ?>"><i class="bi bi-circle"></i><span>Tenants Archives</span></a></li>
        </ul>
      </li>

      <!-- House -->
      <li class="nav-item">
        <a class="nav-link <?= active(['houses_manage.php','rooms_edit.php','houses_archive.php']) ?>"
           data-bs-target="#house-nav" data-bs-toggle="collapse" href="#">
          <i class="bi bi-house"></i><span>House</span><i class="bi bi-chevron-down ms-auto"></i>
        </a>
        <ul id="house-nav"
            class="nav-content collapse <?= active(['houses_manage.php','rooms_edit.php','houses_archive.php'])==''?'show':'' ?>"
            data-bs-parent="#sidebar-nav">
          <li><a href="<?= $BASE ?>/houses_manage.php" class="<?= $current==='houses_manage.php'?'active':'' ?>"><i class="bi bi-circle"></i><span>Add House/Floors/Rooms</span></a></li>
          <li><a href="<?= $BASE ?>/rooms_edit.php" class="<?= $current==='rooms_edit.php'?'active':'' ?>"><i class="bi bi-circle"></i><span>Manage Rooms Rates</span></a></li>
          <li><a href="<?= $BASE ?>/houses_archive.php" class="<?= $current==='houses_archive.php'?'active':'' ?>"><i class="bi bi-circle"></i><span>House Archives</span></a></li>
        </ul>
      </li>

      <!-- Renters -->
      <li class="nav-item">
        <a class="nav-link <?= active(['manage_renters.php','renters_status.php']) ?>"
           data-bs-target="#renters-nav" data-bs-toggle="collapse" href="#">
          <i class="bi bi-person-badge"></i><span>Renters</span><i class="bi bi-chevron-down ms-auto"></i>
        </a>
        <ul id="renters-nav"
            class="nav-content collapse <?= active(['manage_renters.php','renters_status.php'])==''?'show':'' ?>"
            data-bs-parent="#sidebar-nav">
          <li><a href="<?= $BASE ?>/manage_renters.php" class="<?= $current==='manage_renters.php'?'active':'' ?>"><i class="bi bi-circle"></i><span>Manage Renters</span></a></li>
          <li><a href="<?= $BASE ?>/renters_status.php" class="<?= $current==='renters_status.php'?'active':'' ?>"><i class="bi bi-circle"></i><span>View Status</span></a></li>
        </ul>
      </li>

      <!-- Logout -->
      <li class="nav-item push-bottom">
        <a class="nav-link" href="<?= $BASE ?>/logout.php">
          <i class="bi bi-box-arrow-right"></i><span>Logout</span>
        </a>
      </li>
    </div>
  </ul>
</aside>

<script>
  document.addEventListener('DOMContentLoaded', () => {
    const toggleBtn = document.querySelector('.toggle-sidebar-btn');
    if (toggleBtn) {
      toggleBtn.addEventListener('click', () => {
        document.body.classList.toggle('toggle-sidebar');
      });
    }
  });
</script>
