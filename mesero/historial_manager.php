<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../config/db.php';
require_role('mesero');

header('Content-Type: application/json');
$usuario_id = $_SESSION['user']['id'];

// --- ACCIÓN: OBTENER DETALLE ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'obtener_detalle') {
    $id = $_GET['id'] ?? 0;
    
    $stmt = $pdo->prepare("SELECT * FROM comanda WHERE id = ? AND usuario_id = ?");
    $stmt->execute([$id, $usuario_id]);
    $comanda = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($comanda) {
        $stmt_det = $pdo->prepare("
            SELECT dc.*, p.nombre, c.nombre as tipo_categoria 
            FROM detalle_comanda dc
            JOIN producto p ON dc.producto_id = p.id
            JOIN categoria_producto c ON p.categoria_id = c.id
            WHERE dc.comanda_id = ?
        ");
        $stmt_det->execute([$id]);
        $detalles = $stmt_det->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'comanda' => $comanda, 'detalles' => $detalles]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Pedido no encontrado']);
    }
    exit;
}

// --- NUEVA ACCIÓN: BLOQUEAR PARA EDICIÓN ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'bloquear') {
    $id = $_GET['id'] ?? 0;
    
    // Verificamos si ya está siendo editado por alguien más
    $stmt = $pdo->prepare("SELECT editando FROM comanda WHERE id = ?");
    $stmt->execute([$id]);
    $comanda = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($comanda && $comanda['editando'] == 1) {
        echo json_encode(['success' => false, 'error' => 'El pedido ya está en edición']);
    } else {
        $upd = $pdo->prepare("UPDATE comanda SET editando = 1 WHERE id = ? AND usuario_id = ?");
        $upd->execute([$id, $usuario_id]);
        echo json_encode(['success' => true]);
    }
    exit;
}

// --- NUEVA ACCIÓN: DESBLOQUEAR (CANCELAR EDICIÓN) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'desbloquear') {
    $id = $_GET['id'] ?? 0;
    $upd = $pdo->prepare("UPDATE comanda SET editando = 0 WHERE id = ? AND usuario_id = ?");
    $upd->execute([$id, $usuario_id]);
    echo json_encode(['success' => true]);
    exit;
}

// --- ACCIÓN: LEER HISTORIAL DE HOY ---
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare("
        SELECT id, total, estado, tipo_servicio, mesa_id, fecha_creacion, editando 
        FROM comanda 
        WHERE usuario_id = ? AND DATE(fecha_creacion) = CURDATE()
        ORDER BY fecha_creacion DESC
    ");
    $stmt->execute([$usuario_id]);
    echo json_encode(['success' => true, 'pedidos' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// --- ACCIÓN: ELIMINAR PEDIDO ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? 0;
    $action = $_POST['action'] ?? '';

    if ($action === 'eliminar') {
        $check = $pdo->prepare("SELECT estado, mesa_id, editando FROM comanda WHERE id = ? AND usuario_id = ?");
        $check->execute([$id, $usuario_id]);
        $comanda = $check->fetch(PDO::FETCH_ASSOC);

        if (!$comanda) {
            echo json_encode(['success' => false, 'error' => 'Pedido no encontrado']);
            exit;
        }

        // No permitir eliminar si alguien lo está editando
        if ($comanda['editando'] == 1) {
            echo json_encode(['success' => false, 'error' => 'No se puede eliminar: el pedido está siendo editado']);
            exit;
        }

        if (in_array($comanda['estado'], ['pendiente', 'en_preparacion'])) {
            $pdo->beginTransaction();
            try {
                if ($comanda['mesa_id']) {
                    $pdo->prepare("UPDATE mesa SET estado = 'disponible' WHERE id = ?")->execute([$comanda['mesa_id']]);
                }
                $pdo->prepare("DELETE FROM comanda WHERE id = ?")->execute([$id]);
                $pdo->commit();
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Error al eliminar']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Estado no permite eliminación']);
        }
    }
    exit;
}