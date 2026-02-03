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

        // Doble verificación: ¿Ya existe este pedido en la tabla de notificaciones?
        $check = $pdo->prepare("SELECT COUNT(*) FROM notificaciones WHERE usuario_id = ? AND mensaje LIKE ?");
        $check->execute([$usuario_id, "%#$pedido_id %"]);
        
        if ($check->fetchColumn() == 0) {
            $stmt = $pdo->prepare("INSERT INTO notificaciones (usuario_id, mensaje, estado) VALUES (?, ?, 'no_leido')");
            $stmt->execute([$usuario_id, $mensaje]);
            echo json_encode(['success' => true, 'new' => true]);
        } else {
            echo json_encode(['success' => true, 'new' => false]);
        }
    } 
    elseif ($action === 'borrar_todo') {
        $stmt = $pdo->prepare("DELETE FROM notificaciones WHERE usuario_id = ?");
        $stmt->execute([$usuario_id]);
        echo json_encode(['success' => true]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare("SELECT id, mensaje, fecha_creacion FROM notificaciones WHERE usuario_id = ? ORDER BY fecha_creacion DESC");
    $stmt->execute([$usuario_id]);
    $notificaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // CORREGIDO: quitamos la 'a' extra al final de la variable
    echo json_encode(['success' => true, 'notificaciones' => $notificaciones]);
    exit;
}