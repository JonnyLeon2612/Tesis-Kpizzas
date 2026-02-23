<?php
// Configuración de Zona Horaria
date_default_timezone_set('America/Caracas');

require_once __DIR__ . '/../../auth/middleware.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/tasa_helper.php';

// Forzar zona horaria en BD
try {
    $pdo->exec("SET time_zone = '-04:00'");
} catch (Exception $e) {
}

require_role('admin');

// --- CONFIGURACIÓN DE PAGINACIÓN ---
$limit_historial = 10; // Cantidad de registros por página
$pagina_historial = isset($_GET['pagina']) ? max(1, (int)$_GET['pagina']) : 1;
$offset_historial = ($pagina_historial - 1) * $limit_historial;

// --- FILTROS ---
$fecha_desde = isset($_GET['desde']) ? $_GET['desde'] : date('Y-m-01');
$fecha_hasta = isset($_GET['hasta']) ? $_GET['hasta'] : date('Y-m-d');
$busqueda = isset($_GET['q']) ? trim($_GET['q']) : '';

// CORRECCIÓN: Lógica estricta para la pestaña activa
// Si hay búsqueda O si estamos paginando, activamos historial
if (isset($_GET['tab']) && $_GET['tab'] === 'historial') {
    $tab_activa = 'historial';
} elseif (!empty($busqueda) || isset($_GET['pagina'])) {
    $tab_activa = 'historial';
} else {
    $tab_activa = 'ventas';
}

class ReporteController
{
    private $pdo;
    private $tasa_actual;

    public function __construct($pdo, $tasa_actual)
    {
        $this->pdo = $pdo;
        $this->tasa_actual = $tasa_actual;
    }

// NUEVO: CONTAR REGISTROS PARA PAGINACIÓN (INCLUYENDO CLIENTE)
    public function contarHistorialVentas($inicio, $fin, $busqueda = '') {
        $sql = "SELECT COUNT(DISTINCT c.id) as total 
                FROM comanda c
                INNER JOIN pago p ON p.comanda_id = c.id
                LEFT JOIN cliente cl ON c.cliente_id = cl.id
                WHERE DATE(p.fecha_pago) BETWEEN :inicio AND :fin";
        
        $params = [':inicio' => $inicio, ':fin' => $fin];
        
        if (!empty($busqueda)) {
            $sql .= " AND (c.id LIKE :b1 OR p.referencia LIKE :b2 OR p.banco_origen LIKE :b3 OR cl.nombre LIKE :b4 OR cl.cedula LIKE :b5)";
            $params[':b1'] = $params[':b2'] = $params[':b3'] = $params[':b4'] = $params[':b5'] = "%$busqueda%";
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        return $res['total'] ?? 0;
    }

    // MODIFICADO: HISTORIAL CON BÚSQUEDA AVANZADA Y DATOS DEL CLIENTE
    public function obtenerHistorialVentas($inicio, $fin, $busqueda = '', $limit = null, $offset = 0) {
        $sql = "
            SELECT 
                c.id, 
                MAX(p.fecha_pago) as fecha_pago,
                c.total, 
                c.tipo_servicio,
                MAX(u.nombre) as mesero,
                MAX(cl.nombre) as cliente_nombre,
                MAX(cl.cedula) as cliente_cedula,
                SUM(CASE WHEN p.moneda_pago = 'USD' THEN p.monto_total ELSE 0 END) as total_usd,
                SUM(CASE WHEN p.moneda_pago = 'BS' THEN p.monto_total ELSE 0 END) as total_bs,
                MAX(p.tasa_cambio) as tasa_registrada,
                GROUP_CONCAT(DISTINCT tp.nombre SEPARATOR ' + ') as metodo_pago,
                GROUP_CONCAT(DISTINCT NULLIF(p.referencia, '') SEPARATOR ', ') as referencia,
                GROUP_CONCAT(DISTINCT NULLIF(p.banco_origen, '') SEPARATOR ', ') as banco_origen
            FROM comanda c
            INNER JOIN pago p ON p.comanda_id = c.id
            INNER JOIN usuario u ON c.usuario_id = u.id
            LEFT JOIN tipo_pago tp ON p.tipo_pago_id = tp.id
            LEFT JOIN cliente cl ON c.cliente_id = cl.id
            WHERE DATE(p.fecha_pago) BETWEEN :inicio AND :fin
        ";
        
        $params = [':inicio' => $inicio, ':fin' => $fin];
        
        if (!empty($busqueda)) {
            $sql .= " AND (c.id LIKE :b1 OR p.referencia LIKE :b2 OR p.banco_origen LIKE :b3 OR cl.nombre LIKE :b4 OR cl.cedula LIKE :b5)";
            $params[':b1'] = "%$busqueda%"; 
            $params[':b2'] = "%$busqueda%"; 
            $params[':b3'] = "%$busqueda%";
            $params[':b4'] = "%$busqueda%";
            $params[':b5'] = "%$busqueda%";
        }
        
        $sql .= " GROUP BY c.id ORDER BY c.id DESC";
        
        if ($limit !== null) {
            $sql .= " LIMIT :limit OFFSET :offset";
        }
        
        try { 
            $stmt = $this->pdo->prepare($sql);
            if ($limit !== null) {
                $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
                $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
            }
            foreach ($params as $key => $val) {
                $stmt->bindValue($key, $val);
            }
            $stmt->execute(); 
            return $stmt->fetchAll(PDO::FETCH_ASSOC); 
        } catch (PDOException $e) { 
            return []; 
        }
    }
    // --- RESTO DE FUNCIONES DE GRÁFICAS (SIN CAMBIOS) ---
    public function obtenerVentasPorRango($i, $f)
    {
        $sql = "SELECT DATE(fecha_pago) as f, COUNT(id) as c, SUM(CASE WHEN moneda_pago='USD' THEN monto_total WHEN moneda_pago='BS' THEN monto_total/:t ELSE 0 END) as t FROM pago WHERE DATE(fecha_pago) BETWEEN :i AND :f GROUP BY DATE(fecha_pago) ORDER BY f ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['t' => $this->tasa_actual, 'i' => $i, 'f' => $f]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function obtenerVentasMensualesEnRango($i, $f)
    {
        $sql = "SELECT YEAR(fecha_pago) as y, MONTH(fecha_pago) as m, SUM(CASE WHEN moneda_pago='USD' THEN monto_total WHEN moneda_pago='BS' THEN monto_total/:t ELSE 0 END) as t FROM pago WHERE DATE(fecha_pago) BETWEEN :i AND :f GROUP BY y, m ORDER BY y, m";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['t' => $this->tasa_actual, 'i' => $i, 'f' => $f]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function obtenerVentasPorTipoServicio($i, $f)
    {
        $sql = "SELECT c.tipo_servicio as t, COUNT(p.id) as c, SUM(CASE WHEN p.moneda_pago='USD' THEN p.monto_total WHEN p.moneda_pago='BS' THEN p.monto_total/:t ELSE 0 END) as v FROM pago p INNER JOIN comanda c ON p.comanda_id=c.id WHERE DATE(p.fecha_pago) BETWEEN :i AND :f GROUP BY c.tipo_servicio";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['t' => $this->tasa_actual, 'i' => $i, 'f' => $f]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function obtenerProductosMasVendidos($i, $f)
    {
        $sql = "SELECT CASE WHEN cp.nombre='Pizza Base' THEN CONCAT('Pizza ', dc.tamanio) ELSE p.nombre END AS n, cp.nombre as c, SUM(dc.cantidad) as q, SUM(dc.subtotal) as t FROM detalle_comanda dc INNER JOIN producto p ON dc.producto_id=p.id INNER JOIN categoria_producto cp ON p.categoria_id=cp.id INNER JOIN comanda c ON dc.comanda_id=c.id INNER JOIN pago pg ON c.id=pg.comanda_id WHERE c.estado='cobrado' AND DATE(pg.fecha_pago) BETWEEN :i AND :f GROUP BY n, c ORDER BY q DESC LIMIT 10";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['i' => $i, 'f' => $f]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function obtenerVentasPorMesero($i, $f)
    {
        $sql = "SELECT u.nombre as n, c.tipo_servicio as t, SUM(c.total) as v, COUNT(c.id) as c FROM comanda c INNER JOIN usuario u ON c.usuario_id=u.id INNER JOIN rol r ON u.rol_id=r.id INNER JOIN pago pg ON c.id=pg.comanda_id WHERE c.estado='cobrado' AND r.nombre='mesero' AND DATE(pg.fecha_pago) BETWEEN :i AND :f GROUP BY n, t ORDER BY n, v DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['i' => $i, 'f' => $f]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function obtenerEstadisticasResumen($i, $f)
    {
        $sql = "SELECT COUNT(id) as c, SUM(CASE WHEN moneda_pago='USD' THEN monto_total WHEN moneda_pago='BS' THEN monto_total/:t ELSE 0 END) as v, SUM(CASE WHEN moneda_pago='USD' THEN monto_total ELSE 0 END) as u, SUM(CASE WHEN moneda_pago='BS' THEN monto_total ELSE 0 END) as b FROM pago WHERE DATE(fecha_pago) BETWEEN :i AND :f";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['t' => $this->tasa_actual, 'i' => $i, 'f' => $f]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function obtenerResumenMoneda($i, $f)
    {
        $sql = "SELECT moneda_pago as m, SUM(monto_total) as t FROM pago WHERE DATE(fecha_pago) BETWEEN :i AND :f GROUP BY m";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['i' => $i, 'f' => $f]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function obtenerResumenTipoPago($i, $f)
    {
        $sql = "SELECT tp.nombre as n, COUNT(p.id) as c FROM pago p INNER JOIN tipo_pago tp ON p.tipo_pago_id=tp.id WHERE DATE(p.fecha_pago) BETWEEN :i AND :f GROUP BY n ORDER BY c DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['i' => $i, 'f' => $f]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$tasa_actual = obtenerTasaDolarActual($pdo);
$controller = new ReporteController($pdo, $tasa_actual);

// --- OBTENCIÓN DE DATOS ---

// 1. Datos Generales y Gráficas
$ventas_rango = $controller->obtenerVentasPorRango($fecha_desde, $fecha_hasta);
$stats_resumen = $controller->obtenerEstadisticasResumen($fecha_desde, $fecha_hasta);
$moneda_data = $controller->obtenerResumenMoneda($fecha_desde, $fecha_hasta);
$tipo_pago_data = $controller->obtenerResumenTipoPago($fecha_desde, $fecha_hasta);
$top_productos = $controller->obtenerProductosMasVendidos($fecha_desde, $fecha_hasta);
$ventas_mensuales = $controller->obtenerVentasMensualesEnRango($fecha_desde, $fecha_hasta);
$ventas_servicio = $controller->obtenerVentasPorTipoServicio($fecha_desde, $fecha_hasta);
$meseros_stats = $controller->obtenerVentasPorMesero($fecha_desde, $fecha_hasta);

// 2. Datos de Historial con PAGINACIÓN
$total_registros_historial = $controller->contarHistorialVentas($fecha_desde, $fecha_hasta, $busqueda);
$total_paginas = ceil($total_registros_historial / $limit_historial);
// Llamada corregida con limit y offset
$historial_ventas = $controller->obtenerHistorialVentas($fecha_desde, $fecha_hasta, $busqueda, $limit_historial, $offset_historial);

// Obtenemos TODO el historial sin límite para exportarlo
$historial_completo = $controller->obtenerHistorialVentas($fecha_desde, $fecha_hasta, $busqueda);

// --- PREPARACIÓN JS (Sin cambios) ---
$js_ventas = [];
foreach ($ventas_rango as $v) {
    $js_ventas[] = ['fecha' => date('d/m', strtotime($v['f'])), 'ventas' => (float)$v['t']];
}
$js_mensuales = [];
foreach ($ventas_mensuales as $v) {
    $js_mensuales[] = ['mes' => DateTime::createFromFormat('!m', $v['m'])->format('M') . ' ' . $v['y'], 'ventas' => (float)$v['t']];
}
$js_servicios = [];
foreach ($ventas_servicio as $s) {
    $js_servicios[] = ['servicio' => $s['t'], 'ventas' => (float)$s['v'], 'pedidos' => (int)$s['c']];
}
$js_productos = [];
foreach ($top_productos as $p) {
    $js_productos[] = ['producto' => $p['n'], 'vendidos' => (int)$p['q'], 'ingresos' => (float)$p['t']];
}
$js_meseros = [];
$temp_meseros = [];
foreach ($meseros_stats as $m) {
    $nombre = $m['n'];
    if (!isset($temp_meseros[$nombre])) {
        $temp_meseros[$nombre] = ['mesero' => $nombre, 'Mesa' => 0, 'Llevar' => 0];
    }
    $temp_meseros[$nombre][$m['t']] = (float)$m['v'];
}
$js_meseros = array_values($temp_meseros);
$js_moneda = [];
$usd_total = 0;
$bs_total_en_usd = 0;
foreach ($moneda_data as $m) {
    if ($m['m'] == 'USD') $usd_total += $m['t'];
    if ($m['m'] == 'BS') $bs_total_en_usd += ($m['t'] / $tasa_actual);
}
$js_moneda[] = ['moneda' => 'USD', 'total_convertido_usd' => $usd_total];
$js_moneda[] = ['moneda' => 'BS', 'total_convertido_usd' => $bs_total_en_usd];
$js_tipos = [];
foreach ($tipo_pago_data as $t) {
    $js_tipos[] = ['tipo_pago' => $t['n'], 'total_transacciones' => (int)$t['c']];
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Reportes - Kpizza's</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../../css/admin.css" rel="stylesheet">
    <link href="../css/reportes.css" rel="stylesheet">
    <link href="../css/reportes_fix.css" rel="stylesheet">
    <style>
        /* Estilo simple para la paginación activa en rojo */
        .pagination .page-link {
            color: #dc3545;
        }

        .pagination .page-item.active .page-link {
            background-color: #dc3545;
            border-color: #dc3545;
            color: white;
        }
    </style>
</head>

<body>
    <div class="container-fluid">
        <div class="row">
            <?php include '../partials/sidebar.php'; ?>
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content">

                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2"><i class="fas fa-chart-bar me-2 text-danger"></i>Reportes</h1>
                    <form method="GET" class="d-flex gap-2 bg-white p-2 rounded shadow-sm align-items-center">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text fw-bold">Desde</span>
                            <input type="date" name="desde" class="form-control" value="<?php echo htmlspecialchars($fecha_desde); ?>">
                        </div>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text fw-bold">Hasta</span>
                            <input type="date" name="hasta" class="form-control" value="<?php echo htmlspecialchars($fecha_hasta); ?>">
                        </div>
                        <?php if (!empty($busqueda)): ?>
                            <input type="hidden" name="q" value="<?php echo htmlspecialchars($busqueda); ?>">
                        <?php endif; ?>
                        <button type="submit" class="btn btn-sm btn-danger fw-bold">
                            <i class="fas fa-filter me-1"></i> Filtrar
                        </button>
                    </form>
                </div>

                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="stats-card card-1">
                            <div class="stats-icon"><i class="fas fa-shopping-cart"></i></div>
                            <div class="stats-content">
                                <h3><?php echo number_format($stats_resumen['c'] ?? 0); ?></h3>
                                <p>Pagos en Rango</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card card-2">
                            <div class="stats-icon"><i class="fas fa-dollar-sign"></i></div>
                            <div class="stats-content">
                                <h3>$<?php echo number_format($stats_resumen['v'] ?? 0, 2); ?></h3>
                                <p>Venta Total ($)</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card card-3">
                            <div class="stats-icon"><i class="fas fa-money-bill-wave"></i></div>
                            <div class="stats-content">
                                <h3>Bs. <?php echo number_format($stats_resumen['b'] ?? 0, 2); ?></h3>
                                <p>Recibido en Bs.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card card-4">
                            <div class="stats-icon"><i class="fas fa-wallet"></i></div>
                            <div class="stats-content">
                                <h3>$<?php echo number_format($stats_resumen['u'] ?? 0, 2); ?></h3>
                                <p>Recibido en USD</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mb-4">
                    <ul class="nav nav-pills" id="reportesTab" role="tablist">
                        <li class="nav-item"><button class="nav-link <?php echo ($tab_activa === 'ventas') ? 'active' : ''; ?>" id="ventas-tab" data-bs-toggle="pill" data-bs-target="#ventas" type="button"><i class="fas fa-chart-line me-1"></i> Ventas</button></li>
                        <li class="nav-item"><button class="nav-link" id="pagos-tab" data-bs-toggle="pill" data-bs-target="#pagos" type="button"><i class="fas fa-money-bill-wave me-1"></i> Pagos</button></li>
                        <li class="nav-item"><button class="nav-link" id="productos-tab" data-bs-toggle="pill" data-bs-target="#productos" type="button"><i class="fas fa-pizza-slice me-1"></i> Productos</button></li>
                        <li class="nav-item"><button class="nav-link" id="servicios-tab" data-bs-toggle="pill" data-bs-target="#servicios" type="button"><i class="fas fa-concierge-bell me-1"></i> Servicios</button></li>
                        <li class="nav-item"><button class="nav-link" id="meseros-tab" data-bs-toggle="pill" data-bs-target="#meseros" type="button"><i class="fas fa-user-tie me-1"></i> Meseros</button></li>
                        <li class="nav-item"><button class="nav-link <?php echo ($tab_activa === 'historial') ? 'active' : ''; ?>" id="historial-tab" data-bs-toggle="pill" data-bs-target="#historial" type="button"><i class="fas fa-history me-1"></i> Historial de Pagos</button></li>
                    </ul>
                </div>

                <div class="tab-content" id="reportesTabContent">
                    <div class="tab-pane fade <?php echo ($tab_activa === 'ventas') ? 'show active' : ''; ?>" id="ventas">
                        <div class="row">
                            <div class="col-lg-8">
                                <div class="chart-container">
                                    <div class="chart-header mb-3">
                                        <h5><i class="fas fa-chart-line me-2 text-primary"></i>Tendencia Diaria de Ventas</h5>
                                    </div>
                                    <div class="chart-wrapper"><canvas id="ventasChart"></canvas></div>
                                </div>
                            </div>
                            <div class="col-lg-4">
                                <div class="chart-container">
                                    <div class="chart-header mb-3">
                                        <h5><i class="fas fa-calendar-alt me-2 text-success"></i>Resumen Mensual</h5>
                                    </div>
                                    <div class="chart-wrapper"><canvas id="ventasMensualesChart"></canvas></div>
                                </div>
                            </div>
                        </div>
                        <div class="row mt-4">
                            <div class="col-12">
                                <div class="chart-container">
                                    <div class="chart-header mb-3 d-flex justify-content-between align-items-center">
                                        <h5><i class="fas fa-list me-2"></i>Detalle de Ventas Diarias</h5>
                                        <div class="d-flex gap-2">
                                            <button class="btn btn-success btn-sm text-white" onclick="exportarExcel('tabla-ventas', 'Reporte_Ventas_Diarias')"><i class="fas fa-file-excel me-1"></i> Excel</button>
                                            <button class="btn btn-danger btn-sm text-white" onclick="exportarPDF('tabla-ventas', 'Reporte_Ventas_Diarias', 'Reporte de Ventas Diarias')"><i class="fas fa-file-pdf me-1"></i> PDF</button>
                                        </div>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-hover" id="tabla-ventas">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Fecha</th>
                                                    <th>Cantidad de Pagos</th>
                                                    <th>Total Venta ($)</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (!empty($ventas_rango)): ?>
                                                    <?php foreach ($ventas_rango as $v): ?>
                                                        <tr>
                                                            <td><?php echo date('d/m/Y', strtotime($v['f'])); ?></td>
                                                            <td><span class="badge bg-secondary"><?php echo $v['c']; ?></span></td>
                                                            <td class="text-success fw-bold">$<?php echo number_format($v['t'], 2); ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr>
                                                        <td colspan="3" class="text-center py-3 text-muted">No hay datos de ventas en este rango</td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>

                    <div class="tab-pane fade" id="pagos">
                        <div class="row">
                            <div class="col-lg-6">
                                <div class="chart-container">
                                    <div class="chart-header mb-3">
                                        <h5><i class="fas fa-chart-pie me-2 text-info"></i>Proporción por Moneda</h5>
                                    </div>
                                    <div class="chart-wrapper"><canvas id="monedaChart"></canvas></div>
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <div class="chart-container">
                                    <div class="chart-header mb-3">
                                        <h5><i class="fas fa-credit-card me-2 text-purple"></i>Métodos de Pago</h5>
                                    </div>
                                    <div class="chart-wrapper"><canvas id="tipoPagoChart"></canvas></div>
                                </div>
                            </div>
                        </div>
                        <div class="row mt-4">
                            <div class="col-12">
                                <div class="chart-container">
                                    <div class="chart-header mb-3 d-flex justify-content-between align-items-center">
                                        <h5><i class="fas fa-list me-2"></i>Detalle de Métodos de Pago</h5>
                                        <div class="d-flex gap-2">
                                            <button class="btn btn-success btn-sm text-white" onclick="exportarExcel('tabla-pagos', 'Reporte_Pagos')"><i class="fas fa-file-excel me-1"></i> Excel</button>
                                            <button class="btn btn-danger btn-sm text-white" onclick="exportarPDF('tabla-pagos', 'Reporte_Pagos', 'Reporte de Métodos de Pago')"><i class="fas fa-file-pdf me-1"></i> PDF</button>
                                        </div>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-bordered" id="tabla-pagos">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Método de Pago</th>
                                                    <th>Cantidad de Transacciones</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($tipo_pago_data as $t): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($t['n']); ?></td>
                                                        <td><?php echo $t['c']; ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="productos">
                        <div class="row">
                            <div class="col-lg-6">
                                <div class="chart-container">
                                    <div class="chart-header mb-3">
                                        <h5><i class="fas fa-pizza-slice me-2 text-warning"></i>Productos Más Vendidos</h5>
                                    </div>
                                    <div class="chart-wrapper"><canvas id="productosChart"></canvas></div>
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <div class="chart-container">
                                    <div class="chart-header mb-3">
                                        <h5><i class="fas fa-chart-bar me-2 text-info"></i>Ingresos por Producto</h5>
                                    </div>
                                    <div class="chart-wrapper"><canvas id="ingresosProductosChart"></canvas></div>
                                </div>
                            </div>
                        </div>
                        <div class="row mt-4">
                            <div class="col-12">
                                <div class="chart-container">
                                    <div class="chart-header mb-3 d-flex justify-content-between align-items-center">
                                        <h5><i class="fas fa-list me-2"></i>Detalle de Productos</h5>
                                        <div class="d-flex gap-2">
                                            <button class="btn btn-success btn-sm text-white" onclick="exportarExcel('tabla-rentabilidad-productos', 'Rentabilidad_Productos')"><i class="fas fa-file-excel me-1"></i> Excel</button>
                                            <button class="btn btn-danger btn-sm text-white" onclick="exportarPDF('tabla-rentabilidad-productos', 'Rentabilidad_Productos', 'Análisis de Rentabilidad - Productos')"><i class="fas fa-file-pdf me-1"></i> PDF</button>
                                        </div>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-hover table-sm" id="tabla-rentabilidad-productos">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Producto</th>
                                                    <th>Categoría</th>
                                                    <th>Cantidad</th>
                                                    <th>Ingresos ($)</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (!empty($top_productos)): ?>
                                                    <?php foreach ($top_productos as $p): ?>
                                                        <tr>
                                                            <td><?php echo htmlspecialchars($p['n']); ?></td>
                                                            <td><?php echo htmlspecialchars($p['c']); ?></td>
                                                            <td><span class="badge bg-primary"><?php echo $p['q']; ?></span></td>
                                                            <td><strong>$<?php echo number_format($p['t'], 2); ?></strong></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr>
                                                        <td colspan="4" class="text-center py-3 text-muted">No hay productos vendidos en este rango</td>
                                                    </tr>
                                                <?php endif; ?>
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
                                    <div class="chart-header mb-3">
                                        <h5><i class="fas fa-concierge-bell me-2 text-danger"></i>Ventas por Tipo de Servicio</h5>
                                    </div>
                                    <div class="chart-wrapper"><canvas id="serviciosChart"></canvas></div>
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <div class="chart-container">
                                    <div class="chart-header mb-3 d-flex justify-content-between align-items-center">
                                        <h5><i class="fas fa-table me-2 text-success"></i>Detalle por Servicio</h5>
                                        <div class="d-flex gap-2">
                                            <button class="btn btn-success btn-sm text-white" onclick="exportarExcel('tabla-rentabilidad-servicios', 'Rentabilidad_Servicios')"><i class="fas fa-file-excel me-1"></i> Excel</button>
                                            <button class="btn btn-danger btn-sm text-white" onclick="exportarPDF('tabla-rentabilidad-servicios', 'Rentabilidad_Servicios', 'Análisis de Rentabilidad - Servicios')"><i class="fas fa-file-pdf me-1"></i> PDF</button>
                                        </div>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-bordered" id="tabla-rentabilidad-servicios">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Servicio</th>
                                                    <th>Pedidos</th>
                                                    <th>Ventas ($)</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (!empty($ventas_servicio)): ?>
                                                    <?php foreach ($ventas_servicio as $s): ?>
                                                        <tr>
                                                            <td><?php echo htmlspecialchars($s['t']); ?></td>
                                                            <td><?php echo $s['c']; ?></td>
                                                            <td><strong>$<?php echo number_format($s['v'], 2); ?></strong></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr>
                                                        <td colspan="3" class="text-center py-3 text-muted">No hay datos de servicios en este rango</td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="meseros">
                        <div class="row">
                            <div class="col-lg-12">
                                <div class="chart-container">
                                    <div class="chart-header mb-3">
                                        <h5><i class="fas fa-user-tie me-2 text-primary"></i>Desempeño de Meseros</h5>
                                    </div>
                                    <div class="chart-wrapper"><canvas id="meserosChart"></canvas></div>
                                </div>
                            </div>
                        </div>
                        <div class="row mt-4">
                            <div class="col-12">
                                <div class="chart-container">
                                    <div class="chart-header mb-3 d-flex justify-content-between align-items-center">
                                        <h5><i class="fas fa-list me-2"></i>Detalle por Mesero</h5>
                                        <div class="d-flex gap-2">
                                            <button class="btn btn-success btn-sm text-white" onclick="exportarExcel('tabla-meseros', 'Reporte_Meseros')"><i class="fas fa-file-excel me-1"></i> Excel</button>
                                            <button class="btn btn-danger btn-sm text-white" onclick="exportarPDF('tabla-meseros', 'Reporte_Meseros', 'Reporte de Desempeño de Meseros')"><i class="fas fa-file-pdf me-1"></i> PDF</button>
                                        </div>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-bordered" id="tabla-meseros">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Mesero</th>
                                                    <th>Tipo de Servicio</th>
                                                    <th>Total Vendido ($)</th>
                                                    <th>Cantidad de Pedidos</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($meseros_stats as $m): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($m['n']); ?></td>
                                                        <td><?php echo htmlspecialchars($m['t']); ?></td>
                                                        <td><strong>$<?php echo number_format($m['v'], 2); ?></strong></td>
                                                        <td><?php echo $m['c']; ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade <?php echo ($tab_activa === 'historial') ? 'show active' : ''; ?>" id="historial">
                        <div class="card shadow-sm border-0">
                            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap">
                                <div>
                                    <h5 class="mb-0"><i class="fas fa-list me-2 text-danger"></i>Historial de Pagos</h5>
                                    <small class="text-muted">
                                        Mostrando pagos del <?php echo date('d/m/Y', strtotime($fecha_desde)); ?> al <?php echo date('d/m/Y', strtotime($fecha_hasta)); ?>
                                        | Página <?php echo $pagina_historial; ?> de <?php echo $total_paginas; ?>
                                    </small>
                                </div>

                                <div class="d-flex gap-2 mt-2 mt-md-0 align-items-center">
                                    <button class="btn btn-success btn-sm text-white" onclick="exportarExcel('tabla-historial-completo', 'Historial_Pagos_Completo')"><i class="fas fa-file-excel me-1"></i> Excel</button>
                                    <button class="btn btn-danger btn-sm text-white" onclick="exportarPDF('tabla-historial-completo', 'Historial_Pagos_Completo', 'Historial de Pagos Completo')"><i class="fas fa-file-pdf me-1"></i> PDF</button>

                                    <form method="GET" class="d-flex m-0 ms-2" onsubmit="return validarBusqueda(this)">
                                        <input type="hidden" name="desde" value="<?php echo htmlspecialchars($fecha_desde); ?>">
                                        <input type="hidden" name="hasta" value="<?php echo htmlspecialchars($fecha_hasta); ?>">
                                        <input type="hidden" name="tab" value="historial">
                                        <div class="input-group input-group-sm">
                                            <input type="text" name="q" id="inputBusqueda" class="form-control"
                                                placeholder="Buscar..."
                                                value="<?php echo htmlspecialchars($busqueda); ?>"
                                                style="width: 250px;">
                                            <button class="btn btn-danger" type="submit">
                                                <i class="fas fa-search"></i>
                                            </button>
                                            <?php if (!empty($busqueda)): ?>
                                                <a href="reportes.php?desde=<?php echo urlencode($fecha_desde); ?>&hasta=<?php echo urlencode($fecha_hasta); ?>&tab=historial"
                                                    class="btn btn-secondary" title="Limpiar búsqueda">
                                                    <i class="fas fa-times"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </form>
                                </div>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0" id="tabla-historial-pagos">
                                    <thead class="table-light">
                                        <tr>
                                            <th>ID</th>
                                            <th>Fecha/Hora Pago</th>
                                            <th>Mesero</th>
                                            <th>Tipo</th>
                                            <th>Método</th>
                                            <th>Monto</th>
                                            <th>Tasa</th>
                                            <th>Ref / Banco</th>
                                            <th class="text-end">Total Comanda ($)</th>
                                            <th class="text-center">Acción</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($historial_ventas)): ?>
                                            <tr>
                                                <td colspan="10" class="text-center py-5 text-muted">
                                                    <i class="fas fa-search fa-2x mb-3"></i><br>
                                                    No se encontraron pagos en este rango de fechas
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($historial_ventas as $venta): ?>
                                               <tr>
                                                <td><span class="badge bg-secondary">#<?php echo $venta['id']; ?></span></td>
                                                <td class="fw-bold">
                                                    <?php 
                                                        if (!empty($venta['fecha_pago'])) {
                                                            echo date('d/m/Y H:i', strtotime($venta['fecha_pago']));
                                                        } else {
                                                            echo '<span class="text-muted">Sin fecha</span>';
                                                        }
                                                    ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($venta['mesero']); ?></td>
                                                <td>
                                                    <?php if($venta['tipo_servicio'] == 'Mesa'): ?>
                                                        <span class="badge bg-primary">Mesa</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning text-dark">Llevar</span>
                                                        <?php if(!empty($venta['cliente_nombre'])): ?>
                                                            <br><small class="text-muted fw-bold" style="font-size: 0.75rem;"><i class="fas fa-user me-1"></i><?php echo htmlspecialchars($venta['cliente_nombre']); ?></small>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="small fw-bold text-primary"><?php echo htmlspecialchars($venta['metodo_pago'] ?? 'N/A'); ?></td>
                                                <td class="small">
                                                    <?php if($venta['total_usd'] > 0): ?>
                                                        <div class="text-primary fw-bold">$<?php echo number_format($venta['total_usd'], 2); ?></div>
                                                    <?php endif; ?>
                                                    <?php if($venta['total_bs'] > 0): ?>
                                                        <div class="text-success fw-bold">Bs. <?php echo number_format($venta['total_bs'], 2); ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="small text-center"><?php echo ($venta['tasa_registrada'] > 0) ? number_format($venta['tasa_registrada'], 2) : '-'; ?></td>
                                                <td class="small">
                                                    <?php if(!empty($venta['referencia'])): ?>
                                                        <strong>Ref: <?php echo htmlspecialchars($venta['referencia']); ?></strong>
                                                        <?php if(!empty($venta['banco_origen'])): ?>
                                                            <br><span class="text-muted"><?php echo htmlspecialchars($venta['banco_origen']); ?></span>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end fw-bold text-dark">$<?php echo number_format($venta['total'], 2); ?></td>
                                                <td class="text-center">
                                                    <a href="../../caja/generar_factura_pdf.php?id=<?php echo $venta['id']; ?>" target="_blank" class="btn btn-sm btn-outline-danger" title="Reimprimir factura">
                                                        <i class="fas fa-file-pdf"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <table id="tabla-historial-completo" style="display: none;">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Fecha/Hora Pago</th>
                                        <th>Mesero</th>
                                        <th>Tipo</th>
                                        <th>Método</th>
                                        <th>Monto</th>
                                        <th>Tasa</th>
                                        <th>Ref / Banco</th>
                                        <th>Total Comanda ($)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($historial_completo as $venta): ?>
                                        <tr>
                                        <td><?php echo $venta['id']; ?></td>
                                        <td><?php echo !empty($venta['fecha_pago']) ? date('d/m/Y H:i', strtotime($venta['fecha_pago'])) : 'Sin fecha'; ?></td>
                                        <td><?php echo htmlspecialchars($venta['mesero']); ?></td>
                                        <td>
                                            <?php 
                                                echo $venta['tipo_servicio']; 
                                                if ($venta['tipo_servicio'] == 'Llevar' && !empty($venta['cliente_nombre'])) {
                                                    echo ' (' . htmlspecialchars($venta['cliente_nombre']) . ')';
                                                }
                                            ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($venta['metodo_pago'] ?? 'N/A'); ?></td>
                                        <td>
                                            <?php 
                                                $montos_str = [];
                                                if($venta['total_usd'] > 0) $montos_str[] = '$' . number_format($venta['total_usd'], 2);
                                                if($venta['total_bs'] > 0) $montos_str[] = 'Bs. ' . number_format($venta['total_bs'], 2);
                                                echo implode(" | ", $montos_str);
                                            ?>
                                        </td>
                                        <td><?php echo ($venta['tasa_registrada'] > 0) ? number_format($venta['tasa_registrada'], 2) : '-'; ?></td>
                                        <td><?php echo !empty($venta['referencia']) ? htmlspecialchars($venta['referencia']) . ' ' . htmlspecialchars($venta['banco_origen'] ?? '') : '-'; ?></td>
                                        <td>$<?php echo number_format($venta['total'], 2); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>

                            <?php if ($total_paginas > 1): ?>
                                <div class="card-footer bg-white">
                                    <nav aria-label="Paginación del historial">
                                        <ul class="pagination pagination-sm justify-content-center mb-0">
                                            <?php if ($pagina_historial > 1): ?>
                                                <li class="page-item">
                                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $pagina_historial - 1])); ?>#historial">
                                                        &laquo; Anterior
                                                    </a>
                                                </li>
                                            <?php else: ?>
                                                <li class="page-item disabled"><span class="page-link">&laquo; Anterior</span></li>
                                            <?php endif; ?>

                                            <?php for ($i = 1; $i <= $total_paginas; $i++): ?>
                                                <li class="page-item <?php echo ($pagina_historial == $i) ? 'active' : ''; ?>">
                                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $i])); ?>#historial">
                                                        <?php echo $i; ?>
                                                    </a>
                                                </li>
                                            <?php endfor; ?>

                                            <?php if ($pagina_historial < $total_paginas): ?>
                                                <li class="page-item">
                                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $pagina_historial + 1])); ?>#historial">
                                                        Siguiente &raquo;
                                                    </a>
                                                </li>
                                            <?php else: ?>
                                                <li class="page-item disabled"><span class="page-link">Siguiente &raquo;</span></li>
                                            <?php endif; ?>
                                        </ul>
                                    </nav>
                                </div>
                            <?php endif; ?>

                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/xlsx-js-style@1.2.0/dist/xlsx.bundle.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // DATOS PARA LAS GRÁFICAS
        window.reportData = {
            ventas: <?php echo json_encode($js_ventas); ?>,
            mensuales: <?php echo json_encode($js_mensuales); ?>,
            servicios: <?php echo json_encode($js_servicios); ?>,
            productos: <?php echo json_encode($js_productos); ?>,
            meseros: <?php echo json_encode($js_meseros); ?>,
            tipoPago: <?php echo json_encode($js_tipos); ?>,
            moneda: <?php echo json_encode($js_moneda); ?>,
            tasaActual: <?php echo $tasa_actual; ?>
        };

        console.log('Datos cargados:', window.reportData);

        // FUNCIÓN PARA VALIDAR BÚSQUEDA VACÍA
        function validarBusqueda(form) {
            const input = document.getElementById('inputBusqueda');
            if (input.value.trim() === '') {
                // Si el campo está vacío, no envía el formulario
                return false;
            }
            return true;
        }
    </script>
    <script src="../js/reportes.js"></script>
</body>

</html>