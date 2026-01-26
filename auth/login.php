<?php
// auth/login.php
session_start();
require_once __DIR__ . '/../config/db.php';

// 1. VERIFICACIÓN INICIAL: Si ya está logueado, redirigir a su área
if (isset($_SESSION['user']) && isset($_SESSION['user']['rol'])) {
    switch ($_SESSION['user']['rol']) {
        case 'admin': header('Location: ../admin/dashboard.php'); exit;
        case 'mesero': header('Location: ../mesero/venta.php'); exit;
        case 'caja': header('Location: ../caja/caja.php'); exit;
        case 'cocina': header('Location: ../cocina/tablero.php'); exit;
        default: header('Location: ../public/index.php'); exit;
    }
}

// 2. Validar que la petición sea POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../public/index.php');
    exit; 
}

// 3. Obtener y limpiar datos
$usuario = isset($_POST['usuario']) ? trim($_POST['usuario']) : '';
$clave = isset($_POST['clave']) ? trim($_POST['clave']) : '';

// Validar campos vacíos
if ($usuario === '' || $clave === '') {
    header('Location: ../public/index.php?e=1'); 
    exit;
}

// 4. Consulta a la base de datos
// Buscamos al usuario por nombre y verificamos que esté activo
$sql = "SELECT u.id, u.nombre, u.usuario, u.contrasena, r.nombre as rol 
        FROM usuario u 
        JOIN rol r ON u.rol_id = r.id 
        WHERE u.usuario = ? AND u.estado = 'activo' LIMIT 1";
        
$stmt = $pdo->prepare($sql);
$stmt->execute([$usuario]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// 5. Verificar existencia del usuario
if (!$user) {
    header('Location: ../public/index.php?e=1');
    exit;
}

// 6. VERIFICAR CLAVE (CORREGIDO)
// Usamos password_verify() para comparar la contraseña escrita con el hash encriptado
if (!password_verify($clave, $user['contrasena'])) { 
    // Si la contraseña no coincide, error
    header('Location: ../public/index.php?e=1');
    exit;
}

// 7. Crear la sesión
$_SESSION['user'] = [
    'id' => (int)$user['id'],
    'nombre'=> $user['nombre'],
    'rol' => $user['rol']
];

// 8. Redirección final según el Rol
switch ($user['rol']) {
    case 'admin': header('Location: ../admin/dashboard.php'); break;
    case 'mesero': header('Location: ../mesero/venta.php'); break;
    case 'caja': header('Location: ../caja/caja.php'); break;
    case 'cocina': header('Location: ../cocina/tablero.php'); break;
    default: header('Location: ../public/index.php?e=1'); break;
}
exit;
?>