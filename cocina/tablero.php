<?php
// --- AUTENTICACIÓN Y CONFIGURACIÓN ---
// Carga el middleware de autenticación para asegurar que el usuario ha iniciado sesión.
require_once __DIR__ . '/../auth/middleware.php';
// Carga la configuración de la base de datos (conexión $pdo).
require_once __DIR__ . '/../config/db.php';
// Requiere que el usuario tenga el rol de 'cocina' para ver esta página.
require_role('cocina');

// --- LÓGICA DE ACTUALIZACIÓN DE ESTADO (POST) ---
// Verifica si la solicitud es de tipo POST (es decir, si se envió un formulario).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['comanda_id'])) {
    // Obtiene el ID de la comanda y el nuevo estado desde el formulario.
    $comanda_id = (int)$_POST['comanda_id'];
    $estado = $_POST['estado'];

    // Lista de estados que la cocina puede asignar.
    $estados_permitidos = ['en_preparacion', 'listo'];

    // Si el estado enviado es válido...
    if (in_array($estado, $estados_permitidos)) {
        // Prepara y ejecuta la actualización en la base de datos.
        $stmt = $pdo->prepare("UPDATE comanda SET estado = ?, es_anexo = 0 WHERE id = ?");
        $stmt->execute([$estado, $comanda_id]);
    }

    // --- REDIRECCIÓN ---
    // Redirige de vuelta a la página de la que vino (HTTP_REFERER)
    // Esto es útil para conservar la página de la paginación en la
    // que estaba el usuario.
    $redirect_url = 'tablero.php';
    if (isset($_SERVER['HTTP_REFERER']) && strpos($_SERVER['HTTP_REFERER'], 'tablero.php') !== false) {
        $redirect_url = $_SERVER['HTTP_REFERER'];
    }

    header('Location: ' . $redirect_url);
    exit;
}

// --- CONSULTA DE ESTADÍSTICAS TOTALES ---
// Obtiene la cantidad total de pedidos en preparación y listos (sin paginación).
// Se usa SUM(CASE...) para contar condicionalmente en una sola consulta.
$stmt_stats = $pdo->query("
    SELECT
        SUM(CASE WHEN estado = 'en_preparacion' THEN 1 ELSE 0 END) as total_preparacion,
        SUM(CASE WHEN estado = 'listo' THEN 1 ELSE 0 END) as total_listo
    FROM comanda
    WHERE estado IN ('en_preparacion', 'listo')
");
$stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);
$total_en_preparacion = $stats['total_preparacion'] ?? 0;
$total_listos = $stats['total_listo'] ?? 0;

// --- LÓGICA DE PAGINACIÓN ---

// 1. Definir cuántos pedidos mostrar por página
$pedidos_por_pagina = 5;

// 2. Obtener la página actual desde la URL (ej: tablero.php?pagina=2), default es 1.
$pagina_actual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
if ($pagina_actual < 1) {
    $pagina_actual = 1; // Asegurarse de que la página no sea negativa.
}

// 3. Calcular el OFFSET para la consulta SQL (cuántos registros saltar).
// (Página 1 -> 0, Página 2 -> 5, Página 3 -> 10)
$offset = ($pagina_actual - 1) * $pedidos_por_pagina;

// 4. Obtener el NÚMERO TOTAL de pedidos (suma de los contadores de estadísticas).
$total_pedidos = $total_en_preparacion + $total_listos;

// 5. Calcular el total de páginas necesarias (usando ceil() para redondear hacia arriba).
$total_paginas = ceil($total_pedidos / $pedidos_por_pagina);


// --- CONSULTA PRINCIPAL DE PEDIDOS (PAGINADA) ---
// Selecciona los pedidos que la cocina necesita ver.
$stmt = $pdo->prepare("
    SELECT 
        c.id, 
        c.estado, 
        c.total, 
        c.fecha_creacion, 
        c.tipo_servicio,
        c.editando,
        c.es_anexo,
        u.nombre as mesero_nombre,
        COALESCE(m.numero, 0) as mesa_numero /* COALESCE para manejar NULOs (pedidos para llevar) */
    FROM comanda c
    JOIN usuario u ON c.usuario_id = u.id /* Unir con usuario para saber nombre del mesero */
    LEFT JOIN mesa m ON c.mesa_id = m.id /* Unir con mesa para saber el número */
    WHERE c.estado IN ('en_preparacion', 'listo') /* Solo mostrar estos estados */
    ORDER BY 
        CASE /* Ordenar: primero 'en_preparacion', luego 'listo' */
            WHEN c.estado = 'en_preparacion' THEN 1
            WHEN c.estado = 'listo' THEN 2
            ELSE 3
        END,
        c.fecha_creacion ASC /* El más antiguo primero */
    LIMIT :limit OFFSET :offset /* Aplicar paginación */
");

// 7. "Bindear" (asignar) los valores de LIMIT y OFFSET de forma segura.
$stmt->bindParam(':limit', $pedidos_por_pagina, PDO::PARAM_INT);
$stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$pedidos = $stmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kpizza's - Tablero Cocina</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="../css/cocina.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <meta http-equiv="refresh" content="5">
</head>

<body class="bg-light">

    <nav class="navbar navbar-expand-lg navbar-dark bg-kpizzas-red">
        <div class="container">
            <a class="navbar-brand fw-bold" href="#">Kpizza's Cocina</a>
            <div class="d-flex ms-auto">
                <span class="navbar-text text-white me-3">
                    <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($_SESSION['user']['nombre']); ?>
                </span>
                <a href="../auth/logout.php" class="btn btn-outline-light border-2">
                    <i class="fas fa-sign-out-alt me-1"></i>Salir</a>
            </div>
        </div>
    </nav>

    <div class="container my-4">
        <h1 class="text-center mb-4">Tablero de Pedidos - Cocina</h1>

        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card text-center bg-warning bg-opacity-10">
                    <div class="card-body">
                        <h3 class="text-warning"><?php echo $total_en_preparacion; ?></h3>
                        <p class="mb-0">En Preparación</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card text-center bg-success bg-opacity-10">
                    <div class="card-body">
                        <h3 class="text-success"><?php echo $total_listos; ?></h3>
                        <p class="mb-0">Listos</p>
                    </div>
                </div>
            </div>
        </div>

        <?php if (empty($pedidos) && $pagina_actual == 1): ?>
            <div class="alert alert-success text-center">
                <h4 class="alert-heading">¡Todo al día!</h4>
                <p class="mb-0">No hay pedidos en la cola de preparación.</p>
            </div>
        <?php elseif (empty($pedidos) && $pagina_actual > 1): ?>
            <div class="alert alert-warning text-center">
                <h4 class="alert-heading">No hay más pedidos</h4>
                <p class="mb-0">Parece que llegaste al final de la lista.</p>
            </div>
        <?php else: ?>
            <div class="row">
                <?php foreach ($pedidos as $pedido):
                    // 1. Lógica de detección de bloqueo
                    $esta_bloqueado = (isset($pedido['editando']) && $pedido['editando'] == 1);
                    $clase_bloqueo = $esta_bloqueado ? 'pedido-bloqueado border-danger' : '';

                    // --- NUEVA LÓGICA EXACTA PARA ANEXOS ---
                    // Leemos el valor "es_anexo" directamente desde la base de datos
                    $es_anexo = (isset($pedido['es_anexo']) && $pedido['es_anexo'] == 1 && $pedido['estado'] === 'en_preparacion');
                    
                    // Estilo especial para Anexo (Borde amarillo grueso)
                    $clase_anexo = $es_anexo ? 'border-warning border-4' : '';
                    // --------------------------------------------------

                    // Define el color y texto del badge según el estado
                    $estado_badge = [
                        'en_preparacion' => ['warning', 'En Preparación'],
                        'listo' => ['success', 'Listo']
                    ][$pedido['estado']];
                ?>
                    <div class="col-12 mb-3">
                        <div class="card pedido-card shadow-sm estado-<?php echo $pedido['estado']; ?> <?php echo $clase_bloqueo . ' ' . $clase_anexo; ?>" style="position: relative; overflow: hidden;">

                            <?php if ($esta_bloqueado): ?>
                                <div class="bloqueo-overlay d-flex align-items-center justify-content-center" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(233, 236, 239, 0.7); z-index: 10;">
                                    <span class="badge bg-dark p-2 shadow">
                                        <i class="fas fa-user-edit me-2"></i>MESERO EDITANDO...
                                    </span>
                                </div>
                            <?php endif; ?>

                            <div class="card-body">
                                <?php if ($es_anexo): ?>
                                    <div class="alert alert-warning py-1 px-2 fw-bold text-center mb-3 animate__animated animate__flash">
                                        <i class="fas fa-exclamation-circle me-2"></i>⚠️ ATENCIÓN: NUEVOS PRODUCTOS AGREGADOS (ANEXO)
                                    </div>
                                <?php endif; ?>

                                <div class="row align-items-center <?php echo $esta_bloqueado ? 'opacity-50' : ''; ?>">
                                    <div class="col-md-8">
                                        <div class="d-flex align-items-center mb-2">
                                            <h5 class="card-title mb-0 me-3">
                                                <?php if ($pedido['tipo_servicio'] === 'Llevar'): ?>
                                                    <i class="fas fa-shopping-bag me-2 text-success"></i>Pedido Para Llevar
                                                <?php else: ?>
                                                    <i class="fas fa-utensils me-2 text-primary"></i>Mesa <?php echo $pedido['mesa_numero']; ?>
                                                <?php endif; ?>
                                            </h5>
                                            <span class="badge bg-<?php echo $estado_badge[0]; ?>">
                                                <?php echo $estado_badge[1]; ?>
                                            </span>
                                        </div>

                                        <div class="row text-muted small">
                                            <div class="col-sm-4">
                                                <strong>Mesero:</strong> <?php echo htmlspecialchars($pedido['mesero_nombre']); ?>
                                            </div>
                                            <div class="col-sm-4">
                                                <strong>Hora:</strong> <?php echo date('H:i', strtotime($pedido['fecha_creacion'])); ?>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-md-4 text-end">
                                        <?php if ($pedido['estado'] === 'en_preparacion'): ?>
                                            <button type="button" class="btn btn-success btn-sm"
                                                <?php echo $esta_bloqueado ? 'disabled' : ''; ?>
                                                onclick="cocinaManager.marcarComoListo(<?php echo $pedido['id']; ?>)">
                                                <i class="fas fa-check me-1"></i>Marcar Listo
                                            </button>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-warning btn-sm"
                                                <?php echo $esta_bloqueado ? 'disabled' : ''; ?>
                                                onclick="cocinaManager.volverAPreparacion(<?php echo $pedido['id']; ?>)">
                                                <i class="fas fa-undo me-1"></i>Volver a Preparar
                                            </button>
                                        <?php endif; ?>

                                        <button class="btn btn-info btn-sm ms-1"
                                            <?php echo $esta_bloqueado ? 'disabled' : ''; ?>
                                            onclick="cocinaManager.toggleDetalles(<?php echo $pedido['id']; ?>)">
                                            <i class="fas fa-list me-1"></i>Detalles
                                        </button>
                                    </div>
                                </div>

                                <div class="detalles-pedido mt-3" id="detalles-<?php echo $pedido['id']; ?>">
                                    <?php
                                    // CONSULTA ANIDADA: Obtener los detalles de ESTA comanda específica.
                                    $stmt_detalle = $pdo->prepare("
                                        SELECT 
                                            dc.id, p.nombre, p.categoria_id,
                                            cp.nombre as categoria, dc.tamanio,
                                            dc.cantidad, dc.precio_unitario
                                        FROM detalle_comanda dc
                                        JOIN producto p ON dc.producto_id = p.id
                                        JOIN categoria_producto cp ON p.categoria_id = cp.id
                                        WHERE dc.comanda_id = ?
                                        ORDER BY dc.id ASC
                                    ");
                                    $stmt_detalle->execute([$pedido['id']]);
                                    $detalles = $stmt_detalle->fetchAll();

                                    $pizzas = [];
                                    $bebidas = [];
                                    $current_pizza_index = -1;

                                    foreach ($detalles as $detalle) {
                                        if ($detalle['categoria'] === 'Pizza Base') {
                                            $current_pizza_index++;
                                            $pizzas[$current_pizza_index] = [
                                                'base' => $detalle,
                                                'ingredientes' => [],
                                                'tamanio' => $detalle['tamanio']
                                            ];
                                        } elseif ($detalle['categoria'] === 'Ingrediente' && $current_pizza_index >= 0) {
                                            $pizzas[$current_pizza_index]['ingredientes'][] = $detalle;
                                        } elseif ($detalle['categoria'] === 'Bebida') {
                                            $bebidas[] = $detalle;
                                        }
                                    }
                                    ?>

                                    <?php if (!empty($pizzas)): ?>
                                        <div class="mb-4">
                                            <h6 class="fw-bold border-bottom pb-2 mb-3 text-warning">
                                                <i class="fas fa-pizza-slice me-2"></i>Pizzas del Pedido
                                            </h6>

                                            <?php foreach ($pizzas as $index => $pizza): ?>
                                                <div class="pizza-individual mb-3 p-3 border rounded bg-light">
                                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                                        <h6 class="fw-bold text-primary mb-0">
                                                            Pizza <?php echo $index + 1; ?> -
                                                            <span class="badge bg-warning text-dark"><?php echo $pizza['tamanio']; ?></span>
                                                        </h6>
                                                    </div>

                                                    <?php if (!empty($pizza['ingredientes'])): ?>
                                                        <div class="ms-3">
                                                            <strong class="text-success small">Ingredientes:</strong>
                                                            <div class="mt-2">
                                                                <?php foreach ($pizza['ingredientes'] as $ingrediente): ?>
                                                                    <span class="badge bg-secondary me-1 mb-1">
                                                                        <?php echo htmlspecialchars($ingrediente['nombre']); ?>
                                                                    </span>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($bebidas)): ?>
                                        <div class="mb-3">
                                            <h6 class="fw-bold border-bottom pb-2 mb-3 text-info">
                                                <i class="fas fa-wine-bottle me-2"></i>Bebidas
                                            </h6>
                                            <div class="row">
                                                <?php foreach ($bebidas as $bebida): ?>
                                                    <div class="col-md-6 col-lg-4 mb-2">
                                                        <div class="d-flex justify-content-between align-items-center p-2 border rounded bg-white">
                                                            <span class="small"><?php echo htmlspecialchars($bebida['nombre']); ?></span>
                                                            <span class="badge bg-dark">x<?php echo $bebida['cantidad']; ?></span>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?> <?php if ($total_paginas > 1): ?>
            <nav aria-label="Navegación de pedidos" class="mt-4 d-flex justify-content-center">
                <ul class="pagination">

                    <li class="page-item <?php echo ($pagina_actual <= 1) ? 'disabled' : ''; ?>">
                        <a class="page-link" href="tablero.php?pagina=<?php echo $pagina_actual - 1; ?>">Anterior</a>
                    </li>

                    <?php for ($i = 1; $i <= $total_paginas; $i++): ?>
                        <li class="page-item <?php echo ($i === $pagina_actual) ? 'active' : ''; ?>">
                            <a class="page-link" href="tablero.php?pagina=<?php echo $i; ?>"><?php echo $i; ?></a>
                        </li>
                    <?php endfor; ?>

                    <li class="page-item <?php echo ($pagina_actual >= $total_paginas) ? 'disabled' : ''; ?>">
                        <a class="page-link" href="tablero.php?pagina=<?php echo $pagina_actual + 1; ?>">Siguiente</a>
                    </li>

                </ul>
            </nav>
        <?php endif; ?>

    </div>

    <footer class="bg-dark text-white text-center py-3 mt-4">
        <i class="fas fa-pizza-slice me-1"></i>Kpizza's © <?php echo date('Y'); ?> - Sistema de Gestión
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="../js/cocina.js"></script>
</body>

</html>