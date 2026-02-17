<?php
require_once '../config/db.php';

$data = json_decode(file_get_contents('php://input'), true);

if (isset($data['nombre'])) {
    $nombre = $data['nombre'];
    $telefono = $data['telefono'] ?? '';
    $cedula = $data['cedula'] ?? '';
    $direccion = $data['direccion'] ?? '';

    // Validación simple: verificar si la cédula ya existe (si no está vacía)
    if (!empty($cedula)) {
        $stmt = $pdo->prepare("SELECT id FROM cliente WHERE cedula = ?");
        $stmt->execute([$cedula]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Esta cédula ya está registrada.']);
            exit;
        }
    }

    $stmt = $pdo->prepare("INSERT INTO cliente (nombre, cedula, telefono, direccion) VALUES (?, ?, ?, ?)");
    if ($stmt->execute([$nombre, $cedula, $telefono, $direccion])) {
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId(), 'message' => 'Cliente registrado con éxito']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error al guardar en la base de datos']);
    }
}
?>