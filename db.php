<?php
$servername = "127.0.0.1";
$username = "root";
$password = "";
$database = "accounting_system";
$root = "";

$conn = mysqli_connect($servername, $username, $password, $database, $root);

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}
?>