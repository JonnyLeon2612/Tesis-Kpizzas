<?php
require_once '../config/db.php';

$data = json_decode(file_get_contents('php://input'), true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Limpieza básica (quitar espacios al inicio y final)
    $nombre = trim($data['nombre'] ?? '');
    $telefono = trim($data['telefono'] ?? '');
    $cedula = trim($data['cedula'] ?? '');
    $direccion = trim($data['direccion'] ?? '');

    $errores = [];

    // --- VALIDACIÓN DE NOMBRE ---
    if (empty($nombre)) {
        $errores[] = "El nombre es obligatorio.";
    } elseif (strlen($nombre) < 3) {
        $errores[] = "El nombre debe tener al menos 3 letras.";
    } elseif (!preg_match("/^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]+$/", $nombre)) {
        $errores[] = "El nombre solo puede contener letras y espacios.";
    }

    // --- VALIDACIÓN DE CÉDULA (SOLO NÚMEROS) ---
    if (empty($cedula)) {
        $errores[] = "La cédula es obligatoria.";
    } elseif (!preg_match('/^[0-9]+$/', $cedula)) {
        $errores[] = "La cédula debe contener SOLO números (sin puntos, letras, ni guiones).";
    } elseif (strlen($cedula) < 6) {
        $errores[] = "La cédula es muy corta.";
    } else {
        // Verificar si la cédula ya existe
        $stmt = $pdo->prepare("SELECT id FROM cliente WHERE cedula = ?");
        $stmt->execute([$cedula]);
        if ($stmt->fetch()) {
            $errores[] = "Esta cédula ya está registrada en el sistema.";
        }
    }

    // --- VALIDACIÓN DE TELÉFONO (SOLO NÚMEROS) ---
    // Quitamos guiones, espacios o el + si el usuario los puso por error
    $telefono_limpio = str_replace(['-', ' ', '+'], '', $telefono);
    
    if (empty($telefono_limpio)) {
        $errores[] = "El teléfono es obligatorio.";
    } elseif (!preg_match('/^[0-9]+$/', $telefono_limpio)) {
        $errores[] = "El teléfono debe contener SOLO números.";
    } elseif (strlen($telefono_limpio) < 10 || strlen($telefono_limpio) > 11) {
        $errores[] = "El teléfono debe tener 11 dígitos numéricos (Ej: 04141234567).";
    }

    // --- VALIDACIÓN DE DIRECCIÓN ---
    if (empty($direccion)) {
        $errores[] = "La dirección es obligatoria.";
    } elseif (strlen($direccion) < 5) {
        $errores[] = "La dirección es muy corta, por favor sea más específico.";
    }

    // 2. Comprobar si hubo errores
    if (count($errores) > 0) {
        echo json_encode(['success' => false, 'message' => implode("<br>", $errores)]);
        exit;
    }

    // 3. Si todo está perfecto, insertamos en la BD
    try {
        $stmt = $pdo->prepare("INSERT INTO cliente (nombre, cedula, telefono, direccion) VALUES (?, ?, ?, ?)");
        if ($stmt->execute([$nombre, $cedula, $telefono_limpio, $direccion])) {
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId(), 'message' => 'Cliente registrado con éxito']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error interno al guardar en la base de datos.']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error de conexión: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Petición inválida.']);
}
?>