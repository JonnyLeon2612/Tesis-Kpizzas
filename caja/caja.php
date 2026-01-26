<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/tasa_helper.php'; // Incluimos el helper
require_role('caja');

// --- OBTENER TASA DE CAMBIO ACTUAL ---
$tasa_actual = obtenerTasaDolarActual($pdo);

// --- LÓGICA DE PAGINACIÓN ---
$pedidos_por_pagina = 5;
$pagina_actual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
if ($pagina_actual < 1) {
    $pagina_actual = 1;
}
$offset = ($pagina_actual - 1) * $pedidos_por_pagina;

// 4. Obtener el NÚMERO TOTAL de pedidos 'listo' (para la insignia de pendientes)
$stmt_total = $pdo->query("SELECT COUNT(*) FROM comanda WHERE estado = 'listo'");
$total_pedidos_listos = $stmt_total->fetchColumn();

// 5. Calcular el total de páginas
$total_paginas = ceil($total_pedidos_listos / $pedidos_por_pagina);

// 6. Obtener los pedidos 'listo' paginados
$stmt_pedidos = $pdo->prepare("
    SELECT 
        c.id, 
        COALESCE(m.numero, 0) as mesa_numero, 
        c.total, 
        c.fecha_creacion, 
        u.nombre as mesero_nombre,
        c.tipo_servicio
    FROM comanda c
    JOIN usuario u ON c.usuario_id = u.id
    LEFT JOIN mesa m ON c.mesa_id = m.id
    WHERE c.estado = 'listo'
    ORDER BY 
        CASE 
            WHEN c.tipo_servicio = 'Llevar' THEN 1
            ELSE 2
        END,
        c.fecha_creacion ASC
    LIMIT :limit OFFSET :offset
");
$stmt_pedidos->bindParam(':limit', $pedidos_por_pagina, PDO::PARAM_INT);
$stmt_pedidos->bindParam(':offset', $offset, PDO::PARAM_INT);
$stmt_pedidos->execute();
$pedidos = $stmt_pedidos->fetchAll();


// --- LÓGICA DE TOTALES DE VENTA (MODIFICADA) ---

// 1. Ventas Totales en DÓLARES (Pagos recibidos en USD)
$stmt_ventas_usd = $pdo->query("
    SELECT COALESCE(SUM(monto_total), 0) as total_usd
    FROM pago 
    WHERE DATE(fecha_pago) = CURDATE() AND moneda_pago = 'USD'
");
$total_ventas_usd = (float)$stmt_ventas_usd->fetchColumn();
$stmt_count_usd = $pdo->query("SELECT COUNT(*) FROM pago WHERE DATE(fecha_pago) = CURDATE() AND moneda_pago = 'USD'");
$count_usd = $stmt_count_usd->fetchColumn();

// 2. Ventas Totales en BOLÍVARES (Pagos recibidos en BS)
$stmt_ventas_bs = $pdo->query("
    SELECT COALESCE(SUM(monto_total), 0) as total_bs
    FROM pago 
    WHERE DATE(fecha_pago) = CURDATE() AND moneda_pago = 'BS'
");
$total_ventas_bs = (float)$stmt_ventas_bs->fetchColumn();
$stmt_count_bs = $pdo->query("SELECT COUNT(*) FROM pago WHERE DATE(fecha_pago) = CURDATE() AND moneda_pago = 'BS'");
$count_bs = $stmt_count_bs->fetchColumn();

// 3. Total General (Todo convertido a USD para referencia)
// Convertimos las ventas en BS a USD usando la tasa actual y las sumamos a las ventas en USD
$total_general_usd = $total_ventas_usd + ($total_ventas_bs / $tasa_actual);

// 4. Total de Pedidos Cobrados Hoy
$stmt_cobrados_hoy = $pdo->query("SELECT COUNT(*) FROM pago WHERE DATE(fecha_pago) = CURDATE()");
$cobrados_hoy_count = $stmt_cobrados_hoy->fetchColumn();
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kpizza's - Caja</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link href="../css/caja.css" rel="stylesheet">
</head>

<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-kpizzas-red">
        <div class="container">
            <a class="navbar-brand fw-bold" href="#">
                <i class=""></i>Kpizza's Caja
            </a>
            <div class="d-flex ms-auto">
                <span class="navbar-text text-white me-3">
                    <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($_SESSION['user']['nombre']); ?>
                </span>
                <a href="../auth/logout.php" class="btn btn-outline-light border-2">
                    <i class="fas fa-sign-out-alt me-1"></i>Salir
                </a>
            </div>
        </div>
    </nav>

    <div class="container my-4">
        <div class="row mb-4">
            <div class="col-12">
                <div class="card bg-gradient-primary text-white">
                    <div class="card-body text-center py-4">
                        <h1 class="display-4 fw-bold mb-2">
                            <i class="fas fa-cash-register me-3"></i>Módulo de Caja
                        </h1>
                        <p class="lead mb-0">Gestión de cobros y ventas del día</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-lg-10 mx-auto">
                <div class="card sales-card shadow-sm">
                    <div class="card-body py-4 px-3">
                        <div class="row align-items-center text-center">
                            
                            <div class="col-md-4">
                                <h5 class="card-title text-muted mb-2">Ventas en Dólares</h5>
                                <h2 class="display-6 fw-bold text-kpizzas-red mb-0">
                                    $<?php echo number_format($total_ventas_usd, 2); ?>
                                </h2>
                                <small class="text-muted">(<?php echo $count_usd; ?> transacciones)</small>
                            </div>
                            
                            <div class="col-md-4 border-start border-end">
                                <h5 class="card-title text-muted mb-2">Ventas en Bolívares</h5>
                                <h2 class="display-6 fw-bold text-success mb-0">
                                    Bs. <?php echo number_format($total_ventas_bs, 2); ?>
                                </h2>
                                <small class="text-muted">(<?php echo $count_bs; ?> transacciones)</small>
                            </div>

                            <div class="col-md-4">
                                <h5 class="card-title text-muted mb-2">Total General (en USD)</h5>
                                <h2 class="display-6 fw-bold text-primary mb-0">
                                    $<?php echo number_format($total_general_usd, 2); ?>
                                </h2>
                                <small class="text-muted">(<?php echo $cobrados_hoy_count; ?> trans. totales)</small>
                            </div>

                        </div>
                    </div>
                    <div class="card-footer text-center text-muted py-2">
                        <i class="fas fa-exchange-alt me-1"></i>
                        Tasa actual: <strong>Bs. <?php echo number_format($tasa_actual, 2); ?></strong> por $1
                    </div>
                </div>
            </div>
        </div>

        <h2 class="section-title mb-4">
            <i class="fas fa-list-check me-2"></i>Pedidos Listos para Cobrar (<?php echo $total_pedidos_listos; ?>)
        </h2>

        <?php if (empty($pedidos) && $pagina_actual == 1): // Si no hay pedidos y estamos en la primera página 
        ?>
            <div class="empty-state text-center py-5">
                <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
                <h3 class="text-muted">¡Todo al día!</h3>
                <p class="text-muted">No hay pedidos pendientes por cobrar</p>
            </div>
        <?php elseif (empty($pedidos) && $pagina_actual > 1): // Si no hay pedidos en la página actual pero no es la primera 
        ?>
            <div class="empty-state text-center py-5">
                <i class="fas fa-exclamation-circle fa-4x text-warning mb-3"></i>
                <h3 class="text-muted">No hay más pedidos</h3>
                <p class="text-muted">Parece que llegaste al final de la lista.</p>
            </div>
        <?php else: ?>
            <div class="row">
                <?php foreach ($pedidos as $pedido): 
                    // Calcular el equivalente en Bs para CADA pedido
                    $total_pedido_bs = convertirDolaresABolivares((float)$pedido['total'], $tasa_actual);
                ?>
                    <div class="col-12 mb-4">
                        <div class="card pedido-card shadow-sm">
                            <div class="card-header pedido-header <?php echo $pedido['tipo_servicio'] === 'Llevar' ? 'header-llevar' : 'header-mesa'; ?>">
                                <div class="row align-items-center">
                                    <div class="col-md-6">
                                        <div class="d-flex align-items-center">
                                            <?php if ($pedido['tipo_servicio'] === 'Llevar'): ?>
                                                <div class="header-icon bg-warning">
                                                    <i class="fas fa-shopping-bag"></i>
                                                </div>
                                                <div>
                                                    <h5 class="mb-0 fw-bold">Pedido Para Llevar</h5>
                                                    <small class="opacity-75">Listo para entrega</small>
                                                </div>
                                            <?php else: ?>
                                                <div class="header-icon bg-primary">
                                                    <i class="fas fa-utensils"></i>
                                                </div>
                                                <div>
                                                    <h5 class="mb-0 fw-bold">Mesa <?php echo htmlspecialchars($pedido['mesa_numero']); ?></h5>
                                                    <small class="opacity-75">Consumo en restaurante</small>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="col-md-6 text-end">
                                        <div class="d-flex justify-content-end align-items-center">
                                            <div class="me-3 text-end">
                                                <div class="h5 mb-0 fw-bold">$<?php echo number_format($pedido['total'], 2); ?></div>
                                                <div class="h6 mb-0 text-dark">Bs. <?php echo number_format($total_pedido_bs, 2); ?></div>
                                                <small class="opacity-75">
                                                    <i class="fas fa-clock me-1"></i><?php echo date('H:i', strtotime($pedido['fecha_creacion'])); ?>
                                                </small>
                                            </div>
                                            
                                            <button class="btn btn-light btn-sm me-2"
                                                onclick="cajaManager.toggleDetalles(event, <?php echo $pedido['id']; ?>)">
                                                <i class="fas fa-list me-1"></i>Detalles
                                            </button>
                                            <a href="generar_factura_pdf.php?id=<?php echo $pedido['id']; ?>" target="_blank" class="btn btn-danger btn-sm me-2">
                                                <i class="fas fa-file-pdf me-1"></i>PDF
                                            </a>
                                            <a href="formulario_cobro.php?id=<?php echo $pedido['id']; ?>" class="btn btn-success btn-sm">
                                                <i class="fas fa-cash-register me-1"></i>Cobrar
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="card-body py-3">
                                <div class="row">
                                    <div class="col-md-3">
                                        <div class="info-item">
                                            <i class="fas fa-user-tie text-primary me-2"></i>
                                            <strong>Mesero:</strong> <?php echo htmlspecialchars($pedido['mesero_nombre']); ?>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="info-item">
                                            <i class="fas fa-clock text-info me-2"></i>
                                            <strong>Hora pedido:</strong> <?php echo date('H:i', strtotime($pedido['fecha_creacion'])); ?>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="info-item">
                                            <i class="fas fa-receipt text-success me-2"></i>
                                            <strong>Total $:</strong> $<?php echo number_format($pedido['total'], 2); ?>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="info-item">
                                            <i class="fas fa-money-bill-wave text-warning me-2"></i>
                                            <strong>Total Bs:</strong> Bs. <?php echo number_format($total_pedido_bs, 2); ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="detalles-pedido" id="detalles-<?php echo $pedido['id']; ?>">
                                <div class="card-body border-top bg-light">
                                    <?php
                                    // Consulta para detalles
                                    $stmt_detalle = $pdo->prepare("
                                        SELECT 
                                            dc.id, p.nombre, p.categoria_id, cp.nombre as categoria,
                                            dc.tamanio, dc.cantidad, dc.precio_unitario
                                        FROM detalle_comanda dc
                                        JOIN producto p ON dc.producto_id = p.id
                                        JOIN categoria_producto cp ON p.categoria_id = cp.id
                                        WHERE dc.comanda_id = ?
                                        ORDER BY dc.id ASC
                                    ");
                                    $stmt_detalle->execute([$pedido['id']]);
                                    $detalles = $stmt_detalle->fetchAll();
                                    
                                    // Agrupar por pizzas
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
                                            <h6 class="section-subtitle">
                                                <i class="fas fa-pizza-slice me-2"></i>Pizzas del Pedido
                                            </h6>
                                            <div class="row">
                                                <?php foreach ($pizzas as $index => $pizza): ?>
                                                    <div class="col-lg-6 mb-3">
                                                        <div class="pizza-card">
                                                            <div class="pizza-header">
                                                                <h6 class="mb-0">
                                                                    <i class="fas fa-pizza-slice me-2 text-warning"></i>
                                                                    Pizza <?php echo $index + 1; ?>
                                                                </h6>
                                                                <span class="badge tamanio-badge"><?php echo $pizza['tamanio']; ?></span>
                                                            </div>
                                                            <div class="pizza-body">
                                                                <?php if (!empty($pizza['ingredientes'])): ?>
                                                                    <div class="ingredientes-list">
                                                                        <?php foreach ($pizza['ingredientes'] as $ingrediente): ?>
                                                                            <span class="ingrediente-item">
                                                                                <i class="fas fa-plus text-success me-1"></i>
                                                                                <?php echo htmlspecialchars($ingrediente['nombre']); ?>
                                                                                <?php if ($ingrediente['cantidad'] > 1): ?>
                                                                                    <small class="text-muted">(x<?php echo $ingrediente['cantidad']; ?>)</small>
                                                                                <?php endif; ?>
                                                                            </span>
                                                                        <?php endforeach; ?>
                                                                    </div>
                                                                <?php else: ?>
                                                                    <div class="text-muted small">
                                                                        <i class="fas fa-info-circle me-1"></i>Pizza básica sin ingredientes adicionales
                                                                    </div>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($bebidas)): ?>
                                        <div class="mb-3">
                                            <h6 class="section-subtitle">
                                                <i class="fas fa-wine-bottle me-2"></i>Bebidas
                                            </h6>
                                            <div class="row">
                                                <?php foreach ($bebidas as $bebida): ?>
                                                    <div class="col-md-6 col-lg-4 mb-2">
                                                        <div class="bebida-card">
                                                            <i class="fas fa-cocktail text-primary me-2"></i>
                                                            <span class="bebida-nombre"><?php echo htmlspecialchars($bebida['nombre']); ?></span>
                                                            <span class="bebida-cantidad">x<?php echo $bebida['cantidad']; ?></span>
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
        <?php endif; ?>

        <?php if ($total_paginas > 1): ?>
            <nav aria-label="Navegación de pedidos" class="mt-4 d-flex justify-content-center">
                <ul class="pagination">
                    <li class="page-item <?php echo ($pagina_actual <= 1) ? 'disabled' : ''; ?>">
                        <a class="page-link" href="caja.php?pagina=<?php echo $pagina_actual - 1; ?>">Anterior</a>
                    </li>
                    <?php for ($i = 1; $i <= $total_paginas; $i++): ?>
                        <li class="page-item <?php echo ($i === $pagina_actual) ? 'active' : ''; ?>">
                            <a class="page-link" href="caja.php?pagina=<?php echo $i; ?>"><?php echo $i; ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?php echo ($pagina_actual >= $total_paginas) ? 'disabled' : ''; ?>">
                        <a class="page-link" href="caja.php?pagina=<?php echo $pagina_actual + 1; ?>">Siguiente</a>
                    </li>
                </ul>
            </nav>
        <?php endif; ?>
    </div>

    <footer class="bg-dark text-white text-center py-3 mt-5">
        <p class="mb-0">
            <i class="fas fa-pizza-slice me-1"></i>Kpizza's © <?php echo date('Y'); ?> - Sistema de Gestión
        </p>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="../js/caja.js"></script>
</body>

</html>