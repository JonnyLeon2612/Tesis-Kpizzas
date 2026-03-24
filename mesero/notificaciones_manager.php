<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../config/db.php';
require_role('mesero');

header('Content-Type: application/json');
$usuario_id = $_SESSION['user']['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'guardar') {
        $mensaje = $_POST['mensaje'] ?? '';
        $pedido_id = $_POST['pedido_id'] ?? 0;

        // NUEVO: Solo bloquea si la notificación anterior sigue activa (no_leido)
        $check = $pdo->prepare("SELECT COUNT(*) FROM notificaciones WHERE usuario_id = ? AND mensaje LIKE ? AND estado = 'no_leido'");
        $check->execute([$usuario_id, "%#$pedido_id %"]);
        
        if ($check->fetchColumn() == 0) {
            $stmt = $pdo->prepare("INSERT INTO notificaciones (usuario_id, mensaje, estado) VALUES (?, ?, 'no_leido')");
//...
            $stmt->execute([$usuario_id, $mensaje]);
            echo json_encode(['success' => true, 'new' => true]);
        } else {
            echo json_encode(['success' => true, 'new' => false]);
        }
    } 
    // --- NUEVO: ACCIÓN PARA MARCAR UNA SOLA COMO LEÍDA ---
    elseif ($action === 'marcar_leida') {
        $id = $_POST['id'] ?? 0;
        $stmt = $pdo->prepare("UPDATE notificaciones SET estado = 'leido' WHERE id = ? AND usuario_id = ?");
        $stmt->execute([$id, $usuario_id]);
        echo json_encode(['success' => true]);
    } 
    // -----------------------------------------------------
    elseif ($action === 'borrar_todo') {
        // Con DELETE las borramos físicamente para limpiar por completo el panel
        $stmt = $pdo->prepare("DELETE FROM notificaciones WHERE usuario_id = ?");
        $stmt->execute([$usuario_id]);
        echo json_encode(['success' => true]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // --- MODIFICADO: Traemos TODAS las de hoy (estado leido y no_leido) ---
    // Agregamos "estado" al SELECT y filtramos por CURDATE() para que no traiga las de ayer
    $stmt = $pdo->prepare("
        SELECT id, mensaje, fecha_creacion, estado 
        FROM notificaciones 
        WHERE usuario_id = ? AND DATE(fecha_creacion) = CURDATE() 
        ORDER BY fecha_creacion DESC
    ");
    $stmt->execute([$usuario_id]);
    $notificaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'notificaciones' => $notificaciones]);
    exit;
}
?>