<?php
// Detectamos en qué carpeta estamos para ajustar las rutas
$current_page = basename($_SERVER['PHP_SELF']);
$in_admin_folder = (dirname($_SERVER['PHP_SELF']) == '/admin' || str_ends_with(dirname($_SERVER['PHP_SELF']), 'admin'));

// Si estamos en dashboard.php, los otros archivos están en administrar/
// Si estamos en usuarios.php, el dashboard está en ../
$prefix = ($current_page == 'dashboard.php') ? 'administrar/' : '';
$dash_prefix = ($current_page == 'dashboard.php') ? '' : '../';
?>

<nav class="col-md-3 col-lg-2 d-md-block sidebar">
    <div class="position-sticky pt-2">
        
        <div class="px-3 pb-3 mb-3 border-bottom text-center">
            <i class="fas fa-cogs fa-2x text-muted mb-2"></i>
            <h6 class="text-uppercase fw-bold text-muted mb-0" style="font-size: 0.8rem; letter-spacing: 1px;">Menú Principal</h6>
        </div>

        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>" href="<?php echo $dash_prefix; ?>dashboard.php">
                    <i class="fas fa-tachometer-alt"></i> <span>Dashboard</span>
                </a>
            </li>
            
            <li class="nav-item mt-2">
                <a class="nav-link <?php echo $current_page == 'usuarios.php' ? 'active' : ''; ?>" href="<?php echo $prefix; ?>usuarios.php">
                    <i class="fas fa-users"></i> <span>Usuarios</span>
                </a>
            </li>
            
            <li class="nav-item">
                <a class="nav-link <?php echo $current_page == 'productos.php' ? 'active' : ''; ?>" href="<?php echo $prefix; ?>productos.php">
                    <i class="fas fa-box-open"></i> <span>Productos</span>
                </a>
            </li>
            
            <li class="nav-item">
                <a class="nav-link <?php echo $current_page == 'reportes.php' ? 'active' : ''; ?>" href="<?php echo $prefix; ?>reportes.php">
                    <i class="fas fa-chart-pie"></i> <span>Reportes</span>
                </a>
            </li>
            
            <li class="nav-item mt-3 pt-3 border-top">
                <a class="nav-link <?php echo $current_page == 'dolar.php' ? 'active' : ''; ?>" href="<?php echo $prefix; ?>dolar.php">
                    <i class="fas fa-exchange-alt"></i> <span>Tasa del Día</span>
                </a>
            </li>
        </ul>
    </div>
</nav>