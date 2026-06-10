<?php
$host = 'localhost';
$dbname = 'education_tests';
$username = 'root';
$password = '';

$conn = new mysqli($host, $username, $password, $dbname);

if ($conn->connect_error) {
    die('Ошибка подключения к базе данных: ' . $conn->connect_error);
}

$conn->set_charset('utf8mb4');
?>
