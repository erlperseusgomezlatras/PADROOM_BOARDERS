<?php $BASE = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/'); ?>
<style>
  :root{
    --footer-bg: linear-gradient(90deg, #361E5C 0%, #6141A6 100%);
    --footer-text:#ffffff;
    --footer-muted:#d2c6e9;
    --footer-border:rgba(255,255,255,0.08);
  }

  #footer{
    background:var(--footer-bg);
    color:var(--footer-text);
    border-top:1px solid var(--footer-border);
    width:100%;
    position:relative;
    bottom:0;
    font-size:0.95rem;
    box-shadow:0 -4px 25px rgba(54,30,92,0.25);
    z-index:1000;
  }

  @media (min-width: 992px){
    #footer{ margin-left:250px; }
  }

  #footer .bar{
    display:flex;
    align-items:center;
    justify-content:space-between;
    flex-wrap:wrap;
    gap:.8rem;
    padding:.9rem 1.25rem;
  }

  #footer .brand{
    font-weight:700;
    letter-spacing:0.5px;
  }

  #footer a{
    color:var(--footer-muted);
    text-decoration:none;
    transition:color .2s ease, transform .2s ease;
  }
  #footer a:hover{
    color:#fff;
    transform:translateY(-2px);
  }

  /* Social icons */
  .socials{
    display:flex;
    align-items:center;
    gap:.7rem;
  }
  .socials a{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    width:32px; height:32px;
    border:1px solid var(--footer-border);
    border-radius:50%;
    color:var(--footer-muted);
    transition:all .2s ease;
  }
  .socials a:hover{
    color:#fff;
    background:rgba(255,255,255,0.1);
    border-color:#fff;
  }
</style>

<footer id="footer" class="footer mt-auto">
  <div class="bar">
    <div class="brand">
      © <?= date('Y') ?> <strong>Padroom</strong>
    </div>
    <div class="socials">
      <a href="#" title="Facebook"><i class="bi bi-facebook"></i></a>
      <a href="#" title="Instagram"><i class="bi bi-instagram"></i></a>
      <a href="#" title="Twitter / X"><i class="bi bi-twitter-x"></i></a>
      <a href="#" title="GitHub"><i class="bi bi-github"></i></a>
    </div>
    <div class="small">
      Built with <span style="color:#ff4d8d;">❤️</span> by <a href="#" target="_blank">Padroom Dev</a>
    </div>
  </div>
</footer>

<!-- Core JS -->
<script src="<?= $BASE ?>/../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="<?= $BASE ?>/../assets/js/main.js"></script>
