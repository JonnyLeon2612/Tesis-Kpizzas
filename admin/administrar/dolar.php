<?php
require_once __DIR__ . '/../../auth/middleware.php';
require_once __DIR__ . '/../../config/db.php';
require_role('admin');

// Crear tabla de tasa_dolar si no existe
$pdo->exec("
    CREATE TABLE IF NOT EXISTS tasa_dolar (
        id INT PRIMARY KEY AUTO_INCREMENT,
        tasa DECIMAL(10,2) NOT NULL,
        fecha DATE NOT NULL UNIQUE,
        fecha_actualizacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");

class DolarController {
    private $pdo;
    private $mensaje;
    private $error;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function actualizarTasa($datos) {
        try {
            $tasa = $datos['tasa'];
            $fecha = $datos['fecha'];
            
            if (empty($tasa) || empty($fecha)) {
                $this->error = "La tasa y fecha son obligatorias";
                return false;
            }
            
            if ($tasa <= 0) {
                $this->error = "La tasa debe ser mayor a 0";
                return false;
            }
            
            // Verificar si ya existe una tasa para esta fecha
            $stmt = $this->pdo->prepare("SELECT id FROM tasa_dolar WHERE fecha = ?");
            $stmt->execute([$fecha]);
            $existente = $stmt->fetch();
            
            if ($existente) {
                // Actualizar tasa existente
                $stmt = $this->pdo->prepare("UPDATE tasa_dolar SET tasa = ?, fecha_actualizacion = CURRENT_TIMESTAMP WHERE fecha = ?");
                $stmt->execute([$tasa, $fecha]);
                $this->mensaje = "Tasa del dólar actualizada exitosamente";
            } else {
                // Insertar nueva tasa
                $stmt = $this->pdo->prepare("INSERT INTO tasa_dolar (tasa, fecha) VALUES (?, ?)");
                $stmt->execute([$tasa, $fecha]);
                $this->mensaje = "Tasa del dólar registrada exitosamente";
            }
            
            return true;
        } catch (Exception $e) {
            $this->error = "Error al actualizar la tasa: " . $e->getMessage();
            return false;
        }
    }

    public function obtenerTasaActual() {
        // --- ¡CAMBIO LÓGICO AQUÍ! ---
        // Ahora busca la tasa más reciente que sea de HOY o ANTERIOR a hoy.
        // Así, si no hay tasa hoy, usa la de ayer (o la última registrada).
        $stmt = $this->pdo->query("
            SELECT * FROM tasa_dolar 
            WHERE fecha <= CURDATE() 
            ORDER BY fecha DESC, fecha_actualizacion DESC 
            LIMIT 1
        ");
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function obtenerHistorialTasas() {
        $stmt = $this->pdo->query("SELECT * FROM tasa_dolar ORDER BY fecha DESC LIMIT 30");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getMensaje() {
        return $this->mensaje;
    }

    public function getError() {
        return $this->error;
    }
}

$controller = new DolarController($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_tasa'])) {
    $controller->actualizarTasa($_POST);
}

$tasa_actual = $controller->obtenerTasaActual();
$historial = $controller->obtenerHistorialTasas();
$mensaje = $controller->getMensaje();
$error = $controller->getError();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tipo de Cambio Dólar - Kpizza's</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link href="../../css/admin.css" rel="stylesheet">
    <link href="../css/dolar.css" rel="stylesheet">
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <?php include '../partials/sidebar.php'; ?>

            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="fas fa-exchange-alt me-2"></i>Tipo de Cambio Dólar
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <a href="../dashboard.php" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-arrow-left me-1"></i> Volver al Panel
                        </a>
                    </div>
                </div>

                <!-- Tasa Actual -->
                <div class="row mb-4">
                    <div class="col-md-8 mx-auto">
                        <div class="tasa-actual-card">
                            <div class="tasa-header">
                                <i class="fas fa-dollar-sign"></i>
                                <h3>Tasa Actual del Dólar</h3>
                            </div>
                            <div class="tasa-body">
                                <?php if ($tasa_actual): ?>
                                    <div class="tasa-valor">
                                        Bs. <?php echo number_format($tasa_actual['tasa'], 2); ?>
                                    </div>
                                    <div class="tasa-info">
                                        <p><strong>Fecha:</strong> <?php echo date('d/m/Y', strtotime($tasa_actual['fecha'])); ?></p>
                                        <p><strong>Actualizado:</strong> <?php echo date('H:i', strtotime($tasa_actual['fecha_actualizacion'])); ?></p>
                                    </div>
                                <?php else: ?>
                                    <div class="tasa-valor text-warning">
                                        No registrada
                                    </div>
                                    <div class="tasa-info">
                                        <!-- CAMBIO: Mensaje más preciso -->
                                        <p>No se ha establecido ninguna tasa (o la tasa es futura).</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- CAMBIO: Se eliminaron los 'div' de alertas de Bootstrap -->
                <!-- Los mensajes ahora se manejan con SweetAlert al final del body -->

                <!-- Formulario para actualizar tasa -->
                <div class="card mb-4 shadow-sm">
                    <div class="card-header bg-red-kpizza text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-sync-alt me-2"></i>
                            <?php echo $tasa_actual ? 'Actualizar Tasa del Dólar' : 'Establecer Tasa del Dólar'; ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="" id="form-dolar" class="needs-validation" novalidate>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="tasa" class="form-label">Tasa (Bs. por $1) <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control" id="tasa" name="tasa" 
                                           value="<?php echo $tasa_actual ? $tasa_actual['tasa'] : ''; ?>" 
                                           step="0.01" min="0.01" placeholder="Ej: 36.50" required>
                                    <div class="invalid-feedback">
                                        Por favor ingresa una tasa válida.
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="fecha" class="form-label">Fecha <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" id="fecha" name="fecha" 
                                           value="<?php echo date('Y-m-d'); ?>" 
                                           required>
                                </div>
                                <div class="col-md-4 mb-3 d-flex align-items-end">
                                    <button type="submit" name="actualizar_tasa" class="btn btn-primary w-100">
                                        <i class="fas fa-save me-1"></i> 
                                        <?php echo $tasa_actual ? 'Actualizar Tasa' : 'Guardar Tasa'; ?>
                                    </button>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-12">
                                    <div class="form-text">
                                        <i class="fas fa-info-circle me-1"></i>
                                        La tasa se utilizará para cálculos de costos y precios en el sistema.
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card shadow-sm">
                    <div class="card-header bg-red-kpizza text-white">
                        <h5 class="mb-0"><i class="fas fa-history me-2"></i> Historial de Tasas (Últimos 30 días)</h5>
                    </div>
                    <div class="card-body">
                        <?php if (count($historial) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Fecha</th>
                                            <th>Tasa (Bs/$)</th>
                                            <th>Actualizado</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($historial as $tasa): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo date('d/m/Y', strtotime($tasa['fecha'])); ?></strong>
                                                    <?php if ($tasa['fecha'] == date('Y-m-d')): ?>
                                                        <span class="badge bg-success ms-1">Hoy</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="h5 text-primary">Bs. <?php echo number_format($tasa['tasa'], 2); ?></span>
                                                </td>
                                                <td>
                                                    <small class="text-muted">
                                                        <?php echo date('H:i', strtotime($tasa['fecha_actualizacion'])); ?>
                                                    </small>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info text-center">
                                <i class="fas fa-info-circle me-2"></i> No hay historial de tasas registrado.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="js/dolar.js"></script>

    <script>
    document.addEventListener('DOMContentLoaded', () => {
        <?php if ($mensaje): ?>
            Swal.fire({
                title: '¡Éxito!',
                text: '<?php echo $mensaje; ?>',
                icon: 'success',
                confirmButtonColor: '#28a745',
                confirmButtonText: 'Entendido'
            });
        <?php endif; ?>

        <?php if ($error): ?>
            Swal.fire({
                title: 'Error',
                text: '<?php echo $error; ?>',
                icon: 'error',
                confirmButtonColor: '#d32f2f',
                confirmButtonText: 'Entendido'
            });
        <?php endif; ?>
    });
    </script>
</body>
</html>