<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../config/db.php';
require_role('mesero');

header('Content-Type: application/json');
$usuario_id = $_SESSION['user']['id'];

// --- LIMPIEZA AUTOMÁTICA DE BLOQUEOS FANTASMA ---
// Libera pedidos que quedaron trabados por más de 30 minutos
$pdo->query("UPDATE comanda SET editando = 0 WHERE editando = 1 AND fecha_creacion < NOW() - INTERVAL 30 MINUTE");

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

// --- BLOQUEAR PARA EDICIÓN (CORREGIDO) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'bloquear') {
    $id = $_GET['id'] ?? 0;
    
    $stmt = $pdo->prepare("SELECT editando, usuario_id FROM comanda WHERE id = ?");
    $stmt->execute([$id]);
    $comanda = $stmt->fetch(PDO::FETCH_ASSOC);

    // Si está editando pero el usuario es el mismo, permitimos re-entrar (auto-desbloqueo)
    if ($comanda && $comanda['editando'] == 1 && $comanda['usuario_id'] != $usuario_id) {
        echo json_encode(['success' => false, 'error' => 'El pedido ya está siendo editado por otro mesero']);
    } else {
        $upd = $pdo->prepare("UPDATE comanda SET editando = 1 WHERE id = ?");
        $upd->execute([$id]);
        echo json_encode(['success' => true]);
    }
    exit;
}

// --- DESBLOQUEAR ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'desbloquear') {
    $id = $_GET['id'] ?? 0;
    $upd = $pdo->prepare("UPDATE comanda SET editando = 0 WHERE id = ? AND usuario_id = ?");
    $upd->execute([$id, $usuario_id]);
    echo json_encode(['success' => true]);
    exit;
}

// --- HISTORIAL DE HOY ---
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare("
        SELECT c.id, c.total, c.estado, c.tipo_servicio, c.mesa_id, c.fecha_creacion, c.editando,
               cl.nombre as nombre_cliente
        FROM comanda c
        LEFT JOIN cliente cl ON c.cliente_id = cl.id
        WHERE c.usuario_id = ? AND DATE(c.fecha_creacion) = CURDATE()
        ORDER BY c.fecha_creacion DESC
    ");
    $stmt->execute([$usuario_id]);
    echo json_encode(['success' => true, 'pedidos' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// --- ELIMINAR PEDIDO ---
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