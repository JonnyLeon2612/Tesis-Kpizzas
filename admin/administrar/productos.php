<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_once __DIR__ . '/../../config/db.php';
require_role('admin');

class ProductoController {
    private $pdo;
    private $mensaje;
    private $error;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function crearProducto($datos) {
        try {
            // (Tu código de 'crearProducto' no necesita cambios)
            $categoria_id = $datos['categoria_id'];
            $nombre = trim($datos['nombre']);
            $descripcion = trim($datos['descripcion']);
            $precio_pequena = $datos['precio_pequena'] ?? 0;
            $precio_mediana = $datos['precio_mediana'] ?? 0;
            $precio_familiar = $datos['precio_familiar'] ?? 0;
            
            if (empty($nombre) || empty($categoria_id)) {
                $this->error = "Los campos nombre y categoría son obligatorios";
                return false;
            }
            
            $stmt = $this->pdo->prepare("SELECT id FROM producto WHERE nombre = ?");
            $stmt->execute([$nombre]);
            
            if ($stmt->fetch()) {
                $this->error = "Ya existe un producto con este nombre";
                return false;
            }
            
            $stmt = $this->pdo->prepare("INSERT INTO producto (categoria_id, nombre, descripcion, precio_pequena, precio_mediana, precio_familiar, estado) VALUES (?, ?, ?, ?, ?, ?, 'activo')");
            $stmt->execute([$categoria_id, $nombre, $descripcion, $precio_pequena, $precio_mediana, $precio_familiar]);
            
            $this->mensaje = "Producto creado exitosamente";
            return true;
        } catch (Exception $e) {
            $this->error = "Error al crear el producto: " . $e->getMessage();
            return false;
        }
    }

    public function actualizarProducto($datos) {
        try {
            // (Tu código de 'actualizarProducto' no necesita cambios)
            $id = $datos['id'];
            $categoria_id = $datos['categoria_id'];
            $nombre = trim($datos['nombre']);
            $descripcion = trim($datos['descripcion']);
            $precio_pequena = $datos['precio_pequena'] ?? 0;
            $precio_mediana = $datos['precio_mediana'] ?? 0;
            $precio_familiar = $datos['precio_familiar'] ?? 0;
            $estado = $datos['estado'];
            
            if (empty($nombre) || empty($categoria_id)) {
                $this->error = "Los campos nombre y categoría son obligatorios";
                return false;
            }
            
            $stmt = $this->pdo->prepare("UPDATE producto SET categoria_id = ?, nombre = ?, descripcion = ?, precio_pequena = ?, precio_mediana = ?, precio_familiar = ?, estado = ? WHERE id = ?");
            $stmt->execute([$categoria_id, $nombre, $descripcion, $precio_pequena, $precio_mediana, $precio_familiar, $estado, $id]);
            
            $this->mensaje = "Producto actualizado exitosamente";
            return true;
        } catch (Exception $e) {
            $this->error = "Error al actualizar el producto: " . $e->getMessage();
            return false;
        }
    }

    public function eliminarProducto($id) {
        try {
            // (Tu código de 'eliminarProducto' no necesita cambios)
            $stmt = $this->pdo->prepare("DELETE FROM producto WHERE id = ?");
            $stmt->execute([$id]);
            $this->mensaje = "Producto eliminado exitosamente";
            return true;
        } catch (Exception $e) {
            $this->error = "Error al eliminar el producto: " . $e->getMessage();
            return false;
        }
    }

    // --- CAMBIO: 'obtenerProductos' ahora es 'obtenerProductosPaginados' ---
    public function obtenerProductosPaginados($busqueda, $offset, $limit) {
        $sql_busqueda = "WHERE (p.nombre LIKE ? OR cp.nombre LIKE ?)";
        $params = ["%{$busqueda}%", "%{$busqueda}%", $limit, $offset];

        $stmt = $this->pdo->prepare("
            SELECT p.*, cp.nombre as categoria_nombre 
            FROM producto p 
            INNER JOIN categoria_producto cp ON p.categoria_id = cp.id 
            $sql_busqueda
            ORDER BY cp.nombre, p.nombre
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // --- NUEVA FUNCIÓN ---
    public function contarProductos($busqueda) {
        $sql_busqueda = "WHERE (p.nombre LIKE ? OR cp.nombre LIKE ?)";
        $params = ["%{$busqueda}%", "%{$busqueda}%"];

        $stmt = $this->pdo->prepare("
            SELECT COUNT(p.id) 
            FROM producto p 
            INNER JOIN categoria_producto cp ON p.categoria_id = cp.id 
            $sql_busqueda
        ");
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    public function obtenerProducto($id) {
        // (Tu código de 'obtenerProducto' no necesita cambios)
        $stmt = $this->pdo->prepare("SELECT * FROM producto WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function obtenerCategorias() {
        // (Tu código de 'obtenerCategorias' no necesita cambios)
        $stmt = $this->pdo->query("SELECT * FROM categoria_producto ORDER BY nombre");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getMensaje() {
        return $this->mensaje;
    }

    public function getError() {
        return $this->error;
    }
}

$controller = new ProductoController($pdo);

// --- LÓGICA DE PAGINACIÓN Y BÚSQUEDA ---
$productos_por_pagina = 10;
$busqueda = isset($_GET['buscar']) ? trim($_GET['buscar']) : '';
$pagina_actual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
if ($pagina_actual < 1) $pagina_actual = 1;

$offset = ($pagina_actual - 1) * $productos_por_pagina;

// Construir query string para preservar estado
$query_params = ['pagina' => $pagina_actual, 'buscar' => $busqueda];
$query_string = http_build_query($query_params);
$query_string_http = http_build_query(['pagina' => $pagina_actual, 'buscar' => $busqueda]);


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['crear_producto'])) {
        $controller->crearProducto($_POST);
    } elseif (isset($_POST['actualizar_producto'])) {
        $controller->actualizarProducto($_POST);
    }
    // Redirigir preservando filtros y paginación
    header('Location: productos.php?' . $query_string);
    exit;
} elseif (isset($_GET['eliminar'])) {
    $controller->eliminarProducto($_GET['eliminar']);
    // Redirigir preservando filtros y paginación
    header('Location: productos.php?' . $query_string);
    exit;
}

// Obtener datos para la vista
$total_productos = $controller->contarProductos($busqueda);
$total_paginas = ceil($total_productos / $productos_por_pagina);
$productos = $controller->obtenerProductosPaginados($busqueda, $offset, $productos_por_pagina);

$categorias = $controller->obtenerCategorias();
$producto_editar = isset($_GET['editar']) ? $controller->obtenerProducto($_GET['editar']) : null;
$mensaje = $controller->getMensaje();
$error = $controller->getError();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Productos - Kpizza's</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link href="../../css/admin.css" rel="stylesheet">
    <link href="../css/productos.css" rel="stylesheet">
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
                        <i class="fas fa-pizza-slice me-2"></i>Gestión de Productos
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <a href="../dashboard.php" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-arrow-left me-1"></i> Volver al Panel
                        </a>
                    </div>
                </div>

                <!-- Mensajes -->
                <!-- (Tu HTML de mensajes no cambia) -->
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


                <!-- Formulario para crear/editar producto -->
                <div class="card mb-4 shadow-sm">
                    <div class="card-header bg-red-kpizza text-white">
                        <h5 class="mb-0">
                            <i class="fas <?php echo $producto_editar ? 'fa-edit' : 'fa-plus-circle'; ?> me-2"></i>
                            <?php echo $producto_editar ? 'Editar Producto' : 'Agregar Nuevo Producto'; ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="productos.php?<?php echo $query_string_http; ?>" id="form-producto" class="needs-validation" novalidate>
                            <?php if ($producto_editar): ?>
                                <input type="hidden" name="id" value="<?php echo $producto_editar['id']; ?>">
                            <?php endif; ?>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="nombre" class="form-label">Nombre del Producto <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="nombre" name="nombre" 
                                           value="<?php echo $producto_editar ? htmlspecialchars($producto_editar['nombre']) : ''; ?>" 
                                           required>
                                    <div class="invalid-feedback">
                                        Por favor ingresa el nombre del producto.
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="categoria_id" class="form-label">Categoría <span class="text-danger">*</span></label>
                                    <select class="form-select" id="categoria_id" name="categoria_id" required>
                                        <option value="">Seleccionar Categoría</option>
                                        <?php foreach ($categorias as $categoria): ?>
                                            <option value="<?php echo $categoria['id']; ?>" 
                                                data-nombre="<?php echo htmlspecialchars($categoria['nombre']); ?>"
                                                <?php echo ($producto_editar && $producto_editar['categoria_id'] == $categoria['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($categoria['nombre']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="invalid-feedback">
                                        Por favor selecciona una categoría.
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-12 mb-3">
                                    <label for="descripcion" class="form-label">Descripción</label>
                                    <textarea class="form-control" id="descripcion" name="descripcion" rows="2"><?php echo $producto_editar ? htmlspecialchars($producto_editar['descripcion']) : ''; ?></textarea>
                                </div>
                            </div>

                            <!-- --- NUEVO: Contenedor para precios (para JS) --- -->
                            <div id="campos-precios-pizza" class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="precio_pequena" class="form-label">Precio Pequeña ($)</label>
                                    <input type="number" class="form-control precio-input" id="precio_pequena" name="precio_pequena" 
                                           value="<?php echo $producto_editar ? $producto_editar['precio_pequena'] : '0.00'; ?>" 
                                           step="0.01" min="0">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="precio_mediana" class="form-label">Precio Mediana ($)</label>
                                    <input type="number" class="form-control precio-input" id="precio_mediana" name="precio_mediana" 
                                           value="<?php echo $producto_editar ? $producto_editar['precio_mediana'] : '0.00'; ?>" 
                                           step="0.01" min="0">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="precio_familiar" class="form-label">Precio Familiar ($)</label>
                                    <input type="number" class="form-control precio-input" id="precio_familiar" name="precio_familiar" 
                                           value="<?php echo $producto_editar ? $producto_editar['precio_familiar'] : '0.00'; ?>" 
                                           step="0.01" min="0">
                                </div>
                            </div>
                            
                            <?php if ($producto_editar): ?>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="estado" class="form-label">Estado <span class="text-danger">*</span></label>
                                    <select class="form-select" id="estado" name="estado" required>
                                        <option value="activo" <?php echo ($producto_editar && $producto_editar['estado'] == 'activo') ? 'selected' : ''; ?>>Activo</option>
                                        <option value="inactivo" <?php echo ($producto_editar && $producto_editar['estado'] == 'inactivo') ? 'selected' : ''; ?>>Inactivo</option>
                                    </select>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <div class="d-flex justify-content-between mt-4">
                                <?php if ($producto_editar): ?>
                                    <button type="submit" name="actualizar_producto" class="btn btn-success">
                                        <i class="fas fa-save me-1"></i> Actualizar Producto
                                    </button>
                                    <!-- CAMBIO: El link de cancelar ahora preserva el estado -->
                                    <a href="productos.php?<?php echo $query_string_http; ?>" class="btn btn-secondary">
                                        <i class="fas fa-times me-1"></i> Cancelar
                                    </a>
                                <?php else: ?>
                                    <button type="submit" name="crear_producto" class="btn btn-primary">
                                        <i class="fas fa-plus me-1"></i> Crear Producto
                                    </button>
                                    <button type="reset" class="btn btn-outline-secondary">
                                        <i class="fas fa-undo me-1"></i> Limpiar
                                    </button>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- --- NUEVO: Formulario de Búsqueda --- -->
                <div class="card mb-4 shadow-sm">
                    <div class="card-body">
                        <form method="GET" action="productos.php">
                            <div class="row g-3">
                                <div class="col-md-10">
                                    <label for="buscar" class="form-label">Buscar Producto o Categoría</label>
                                    <input type="text" class="form-control" id="buscar" name="buscar" 
                                           value="<?php echo htmlspecialchars($busqueda); ?>" 
                                           placeholder="Ej: Pepperoni, Bebida, etc...">
                                </div>
                                <div class="col-md-2 d-flex align-items-end">
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="fas fa-search me-1"></i> Buscar
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Tabla de productos -->
                <div class="card shadow-sm">
                    <div class="card-header bg-red-kpizza text-white">
                        <h5 class="mb-0"><i class="fas fa-list me-2"></i> Lista de Productos</h5>
                    </div>
                    <div class="card-body">
                        <?php if (count($productos) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>ID</th>
                                            <th>Nombre</th>
                                            <th>Categoría</th>
                                            <th>Precios (P/M/F)</th>
                                            <th>Estado</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($productos as $producto): ?>
                                            <tr>
                                                <td><strong><?php echo $producto['id']; ?></strong></td>
                                                <td><?php echo htmlspecialchars($producto['nombre']); ?></td>
                                                <td>
                                                    <span class="badge bg-primary categoria-badge">
                                                        <?php echo htmlspecialchars($producto['categoria_nombre']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <small>
                                                        $<?php echo number_format($producto['precio_pequena'], 2); ?> /
                                                        $<?php echo number_format($producto['precio_mediana'], 2); ?> /
                                                        $<?php echo number_format($producto['precio_familiar'], 2); ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <span class="badge <?php echo $producto['estado'] == 'activo' ? 'bg-success' : 'bg-secondary'; ?>">
                                                        <?php echo ucfirst($producto['estado']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm" role="group">
                                                        <!-- CAMBIO: Links de acción preservan estado -->
                                                        <a href="?editar=<?php echo $producto['id']; ?>&<?php echo $query_string_http; ?>" class="btn btn-warning" title="Editar">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <a href="?eliminar=<?php echo $producto['id']; ?>&<?php echo $query_string_http; ?>" 
                                                           class="btn btn-danger btn-eliminar" 
                                                           title="Eliminar"
                                                           data-producto="<?php echo htmlspecialchars($producto['nombre']); ?>">
                                                            <i class="fas fa-trash"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info text-center">
                                <i class="fas fa-info-circle me-2"></i> 
                                <?php if (!empty($busqueda)): ?>
                                    No se encontraron productos con el término "<?php echo htmlspecialchars($busqueda); ?>".
                                <?php else: ?>
                                    No hay productos registrados.
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- --- NUEVO: Paginación --- -->
                <?php if ($total_paginas > 1): ?>
                    <nav aria-label="Navegación de productos" class="mt-4 d-flex justify-content-center">
                        <ul class="pagination">
                            <!-- Botón Anterior -->
                            <li class="page-item <?php echo ($pagina_actual <= 1) ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?pagina=<?php echo $pagina_actual - 1; ?>&buscar=<?php echo urlencode($busqueda); ?>">Anterior</a>
                            </li>

                            <!-- Números de página -->
                            <?php for ($i = 1; $i <= $total_paginas; $i++): ?>
                                <li class="page-item <?php echo ($i === $pagina_actual) ? 'active' : ''; ?>">
                                    <a class="page-link" href="?pagina=<?php echo $i; ?>&buscar=<?php echo urlencode($busqueda); ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>

                            <!-- Botón Siguiente -->
                            <li class="page-item <?php echo ($pagina_actual >= $total_paginas) ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?pagina=<?php echo $pagina_actual + 1; ?>&buscar=<?php echo urlencode($busqueda); ?>">Siguiente</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>

            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    
    <script src="../js/productos.js"></script>
</body>
</html>