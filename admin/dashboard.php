<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../config/db.php';
require_role('admin'); 
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kpizza's - Administración</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../css/admin.css" rel="stylesheet">
</head>
<body class="bg-light d-flex flex-column min-vh-100">
    
    <nav class="navbar navbar-expand-lg navbar-dark bg-kpizzas-red">
        <div class="container">
            <a class="navbar-brand fw-bold" href="#">Kpizza's Administración</a>
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
        <h1 class="text-center mb-5 fw-bold text-dark">Panel de Administración</h1>

        <div class="row g-4">
            <div class="col-md-6 col-lg-3">
                <div class="card h-100 text-center p-3">
                    <div class="card-body d-flex flex-column">
                        <div class="card-icon-wrapper">
                            <i class="fas fa-users"></i>
                        </div>
                        <h5 class="card-title">Gestión de Usuarios</h5>
                        <p class="card-text flex-grow-1 text-muted small">Administra los usuarios del sistema: meseros, caja, cocina y administradores.</p>
                        <a href="administrar/usuarios.php" class="btn btn-kpizzas-red w-100 mt-auto">Gestionar Usuarios</a>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-lg-3">
                <div class="card h-100 text-center p-3">
                    <div class="card-body d-flex flex-column">
                        <div class="card-icon-wrapper">
                            <i class="fas fa-box-open"></i>
                        </div>
                        <h5 class="card-title">Gestión de Productos</h5>
                        <p class="card-text flex-grow-1 text-muted small">Administra los productos: pizzas, ingredientes, bebidas y adicionales.</p>
                        <a href="administrar/productos.php" class="btn btn-kpizzas-red w-100 mt-auto">Gestionar Productos</a>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-lg-3">
                <div class="card h-100 text-center p-3">
                    <div class="card-body d-flex flex-column">
                        <div class="card-icon-wrapper">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <h5 class="card-title">Análisis de Rentabilidad</h5>
                        <p class="card-text flex-grow-1 text-muted small">Analiza ventas, costos y la rentabilidad neta del negocio.</p>
                        <a href="administrar/reportes.php" class="btn btn-kpizzas-red w-100 mt-auto">Ver Reportes</a>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-lg-3">
                <div class="card h-100 text-center p-3">
                    <div class="card-body d-flex flex-column">
                        <div class="card-icon-wrapper">
                            <i class="fas fa-exchange-alt"></i>
                        </div>
                        <h5 class="card-title">Precio del Dólar</h5>
                        <p class="card-text flex-grow-1 text-muted small">Actualiza el precio del dólar del día para el cálculo de precios.</p>
                        <a href="administrar/dolar.php" class="btn btn-kpizzas-red w-100 mt-auto">Actualizar Precio</a>
                    </div>
                </div>
            </div>
        </div>  
    </div>

    <footer class="bg-dark text-white text-center py-3 mt-auto">
        <i class="fas fa-pizza-slice me-1"></i>Kpizza's © <?php echo date('Y'); ?> - Sistema de Gestión
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>