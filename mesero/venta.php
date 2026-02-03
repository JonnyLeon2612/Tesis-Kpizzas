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
// Verifica si la solicitud es POST y si el contenido es JSON.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
    header('Content-Type: application/json');
    $data = json_decode(file_get_contents('php://input'), true);

    $edit_id = isset($data['edit_id']) ? (int)$data['edit_id'] : null; // Detectar si es edición
    $mesa_id = isset($data['mesa_id']) ? (int)$data['mesa_id'] : 0;
    $tipo_servicio = isset($data['tipo_servicio']) ? $data['tipo_servicio'] : 'Mesa';
    $pedido_detalle = isset($data['pedido']) ? $data['pedido'] : [];

    if (($tipo_servicio === 'Mesa' && empty($mesa_id) && !$edit_id) || empty($pedido_detalle)) {
        echo json_encode(['success' => false, 'error' => 'Complete todos los campos requeridos.']);
        exit;
    }

    try {
        $pdo->beginTransaction();
        
        // 1. Calcular el total del pedido
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

        if ($edit_id) {
            // --- LÓGICA DE ACTUALIZACIÓN ---
            // A. Actualizar cabecera de la comanda y quitar el bloqueo de edición
            $sql_update = "UPDATE comanda SET total = ?, editando = 0, mesa_id = ?, tipo_servicio = ? WHERE id = ?";
            $stmt_update = $pdo->prepare($sql_update);
            $stmt_update->execute([
                $total_pedido, 
                $tipo_servicio === 'Mesa' ? $mesa_id : NULL, 
                $tipo_servicio, 
                $edit_id
            ]);
            $comanda_id = $edit_id;

            // B. Limpiar detalles anteriores para insertar los nuevos (evita duplicados/conflictos)
            $stmt_delete = $pdo->prepare("DELETE FROM detalle_comanda WHERE comanda_id = ?");
            $stmt_delete->execute([$comanda_id]);

        } else {
            // --- LÓGICA DE INSERCIÓN NUEVA ---
            $sql_insert = "INSERT INTO comanda (usuario_id, mesa_id, estado, tipo_servicio, total, editando) VALUES (?, ?, ?, ?, ?, 0)";
            $stmt_insert = $pdo->prepare($sql_insert);
            $stmt_insert->execute([
                $_SESSION['user']['id'],
                $tipo_servicio === 'Mesa' ? $mesa_id : NULL,
                'en_preparacion',
                $tipo_servicio,
                $total_pedido
            ]);
            $comanda_id = $pdo->lastInsertId();
        }

        // 2. Insertar los detalles de la comanda (común para ambos casos)
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

        // 3. Gestionar estado de la mesa (solo si es nuevo y en mesa)
        if (!$edit_id && $tipo_servicio === 'Mesa' && $mesa_id > 0) {
            $stmt_mesa = $pdo->prepare("UPDATE mesa SET estado = 'ocupada' WHERE id = ?");
            $stmt_mesa->execute([$mesa_id]);
        }

        $pdo->commit();

        echo json_encode([
            'success' => true, 
            'message' => $edit_id ? 'Pedido actualizado exitosamente' : 'Pedido enviado a cocina',
            'comanda_id' => $comanda_id
        ]);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}
// --- ROL 2: LÓGICA DE CARGA DE PÁGINA (GET) ---
// Si no fue un POST JSON, el script continúa para cargar el HTML.

// Obtener todos los productos (pizzas, ingredientes, bebidas).
$sql_productos = "SELECT p.id, p.nombre, c.nombre as tipo, p.precio_pequena, p.precio_mediana, p.precio_familiar 
                  FROM producto p
                  JOIN categoria_producto c ON p.categoria_id = c.id
                  WHERE p.estado = 'activo'
                  ORDER BY c.nombre, p.nombre";
$stmt_productos = $pdo->query($sql_productos);
$productos = $stmt_productos->fetchAll();

// Clasificar los productos en arreglos separados para el formulario.
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

// Obtener la lista de mesas y su estado.
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
    <div id="history-list" class="sidebar-body p-3 overflow-auto" style="max-height: 80vh;">
        </div>
</div>

<div id="notif-sidebar" class="sidebar-custom sidebar-right shadow">
    <div class="sidebar-header bg-dark text-white p-3 d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-bell me-2"></i>Notificaciones</h5>
        <button class="btn-close btn-close-white" onclick="toggleSidebar('notif-sidebar')"></button>
    </div>
    <div id="notif-list" class="sidebar-body p-3 overflow-auto" style="max-height: 70vh;">
        </div>
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
                // Asignar clase CSS según el estado de la mesa.
                $estado_clase = 'disponible';
                if ($mesa['estado'] === 'ocupada') $estado_clase = 'ocupada';
                elseif ($mesa['estado'] === 'reservada') $estado_clase = 'reservada';
                ?>
                <div class="mesa mesa-<?php echo $estado_clase; ?>"
                  data-mesa-id="<?php echo $mesa['id']; ?>"
                  data-mesa-numero="<?php echo $mesa['numero']; ?>"
                  <?php echo $mesa['estado'] !== 'disponible' ? 'disabled' : ''; /* Deshabilitar si no está disponible */ ?>
                  style="cursor: pointer;">
                  Mesa <?php echo $mesa['numero']; ?>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div id="order-form-section" style="display: none;">
            <h1 class="h4 mb-4 text-center text-uppercase fw-bold">
               <span id="current-servicio-display"></span> </h1>
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
                    
                    <!-- NUEVO: Sección de totales en ambas monedas -->
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
      
      // NUEVO: Pasar la tasa de cambio a JavaScript
      window.tasaDolar = <?php echo $tasa_actual; ?>;
  </script>
  <script src="../js/venta.js"></script>
</body>
</html>