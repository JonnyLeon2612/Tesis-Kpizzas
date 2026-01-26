<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/tasa_helper.php';


require_role('admin');

$fecha_desde = isset($_GET['desde']) ? $_GET['desde'] : date('Y-m-01');
$fecha_hasta = isset($_GET['hasta']) ? $_GET['hasta'] : date('Y-m-d');

$busqueda = isset($_GET['q']) ? trim($_GET['q']) : ''; 

$tab_activa = !empty($busqueda) ? 'historial' : 'ventas';


class ReporteController {
    private $pdo;
    private $tasa_actual;

    public function __construct($pdo, $tasa_actual) {
        $this->pdo = $pdo;
        $this->tasa_actual = $tasa_actual;
    }

    public function obtenerHistorialVentas($inicio, $fin, $busqueda = '') {
        $sql = "
            SELECT 
                c.id, 
                c.fecha_creacion, 
                c.total, 
                c.tipo_servicio,
                u.nombre as mesero,
                -- Concatenamos datos relacionados para mostrar en una sola fila
                GROUP_CONCAT(DISTINCT tp.nombre SEPARATOR ', ') as metodos_pago,
                GROUP_CONCAT(DISTINCT p.referencia SEPARATOR ', ') as referencias,
                GROUP_CONCAT(DISTINCT p.banco_origen SEPARATOR ', ') as bancos
            FROM comanda c
            JOIN usuario u ON c.usuario_id = u.id
            LEFT JOIN pago p ON c.id = p.comanda_id
            LEFT JOIN tipo_pago tp ON p.tipo_pago_id = tp.id
            WHERE c.estado = 'cobrado'
            AND DATE(c.fecha_creacion) BETWEEN :inicio AND :fin
        ";

        $params = [':inicio' => $inicio, ':fin' => $fin];

        if (!empty($busqueda)) {            $sql .= " AND (c.id LIKE :b1 OR p.referencia LIKE :b2 OR p.banco_origen LIKE :b3)";
            $params[':b1'] = "%$busqueda%";
            $params[':b2'] = "%$busqueda%";
            $params[':b3'] = "%$busqueda%";
        }

        $sql .= " GROUP BY c.id ORDER BY c.fecha_creacion DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


    public function obtenerVentasPorRango($inicio, $fin) {
        $stmt = $this->pdo->prepare("
            SELECT DATE(fecha_pago) as fecha, COUNT(id) as total_pedidos,
            SUM(CASE WHEN moneda_pago = 'USD' THEN monto_total WHEN moneda_pago = 'BS' THEN monto_total / :tasa_actual ELSE 0 END) as total_ventas_usd
            FROM pago WHERE DATE(fecha_pago) BETWEEN :inicio AND :fin GROUP BY DATE(fecha_pago) ORDER BY fecha ASC
        ");
        $stmt->execute(['tasa_actual' => $this->tasa_actual, 'inicio' => $inicio, 'fin' => $fin]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function obtenerVentasMensualesEnRango($inicio, $fin) {
        $stmt = $this->pdo->prepare("
            SELECT YEAR(fecha_pago) as año, MONTH(fecha_pago) as mes,
            SUM(CASE WHEN moneda_pago = 'USD' THEN monto_total WHEN moneda_pago = 'BS' THEN monto_total / :tasa_actual ELSE 0 END) as total_ventas_usd
            FROM pago WHERE DATE(fecha_pago) BETWEEN :inicio AND :fin GROUP BY YEAR(fecha_pago), MONTH(fecha_pago) ORDER BY año ASC, mes ASC
        ");
        $stmt->execute(['tasa_actual' => $this->tasa_actual, 'inicio' => $inicio, 'fin' => $fin]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function obtenerVentasPorTipoServicio($inicio, $fin) {
        $stmt = $this->pdo->prepare("
            SELECT c.tipo_servicio, COUNT(p.id) as total_pedidos,
            SUM(CASE WHEN p.moneda_pago = 'USD' THEN p.monto_total WHEN p.moneda_pago = 'BS' THEN p.monto_total / :tasa_actual ELSE 0 END) as total_ventas_usd
            FROM pago p JOIN comanda c ON p.comanda_id = c.id 
            WHERE DATE(p.fecha_pago) BETWEEN :inicio AND :fin GROUP BY c.tipo_servicio
        ");
        $stmt->execute(['tasa_actual' => $this->tasa_actual, 'inicio' => $inicio, 'fin' => $fin]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function obtenerProductosMasVendidos($inicio, $fin) {
        $stmt = $this->pdo->prepare("
            SELECT CASE WHEN cp.nombre = 'Pizza Base' THEN CONCAT('Pizza ', dc.tamanio) ELSE p.nombre END AS nombre,
            cp.nombre as categoria, SUM(dc.cantidad) as total_vendido, SUM(dc.subtotal) as total_ingresos
            FROM detalle_comanda dc JOIN producto p ON dc.producto_id = p.id 
            JOIN categoria_producto cp ON p.categoria_id = cp.id JOIN comanda c ON dc.comanda_id = c.id
            WHERE c.estado = 'cobrado' AND DATE(c.fecha_creacion) BETWEEN :inicio AND :fin 
            GROUP BY nombre, categoria ORDER BY total_vendido DESC LIMIT 10
        ");
        $stmt->execute(['inicio' => $inicio, 'fin' => $fin]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function obtenerVentasPorMesero($inicio, $fin) {
        $stmt = $this->pdo->prepare("
            SELECT u.nombre as mesero_nombre, c.tipo_servicio, SUM(c.total) as total_ventas, COUNT(c.id) as total_pedidos
            FROM comanda c JOIN usuario u ON c.usuario_id = u.id JOIN rol r ON u.rol_id = r.id
            WHERE c.estado = 'cobrado' AND r.nombre = 'mesero' AND DATE(c.fecha_creacion) BETWEEN :inicio AND :fin 
            GROUP BY u.nombre, c.tipo_servicio ORDER BY mesero_nombre, total_ventas DESC
        ");
        $stmt->execute(['inicio' => $inicio, 'fin' => $fin]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function obtenerEstadisticasResumen($inicio, $fin) {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(id) as total_pedidos,
            SUM(CASE WHEN moneda_pago = 'USD' THEN monto_total WHEN moneda_pago = 'BS' THEN monto_total / :tasa ELSE 0 END) as total_ventas_usd,
            SUM(CASE WHEN moneda_pago = 'USD' THEN monto_total ELSE 0 END) as usd_recibido,
            SUM(CASE WHEN moneda_pago = 'BS' THEN monto_total ELSE 0 END) as bs_recibido
            FROM pago WHERE DATE(fecha_pago) BETWEEN :inicio AND :fin
        ");
        $stmt->execute(['tasa' => $this->tasa_actual, 'inicio' => $inicio, 'fin' => $fin]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function obtenerResumenMoneda($inicio, $fin) {
        $stmt = $this->pdo->prepare("
            SELECT moneda_pago, SUM(monto_total) as total FROM pago 
            WHERE DATE(fecha_pago) BETWEEN :inicio AND :fin GROUP BY moneda_pago
        ");
        $stmt->execute(['inicio' => $inicio, 'fin' => $fin]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function obtenerResumenTipoPago($inicio, $fin) {
        $stmt = $this->pdo->prepare("
            SELECT tp.nombre as tipo_pago, COUNT(p.id) as total_transacciones FROM pago p 
            JOIN tipo_pago tp ON p.tipo_pago_id = tp.id 
            WHERE DATE(p.fecha_pago) BETWEEN :inicio AND :fin GROUP BY tp.nombre ORDER BY total_transacciones DESC
        ");
        $stmt->execute(['inicio' => $inicio, 'fin' => $fin]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$tasa_actual = obtenerTasaDolarActual($pdo);
$controller = new ReporteController($pdo, $tasa_actual);

$ventas_rango = $controller->obtenerVentasPorRango($fecha_desde, $fecha_hasta);
$ventas_mensuales = $controller->obtenerVentasMensualesEnRango($fecha_desde, $fecha_hasta);
$ventas_servicio = $controller->obtenerVentasPorTipoServicio($fecha_desde, $fecha_hasta);
$top_productos = $controller->obtenerProductosMasVendidos($fecha_desde, $fecha_hasta);
$stats_resumen = $controller->obtenerEstadisticasResumen($fecha_desde, $fecha_hasta);
$meseros_stats = $controller->obtenerVentasPorMesero($fecha_desde, $fecha_hasta);
$moneda_data = $controller->obtenerResumenMoneda($fecha_desde, $fecha_hasta);
$tipo_pago_data = $controller->obtenerResumenTipoPago($fecha_desde, $fecha_hasta);

$historial_ventas = $controller->obtenerHistorialVentas($fecha_desde, $fecha_hasta, $busqueda);

$js_ventas = []; 
foreach ($ventas_rango as $v) $js_ventas[] = ['fecha' => date('d/m', strtotime($v['fecha'])), 'ventas' => (float)$v['total_ventas_usd']];

$js_mensuales = []; 
foreach ($ventas_mensuales as $v) $js_mensuales[] = ['mes' => DateTime::createFromFormat('!m', $v['mes'])->format('M') . ' ' . $v['año'], 'ventas' => (float)$v['total_ventas_usd']];

$js_servicios = []; 
foreach ($ventas_servicio as $s) $js_servicios[] = ['servicio' => $s['tipo_servicio'], 'ventas' => (float)$s['total_ventas_usd'], 'pedidos' => $s['total_pedidos']];

$js_productos = []; 
foreach ($top_productos as $p) $js_productos[] = ['producto' => $p['nombre'], 'vendidos' => (int)$p['total_vendido'], 'ingresos' => (float)$p['total_ingresos']];

$js_meseros = []; 
$temp_meseros = []; 
foreach ($meseros_stats as $m) {
    $nombre = $m['mesero_nombre'];
    if (!isset($temp_meseros[$nombre])) $temp_meseros[$nombre] = ['mesero' => $nombre, 'Mesa' => 0, 'Llevar' => 0];
    $temp_meseros[$nombre][$m['tipo_servicio']] = (float)$m['total_ventas'];
}
$js_meseros = array_values($temp_meseros);

$js_moneda = []; 
$usd_total = 0; $bs_total_en_usd = 0;
foreach ($moneda_data as $m) {
    if ($m['moneda_pago'] == 'USD') $usd_total += $m['total'];
    if ($m['moneda_pago'] == 'BS') $bs_total_en_usd += ($m['total'] / $tasa_actual);
}
$js_moneda[] = ['moneda' => 'USD', 'total_convertido_usd' => $usd_total];
$js_moneda[] = ['moneda' => 'BS', 'total_convertido_usd' => $bs_total_en_usd];

$js_tipos = []; 
foreach ($tipo_pago_data as $t) $js_tipos[] = ['tipo_pago' => $t['tipo_pago'], 'total_transacciones' => $t['total_transacciones']];
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reportes - Kpizza's</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../../css/admin.css" rel="stylesheet">
    <link href="../css/reportes.css" rel="stylesheet">
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <?php include '../partials/sidebar.php'; ?>

            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content">
                
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2"><i class="fas fa-chart-bar me-2 text-kpizza"></i>Reportes</h1>
                    
                    <form method="GET" class="d-flex gap-2 bg-white p-2 rounded shadow-sm align-items-center flex-wrap">
                        <div class="input-group input-group-sm" style="width: auto;">
                            <span class="input-group-text fw-bold">Desde</span>
                            <input type="date" name="desde" class="form-control" value="<?php echo $fecha_desde; ?>">
                        </div>
                        <div class="input-group input-group-sm" style="width: auto;">
                            <span class="input-group-text fw-bold">Hasta</span>
                            <input type="date" name="hasta" class="form-control" value="<?php echo $fecha_hasta; ?>">
                        </div>
                        
                        <?php if(!empty($busqueda)): ?>
                            <input type="hidden" name="q" value="<?php echo htmlspecialchars($busqueda); ?>">
                        <?php endif; ?>

                        <button type="submit" class="btn btn-sm btn-primary fw-bold">
                            <i class="fas fa-sync-alt me-1"></i> Filtrar
                        </button>
                    </form>
                </div>

                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="stats-card card-1">
                            <div class="stats-icon"><i class="fas fa-shopping-cart"></i></div>
                            <div class="stats-content"><h3><?php echo number_format($stats_resumen['total_pedidos']); ?></h3><p>Pedidos en Rango</p></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card card-2">
                            <div class="stats-icon"><i class="fas fa-dollar-sign"></i></div>
                            <div class="stats-content"><h3>$<?php echo number_format($stats_resumen['total_ventas_usd'], 2); ?></h3><p>Venta Total ($)</p></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card card-3">
                            <div class="stats-icon"><i class="fas fa-money-bill-wave"></i></div>
                            <div class="stats-content"><h3>Bs. <?php echo number_format($stats_resumen['bs_recibido'], 2); ?></h3><p>Recibido en Bs.</p></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card card-4">
                            <div class="stats-icon"><i class="fas fa-wallet"></i></div>
                            <div class="stats-content"><h3>$<?php echo number_format($stats_resumen['usd_recibido'], 2); ?></h3><p>Recibido en Divisa</p></div>
                        </div>
                    </div>
                </div>

                <div class="report-tabs-container mb-4">
                    <ul class="nav nav-pills" id="reportesTab" role="tablist">
                        <li class="nav-item"><button class="nav-link <?php echo ($tab_activa === 'ventas') ? 'active' : ''; ?>" id="ventas-tab" data-bs-toggle="pill" data-bs-target="#ventas" type="button"><i class="fas fa-chart-line me-1"></i> Ventas</button></li>
                        <li class="nav-item"><button class="nav-link" id="pagos-tab" data-bs-toggle="pill" data-bs-target="#pagos" type="button"><i class="fas fa-money-bill-wave me-1"></i> Pagos</button></li>
                        <li class="nav-item"><button class="nav-link" id="productos-tab" data-bs-toggle="pill" data-bs-target="#productos" type="button"><i class="fas fa-pizza-slice me-1"></i> Productos</button></li>
                        <li class="nav-item"><button class="nav-link" id="servicios-tab" data-bs-toggle="pill" data-bs-target="#servicios" type="button"><i class="fas fa-concierge-bell me-1"></i> Servicios</button></li>
                        <li class="nav-item"><button class="nav-link" id="meseros-tab" data-bs-toggle="pill" data-bs-target="#meseros" type="button"><i class="fas fa-user-tie me-1"></i> Meseros</button></li>
                        
                        <li class="nav-item">
                            <button class="nav-link <?php echo ($tab_activa === 'historial') ? 'active' : ''; ?>" id="historial-tab" data-bs-toggle="pill" data-bs-target="#historial" type="button">
                                <i class="fas fa-history me-1"></i> Historial de ventas
                            </button>
                        </li>
                    </ul>
                </div>

                <div class="tab-content" id="reportesTabContent">
                    
                    <div class="tab-pane fade <?php echo ($tab_activa === 'ventas') ? 'show active' : ''; ?>" id="ventas">
                        <div class="row">
                            <div class="col-lg-8">
                                <div class="chart-container">
                                    <div class="chart-header"><h5><i class="fas fa-chart-line me-2 text-primary"></i>Tendencia Diaria</h5></div>
                                    <canvas id="ventasChart" height="250"></canvas>
                                </div>
                            </div>
                            <div class="col-lg-4">
                                <div class="chart-container">
                                    <div class="chart-header"><h5><i class="fas fa-calendar-alt me-2 text-success"></i>Resumen Mensual</h5></div>
                                    <canvas id="ventasMensualesChart" height="250"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="pagos">
                        <div class="row">
                            <div class="col-lg-6">
                                <div class="chart-container">
                                    <div class="chart-header"><h5><i class="fas fa-chart-pie me-2 text-info"></i>Proporción Moneda (en valor $)</h5></div>
                                    <canvas id="monedaChart" height="300"></canvas>
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <div class="chart-container">
                                    <div class="chart-header"><h5><i class="fas fa-credit-card me-2 text-purple"></i>Transacciones por Método</h5></div>
                                    <canvas id="tipoPagoChart" height="300"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="productos">
                        <div class="row">
                            <div class="col-lg-6">
                                <div class="chart-container">
                                    <div class="chart-header"><h5><i class="fas fa-pizza-slice me-2 text-warning"></i>Top Productos (Cantidad)</h5></div>
                                    <canvas id="productosChart" height="300"></canvas>
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <div class="chart-container">
                                    <div class="chart-header"><h5><i class="fas fa-chart-bar me-2 text-info"></i>Top Productos (Ingresos $)</h5></div>
                                    <canvas id="ingresosProductosChart" height="300"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="row mt-4">
                            <div class="col-12">
                                <div class="chart-container">
                                    <div class="chart-header"><h5><i class="fas fa-list me-2"></i>Detalle de Productos</h5></div>
                                    <div class="table-responsive">
                                        <table class="table table-hover table-sm">
                                            <thead class="table-light"><tr><th>Producto</th><th>Categoría</th><th>Cant.</th><th>Ingresos</th></tr></thead>
                                            <tbody>
                                                <?php foreach ($top_productos as $p): ?>
                                                <tr>
                                                    <td><?php echo $p['nombre']; ?></td>
                                                    <td><?php echo $p['categoria']; ?></td>
                                                    <td><span class="badge bg-primary"><?php echo $p['total_vendido']; ?></span></td>
                                                    <td><strong>$<?php echo number_format($p['total_ingresos'], 2); ?></strong></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="servicios">
                        <div class="row">
                            <div class="col-lg-6">
                                <div class="chart-container">
                                    <div class="chart-header"><h5><i class="fas fa-concierge-bell me-2"></i>Ventas por Servicio</h5></div>
                                    <canvas id="serviciosChart" height="300"></canvas>
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <div class="table-responsive chart-container">
                                    <table class="table table-bordered mt-4">
                                        <thead class="table-light"><tr><th>Servicio</th><th>Pedidos</th><th>Ventas ($)</th></tr></thead>
                                        <tbody>
                                            <?php foreach ($ventas_servicio as $s): ?>
                                            <tr>
                                                <td><?php echo $s['tipo_servicio']; ?></td>
                                                <td><?php echo $s['total_pedidos']; ?></td>
                                                <td>$<?php echo number_format($s['total_ventas_usd'], 2); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="meseros">
                        <div class="row">
                            <div class="col-lg-12">
                                <div class="chart-container">
                                    <div class="chart-header"><h5><i class="fas fa-user-tie me-2"></i>Desempeño Meseros</h5></div>
                                    <canvas id="meserosChart" height="150"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade <?php echo ($tab_activa === 'historial') ? 'show active' : ''; ?>" id="historial">
                        <div class="card shadow-sm border-0">
                            
                            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap">
                                <div>
                                    <h5 class="mb-0"><i class="fas fa-list me-2"></i>Detalle de Transacciones</h5>
                                    <small class="text-muted">Mostrando resultados del <?php echo date('d/m/Y', strtotime($fecha_desde)); ?> al <?php echo date('d/m/Y', strtotime($fecha_hasta)); ?></small>
                                </div>
                                
                                <form method="GET" class="d-flex mt-2 mt-md-0">
                                    <input type="hidden" name="desde" value="<?php echo $fecha_desde; ?>">
                                    <input type="hidden" name="hasta" value="<?php echo $fecha_hasta; ?>">
                                    <div class="input-group input-group-sm">
                                        <input type="text" name="q" class="form-control" placeholder="Buscar ID, Ref o Banco..." value="<?php echo htmlspecialchars($busqueda); ?>" style="width: 220px;">
                                        <button class="btn btn-primary" type="submit"><i class="fas fa-search"></i></button>
                                        <?php if(!empty($busqueda)): ?>
                                            <a href="reportes.php?desde=<?php echo $fecha_desde; ?>&hasta=<?php echo $fecha_hasta; ?>" class="btn btn-secondary" title="Limpiar"><i class="fas fa-times"></i></a>
                                        <?php endif; ?>
                                    </div>
                                </form>
                            </div>

                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>ID</th><th>Fecha/Hora</th><th>Mesero</th><th>Tipo</th><th>Métodos de Pago</th><th>Referencias / Bancos</th><th class="text-end">Total ($)</th><th class="text-center">Acción</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($historial_ventas)): ?>
                                            <tr><td colspan="8" class="text-center py-5 text-muted">No se encontraron registros en este rango.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($historial_ventas as $venta): ?>
                                                <tr>
                                                    <td><span class="badge bg-secondary">#<?php echo $venta['id']; ?></span></td>
                                                    <td><?php echo date('d/m/y H:i', strtotime($venta['fecha_creacion'])); ?></td>
                                                    <td><?php echo htmlspecialchars($venta['mesero']); ?></td>
                                                    <td><?php echo ($venta['tipo_servicio'] == 'Mesa') ? '<span class="badge bg-primary">Mesa</span>' : '<span class="badge bg-warning text-dark">Llevar</span>'; ?></td>
                                                    <td class="small"><?php echo htmlspecialchars($venta['metodos_pago']); ?></td>
                                                    <td class="small">
                                                        <?php if($venta['referencias']): ?>
                                                            <strong>Ref:</strong> <?php echo htmlspecialchars($venta['referencias']); ?><br>
                                                            <span class="text-muted"><?php echo htmlspecialchars($venta['bancos']); ?></span>
                                                        <?php else: ?> - <?php endif; ?>
                                                    </td>
                                                    <td class="text-end fw-bold text-success">$<?php echo number_format($venta['total'], 2); ?></td>
                                                    <td class="text-center">
                                                        <a href="../../caja/generar_factura_pdf.php?id=<?php echo $venta['id']; ?>" target="_blank" class="btn btn-sm btn-outline-danger" title="Reimprimir">
                                                            <i class="fas fa-file-pdf"></i>
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <script>
        // Paso de datos PHP a JS
        window.reportData = {
            ventas: <?php echo json_encode($js_ventas); ?>,
            mensuales: <?php echo json_encode($js_mensuales); ?>,
            servicios: <?php echo json_encode($js_servicios); ?>,
            productos: <?php echo json_encode($js_productos); ?>,
            meseros: <?php echo json_encode($js_meseros); ?>,
            tipoPago: <?php echo json_encode($tipo_pago_data); ?>,
            moneda: <?php echo json_encode($js_moneda); ?>,
            tasaActual: <?php echo $tasa_actual; ?>
        };
    </script>
    
    <script src="../js/reportes.js"></script> 
</body>
</html>