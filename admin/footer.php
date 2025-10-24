<?php $BASE = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/'); ?>
<style>
  :root{
    --footer-bg:#2D1B4E;
    --footer-border:#3E2B64;
    --footer-text:#FFFFFF;
    --footer-muted:#CBB9E8;
  }

  #footer{
    background:var(--footer-bg);
    color:var(--footer-text);
    border-top:1px solid var(--footer-border);
    width:100%;
    position:relative;
    bottom:0;
  }

  /* Match sidebar offset */
  @media (min-width: 992px){
    #footer{ margin-left:250px; }
  }
  @media (max-width: 991.98px){
    #footer{ margin-left:0; }
  }

  #footer .bar{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:1rem;
    padding:.55rem 1rem;
  }
  #footer a{
    color:var(--footer-muted);
    text-decoration:none;
  }
  #footer a:hover{ color:#fff; }
</style>

<footer id="footer" class="footer mt-auto">
  <div class="bar">
    <span>© <?= date('Y') ?> Padroom</span>
    <span class="small">Built with ❤️ on NiceAdmin</span>
  </div>
</footer>

<!-- Core JS -->
<script src="<?= $BASE ?>/../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="<?= $BASE ?>/../assets/js/main.js"></script>
