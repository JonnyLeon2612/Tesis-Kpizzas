<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../config/db.php';
require_role('cocina');

// Mantenemos el recibidor POST por si el JS necesita cambiar estados
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['comanda_id'])) {
    $comanda_id = (int)$_POST['comanda_id'];
    $estado = $_POST['estado'];
    if (in_array($estado, ['en_preparacion', 'listo'])) {
        $stmt = $pdo->prepare("UPDATE comanda SET estado = ?, es_anexo = 0 WHERE id = ?");
        $stmt->execute([$estado, $comanda_id]);
    }
    exit('ok'); // Solo respondemos 'ok' al JS, sin recargar
}
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
</head>
<body class="bg-light d-flex flex-column min-vh-100">
    <nav class="navbar navbar-expand-lg navbar-dark bg-kpizzas-red">
        <div class="container">
            <a class="navbar-brand fw-bold" href="#">Kpizza's Cocina</a>
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
        <h1 class="text-center mb-4">Tablero de Pedidos</h1>

        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card text-center bg-warning bg-opacity-10">
                    <div class="card-body">
                        <h3 class="text-warning" id="stat-preparacion">0</h3>
                        <p class="mb-0">En Preparación</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card text-center bg-success bg-opacity-10">
                    <div class="card-body">
                        <h3 class="text-success" id="stat-listos">0</h3>
                        <p class="mb-0">Listos</p>
                    </div>
                </div>
            </div>
        </div>

        <div id="pedidos-wrapper" class="row">
            <div class="col-12 text-center text-muted my-5">
                <div class="spinner-border text-danger" role="status"></div>
                <p class="mt-2">Cargando pedidos en vivo...</p>
            </div>
        </div>

        <nav class="mt-4 d-flex justify-content-center" id="paginacion-wrapper"></nav>
    </div>
      <footer class="bg-dark text-white text-center py-3 mt-auto">
        <i class="fas fa-pizza-slice me-1"></i>Kpizza's © <?php echo date('Y'); ?> - Sistema de Gestión
  </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="../js/cocina.js"></script>
</body>
</html>