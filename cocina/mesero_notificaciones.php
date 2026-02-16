    <?php
    require_once __DIR__ . '/../auth/middleware.php';
    require_once __DIR__ . '/../config/db.php';
    require_role('mesero');

    header('Content-Type: application/json');
    $mesero_id = $_SESSION['user']['id'];

    try {
        $pdo->beginTransaction();

        // --- MODIFICACIÓN: Se agregó LEFT JOIN con cliente para obtener el nombre ---
        $stmt = $pdo->prepare("
            SELECT 
                c.id, 
                c.mesa_id, 
                c.tipo_servicio, 
                c.fecha_creacion,
                cl.nombre AS cliente_nombre
            FROM comanda c
            LEFT JOIN cliente cl ON c.cliente_id = cl.id
            WHERE c.estado = 'listo' 
            AND c.notificado = 0 
            AND c.usuario_id = ?
            ORDER BY c.fecha_creacion DESC
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