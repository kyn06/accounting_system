<?php
$servername = "127.0.0.1:3006";
$username = "root";
$password = "aespa";
$database = "accounting_system";

$conn = mysqli_connect($servername, $username, $password, $database);

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}
?>
