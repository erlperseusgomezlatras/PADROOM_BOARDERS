<?php
// admin/logout.php
session_start();

// Clear session and cookies
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Destroy session completely
session_destroy();

// Wait 5 seconds before redirect to login page (outside /admin/)
header("Refresh: 5; URL=/PADROOM_BOARDERS/login.php");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Signing Out...</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <style>
    :root {
      --vio1: #361E5C;
      --vio2: #6141A6;
      --green: #4ADE80;
    }

    * {
      box-sizing: border-box;
    }

    body {
      min-height: 100vh;
      margin: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      background: linear-gradient(135deg, var(--vio1), var(--vio2));
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
      color: #fff;
      overflow: hidden;
    }

    .card {
      width: min(520px, 92%);
      background: rgba(255, 255, 255, 0.1);
      border: 1px solid rgba(255, 255, 255, 0.15);
      border-radius: 16px;
      padding: 28px;
      box-shadow: 0 20px 60px rgba(0, 0, 0, 0.35);
      animation: pop 0.25s ease-out;
      text-align: center;
      backdrop-filter: blur(10px);
      position: relative;
    }

    @keyframes pop {
      from { transform: scale(0.96); opacity: 0; }
      to { transform: scale(1); opacity: 1; }
    }

    h1 {
      margin: 0.25rem 0 1.25rem;
      font-weight: 700;
      font-size: 1.8rem;
    }

    p {
      margin: 1rem 0;
      color: #e6dfff;
      font-size: 1rem;
    }

    /* Spinner */
    .spinner {
      margin: 1rem auto 1.5rem;
      width: 60px;
      height: 60px;
      border: 6px solid rgba(255, 255, 255, 0.2);
      border-top-color: #fff;
      border-radius: 50%;
      animation: spin 1s linear infinite;
    }

    @keyframes spin {
      from { transform: rotate(0deg); }
      to { transform: rotate(360deg); }
    }

    /* Checkmark */
    .checkmark-wrapper {
      display: none;
      justify-content: center;
      align-items: center;
      margin: 1rem auto 1.5rem;
    }

    .checkmark {
      width: 60px;
      height: 60px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      background: var(--green);
      transform: scale(0);
      opacity: 0;
      animation-fill-mode: forwards;
    }

    .checkmark svg {
      width: 30px;
      height: 30px;
      stroke: white;
      stroke-width: 3;
      stroke-linecap: round;
      stroke-linejoin: round;
      fill: none;
      stroke-dasharray: 48;
      stroke-dashoffset: 48;
      animation: draw 0.8s ease forwards;
      animation-delay: 0.3s;
    }

    @keyframes draw {
      to {
        stroke-dashoffset: 0;
      }
    }

    @keyframes scaleIn {
      0% { transform: scale(0); opacity: 0; }
      70% { transform: scale(1.2); opacity: 1; }
      100% { transform: scale(1); opacity: 1; }
    }

    .countdown {
      margin-top: 1rem;
      font-size: 0.95rem;
      opacity: 0.85;
    }
  </style>
</head>
<body>
  <div class="card">
    <div class="spinner"></div>
    <div class="checkmark-wrapper">
      <div class="checkmark">
        <svg viewBox="0 0 52 52">
          <path d="M14 27 L22 35 L38 18"></path>
        </svg>
      </div>
    </div>
    <h1 id="statusText">Signing you out...</h1>
    <p>Your session is ending. You’ll be redirected to login shortly.</p>
    <div class="countdown">Redirecting in <span id="timer">5</span> seconds...</div>
  </div>

  <script>
    let timeLeft = 5;
    const timer = document.getElementById('timer');
    const countdown = setInterval(() => {
      timeLeft--;
      timer.textContent = timeLeft;
      if (timeLeft <= 0) clearInterval(countdown);
    }, 1000);

    // Switch spinner to checkmark after 2 seconds
    setTimeout(() => {
      document.querySelector('.spinner').style.display = 'none';
      const checkWrapper = document.querySelector('.checkmark-wrapper');
      checkWrapper.style.display = 'flex';
      const checkmark = document.querySelector('.checkmark');
      checkmark.style.animation = 'scaleIn 0.5s ease forwards';

      document.getElementById('statusText').textContent = 'Successfully signed out';
    }, 2000);
  </script>
</body>
</html>
