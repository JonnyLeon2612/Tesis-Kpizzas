<?php
// config/db.php
date_default_timezone_set('America/Caracas');
$host = 'localhost';
$db = 'kpizzas_db';
$user = 'root'; // XAMPP por defecto
$pass = ''; // XAMPP por defecto (vacío)
$dsn = "mysql:host=$host;dbname=$db;charset=utf8mb4";
$options = [
PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
PDO::ATTR_EMULATE_PREPARES => false,
];
try {
$pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
http_response_code(500);
die('Error de conexión: ' . $e->getMessage());
}