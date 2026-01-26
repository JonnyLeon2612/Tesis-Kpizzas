<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_once __DIR__ . '/../../config/db.php';
require_role('admin');

class UsuarioController {
    private $pdo;
    private $mensaje;
    private $error;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function crearUsuario($datos) {
        try {
            $nombre = trim($datos['nombre']);
            $usuario = trim($datos['usuario']);
            $contrasena = $datos['contrasena'];
            $rol_id = $datos['rol_id'];
            $estado = $datos['estado'];
            
            if (empty($nombre) || empty($usuario) || empty($contrasena)) {
                $this->error = "Todos los campos obligatorios deben ser completados";
                return false;
            }
            
            if (strlen($contrasena) < 6) {
                $this->error = "La contraseña debe tener al menos 6 caracteres";
                return false;
            }
            
            $stmt = $this->pdo->prepare("SELECT id FROM usuario WHERE usuario = ?");
            $stmt->execute([$usuario]);
            
            if ($stmt->fetch()) {
                $this->error = "El nombre de usuario ya existe";
                return false;
            }
            
            $contrasena_hash = password_hash($contrasena, PASSWORD_DEFAULT);
            
            $stmt = $this->pdo->prepare("INSERT INTO usuario (rol_id, nombre, usuario, contrasena, estado) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$rol_id, $nombre, $usuario, $contrasena_hash, $estado]);
            
            $this->mensaje = "Usuario creado exitosamente";
            return true;
        } catch (Exception $e) {
            $this->error = "Error al crear el usuario: " . $e->getMessage();
            return false;
        }
    }

    public function actualizarUsuario($datos) {
        try {
            $id = $datos['id'];
            $nombre = trim($datos['nombre']);
            $usuario = trim($datos['usuario']);
            $rol_id = $datos['rol_id'];
            $estado = $datos['estado'];
            
            if (empty($nombre) || empty($usuario)) {
                $this->error = "Los campos nombre y usuario son obligatorios";
                return false;
            }
            
            if (!empty($datos['contrasena'])) {
                if (strlen($datos['contrasena']) < 6) {
                    $this->error = "La contraseña debe tener al menos 6 caracteres";
                    return false;
                }
                $contrasena_hash = password_hash($datos['contrasena'], PASSWORD_DEFAULT);
                $stmt = $this->pdo->prepare("UPDATE usuario SET rol_id = ?, nombre = ?, usuario = ?, contrasena = ?, estado = ? WHERE id = ?");
                $stmt->execute([$rol_id, $nombre, $usuario, $contrasena_hash, $estado, $id]);
            } else {
                $stmt = $this->pdo->prepare("UPDATE usuario SET rol_id = ?, nombre = ?, usuario = ?, estado = ? WHERE id = ?");
                $stmt->execute([$rol_id, $nombre, $usuario, $estado, $id]);
            }
            
            $this->mensaje = "Usuario actualizado exitosamente";
            return true;
        } catch (Exception $e) {
            $this->error = "Error al actualizar el usuario: " . $e->getMessage();
            return false;
        }
    }

    public function eliminarUsuario($id) {
        try {
            if ($id == 1) {
                $this->error = "No se puede eliminar el usuario administrador principal";
                return false;
            }
            
            $stmt = $this->pdo->prepare("DELETE FROM usuario WHERE id = ?");
            $stmt->execute([$id]);
            $this->mensaje = "Usuario eliminado exitosamente";
            return true;
        } catch (Exception $e) {
            $this->error = "Error al eliminar el usuario: " . $e->getMessage();
            return false;
        }
    }

    public function obtenerUsuarios() {
        $stmt = $this->pdo->query("
            SELECT u.*, r.nombre as rol_nombre 
            FROM usuario u 
            INNER JOIN rol r ON u.rol_id = r.id 
            ORDER BY u.id
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function obtenerUsuario($id) {
        $stmt = $this->pdo->prepare("SELECT * FROM usuario WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function obtenerRoles() {
        $stmt = $this->pdo->query("SELECT * FROM rol ORDER BY id");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getMensaje() {
        return $this->mensaje;
    }

    public function getError() {
        return $this->error;
    }
}

$controller = new UsuarioController($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['crear_usuario'])) {
        $controller->crearUsuario($_POST);
    } elseif (isset($_POST['actualizar_usuario'])) {
        $controller->actualizarUsuario($_POST);
    }
} elseif (isset($_GET['eliminar'])) {
    $controller->eliminarUsuario($_GET['eliminar']);
}

$usuarios = $controller->obtenerUsuarios();
$roles = $controller->obtenerRoles();
$usuario_editar = isset($_GET['editar']) ? $controller->obtenerUsuario($_GET['editar']) : null;
$mensaje = $controller->getMensaje();
$error = $controller->getError();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Usuarios - Kpizza's</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link href="../../css/admin.css" rel="stylesheet">
    <link href="../css/usuarios.css" rel="stylesheet">
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <?php include '../partials/sidebar.php'; ?>

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="fas fa-users me-2"></i>Gestión de Usuarios
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <a href="../dashboard.php" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-arrow-left me-1"></i> Volver al Panel
                        </a>
                    </div>
                </div>

                <!-- Mensajes -->
                <?php if ($mensaje): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i> <?php echo $mensaje; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i> <?php echo $error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Formulario para crear/editar usuario -->
                <div class="card mb-4 shadow-sm">
                    <div class="card-header bg-red-kpizza text-white">
                        <h5 class="mb-0">
                            <i class="fas <?php echo $usuario_editar ? 'fa-edit' : 'fa-user-plus'; ?> me-2"></i>
                            <?php echo $usuario_editar ? 'Editar Usuario' : 'Agregar Nuevo Usuario'; ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="" id="form-usuario" class="needs-validation" novalidate>
                            <?php if ($usuario_editar): ?>
                                <input type="hidden" name="id" value="<?php echo $usuario_editar['id']; ?>">
                            <?php endif; ?>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="nombre" class="form-label">Nombre Completo <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="nombre" name="nombre" 
                                           value="<?php echo $usuario_editar ? htmlspecialchars($usuario_editar['nombre']) : ''; ?>" 
                                           required>
                                    <div class="invalid-feedback">
                                        Por favor ingresa el nombre completo.
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="usuario" class="form-label">Nombre de Usuario <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="usuario" name="usuario" 
                                           value="<?php echo $usuario_editar ? htmlspecialchars($usuario_editar['usuario']) : ''; ?>" 
                                           required>
                                    <div class="invalid-feedback">
                                        Por favor ingresa un nombre de usuario.
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="contrasena" class="form-label">
                                        <?php echo $usuario_editar ? 'Nueva Contraseña' : 'Contraseña'; ?>
                                        <?php if (!$usuario_editar): ?><span class="text-danger">*</span><?php endif; ?>
                                    </label>
                                    <input type="password" class="form-control" id="contrasena" name="contrasena" 
                                           <?php echo $usuario_editar ? '' : 'required'; ?>
                                           minlength="6">
                                    <div class="form-text">
                                        <?php if ($usuario_editar): ?>
                                            Dejar en blanco para mantener la contraseña actual
                                        <?php else: ?>
                                            Mínimo 6 caracteres
                                        <?php endif; ?>
                                    </div>
                                    <div class="invalid-feedback">
                                        La contraseña debe tener al menos 6 caracteres.
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="rol_id" class="form-label">Rol <span class="text-danger">*</span></label>
                                    <select class="form-select" id="rol_id" name="rol_id" required>
                                        <option value="">Seleccionar Rol</option>
                                        <?php foreach ($roles as $rol): ?>
                                            <option value="<?php echo $rol['id']; ?>" 
                                                <?php echo ($usuario_editar && $usuario_editar['rol_id'] == $rol['id']) ? 'selected' : ''; ?>>
                                                <?php echo ucfirst($rol['nombre']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="invalid-feedback">
                                        Por favor selecciona un rol.
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="estado" class="form-label">Estado <span class="text-danger">*</span></label>
                                    <select class="form-select" id="estado" name="estado" required>
                                        <option value="activo" <?php echo ($usuario_editar && $usuario_editar['estado'] == 'activo') ? 'selected' : ''; ?>>Activo</option>
                                        <option value="inactivo" <?php echo ($usuario_editar && $usuario_editar['estado'] == 'inactivo') ? 'selected' : ''; ?>>Inactivo</option>
                                    </select>
                                    <div class="invalid-feedback">
                                        Por favor selecciona un estado.
                                    </div>
                                </div>
                            </div>
                            
                            <div class="d-flex justify-content-between mt-4">
                                <?php if ($usuario_editar): ?>
                                    <button type="submit" name="actualizar_usuario" class="btn btn-success">
                                        <i class="fas fa-save me-1"></i> Actualizar Usuario
                                    </button>
                                    <a href="usuarios.php" class="btn btn-secondary">
                                        <i class="fas fa-times me-1"></i> Cancelar
                                    </a>
                                <?php else: ?>
                                    <button type="submit" name="crear_usuario" class="btn btn-primary">
                                        <i class="fas fa-plus me-1"></i> Crear Usuario
                                    </button>
                                    <button type="reset" class="btn btn-outline-secondary">
                                        <i class="fas fa-undo me-1"></i> Limpiar
                                    </button>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Tabla de usuarios -->
                <div class="card shadow-sm">
                    <div class="card-header bg-red-kpizza text-white">
                        <h5 class="mb-0"><i class="fas fa-list me-2"></i> Lista de Usuarios</h5>
                    </div>
                    <div class="card-body">
                        <?php if (count($usuarios) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>ID</th>
                                            <th>Nombre</th>
                                            <th>Usuario</th>
                                            <th>Rol</th>
                                            <th>Estado</th>
                                            <th>Fecha de Creación</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($usuarios as $usuario): ?>
                                            <tr>
                                                <td><strong><?php echo $usuario['id']; ?></strong></td>
                                                <td><?php echo htmlspecialchars($usuario['nombre']); ?></td>
                                                <td><?php echo htmlspecialchars($usuario['usuario']); ?></td>
                                                <td>
                                                    <span class="badge bg-primary">
                                                        <?php echo ucfirst($usuario['rol_nombre']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge <?php echo $usuario['estado'] == 'activo' ? 'bg-success' : 'bg-secondary'; ?>">
                                                        <?php echo ucfirst($usuario['estado']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo date('d/m/Y H:i', strtotime($usuario['fecha_creacion'])); ?></td>
                                                <td>
                                                    <div class="btn-group btn-group-sm" role="group">
                                                        <a href="?editar=<?php echo $usuario['id']; ?>" class="btn btn-warning" title="Editar">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <?php if ($usuario['id'] != 1): ?>
                                                            <a href="?eliminar=<?php echo $usuario['id']; ?>" 
                                                               class="btn btn-danger btn-eliminar" 
                                                               title="Eliminar"
                                                               data-usuario="<?php echo htmlspecialchars($usuario['nombre']); ?>">
                                                                <i class="fas fa-trash"></i>
                                                            </a>
                                                        <?php else: ?>
                                                            <button class="btn btn-secondary" disabled title="No se puede eliminar">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info text-center">
                                <i class="fas fa-info-circle me-2"></i> No hay usuarios registrados.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="js/usuarios.js"></script>
</body>
</html>