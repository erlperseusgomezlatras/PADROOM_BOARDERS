<?php
// DB connection — adjust credentials if yours differ
$DB_HOST = '127.0.0.1';
$DB_USER = 'root';
$DB_PASS = '';                  // <- set your password if any
$DB_NAME = 'padroom_borders';

$conn = @new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($conn->connect_error) {
  die('Database connection failed: ' . $conn->connect_error);
}
$conn->set_charset('utf8mb4');
