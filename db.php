<?php
$servername = "127.0.0.1";
$username = "root";
$password = "";
$database = "acc_sys";

$conn = mysqli_connect($servername, $username, $password, $database);

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}
?>