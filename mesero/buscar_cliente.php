<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../config/db.php';
require_role('mesero');

header('Content-Type: application/json');
$cedula = $_GET['cedula'] ?? '';

if (strlen($cedula) >= 3) {
    try {
        $stmt = $pdo->prepare("SELECT id, nombre, cedula FROM cliente WHERE cedula = ? LIMIT 1");
        $stmt->execute([$cedula]);
        $cliente = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['found' => !!$cliente, 'data' => $cliente]);
    } catch (Exception $e) {
        echo json_encode(['found' => false, 'error' => $e->getMessage()]);
    }
} else {
    echo json_encode(['found' => false]);
}