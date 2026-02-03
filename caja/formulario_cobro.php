<?php
// --- AUTENTICACIÓN Y CONFIGURACIÓN ---
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../config/db.php'; 
require_once __DIR__ . '/../fpdf186/fpdf.php'; 
require_once __DIR__ . '/../config/tasa_helper.php';

session_start();
if (!isset($_SESSION['user']) || ($_SESSION['user']['rol'] !== 'caja' && $_SESSION['user']['rol'] !== 'admin')) {
    die('Acceso denegado: Se requieren permisos de Caja o Administrador.');
}

// --- VALIDACIÓN DE ENTRADA ---
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die('ID de comanda no válido.');
}
$comanda_id = (int)$_GET['id'];

// --- OBTENER TASA DE CAMBIO ---
$tasa_dolar = obtenerTasaDolarActual($pdo);

// --- OBTENER DATOS DE LA COMANDA ---
$stmt_comanda = $pdo->prepare("
    SELECT c.id, c.total, c.fecha_creacion, u.nombre as mesero_nombre, c.tipo_servicio, m.numero as mesa_numero
    FROM comanda c JOIN usuario u ON c.usuario_id = u.id LEFT JOIN mesa m ON c.mesa_id = m.id
    WHERE c.id = ?
");
$stmt_comanda->execute([$comanda_id]);
$comanda = $stmt_comanda->fetch(PDO::FETCH_ASSOC); 

if (!$comanda) { die('Comanda no encontrada.'); }

$total_usd = (float)$comanda['total'];
$total_bs = convertirDolaresABolivares($total_usd, $tasa_dolar);

// --- PROCESAR EL PAGO (POST) ---
// --- PROCESAR EL PAGO (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tipo_pago_nombre = $_POST['tipo_pago'];
    $monto_recibido_usd = !empty($_POST['monto_recibido_usd']) ? (float)$_POST['monto_recibido_usd'] : 0;
    $monto_recibido_bs = !empty($_POST['monto_recibido_bs']) ? (float)$_POST['monto_recibido_bs'] : 0;
    $referencia = !empty($_POST['referencia']) ? trim($_POST['referencia']) : null;
    $banco_origen = !empty($_POST['banco_origen']) ? $_POST['banco_origen'] : null;

    try {
        $total_recibido_convertido_a_usd = $monto_recibido_usd + ($monto_recibido_bs / $tasa_dolar);

        if ($total_recibido_convertido_a_usd < ($total_usd - 0.01)) {
            throw new Exception("El pago recibido es insuficiente.");
        }

        $pdo->beginTransaction();

        // 1. Marcar la comanda como cobrada
        $stmt_comanda = $pdo->prepare("UPDATE comanda SET estado = 'cobrado' WHERE id = ?");
        $stmt_comanda->execute([$comanda_id]);

        // 2. LIBERAR LA MESA (Esto la pone en verde)
        // Buscamos si la comanda tiene una mesa asignada
        $stmt_info = $pdo->prepare("SELECT mesa_id, tipo_servicio FROM comanda WHERE id = ?");
        $stmt_info->execute([$comanda_id]);
        $info_comanda = $stmt_info->fetch(PDO::FETCH_ASSOC);

        if ($info_comanda['tipo_servicio'] === 'Mesa' && !empty($info_comanda['mesa_id'])) {
            $stmt_mesa = $pdo->prepare("UPDATE mesa SET estado = 'disponible' WHERE id = ?");
            $stmt_mesa->execute([$info_comanda['mesa_id']]);
        }

        // 3. Obtener el ID del tipo de pago
        $stmt_tp = $pdo->prepare("SELECT id FROM tipo_pago WHERE nombre = ? LIMIT 1");
        $stmt_tp->execute([$tipo_pago_nombre]);
        $tipo_pago_id = $stmt_tp->fetchColumn();

        // 4. Insertar el registro en la tabla pago
        $sql_pago = "INSERT INTO pago (comanda_id, tipo_pago_id, monto_total, moneda_pago, fecha_pago, referencia, banco_origen, tasa_cambio) 
                     VALUES (?, ?, ?, ?, NOW(), ?, ?, ?)";
        $stmt_pago = $pdo->prepare($sql_pago);

        if ($monto_recibido_usd > 0) {
            $stmt_pago->execute([$comanda_id, $tipo_pago_id, $monto_recibido_usd, 'USD', null, null, $tasa_dolar]);
        }
        if ($monto_recibido_bs > 0) {
            $stmt_pago->execute([$comanda_id, $tipo_pago_id, $monto_recibido_bs, 'BS', $referencia, $banco_origen, $tasa_dolar]);
        }

        $pdo->commit();
        header("Location: caja.php?cobro_exito=true");
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $error_msg = $e->getMessage();
        header("Location: caja.php?cobro_exito=false&error_msg=" . urlencode($error_msg));
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cobrar Pedido #<?php echo $comanda_id; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link href="../css/caja.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-kpizzas-red">
        <div class="container">
            <a class="navbar-brand fw-bold" href="caja.php"><i class="fas fa-arrow-left me-2"></i>Volver a Caja</a>
        </div>
    </nav>

    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-lg-7">
                <div class="card shadow border-0">
                    <div class="card-header bg-success text-white">
                        <h4 class="mb-0"><i class="fas fa-cash-register me-2"></i>Cobrar Pedido #<?php echo $comanda_id; ?></h4>
                    </div>
                    <div class="card-body p-4">

                        <div class="mb-4 p-3 border rounded bg-light">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0 text-muted">Total a Pagar:</h5>
                                <div class="text-end">
                                    <div class="h3 text-primary fw-bold mb-0">$<?php echo number_format($total_usd, 2); ?></div>
                                    <div class="h4 text-success fw-normal mb-0">Bs. <?php echo number_format($total_bs, 2); ?></div>
                                </div>
                            </div>
                            <hr class="my-2">
                            <small class="text-muted d-block text-end">
                                <i class="fas fa-exchange-alt me-1"></i> Tasa del día: 1 USD = <?php echo number_format($tasa_dolar, 2); ?> BS
                            </small>
                        </div>

                        <form method="POST" id="form-cobro">
                            <div class="mb-3">
                                <label class="form-label fw-bold"><i class="fas fa-credit-card me-2 text-muted"></i>Método de Pago</label>
                                <select class="form-select form-select-lg" name="tipo_pago" id="tipo_pago_select" required>
                                    <option value="Pago Móvil">Pago Móvil</option>
                                    <option value="Efectivo">Efectivo (Dólares)</option>
                                    <option value="Transferencia">Transferencia</option>
                                    <option value="Tarjeta">Tarjeta (Punto de Venta)</option>
                                    <option value="Pago Mixto">Pago Mixto (Ambas monedas)</option>
                                </select>
                            </div>

                            <div id="datos-bancarios" class="p-3 mb-3 border rounded bg-light">
                                <h6 class="text-primary fw-bold mb-3"><i class="fas fa-university me-1"></i>Datos de la Transacción</h6>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label small fw-bold">Banco de Origen</label>
                                        <select class="form-select" name="banco_origen" id="banco_origen">
                                            <option value="">Seleccione Banco...</option>
                                            <option value="Banco de Venezuela">Banco de Venezuela</option>
                                            <option value="Banesco">Banesco</option>
                                            <option value="Mercantil">Mercantil</option>
                                            <option value="Provincial">Provincial</option>
                                            <option value="BNC">BNC</option>
                                            <option value="Bancaribe">Bancaribe</option>
                                            <option value="Otro">Otro</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label small fw-bold">Ref. (Últimos 4 dígitos)</label>
                                        <input type="text" class="form-control" name="referencia" id="referencia" placeholder="Ej: 1234" maxlength="4">
                                        <div class="invalid-feedback">Debe tener 4 dígitos numéricos.</div>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <div class="mb-3" id="pago-usd-fields" style="display:none;">
                                    <label class="form-label fw-bold text-primary">Monto en Dólares ($)</label>
                                    <input type="number" class="form-control form-control-lg" id="monto_recibido_usd" name="monto_recibido_usd" step="0.01" min="0" placeholder="0.00">
                                </div>

                                <div class="mb-3" id="pago-bs-fields">
                                    <label class="form-label fw-bold text-success">Monto en Bolívares (Bs.)</label>
                                    <input type="number" class="form-control form-control-lg" id="monto_recibido_bs" name="monto_recibido_bs" step="0.01" min="0" placeholder="0.00">
                                </div>
                            </div>

                            <div class="alert alert-info mt-3">
                                <div class="d-flex justify-content-between">
                                    <span>Faltante por Pagar:</span>
                                    <strong id="resumen-faltante" class="text-danger">$<?php echo number_format($total_usd, 2); ?></strong>
                                </div>
                                <div class="text-end"><small id="resumen-faltante-bs" class="text-danger fw-bold"></small></div>
                                <hr>
                                <div class="d-flex justify-content-between h5 mb-0">
                                    <span>Cambio (USD):</span>
                                    <strong id="resumen-cambio" class="text-success">$0.00</strong>
                                </div>
                            </div>

                            <div class="d-grid gap-2 mt-4">
                                <button type="submit" id="btn-confirmar-pago" class="btn btn-secondary btn-lg fw-bold" disabled>
                                    <i class="fas fa-check-circle me-2"></i>Confirmar Pago
                                </button>
                                <a href="caja.php" class="btn btn-outline-secondary">Cancelar</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>

    <script>
        // Constantes desde PHP
        const TASA = <?php echo $tasa_dolar; ?>;
        const TOTAL_USD = <?php echo $total_usd; ?>;
        const TOTAL_BS = <?php echo $total_bs; ?>;

        document.addEventListener('DOMContentLoaded', function() {
            // Elementos del DOM
            const selectMetodo = document.getElementById('tipo_pago_select');
            const divBancos = document.getElementById('datos-bancarios');
            const divUsd = document.getElementById('pago-usd-fields');
            const divBs = document.getElementById('pago-bs-fields');
            
            const inputUsd = document.getElementById('monto_recibido_usd');
            const inputBs = document.getElementById('monto_recibido_bs');
            const inputRef = document.getElementById('referencia');
            const inputBanco = document.getElementById('banco_origen');
            const btnCobrar = document.getElementById('btn-confirmar-pago');

            const lblFaltante = document.getElementById('resumen-faltante');
            const lblFaltanteBs = document.getElementById('resumen-faltante-bs');
            const lblCambio = document.getElementById('resumen-cambio');

            // 1. REGLA: Referencia solo números y max 4 dígitos
            if (inputRef) {
                inputRef.addEventListener('input', function() {
                    this.value = this.value.replace(/[^0-9]/g, '').slice(0, 4);
                    validarFormulario();
                });
            }

            // 2. CAMBIO DE MÉTODO DE PAGO
            if (selectMetodo) {
                selectMetodo.addEventListener('change', function() {
                    const metodo = this.value;
                    
                    // Limpiar valores
                    if(inputUsd) inputUsd.value = '';
                    if(inputBs) inputBs.value = '';
                    if(inputRef) inputRef.value = '';
                    if(inputBanco) inputBanco.value = '';

                    // Configuración inicial de visibilidad
                    if(divUsd) divUsd.style.display = 'none';
                    if(divBs) divBs.style.display = 'none';
                    if(divBancos) divBancos.style.display = 'none';

                    if (metodo === 'Efectivo') {
                        if(divUsd) divUsd.style.display = 'block';
                        if(inputUsd) inputUsd.value = TOTAL_USD.toFixed(2); // Autollenar
                    } 
                    else if (metodo === 'Pago Móvil' || metodo === 'Transferencia') {
                        if(divBs) divBs.style.display = 'block';
                        if(divBancos) divBancos.style.display = 'block'; // Mostrar bancos
                        if(inputBs) inputBs.value = TOTAL_BS.toFixed(2); // Autollenar
                    } 
                    else if (metodo === 'Tarjeta') {
                        if(divBs) divBs.style.display = 'block';
                        if(divBancos) divBancos.style.display = 'none'; // Tarjeta no pide banco origen visualmente aqui
                        if(inputBs) inputBs.value = TOTAL_BS.toFixed(2);
                    } 
                    else if (metodo === 'Pago Mixto') {
                        if(divUsd) divUsd.style.display = 'block';
                        if(divBs) divBs.style.display = 'block';
                        if(divBancos) divBancos.style.display = 'block'; // Opcional referencia
                    }

                    validarFormulario();
                    calcularTotales();
                });
            }

            // 3. AUTOCOMPLETADO MIXTO (La magia)
            if (inputUsd) {
                inputUsd.addEventListener('input', function() {
                    if (selectMetodo.value === 'Pago Mixto') {
                        const valUsd = parseFloat(this.value) || 0;
                        const restanteUsd = TOTAL_USD - valUsd;
                        if (restanteUsd > 0) {
                            const restanteBs = restanteUsd * TASA;
                            if(inputBs) inputBs.value = restanteBs.toFixed(2);
                        } else {
                            if(inputBs) inputBs.value = 0;
                        }
                    }
                    calcularTotales();
                });
            }

            if (inputBs) {
                inputBs.addEventListener('input', function() {
                    if (selectMetodo.value === 'Pago Mixto') {
                        const valBs = parseFloat(this.value) || 0;
                        const valBsEnUsd = valBs / TASA;
                        const restanteUsd = TOTAL_USD - valBsEnUsd;
                        
                        if (restanteUsd > 0) {
                            if(inputUsd) inputUsd.value = restanteUsd.toFixed(2);
                        } else {
                            if(inputUsd) inputUsd.value = 0;
                        }
                    }
                    calcularTotales();
                });
            }

            // 4. CÁLCULO DE TOTALES Y VALIDACIÓN
            function calcularTotales() {
                const usd = inputUsd ? (parseFloat(inputUsd.value) || 0) : 0;
                const bs = inputBs ? (parseFloat(inputBs.value) || 0) : 0;
                const totalPagadoUsd = usd + (bs / TASA);
                
                let faltante = TOTAL_USD - totalPagadoUsd;
                let cambio = 0;

                // Margen de error pequeño por decimales
                if (faltante < 0.01) {
                    cambio = Math.abs(faltante);
                    faltante = 0;
                }

                if(lblFaltante) lblFaltante.textContent = `$${faltante.toFixed(2)}`;
                if(lblCambio) lblCambio.textContent = `$${cambio.toFixed(2)}`;

                if (faltante > 0) {
                    if(lblFaltanteBs) lblFaltanteBs.textContent = `(Bs. ${(faltante * TASA).toFixed(2)})`;
                    if(lblFaltante) lblFaltante.className = 'text-danger fw-bold';
                } else {
                    if(lblFaltanteBs) lblFaltanteBs.textContent = '¡Completo!';
                    if(lblFaltante) lblFaltante.className = 'text-success fw-bold';
                }

                validarFormulario(faltante);
            }

            function validarFormulario(faltante = 999) {
                const metodo = selectMetodo ? selectMetodo.value : '';
                let valido = true;

                // A. Validar Referencia (Solo si es Pago Móvil o Transferencia)
                if (metodo === 'Pago Móvil' || metodo === 'Transferencia') {
                    if (inputRef && inputRef.value.length < 4) {
                        valido = false; // Requiere 4 dígitos
                        if (inputRef.classList) inputRef.classList.add('is-invalid');
                    } else if (inputRef) {
                        if (inputRef.classList) inputRef.classList.remove('is-invalid');
                    }
                    if (inputBanco && inputBanco.value === "") valido = false;
                } 
                // B. En Mixto la referencia es opcional según tu regla ("menos en el pago mixto")
                else if (metodo === 'Pago Mixto') {
                    // No bloqueamos por referencia, pero si escriben algo debe ser números
                    if(inputRef && inputRef.value.length > 0 && inputRef.value.length < 4) {
                         // Opcional: si escribe, que sean 4. Si lo dejas vacío, pasa.
                    }
                }

                // C. Validar Montos Completos
                const usd = inputUsd ? (parseFloat(inputUsd.value) || 0) : 0;
                const bs = inputBs ? (parseFloat(inputBs.value) || 0) : 0;
                const totalPagado = usd + (bs / TASA);
                
                if (totalPagado < (TOTAL_USD - 0.01)) {
                    valido = false;
                }

                // Activar/Desactivar Botón
                if (btnCobrar) {
                    btnCobrar.disabled = !valido;
                    if (valido) {
                        btnCobrar.classList.remove('btn-secondary');
                        btnCobrar.classList.add('btn-success');
                    } else {
                        btnCobrar.classList.add('btn-secondary');
                        btnCobrar.classList.remove('btn-success');
                    }
                }
            }

            // Inicializar
            if (selectMetodo) {
                selectMetodo.dispatchEvent(new Event('change'));
            }
        });
    </script>
</body>
</html>