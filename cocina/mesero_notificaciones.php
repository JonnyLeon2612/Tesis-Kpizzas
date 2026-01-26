<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../config/db.php';
require_role('mesero');

header('Content-Type: application/json');

$mesero_id = $_SESSION['user']['id'];

// Buscar pedidos marcados como "listo" pertenecientes a este mesero
$stmt = $pdo->prepare("
    SELECT id, mesa_id, tipo_servicio, fecha_creacion
    FROM comanda
    WHERE estado = 'listo'
    AND usuario_id = ?
    ORDER BY fecha_creacion DESC
");
$stmt->execute([$mesero_id]);

$pedidos_listos = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    "success" => true,
    "pedidos" => $pedidos_listos
]);
