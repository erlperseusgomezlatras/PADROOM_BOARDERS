<?php
session_start();
require_once __DIR__ . '/inclusions/connection.php';

$err = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

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

                header("Location: /PADROOM_BOARDERS/admin/dashboard.php");
                exit;
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
  <link href="/assets/css/style.css" rel="stylesheet">

  <style>
    :root {
      --vio1: #361E5C;
      --vio2: #6141A6;
      --vio3: #8a5ff9;
      --white: #ffffff;
    }

    body {
      height: 100vh;
      margin: 0;
      background: linear-gradient(135deg, var(--vio1), var(--vio2) 60%, var(--vio3));
      display: flex;
      align-items: center;
      justify-content: center;
      font-family: "Poppins", sans-serif;
      color: var(--white);
    }

    .login-card {
      background: rgba(255, 255, 255, 0.08);
      border-radius: 20px;
      padding: 3rem 2.5rem;
      max-width: 400px;
      width: 100%;
      text-align: center;
      box-shadow: 0 20px 50px rgba(0, 0, 0, 0.5);
      backdrop-filter: blur(12px);
      animation: fadeIn 0.6s ease-in-out;
    }

    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(25px); }
      to { opacity: 1; transform: translateY(0); }
    }

    .brand-icon {
      background: rgba(255, 255, 255, 0.15);
      width: 70px;
      height: 70px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 50%;
      margin: 0 auto 1.5rem;
    }

    .brand-icon i {
      font-size: 2rem;
      color: var(--white);
    }

    .login-card h2 {
      margin-bottom: 2rem;
      font-weight: 600;
      letter-spacing: 1px;
    }

    .form-control {
      background: transparent;
      border: none;
      border-bottom: 1px solid rgba(255, 255, 255, 0.3);
      border-radius: 0;
      color: #fff;
      padding: 0.75rem;
      font-size: 0.95rem;
      text-align: center;
      transition: border-color 0.3s ease;
    }

    .form-control:focus {
      box-shadow: none;
      border-bottom-color: var(--vio3);
    }

    .form-label {
      display: none;
    }

    .input-group-text {
      background: transparent;
      border: none;
      color: #fff;
    }

    .btn-login {
      background: #fff;
      color: var(--vio1);
      border: none;
      border-radius: 50px;
      padding: 0.6rem 2rem;
      font-weight: 600;
      margin-top: 1.5rem;
      transition: all 0.3s ease;
    }

    .btn-login:hover {
      background: var(--vio3);
      color: #fff;
      box-shadow: 0 0 15px rgba(138, 95, 249, 0.7);
    }

    .alert {
      background: rgba(255, 70, 70, 0.1);
      color: #ffb3b3;
      border: 1px solid rgba(255, 80, 80, 0.2);
      border-radius: 8px;
      margin-bottom: 1rem;
      font-size: 0.9rem;
    }

    .extra-links {
      margin-top: 1.2rem;
      font-size: 0.85rem;
      opacity: 0.85;
    }

    .extra-links a {
      color: var(--white);
      text-decoration: none;
      transition: 0.3s;
    }

    .extra-links a:hover {
      text-decoration: underline;
      opacity: 1;
    }
  </style>
</head>
<body>

<div class="login-card">
  <div class="brand-icon">
    <i class="bi bi-house-door-fill"></i>
  </div>
  <h2>LOG IN</h2>

  <?php if ($err): ?>
    <div class="alert text-center"><?= htmlspecialchars($err) ?></div>
  <?php endif; ?>

  <form method="POST" autocomplete="off">
    <div class="mb-3 input-group">
      <span class="input-group-text"><i class="bi bi-person"></i></span>
      <input type="text" name="username" class="form-control" placeholder="Username" required autofocus>
    </div>
    <div class="mb-3 input-group">
      <span class="input-group-text"><i class="bi bi-lock"></i></span>
      <input type="password" name="password" class="form-control" placeholder="Password" required>
    </div>

    <div class="form-check text-start" style="margin-top:1rem; color:#ddd;">
      <input class="form-check-input" type="checkbox" value="" id="rememberMe" style="background:transparent;border:1px solid rgba(255,255,255,0.5);">
      <label class="form-check-label" for="rememberMe"> Remember me </label>
    </div>

    <button type="submit" class="btn btn-login mt-3">Login</button>
  </form>

  <div class="extra-links mt-3">
    <a href="#">Forgot Password?</a>
  </div>
</div>

<script src="/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>
