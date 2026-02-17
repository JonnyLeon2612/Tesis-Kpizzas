<?php
require_once '../config/db.php';

$term = $_GET['term'] ?? '';
$clientes = [];

if ($term) {
    // Buscamos por Nombre, Teléfono o Cédula
    $stmt = $pdo->prepare("SELECT * FROM cliente WHERE nombre LIKE ? OR telefono LIKE ? OR cedula LIKE ? LIMIT 5");
    $stmt->execute(["%$term%", "%$term%", "%$term%"]);
    $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

header('Content-Type: application/json');
echo json_encode($clientes);
?>