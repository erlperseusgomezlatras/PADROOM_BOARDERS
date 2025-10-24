<?php
// Database connection settings
$host = "localhost";      // XAMPP default hostname
$user = "root";           // Default MySQL username
$pass = "";               // Default password is empty in XAMPP
$dbname = "school_grading_db";  // Your database name

// Create connection
$conn = new mysqli($host, $user, $pass, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
// If no error:
# echo "Connected successfully"; // (optional: uncomment for testing)
?>
