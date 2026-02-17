<?php
// --- AUTENTICACIÓN Y CONFIGURACIÓN ---
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../config/db.php';
require_role('mesero');

// --- OBTENER TASA DE CAMBIO ACTUAL ---
$stmt_tasa = $pdo->query("SELECT tasa FROM tasa_dolar WHERE fecha <= CURDATE() ORDER BY fecha DESC, fecha_actualizacion DESC LIMIT 1");
$tasa_dolar = $stmt_tasa->fetch(PDO::FETCH_ASSOC);
$tasa_actual = $tasa_dolar ? $tasa_dolar['tasa'] : 1;

// --- ROL 1: API PARA RECIBIR PEDIDOS (JSON POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
    header('Content-Type: application/json');
    $data = json_decode(file_get_contents('php://input'), true);

    // Variables de control
    $edit_id = isset($data['edit_id']) ? (int)$data['edit_id'] : null;
    $adicion_id = isset($data['adicion_id']) ? (int)$data['adicion_id'] : null; // <--- NUEVO: ID para anexos
    
    $mesa_id = isset($data['mesa_id']) ? (int)$data['mesa_id'] : 0;
    $tipo_servicio = isset($data['tipo_servicio']) ? $data['tipo_servicio'] : 'Mesa';
    $pedido_detalle = isset($data['pedido']) ? $data['pedido'] : [];

    // --- DATOS DEL CLIENTE ---
    $cli_cedula = isset($data['cliente_cedula']) ? trim($data['cliente_cedula']) : '';
    $cli_nombre = isset($data['cliente_nombre']) ? trim($data['cliente_nombre']) : '';
    // RECIBIMOS EL ID DEL CLIENTE SI VIENE DEL FRONTEND
    $cliente_id = isset($data['cliente_id']) ? (int)$data['cliente_id'] : null;

    // Validación: Aceptamos si hay mesa, edit_id O adicion_id
    if (($tipo_servicio === 'Mesa' && empty($mesa_id) && !$edit_id && !$adicion_id) || empty($pedido_detalle)) {
        echo json_encode(['success' => false, 'error' => 'Complete todos los campos requeridos.']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // --- Lógica de cliente (solo para llevar y si no viene ID) ---
        if ($tipo_servicio === 'Llevar' && empty($cliente_id) && !empty($cli_nombre)) {
            if (!empty($cli_cedula)) {
                $stmtCheckCli = $pdo->prepare("SELECT id FROM cliente WHERE cedula = ? LIMIT 1");
                $stmtCheckCli->execute([$cli_cedula]);
                $cliente_id = $stmtCheckCli->fetchColumn();
            }

            if ($cliente_id) {
                $stmtUpdCli = $pdo->prepare("UPDATE cliente SET nombre = ? WHERE id = ?");
                $stmtUpdCli->execute([$cli_nombre, $cliente_id]);
            } else {
                $stmtInsCli = $pdo->prepare("INSERT INTO cliente (cedula, nombre) VALUES (?, ?)");
                $cedula_val = !empty($cli_cedula) ? $cli_cedula : null;
                $stmtInsCli->execute([$cedula_val, $cli_nombre]);
                $cliente_id = $pdo->lastInsertId();
            }
        }

        // Calcular total del pedido ACTUAL (lo nuevo que se envía)
        $total_pedido = 0.00;
        foreach ($pedido_detalle as $item) {
            if ($item['tipo'] === 'Pizza') {
                $total_pedido += (float)$item['precio_base'];
                foreach ($item['ingredientes'] as $ingrediente) {
                    $total_pedido += (float)$ingrediente['precio'];
                }
            } elseif (isset($item['tipo']) && $item['tipo'] === 'Bebida') {
                $total_pedido += (float)$item['precio'];
            }
        }

        if ($adicion_id) {
            // ============================================
            // CASO 1: AGREGAR A COMANDA EXISTENTE (ANEXO)
            // ============================================
            
            // 1. Actualizamos el total sumando lo nuevo a lo viejo.
            // 2. REBOTE: Cambiamos estado a 'en_preparacion' para que Cocina lo vea.
            // 3. Quitamos bandera de edición.
            $sql_update = "UPDATE comanda SET total = total + ?, estado = 'en_preparacion', editando = 0 WHERE id = ?";
            $stmt_update = $pdo->prepare($sql_update);
            $stmt_update->execute([$total_pedido, $adicion_id]);
            
            $comanda_id = $adicion_id;
            
            // IMPORTANTE: NO borramos los detalles anteriores.

        } elseif ($edit_id) {
            // ============================================
            // CASO 2: EDICIÓN COMPLETA (CORRECCIÓN)
            // ============================================
            $sql_update = "UPDATE comanda SET total = ?, editando = 0, mesa_id = ?, cliente_id = ?, tipo_servicio = ? WHERE id = ?";
            $stmt_update = $pdo->prepare($sql_update);
            $stmt_update->execute([
                $total_pedido,
                $tipo_servicio === 'Mesa' ? $mesa_id : NULL,
                $cliente_id,
                $tipo_servicio,
                $edit_id
            ]);
            $comanda_id = $edit_id;

            // En edición normal, SÍ borramos lo viejo para reescribirlo
            $stmt_delete = $pdo->prepare("DELETE FROM detalle_comanda WHERE comanda_id = ?");
            $stmt_delete->execute([$comanda_id]);

        } else {
            // ============================================
            // CASO 3: NUEVA COMANDA
            // ============================================
            $sql_insert = "INSERT INTO comanda (usuario_id, mesa_id, cliente_id, estado, tipo_servicio, total, editando) VALUES (?, ?, ?, ?, ?, ?, 0)";
            $stmt_insert = $pdo->prepare($sql_insert);
            $stmt_insert->execute([
                $_SESSION['user']['id'],
                $tipo_servicio === 'Mesa' ? $mesa_id : NULL,
                $cliente_id,
                'en_preparacion',
                $tipo_servicio,
                $total_pedido
            ]);
            $comanda_id = $pdo->lastInsertId();
        }

        // Insertar detalles (Común para todos: inserta lo que viene en el array 'pedido')
        $sql_detalle = "INSERT INTO detalle_comanda (comanda_id, producto_id, cantidad, tamanio, precio_unitario) VALUES (?, ?, ?, ?, ?)";
        $stmt_detalle = $pdo->prepare($sql_detalle);

        foreach ($pedido_detalle as $item) {
            if ($item['tipo'] === 'Pizza') {
                $stmt_detalle->execute([$comanda_id, $item['id_base'], 1, $item['tamanio'], (float)$item['precio_base']]);
                foreach ($item['ingredientes'] as $ingrediente) {
                    $stmt_detalle->execute([$comanda_id, $ingrediente['id'], 1, $item['tamanio'], (float)$ingrediente['precio']]);
                }
            } elseif (isset($item['tipo']) && $item['tipo'] === 'Bebida') {
                $stmt_detalle->execute([$comanda_id, $item['id'], 1, 'N/A', (float)$item['precio']]);
            }
        }

        // Actualizar estado de mesa (solo si es nuevo y mesa)
        if (!$edit_id && !$adicion_id && $tipo_servicio === 'Mesa' && $mesa_id > 0) {
            $stmt_mesa = $pdo->prepare("UPDATE mesa SET estado = 'ocupada' WHERE id = ?");
            $stmt_mesa->execute([$mesa_id]);
        }

        // Mensaje de Notificación para Cocina (Guardado en BD para que 'mesero_notificaciones.php' lo lea si usas esa lógica)
        $msg_tipo = "Nuevo Pedido";
        if ($edit_id) $msg_tipo = "Pedido Modificado";
        if ($adicion_id) $msg_tipo = "⚠️ AGREGADO EXTRA";

        $identificador = ($tipo_servicio === 'Mesa') ? "Mesa #$mesa_id" : "Llevar";
        
        // OPCIONAL: Si tienes tabla de notificaciones, descomenta esto:
        /*
        $stmtNotif = $pdo->prepare("INSERT INTO notificaciones (usuario_id, mensaje, leido) VALUES (?, ?, 0)");
        $stmtNotif->execute([$_SESSION['user']['id'], "$msg_tipo - $identificador"]); 
        */

        $pdo->commit();

        $msg_final = $edit_id ? 'Pedido actualizado exitosamente' : 'Pedido enviado a cocina';
        if ($adicion_id) $msg_final = 'Productos agregados a la comanda';

        echo json_encode([
            'success' => true,
            'message' => $msg_final,
            'comanda_id' => $comanda_id
        ]);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

// --- ROL 2: LÓGICA DE CARGA DE PÁGINA (GET) ---
$sql_productos = "SELECT p.id, p.nombre, c.nombre as tipo, p.precio_pequena, p.precio_mediana, p.precio_familiar 
                  FROM producto p
                  JOIN categoria_producto c ON p.categoria_id = c.id
                  WHERE p.estado = 'activo'
                  ORDER BY c.nombre, p.nombre";
$stmt_productos = $pdo->query($sql_productos);
$productos = $stmt_productos->fetchAll();

$pizza_base = [];
$ingredientes = [];
$bebidas = [];

foreach ($productos as $producto) {
  if ($producto['tipo'] === 'Pizza Base') {
    $pizza_base = $producto;
  } elseif ($producto['tipo'] === 'Ingrediente') {
    $ingredientes[] = $producto;
  } elseif ($producto['tipo'] === 'Bebida') {
    $bebidas[] = $producto;
  }
}

$sql_mesas = "SELECT id, numero, estado FROM mesa ORDER BY numero ASC";
$stmt_mesas = $pdo->query($sql_mesas);
$mesas = $stmt_mesas->fetchAll();
?>

<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kpizza's — Venta Mesero</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../css/venta.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
</head>
<audio id="notificacion-sonido" src="../img/notificacion.mp3" preload="auto"></audio>

<div id="history-btn-container" class="sidebar-trigger" onclick="toggleSidebar('history-sidebar')">
    <i class="fas fa-history"></i>
</div>
<div id="notif-bell-container" class="sidebar-trigger" onclick="toggleSidebar('notif-sidebar')">
    <i class="fas fa-bell"></i>
    <span id="notif-count" class="badge bg-danger rounded-pill shadow" style="display:none;">0</span>
</div>
<div id="history-sidebar" class="sidebar-custom sidebar-left shadow">
    <div class="sidebar-header bg-dark text-white p-3 d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-list me-2"></i>Mis Pedidos Hoy</h5>
        <button class="btn-close btn-close-white" onclick="toggleSidebar('history-sidebar')"></button>
    </div>
    <div id="history-list" class="sidebar-body p-3 overflow-auto" style="max-height: 80vh;"></div>
</div>
<div id="notif-sidebar" class="sidebar-custom sidebar-right shadow">
    <div class="sidebar-header bg-dark text-white p-3 d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-bell me-2"></i>Notificaciones</h5>
        <button class="btn-close btn-close-white" onclick="toggleSidebar('notif-sidebar')"></button>
    </div>
    <div id="notif-list" class="sidebar-body p-3 overflow-auto" style="max-height: 70vh;"></div>
    <div class="p-3 border-top bg-light">
        <button class="btn btn-danger btn-sm w-100" onclick="limpiarNotificaciones()">
            <i class="fas fa-trash me-1"></i> Borrar Todo
        </button>
    </div>
</div>

<body class="bg-light">
  <nav class="navbar navbar-expand-lg navbar-dark bg-kpizzas-red">
    <div class="container">
      <a class="navbar-brand fw-bold" href="#">Kpizza's Mesero</a>
      <div class="d-flex ms-auto">
        <span class="navbar-text text-white me-3">
            <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($_SESSION['user']['nombre']); ?>
        </span>
        <a href="../auth/logout.php" class="btn btn-outline-light border-2">
          <i class="fas fa-sign-out-alt me-1"></i>Salir</a>
      </div>
    </div>
  </nav>

  <div class="container my-5">
    <div class="row justify-content-center">
      <div class="col-lg-10">
        <div class="card shadow p-4">
          <h1 class="h4 mb-4 text-center text-uppercase fw-bold" id="main-title">Tipo de Servicio</h1>

          <div id="servicio-type-section">
            <p class="text-center text-muted mb-4">Selecciona el tipo de servicio para el pedido</p>
            <div class="row justify-content-center">
              <div class="col-md-5 mb-3">
                <div class="card servicio-option" data-servicio-type="Mesa" style="cursor: pointer;">
                  <div class="card-body text-center p-4">
                    <i class="fas fa-utensils fa-3x text-kpizzas-red mb-3"></i>
                    <h5 class="card-title">Consumo en Mesa</h5>
                  </div>
                </div>
              </div>
              <div class="col-md-5 mb-3">
                <div class="card servicio-option" data-servicio-type="Llevar" style="cursor: pointer;">
                  <div class="card-body text-center p-4">
                    <i class="fas fa-shopping-bag fa-3x text-kpizzas-red mb-3"></i>
                    <h5 class="card-title">Para Llevar</h5>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div id="table-layout-section" style="display: none;">
            <p class="text-center text-muted">Haz clic en una mesa para tomar un pedido.</p>
            <button class="btn btn-outline-secondary mb-3" id="back-to-servicio-btn">← Volver</button>
            <div class="mesa-container">
              <?php foreach ($mesas as $mesa): ?>
                <?php
                $estado_clase = 'disponible';
                if ($mesa['estado'] === 'ocupada') $estado_clase = 'ocupada';
                elseif ($mesa['estado'] === 'reservada') $estado_clase = 'reservada';
                ?>
                <div class="mesa mesa-<?php echo $estado_clase; ?>"
                  data-mesa-id="<?php echo $mesa['id']; ?>"
                  data-mesa-numero="<?php echo $mesa['numero']; ?>"
                  <?php echo $mesa['estado'] !== 'disponible' ? 'disabled' : ''; ?>
                  style="cursor: pointer;">
                  Mesa <?php echo $mesa['numero']; ?>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

        <div id="seccion-cliente-llevar" class="cliente-filter-container" style="display:none;">
            <div class="filter-header">
                <h3><i class="fas fa-motorcycle"></i> Pedido Para Llevar</h3>
                <p class="text-muted">Identifica al cliente antes de tomar la orden.</p>
            </div>

            <div class="search-box-wrapper">
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                    <input type="text" id="input-buscar-cliente" class="form-control" 
                           placeholder="Buscar por Nombre, Cédula o Teléfono..." autocomplete="off">
                </div>
                <div id="lista-resultados-clientes" class="autocomplete-results"></div>
            </div>

            <div class="actions-row mt-3 text-end">
                <button type="button" class="btn btn-outline-primary btn-sm" onclick="toggleNuevoClienteForm()">
                    <i class="fas fa-user-plus"></i> Nuevo Cliente
                </button>
            </div>

            <div id="form-nuevo-cliente" class="new-client-card" style="display:none;">
                <h5 class="mb-3">Registrar Nuevo Cliente</h5>
                <div class="row g-2">
                    <div class="col-md-6">
                        <input type="text" id="new-nombre" class="form-control" placeholder="Nombre Completo *">
                    </div>
                    <div class="col-md-6">
                        <input type="text" id="new-cedula" class="form-control" placeholder="Cédula/DNI">
                    </div>
                    <div class="col-md-6">
                        <input type="text" id="new-telefono" class="form-control" placeholder="Teléfono">
                    </div>
                    <div class="col-md-6">
                        <input type="text" id="new-direccion" class="form-control" placeholder="Dirección">
                    </div>
                    <div class="col-12 text-end mt-2">
                        <button type="button" class="btn btn-secondary btn-sm" onclick="toggleNuevoClienteForm()">Cancelar</button>
                        <button type="button" class="btn btn-success btn-sm" onclick="guardarNuevoCliente()">Guardar y Seleccionar</button>
                    </div>
                </div>
            </div>

            <div class="frequent-clients-section mt-4">
                <h6 class="text-uppercase text-muted" style="font-size: 0.8rem;">Clientes Frecuentes</h6>
                <div id="grid-clientes-frecuentes" class="frequent-grid">
                    </div>
            </div>

            <div id="cliente-seleccionado-info" class="selected-client-card" style="display:none;">
                <div class="icon-box">
                    <i class="fas fa-user-check"></i>
                </div>
                <div class="client-data">
                    <h4 id="sel-cliente-nombre" class="m-0">Nombre Cliente</h4>
                    <small id="sel-cliente-detalle" class="text-muted">C.I: --- | Tel: ---</small>
                    <div id="sel-cliente-direccion" style="font-size: 0.85rem; color: #666;"></div>
                    <input type="hidden" id="input_cliente_id_hidden">
                </div>
                <button class="btn btn-sm btn-outline-danger ms-auto" onclick="resetClienteSelection()">
                    <i class="fas fa-times"></i> Cambiar
                </button>
            </div>

            <div class="footer-actions mt-4">
                <button type="button" id="btn-siguiente-menu" class="btn btn-primary w-100 py-2" disabled onclick="irAlMenu()">
                    CONTINUAR AL MENÚ <i class="fas fa-arrow-right ms-2"></i>
                </button>
                <button class="btn btn-outline-secondary w-100 mt-2" onclick="location.reload()">
                    ← Cancelar
                </button>
            </div>
        </div>

          <div id="order-form-section" style="display: none;">
            <h1 class="h4 mb-4 text-center text-uppercase fw-bold">
               <span id="current-servicio-display"></span>
            </h1>
            <button class="btn btn-outline-secondary mb-3" id="back-to-tables-btn">← Volver</button>

            <form id="pizzaForm">
              <input type="hidden" id="mesa_id_input" name="mesa_id">
              <input type="hidden" id="tipo_servicio_input" name="tipo_servicio">

              <div class="row">
                <div class="col-md-6 mb-4">
                  <h2 class="h5 fw-bold text-uppercase">1. Elige el Tamaño de la Pizza</h2>
                  <div class="d-flex justify-content-around mb-3">
                    <input class="form-check-input" type="radio" name="tamanio" id="pequena" value="Pequena" checked>
                    <label class="form-check-label" for="pequena">Pequeña</label>
                    <input class="form-check-input" type="radio" name="tamanio" id="mediana" value="Mediana">
                    <label class="form-check-label" for="mediana">Mediana</label>
                    <input class="form-check-input" type="radio" name="tamanio" id="familiar" value="Familiar">
                    <label class="form-check-label" for="familiar">Familiar</label>
                  </div>
                  <hr>
                  <h2 class="h5 fw-bold text-uppercase">2. Elige los Ingredientes</h2>
                  <div class="row row-cols-1 row-cols-md-2 g-2">
                    <?php foreach ($ingredientes as $ingrediente): ?>
                      <div class="col">
                        <div class="card p-2 h-100">
                          <div class="form-check">
                            <input class="form-check-input ingrediente-checkbox" type="checkbox" name="ingredientes[]"
                              value="<?php echo $ingrediente['id']; ?>"
                              data-precios='<?php echo json_encode([
                                              "Pequena" => (float)$ingrediente['precio_pequena'],
                                              "Mediana" => (float)$ingrediente['precio_mediana'],
                                              "Familiar" => (float)$ingrediente['precio_familiar']
                                            ]); ?>'
                              data-nombre="<?php echo htmlspecialchars($ingrediente['nombre']); ?>"
                              id="ingrediente-<?php echo $ingrediente['id']; ?>">
                            <label class="form-check-label d-flex justify-content-between" for="ingrediente-<?php echo $ingrediente['id']; ?>">
                              <span><?php echo htmlspecialchars($ingrediente['nombre']); ?></span>
                              <span class="text-muted precios-display" data-id="<?php echo $ingrediente['id']; ?>"></span>
                            </label>
                          </div>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                  <hr class="my-4">
                  <h2 class="h5 fw-bold text-uppercase">3. Elige las Bebidas</h2>
                  <div class="row row-cols-1 row-cols-md-2 g-2">
                    <?php foreach ($bebidas as $bebida): ?>
                      <div class="col">
                        <div class="card p-2 h-100">
                          <div class="form-check">
                            <input class="form-check-input bebida-checkbox" type="checkbox" name="bebidas[]"
                              value="<?php echo $bebida['id']; ?>"
                              data-precios='<?php echo json_encode(["Pequena" => (float)$bebida['precio_pequena']]); ?>'
                              data-nombre="<?php echo htmlspecialchars($bebida['nombre']); ?>"
                              id="bebida-<?php echo $bebida['id']; ?>">
                            <label class="form-check-label d-flex justify-content-between" for="bebida-<?php echo $bebida['id']; ?>">
                              <span><?php echo htmlspecialchars($bebida['nombre']); ?></span>
                              <span class="text-muted precios-display-bebida" data-id="<?php echo $bebida['id']; ?>"></span>
                            </label>
                          </div>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>

                <div class="col-md-6">
                  <h2 class="h5 fw-bold text-uppercase">4. Resumen de Pago</h2>
                  <div class="card p-4 bg-light">
                    <h6 class="fw-bold mb-3">Artículos Actuales</h6>
                    <ul class="list-group list-group-flush mb-3" id="factura-list-current"></ul>
                    <button type="button" class="btn btn-sm btn-outline-secondary w-100" id="agregar-btn">
                      Agregar a la Orden
                    </button>
                    <hr class="my-3">
                    <h6 class="fw-bold mb-3">Orden Completa</h6>
                    <ul class="list-group list-group-flush" id="factura-list-full"></ul>
                    <hr>
                    <div class="totales-container">
                      <div class="d-flex justify-content-between align-items-center fw-bold mt-2">
                        <span class="h5 mb-0">TOTAL:</span>
                        <span class="h4 mb-0 text-kpizzas-red">$<span id="total-display">0.00</span></span>
                      </div>
                      <div class="d-flex justify-content-between align-items-center mt-1">
                        <span class="text-muted small">Equivalente en Bs:</span>
                        <span class="h5 mb-0 text-success">Bs. <span id="total-bs-display">0.00</span></span>
                      </div>
                      <div class="text-end">
                        <small class="text-muted">
                          <i class="fas fa-exchange-alt me-1"></i>
                          Tasa: Bs. <?php echo number_format($tasa_actual, 2); ?> por $1
                        </small>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="mt-4">
                <button type="submit" class="btn btn-lg btn-kpizzas-red w-100 fw-bold">
                  <i class="fas fa-paper-plane me-2"></i>Enviar Pedido a Cocina
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>

  <footer class="bg-dark text-white text-center py-3 mt-auto">
        <i class="fas fa-pizza-slice me-1"></i>Kpizza's © <?php echo date('Y'); ?> - Sistema de Gestión
  </footer>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
  <script>
      window.preciosBase = <?php echo json_encode([
          "nombre" => $pizza_base['nombre'] ?? 'Pizza',
          "id" => $pizza_base['id'] ?? 0,
          "Pequena" => (float)($pizza_base['precio_pequena'] ?? 0),
          "Mediana" => (float)($pizza_base['precio_mediana'] ?? 0),
          "Familiar" => (float)($pizza_base['precio_familiar'] ?? 0)
      ]); ?>;
      window.tasaDolar = <?php echo $tasa_actual; ?>;
  </script>
  <script src="../js/venta.js"></script>
</body>
</html>