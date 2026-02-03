<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../config/db.php';
require_role('mesero');

header('Content-Type: application/json');
$mesero_id = $_SESSION['user']['id'];

try {
    $pdo->beginTransaction();

    // 1. Buscamos solo los pedidos que están LISTOS y que NO hemos notificado antes
    $stmt = $pdo->prepare("
        SELECT id, mesa_id, tipo_servicio, fecha_creacion
        FROM comanda
        WHERE estado = 'listo' 
        AND notificado = 0 
        AND usuario_id = ?
        ORDER BY fecha_creacion DESC
    ");
    $stmt->execute([$mesero_id]);
    $pedidos_listos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Si encontramos pedidos nuevos, los marcamos como notificados DE INMEDIATO
    if (!empty($pedidos_listos)) {
        $ids = array_column($pedidos_listos, 'id');
        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        $update = $pdo->prepare("UPDATE comanda SET notificado = 1 WHERE id IN ($placeholders)");
        $update->execute($ids);
    }

    $pdo->commit();

    // Enviamos solo los pedidos REALMENTE nuevos al JS
    echo json_encode([
        "success" => true,
        "pedidos" => $pedidos_listos
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}