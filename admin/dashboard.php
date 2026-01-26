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
    <link href="../css/admin.css" rel="stylesheet">
</head>
<body>
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

    <div class="container my-4">
        <h1 class="text-center mb-4">Panel de Administración</h1>

        <!-- Fila Existente -->
        <div class="row">
            <div class="col-md-6 mb-4">
                <div class="card h-100">
                    <div class="card-body text-center">
                        <h5 class="card-title">Gestión de Usuarios</h5>
                        <p class="card-text">Administra los usuarios del sistema: meseros, caja, cocina y administradores.</p>
                        <!-- CAMBIO: Ruta actualizada a la subcarpeta -->
                        <a href="administrar/usuarios.php" class="btn btn-kpizzas-red">Gestionar Usuarios</a>
                    </div>
                </div>
            </div>
            <div class="col-md-6 mb-4">
                <div class="card h-100">
                    <div class="card-body text-center">
                        <h5 class="card-title">Gestión de Productos</h5>
                        <p class="card-text">Administra los productos: pizzas, ingredientes, bebidas y adicionales.</p>
                        <!-- CAMBIO: Ruta actualizada a la subcarpeta -->
                        <a href="administrar/productos.php" class="btn btn-kpizzas-red">Gestionar Productos</a>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Nueva Fila -->
        <div class="row">
            <div class="col-md-6 mb-4">
                <div class="card h-100">
                    <div class="card-body text-center">
                       <h5 class="card-title">Análisis de Rentabilidad</h5>
        <p class="card-text">Analiza ventas, costos y la rentabilidad neta del negocio.</p>
            <!-- CAMBIO: Ruta actualizada a reportes -->
            <a href="administrar/reportes.php" class="btn btn-kpizzas-red">Ver Reportes</a>
                    </div>
                </div>
            </div>
            <div class="col-md-6 mb-4">
                <div class="card h-100">
                    <div class="card-body text-center">
                        <h5 class="card-title">Precio del Dólar</h5>
                        <p class="card-text">Actualiza el precio del dólar del día para el cálculo de precios.</p>
                        <!-- CAMBIO: Ruta actualizada a la subcarpeta -->
                        <a href="administrar/dolar.php" class="btn btn-kpizzas-red">Actualizar Precio</a>
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