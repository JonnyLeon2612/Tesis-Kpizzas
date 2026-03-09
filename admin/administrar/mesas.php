<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_once __DIR__ . '/../../config/db.php';
require_role('admin');

// --- 1. LÓGICA PARA AGREGAR MESA (Continuidad: Busca el máximo + 1) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'agregar_mesa') {
    try {
        $stmt = $pdo->query("SELECT COALESCE(MAX(numero), 0) FROM mesa");
        $max_numero = $stmt->fetchColumn();
        $nuevo_numero = $max_numero + 1;

        $ins = $pdo->prepare("INSERT INTO mesa (numero, estado, activo, eliminado) VALUES (?, 'disponible', 1, 0)");
        $ins->execute([$nuevo_numero]);
        
        header("Location: mesas.php?agregado=" . $nuevo_numero);
        exit;
    } catch (PDOException $e) {
        header("Location: mesas.php?error_bd=true");
        exit;
    }
}

// --- 2. LÓGICA PARA INACTIVAR / ACTIVAR (Ocultar a meseros) ---
if (isset($_GET['toggle'])) {
    $id = (int)$_GET['toggle'];
    try {
        $stmt = $pdo->prepare("SELECT activo, estado FROM mesa WHERE id = ? AND eliminado = 0");
        $stmt->execute([$id]);
        $mesa = $stmt->fetch();

        if ($mesa && $mesa['estado'] == 'disponible') {
            $nuevo_activo = $mesa['activo'] == 1 ? 0 : 1;
            $upd = $pdo->prepare("UPDATE mesa SET activo = ? WHERE id = ?");
            $upd->execute([$nuevo_activo, $id]);
            header("Location: mesas.php?toggled=" . $nuevo_activo);
        } else {
            header("Location: mesas.php?error_estado=true");
        }
        exit;
    } catch (PDOException $e) {
        header("Location: mesas.php?error_bd=true");
        exit;
    }
}

// --- 3. LÓGICA PARA ELIMINAR (Soft Delete) ---
if (isset($_GET['eliminar'])) {
    $id = (int)$_GET['eliminar'];
    try {
        $stmt = $pdo->prepare("SELECT estado FROM mesa WHERE id = ?");
        $stmt->execute([$id]);
        $mesa = $stmt->fetch();

        if ($mesa && $mesa['estado'] == 'disponible') {
            $del = $pdo->prepare("UPDATE mesa SET eliminado = 1, activo = 0 WHERE id = ?");
            $del->execute([$id]);
            header("Location: mesas.php?eliminado=true");
        } else {
            header("Location: mesas.php?error_estado=true");
        }
        exit;
    } catch (PDOException $e) {
        header("Location: mesas.php?error_bd=true");
        exit;
    }
}

// --- 4. CONFIGURACIÓN DEL FILTRO DE FECHAS ---
// Por defecto muestra el mes actual si no se ha filtrado nada
$fecha_inicio = isset($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : date('Y-m-01');
$fecha_fin = isset($_GET['fecha_fin']) ? $_GET['fecha_fin'] : date('Y-m-d');

// --- CONSULTA MAESTRA CON FILTRO DE FECHAS ---
// NOTA: Si tu columna de fecha en la tabla 'comanda' se llama diferente (ej. 'created_at'), 
// cámbialo donde dice "DATE(c.fecha)"
$query = "
    SELECT m.*, 
           COUNT(c.id) as total_pedidos, 
           COALESCE(SUM(c.total), 0) as total_ingresos 
    FROM mesa m 
    LEFT JOIN comanda c ON m.id = c.mesa_id 
        AND c.estado = 'cobrado' 
        AND DATE(c.fecha_creacion) BETWEEN :fecha_inicio AND :fecha_fin
    WHERE m.eliminado = 0
    GROUP BY m.id 
    ORDER BY m.activo DESC, m.numero ASC
";

$stmt = $pdo->prepare($query);
$stmt->execute([
    'fecha_inicio' => $fecha_inicio,
    'fecha_fin' => $fecha_fin
]);
$mesas = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Mesas - Kpizza's</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    
    <link href="../../css/admin.css" rel="stylesheet">
    <link href="../css/productos.css" rel="stylesheet"> 

    <style>
        :root {
            --bg-body: #fffdfaf2; 
            --text-main: #2b211e; 
            --text-muted: #7d706a; 
            --border-color: #f0e6e1; 
            --primary: #da251d; /* Rojo Tomate Kpizza's */
            --primary-dark: #b81c15; 
            --accent: #f59e0b; /* Queso derretido */
            --success: #16a34a; 
            --danger: #da251d; 
            --warning: #f59e0b; 
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-body);
            color: var(--text-main);
        }

        /* --- NUEVA CABECERA ROJA PREMIUM --- */
        .page-header-red {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            border-radius: 1rem;
            padding: 2rem 2.5rem;
            margin-top: 1.5rem;
            margin-bottom: 2rem;
            color: white;
            box-shadow: 0 10px 25px -5px rgba(218, 37, 29, 0.4);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .page-header-red .page-title {
            font-weight: 700;
            font-size: 1.75rem;
            color: white;
            margin: 0;
            letter-spacing: -0.025em;
        }

        .btn-header-action {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.4);
            backdrop-filter: blur(4px);
            font-weight: 600;
            padding: 0.6rem 1.25rem;
            border-radius: 0.5rem;
            transition: all 0.2s ease;
        }
        .btn-header-action:hover {
            background: white;
            color: var(--primary);
            transform: translateY(-2px);
        }

        /* Filtro de Fechas */
        .filter-card {
            background: white;
            border-radius: 0.75rem;
            border: 1px solid var(--border-color);
            padding: 1rem 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }

        /* Resto de Card y Tabla */
        .modern-card {
            background: #ffffff;
            border-radius: 1rem;
            border: 1px solid var(--border-color);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }

        .modern-card-header {
            background: #ffffff;
            border-bottom: 1px solid var(--border-color);
            padding: 1.25rem 1.5rem;
            font-weight: 600;
            color: var(--text-main);
        }

        .table-modern th {
            text-transform: uppercase;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            background-color: #faf5f2; 
            border-bottom: 1px solid var(--border-color);
            padding: 1rem 1.5rem;
        }

        .table-modern td {
            padding: 1rem 1.5rem;
            vertical-align: middle;
            border-bottom: 1px solid var(--border-color);
        }

        .table-modern tbody tr:last-child td { border-bottom: none; }
        .table-modern tbody tr:hover { background-color: #fffaf6; }
        .row-inactive { opacity: 0.65; background-color: #fcfcfc; filter: grayscale(30%); }

        .modern-badge {
            padding: 0.35rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
        }
        
        .badge-success { background-color: #dcfce7; color: var(--success); }
        .badge-danger { background-color: #fee2e2; color: var(--danger); }
        .badge-secondary { background-color: #f1f5f9; color: var(--text-muted); }
        .badge-primary { background-color: #fef3c7; color: #b45309; } 

        .icon-box {
            width: 42px;
            height: 42px;
            border-radius: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            background: #f1f5f9;
            color: var(--text-muted);
        }
        .icon-box.active { background: #fee2e2; color: var(--primary); }

        .action-btn {
            width: 32px;
            height: 32px;
            border-radius: 0.375rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid transparent;
            transition: all 0.2s;
            color: var(--text-muted);
            background: transparent;
            text-decoration: none;
        }
        .action-btn:hover { background: #f0e6e1; color: var(--text-main); }
        .btn-toggle { color: var(--warning); }
        .btn-toggle:hover { background: #fef3c7; color: #d97706; }
        .btn-delete { color: var(--danger); }
        .btn-delete:hover { background: #fee2e2; color: var(--danger); }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <?php include '../partials/sidebar.php'; ?>

            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content">
                
                <div class="page-header-red">
                    <div>
                        <h1 class="page-title"><i class="fas fa-pizza-slice me-2"></i> Gestión de Mesas</h1>
                        <p class="mb-0 mt-1 opacity-75 small">Administra la disponibilidad y analiza el rendimiento de tus mesas.</p>
                    </div>
                    <form method="POST" id="form-agregar-mesa" class="m-0">
                        <input type="hidden" name="accion" value="agregar_mesa">
                        <button type="submit" class="btn btn-header-action">
                            <i class="fas fa-plus-circle me-1"></i> Nueva Mesa
                        </button>
                    </form>
                </div>

                <div class="filter-card">
                    <form method="GET" action="mesas.php" class="row g-3 align-items-end">
                        <div class="col-md-4 col-sm-6">
                            <label class="form-label text-muted small fw-bold mb-1">Fecha Inicio</label>
                            <input type="date" name="fecha_inicio" class="form-control" value="<?php echo htmlspecialchars($fecha_inicio); ?>">
                        </div>
                        <div class="col-md-4 col-sm-6">
                            <label class="form-label text-muted small fw-bold mb-1">Fecha Fin</label>
                            <input type="date" name="fecha_fin" class="form-control" value="<?php echo htmlspecialchars($fecha_fin); ?>">
                        </div>
                        <div class="col-md-4 col-sm-12">
                            <button type="submit" class="btn btn-dark w-100 fw-bold" style="background-color: var(--text-main);">
                                <i class="fas fa-filter me-1"></i> Filtrar Resultados
                            </button>
                        </div>
                    </form>
                </div>

                <div class="modern-card">
                    <div class="modern-card-header d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-list-ul me-2 text-muted"></i> Listado General de Mesas</span>
                        <span class="badge bg-light text-dark border"><i class="far fa-calendar-alt"></i> Mostrando datos del <?php echo date('d/m/Y', strtotime($fecha_inicio)); ?> al <?php echo date('d/m/Y', strtotime($fecha_fin)); ?></span>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-modern align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Identificador</th>
                                    <th>Estado Actual</th>
                                    <th>Visibilidad</th>
                                    <th>Pedidos</th>
                                    <th>Ingresos</th>
                                    <th class="text-end">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($mesas)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-5 text-muted">
                                            <i class="fas fa-chair fa-3x mb-3 opacity-25"></i>
                                            <p class="mb-0">No hay mesas configuradas aún.</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>

                                <?php foreach ($mesas as $m): ?>
                                <tr class="<?php echo $m['activo'] == 0 ? 'row-inactive' : ''; ?>">
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="icon-box me-3 <?php echo $m['activo'] == 1 ? 'active' : ''; ?>">
                                                <i class="fas fa-chair"></i>
                                            </div>
                                            <div>
                                                <span class="d-block fw-bold fs-6 text-dark">Mesa <?php echo $m['numero']; ?></span>
                                                <small class="text-muted">ID: #<?php echo str_pad($m['id'], 4, '0', STR_PAD_LEFT); ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    
                                    <td>
                                        <?php if($m['estado'] == 'disponible'): ?>
                                            <span class="modern-badge badge-success"><i class="fas fa-circle" style="font-size: 8px;"></i> Disponible</span>
                                        <?php else: ?>
                                            <span class="modern-badge badge-danger"><i class="fas fa-utensils"></i> Ocupada</span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <td>
                                        <?php if($m['activo'] == 1): ?>
                                            <span class="modern-badge badge-primary"><i class="fas fa-eye"></i> Activa</span>
                                        <?php else: ?>
                                            <span class="modern-badge badge-secondary"><i class="fas fa-eye-slash"></i> Oculta</span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <td>
                                        <span class="fw-semibold text-dark"><?php echo $m['total_pedidos']; ?></span>
                                    </td>
                                    
                                    <td>
                                        <span class="fw-bold text-success">$<?php echo number_format($m['total_ingresos'], 2); ?></span>
                                    </td>
                                    
                                    <td class="text-end">
                                        <?php if ($m['estado'] == 'disponible'): ?>
                                            <div class="d-inline-flex gap-1">
                                                <a href="mesas.php?toggle=<?php echo $m['id']; ?>" 
                                                   class="action-btn btn-toggle" 
                                                   data-bs-toggle="tooltip" 
                                                   title="<?php echo $m['activo'] == 1 ? 'Ocultar a meseros' : 'Mostrar a meseros'; ?>">
                                                    <i class="fas <?php echo $m['activo'] == 1 ? 'fa-eye-slash' : 'fa-eye'; ?>"></i>
                                                </a>
                                                
                                                <button type="button" 
                                                        class="action-btn btn-delete" 
                                                        onclick="confirmarEliminar(<?php echo $m['id']; ?>, <?php echo $m['numero']; ?>)" 
                                                        data-bs-toggle="tooltip" 
                                                        title="Quitar de la lista">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted small"><i class="fas fa-lock me-1"></i> Bloqueada</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]')
        const tooltipList = [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl))

        const swalModern = Swal.mixin({
            customClass: {
                confirmButton: 'btn btn-primary px-4 py-2 mx-2 rounded-3 text-white border-0',
                cancelButton: 'btn btn-light px-4 py-2 mx-2 border rounded-3',
                popup: 'rounded-4'
            },
            buttonsStyling: false
        });

        // Aplicamos el color rojo de Kpizza's a los botones de SweetAlert
        document.documentElement.style.setProperty('--bs-primary', '#da251d');

        const urlParams = new URLSearchParams(window.location.search);
        
        if (urlParams.has('agregado')) {
            swalModern.fire({
                icon: 'success',
                title: 'Mesa Creada',
                text: 'La Mesa ' + urlParams.get('agregado') + ' está lista para usarse.',
                timer: 2500,
                showConfirmButton: false
            });
        }
        if (urlParams.has('eliminado')) {
            swalModern.fire({
                icon: 'success',
                title: 'Mesa Retirada',
                text: 'La mesa se ocultó del sistema correctamente.',
                timer: 2500,
                showConfirmButton: false
            });
        }
        if (urlParams.has('toggled')) {
            let accion = urlParams.get('toggled') == '0' ? 'ocultado' : 'activado';
            swalModern.fire({
                icon: 'success',
                title: 'Estado Actualizado',
                text: 'El estado de la mesa ha sido ' + accion + '.',
                timer: 2000,
                showConfirmButton: false
            });
        }
        if (urlParams.has('error_estado')) {
            swalModern.fire({
                icon: 'warning',
                title: 'Mesa en Uso',
                text: 'No puedes modificar o retirar una mesa mientras esté ocupada.',
            });
        }

        // Limpiar URL de parámetros de acción, pero dejar las fechas si existen
        if (window.history.replaceState) {
            let paramsToKeep = new URLSearchParams();
            if(urlParams.has('fecha_inicio')) paramsToKeep.set('fecha_inicio', urlParams.get('fecha_inicio'));
            if(urlParams.has('fecha_fin')) paramsToKeep.set('fecha_fin', urlParams.get('fecha_fin'));
            
            const queryString = paramsToKeep.toString() ? '?' + paramsToKeep.toString() : '';
            const urlLimpia = window.location.protocol + "//" + window.location.host + window.location.pathname + queryString;
            window.history.replaceState({path: urlLimpia}, '', urlLimpia);
        }

        function confirmarEliminar(id, numero) {
            swalModern.fire({
                title: '¿Retirar Mesa ' + numero + '?',
                text: "Se quitará de la lista pero mantendrá el registro de su número para futuras creaciones.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Sí, retirar',
                cancelButtonText: 'Cancelar',
                reverseButtons: true,
                customClass: {
                    confirmButton: 'btn px-4 py-2 mx-2 rounded-3 text-white',
                    cancelButton: 'btn btn-light px-4 py-2 mx-2 border rounded-3',
                    popup: 'rounded-4'
                },
                didOpen: () => {
                    // Forzamos el color rojo en el botón de confirmar
                    document.querySelector('.swal2-confirm').style.backgroundColor = '#da251d';
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = 'mesas.php?eliminar=' + id;
                }
            })
        }
    </script>
</body>
</html>