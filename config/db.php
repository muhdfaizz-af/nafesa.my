<?php
$servername = "localhost";
$username = "saudagaa_chocoalbab";
$password = "Chocoalbab@123";
$dbname = "saudagaa_nafesa";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
