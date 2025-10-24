<?php
$BASE = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$displayName = htmlspecialchars($_SESSION['full_name'] ?? 'Administrator');
?>
<style>
  :root{
    --topbar-bg:#2D1B4E;             /* static dark violet background */
    --topbar-border:#3E2B64;         /* subtle border line */
    --topbar-text:#FFFFFF;
    --topbar-muted:#CBB9E8;          /* lighter muted text */
    --topbar-hover:rgba(255,255,255,.12);
    --topbar-dropdown-bg:#1E1540;
    --topbar-dropdown-border:#3E2B64;
  }

  /* Topbar container */
  #header{
    background:var(--topbar-bg);
    border-bottom:1px solid var(--topbar-border);
    z-index:1040;
  }
  #header .container-inline{
    padding:.55rem 1rem;
  }

  /* Toggle button */
  .toggle-sidebar-btn{
    color:var(--topbar-text);
    font-size:1.6rem;
    line-height:1;
    cursor:pointer;
    background:transparent;
    border:0;
    padding:.3rem .4rem;
    border-radius:6px;
    margin-right:.6rem;
    transition:background .2s ease;
  }
  .toggle-sidebar-btn:hover{
    background:var(--topbar-hover);
  }

  /* Brand */
  .brand{
    color:var(--topbar-text);
    font-weight:600;
    letter-spacing:.2px;
    text-decoration:none;
    font-size:1.1rem;
  }

  /* Profile dropdown trigger */
  .header-nav .nav-link{
    color:var(--topbar-text);
    background:transparent!important;
    border:0!important;
    padding:0!important;
  }

  /* Avatar */
  .nav-profile img{
    width:36px;
    height:36px;
    border-radius:50%;
    object-fit:cover;
    border:1px solid var(--topbar-border);
    margin-right:.5rem;
  }

  /* Dropdown menu */
  .dropdown-menu.profile{
    background:var(--topbar-dropdown-bg);
    border:1px solid var(--topbar-dropdown-border);
    border-radius:10px;
    box-shadow:0 12px 30px rgba(10,5,25,.6);
  }
  .dropdown-menu.profile .dropdown-item{
    color:var(--topbar-text);
    border-radius:8px;
    transition:background .2s ease;
  }
  .dropdown-menu.profile .dropdown-item:hover{
    background:var(--topbar-hover);
  }

  /* Dropdown header */
  .dropdown-menu.profile .dropdown-header h6{
    color:var(--topbar-text);
  }
  .dropdown-menu.profile .dropdown-header span{
    color:var(--topbar-muted);
    font-size:.85rem;
  }

  .bi-list { 
    color: white;
  }
</style>

<header id="header" class="header fixed-top d-flex align-items-center">
  <div class="container-inline d-flex align-items-center justify-content-between w-100">
    <!-- Left: Toggle + Brand -->
    <div class="d-flex align-items-center">
      <button type="button" class="toggle-sidebar-btn" aria-label="Toggle sidebar">
        <i class="bi bi-list"></i>
      </button>
      <a href="<?= $BASE ?>/dashboard.php" class="brand">Padroom</a>
    </div>

    <!-- Right: Profile Dropdown -->
    <nav class="header-nav ms-auto">
      <ul class="d-flex align-items-center mb-0">
        <li class="nav-item dropdown pe-2">
          <a class="nav-link nav-profile d-flex align-items-center" href="#" data-bs-toggle="dropdown">
            <img src="<?= $BASE ?>/../assets/img/LATRAS.jpg" alt="Profile">
            <span><?= $displayName ?></span>
          </a>
          <ul class="dropdown-menu dropdown-menu-end dropdown-menu-arrow profile">
            <li class="dropdown-header text-center">
              <h6 class="mb-0"><?= $displayName ?></h6>
              <span>Padroom Admin</span>
            </li>
            <li><hr class="dropdown-divider"></li>
            <li>
              <a class="dropdown-item d-flex align-items-center" href="<?= $BASE ?>/logout.php">
                <i class="bi bi-box-arrow-right me-2"></i> <span>Sign Out</span>
              </a>
            </li>
          </ul>
        </li>
      </ul>
    </nav>
  </div>
</header>

<script>
  // Sidebar toggle for desktop & mobile
  (function () {
    const btn = document.querySelector('.toggle-sidebar-btn');
    btn && btn.addEventListener('click', () => {
      document.body.classList.toggle('toggle-sidebar');
    });
  })();
</script>
