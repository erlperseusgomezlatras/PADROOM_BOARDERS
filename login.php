<?php
session_start();
require_once __DIR__ . '/inclusions/connection.php';

$err = "";

// RECAPTCHA SECRET KEY (replace this with your real secret key from Google)
$recaptcha_secret = "6LelIfYrAAAAAF17Oh88_Vc8CP6FeLwO_QdswWcg";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $recaptcha_response = $_POST['g-recaptcha-response'] ?? '';

    // Verify Google reCAPTCHA
    $verify_response = file_get_contents("https://www.google.com/recaptcha/api/siteverify?secret=$recaptcha_secret&response=$recaptcha_response");
    $response_data = json_decode($verify_response);

    if ($response_data->success) {
        if ($username && $password) {
            $stmt = $conn->prepare("SELECT id, username, password, full_name FROM users WHERE username = ? AND is_active = 1 LIMIT 1");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows === 1) {
                $stmt->bind_result($id, $db_user, $db_pass, $full_name);
                $stmt->fetch();

                if ($password === $db_pass) {
                    $_SESSION['user_id'] = $id;
                    $_SESSION['username'] = $db_user;
                    $_SESSION['full_name'] = $full_name ?: $db_user;

                    echo "<script>
                            document.addEventListener('DOMContentLoaded', () => {
                                const overlay = document.getElementById('overlay');
                                overlay.classList.add('active');
                                setTimeout(() => {
                                    window.location.href='/PADROOM_BOARDERS/admin/dashboard.php';
                                }, 3000);
                            });
                          </script>";
                } else {
                    $err = "Invalid username or password.";
                }
            } else {
                $err = "Invalid username or password.";
            }
            $stmt->close();
        } else {
            $err = "Please enter your username and password.";
        }
    } else {
        $err = "Please verify that you're not a robot.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Login - Padroom</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link href="/assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="/assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">

  <!-- reCAPTCHA -->
  <script src="https://www.google.com/recaptcha/api.js" async defer></script>

  <style>
    :root {
      --bg1: #2e005f;
      --bg2: #6b00d5;
      --accent: #8a3ff5;
    }

    body {
      height: 100vh;
      background: linear-gradient(135deg, var(--bg1), var(--bg2));
      display: flex;
      align-items: center;
      justify-content: center;
      font-family: "Poppins", sans-serif;
      color: #fff;
      overflow: hidden;
    }

    /* Floating bubbles */
    .bubble {
      position: absolute;
      border-radius: 50%;
      background: rgba(255, 255, 255, 0.05);
      animation: float 10s infinite ease-in-out alternate;
      pointer-events: none;
    }

    .bubble:nth-child(1) { width: 150px; height: 150px; top: 10%; left: 15%; animation-delay: 0s; }
    .bubble:nth-child(2) { width: 80px; height: 80px; top: 70%; left: 5%; animation-delay: 2s; }
    .bubble:nth-child(3) { width: 120px; height: 120px; top: 40%; right: 10%; animation-delay: 1s; }
    .bubble:nth-child(4) { width: 60px; height: 60px; bottom: 10%; right: 30%; animation-delay: 3s; }

    @keyframes float {
      0% { transform: translateY(0px) rotate(0deg); }
      100% { transform: translateY(-40px) rotate(360deg); }
    }

    .login-card {
      position: relative;
      background: rgba(255, 255, 255, 0.05);
      backdrop-filter: blur(20px);
      border: 1px solid rgba(255, 255, 255, 0.1);
      padding: 2.5rem 2rem;
      width: 100%;
      max-width: 380px;
      text-align: center;
      z-index: 1;
      box-shadow: 0 10px 30px rgba(0,0,0,0.3);
    }

    .login-card h2 {
      font-weight: 600;
      margin-bottom: 2rem;
    }

    .input-group {
      background: rgba(255, 255, 255, 0.1);
      margin-bottom: 1rem;
      display: flex;
      align-items: center;
      padding: 0.5rem;
      border: none;
    }

    .input-group-text {
      background: transparent;
      color: #fff;
      border: none;
    }

    .form-control {
      background: transparent;
      border: none;
      color: #fff;
      padding: 0.5rem;
    }

    .form-control:focus {
      outline: none;
      box-shadow: none;
    }

    ::placeholder {
      color: #d2c9f5;
    }

    .g-recaptcha {
      display: flex;
      justify-content: center;
      margin-top: 1rem;
    }

    .btn-login {
      background: linear-gradient(90deg, var(--accent), var(--bg2));
      color: #fff;
      border: none;
      padding: 0.75rem 0;
      width: 100%;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      margin-top: 1.2rem;
      transition: 0.3s ease;
    }

    .btn-login:hover {
      opacity: 0.9;
    }

    .alert {
      background: rgba(255, 70, 70, 0.1);
      color: #ffb3b3;
      border: 1px solid rgba(255, 80, 80, 0.2);
      border-radius: 0;
      margin-bottom: 1rem;
      font-size: 0.9rem;
    }

    /* Loading overlay */
    .overlay {
      position: absolute;
      inset: 0;
      background: rgba(0, 0, 0, 0.6);
      backdrop-filter: blur(15px);
      display: flex;
      align-items: center;
      justify-content: center;
      flex-direction: column;
      gap: 15px;
      opacity: 0;
      pointer-events: none;
      transition: opacity 0.3s ease;
    }

    .overlay.active {
      opacity: 1;
      pointer-events: auto;
    }

    .spinner {
      width: 45px;
      height: 45px;
      border: 5px solid rgba(255, 255, 255, 0.3);
      border-top: 5px solid #fff;
      border-radius: 50%;
      animation: spin 1s linear infinite;
    }

    @keyframes spin {
      to { transform: rotate(360deg); }
    }

    .status-text {
      font-size: 1rem;
    }
  </style>
</head>
<body>

  <div class="bubble"></div>
  <div class="bubble"></div>
  <div class="bubble"></div>
  <div class="bubble"></div>

  <div class="login-card">
    <h2>Login</h2>

    <?php if ($err): ?>
      <div class="alert text-center"><?= htmlspecialchars($err) ?></div>
    <?php endif; ?>

    <form method="POST">
      <div class="input-group">
        <span class="input-group-text"><i class="bi bi-person"></i></span>
        <input type="text" name="username" class="form-control" placeholder="Username" required>
      </div>
      <div class="input-group">
        <span class="input-group-text"><i class="bi bi-lock"></i></span>
        <input type="password" name="password" class="form-control" placeholder="Password" required>
      </div>

      <!-- Google reCAPTCHA -->
      <div class="g-recaptcha" data-sitekey="6LelIfYrAAAAADH1GEo_jAw1h2tuGQbY4au9ZiTI"></div>

      <button type="submit" class="btn-login">Log In</button>
    </form>

    <div class="overlay" id="overlay">
      <div class="spinner"></div>
      <div class="status-text">Logging in...</div>
    </div>
  </div>

<script src="/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>
