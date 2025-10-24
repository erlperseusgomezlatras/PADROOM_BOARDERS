<?php
if (session_status() === PHP_SESSION_NONE) session_start();

// already logged in?
if (!isset($_SESSION['user_id'])) {
  header('Location: /login.php');
  exit;
}

// handy values
$_SESSION['full_name'] = $_SESSION['full_name'] ?? 'Administrator';
$_SESSION['username']  = $_SESSION['username']  ?? 'admin123';
