<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/tasa_helper.php';
require_role('caja');

// Validar ID de comanda
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die('ID de comanda no válido.');
}
$comanda_id = (int)$_GET['id'];

// Obtener Tasa y Comanda
$tasa_dolar = obtenerTasaDolarActual($pdo);
$stmt_comanda = $pdo->prepare("
    SELECT c.*, m.numero as mesa_numero
    FROM comanda c 
    LEFT JOIN mesa m ON c.mesa_id = m.id 
    WHERE c.id = ? AND c.estado = 'listo'
");
$stmt_comanda->execute([$comanda_id]);
$comanda = $stmt_comanda->fetch(PDO::FETCH_ASSOC);

if (!$comanda) {
    die('Comanda no encontrada, ya fue cobrada o no está lista.');
}

$total_usd = (float)$comanda['total'];
$total_bs = convertirDolaresABolivares($total_usd, $tasa_dolar);

// --- PROCESAR EL PAGO (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tipo_pago_nombre = $_POST['tipo_pago'];

    $monto_recibido_usd = !empty($_POST['monto_recibido_usd']) ? (float)$_POST['monto_recibido_usd'] : 0;
    $monto_recibido_bs = !empty($_POST['monto_recibido_bs']) ? (float)$_POST['monto_recibido_bs'] : 0;

    // --- NUEVO: CAPTURAR DATOS BANCARIOS ---
    $referencia = !empty($_POST['referencia']) ? trim($_POST['referencia']) : null;
    $banco_origen = !empty($_POST['banco_origen']) ? $_POST['banco_origen'] : null;

    try {
        $total_recibido_convertido_a_usd = $monto_recibido_usd + ($monto_recibido_bs / $tasa_dolar);

        // Validar montos (con margen de error pequeño)
        if ($total_recibido_convertido_a_usd < ($total_usd - 0.001)) {
            throw new Exception("El pago recibido es insuficiente.");
        }

        // --- NUEVO: VALIDAR REFERENCIA OBLIGATORIA ---
        // Si el método es digital, exigimos la referencia para asegurar la auditoría futura
        if (in_array($tipo_pago_nombre, ['Pago Móvil', 'Transferencia']) && empty($referencia)) {
            throw new Exception("El número de referencia es obligatorio para " . $tipo_pago_nombre);
        }

        $cambio_usd = $total_recibido_convertido_a_usd - $total_usd;

        $pdo->beginTransaction();

        // 1. Actualizar Comanda
        $stmt_update_comanda = $pdo->prepare("UPDATE comanda SET estado = 'cobrado' WHERE id = ?");
        $stmt_update_comanda->execute([$comanda_id]);

        // 2. Liberar Mesa
        if ($comanda['tipo_servicio'] === 'Mesa' && $comanda['mesa_id']) {
            $stmt_mesa = $pdo->prepare("UPDATE mesa SET estado = 'disponible' WHERE id = ?");
            $stmt_mesa->execute([$comanda['mesa_id']]);
        }

        // 3. Registrar Pagos en BD

        // Gestión de Monedas (Crear si no existen)
        $moneda_id_usd = $pdo->query("SELECT id FROM moneda WHERE simbolo = '$' LIMIT 1")->fetchColumn();
        if (!$moneda_id_usd) {
            $pdo->query("INSERT INTO moneda (nombre, simbolo) VALUES ('Dólar Americano', '$')");
            $moneda_id_usd = $pdo->lastInsertId();
        }
        $moneda_id_bs = $pdo->query("SELECT id FROM moneda WHERE simbolo = 'Bs' LIMIT 1")->fetchColumn();
        if (!$moneda_id_bs) {
            $pdo->query("INSERT INTO moneda (nombre, simbolo) VALUES ('Bolívar', 'Bs')");
            $moneda_id_bs = $pdo->lastInsertId();
        }

        // Gestión Tipo de Pago
        $stmt_tipo_pago = $pdo->prepare("SELECT id FROM tipo_pago WHERE nombre = ? LIMIT 1");
        $stmt_tipo_pago->execute([$tipo_pago_nombre]);
        $tipo_pago_db = $stmt_tipo_pago->fetch();
        $tipo_pago_id = $tipo_pago_db ? $tipo_pago_db['id'] : 0;
        if (!$tipo_pago_db) {
            $pdo->prepare("INSERT INTO tipo_pago (nombre, moneda_id) VALUES (?, ?)")->execute([$tipo_pago_nombre, $moneda_id_usd]);
            $tipo_pago_id = $pdo->lastInsertId();
        }

        // Gestión Tasa
        $stmt_tasa = $pdo->prepare("SELECT id FROM tasa WHERE moneda_id = ? AND valor = ? LIMIT 1");
        $stmt_tasa->execute([$moneda_id_usd, $tasa_dolar]);
        $tasa_db = $stmt_tasa->fetch();
        $tasa_id = $tasa_db ? $tasa_db['id'] : 0;
        if (!$tasa_db) {
            $pdo->prepare("INSERT INTO tasa (moneda_id, valor) VALUES (?, ?)")->execute([$moneda_id_usd, $tasa_dolar]);
            $tasa_id = $pdo->lastInsertId();
        }

        // --- INSERTAR PAGO USD ---
        if ($monto_recibido_usd > 0) {
            $valor_pago_usd = ($tipo_pago_nombre != 'Pago Mixto') ? $total_usd : $monto_recibido_usd;

            // Efectivo USD usualmente no lleva referencia, guardamos NULL
            $stmt_pago_usd = $pdo->prepare("INSERT INTO pago (comanda_id, tipo_pago_id, monto_total, moneda_pago, fecha_pago, referencia, banco_origen) VALUES (?, ?, ?, 'USD', NOW(), NULL, NULL)");
            $stmt_pago_usd->execute([$comanda_id, $tipo_pago_id, $valor_pago_usd]);
            $pago_id_usd = $pdo->lastInsertId();

            $stmt_detalle_usd = $pdo->prepare("INSERT INTO detalle_pago (pago_id, tasa_id, monto) VALUES (?, ?, ?)");
            $stmt_detalle_usd->execute([$pago_id_usd, $tasa_id, $monto_recibido_usd]);
        }

        // --- INSERTAR PAGO BS (CON REFERENCIA) ---
        if ($monto_recibido_bs > 0) {
            $valor_pago_bs = ($tipo_pago_nombre != 'Pago Mixto') ? $total_bs : $monto_recibido_bs;

            // AQUÍ GUARDAMOS LOS DATOS NUEVOS EN LA BASE DE DATOS
            $stmt_pago_bs = $pdo->prepare("INSERT INTO pago (comanda_id, tipo_pago_id, monto_total, moneda_pago, fecha_pago, referencia, banco_origen) VALUES (?, ?, ?, 'BS', NOW(), ?, ?)");
            $stmt_pago_bs->execute([$comanda_id, $tipo_pago_id, $valor_pago_bs, $referencia, $banco_origen]);
            $pago_id_bs = $pdo->lastInsertId();

            $stmt_detalle_bs = $pdo->prepare("INSERT INTO detalle_pago (pago_id, tasa_id, monto) VALUES (?, ?, ?)");
            $stmt_detalle_bs->execute([$pago_id_bs, $tasa_id, $monto_recibido_bs]);
        }

        $pdo->commit();
        header("Location: caja.php?cobro_exito=true&comanda_id=$comanda_id&cambio_usd=$cambio_usd");
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $error_msg = "Error al procesar el pago: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
                                    <div class="h3 text-primary fw-bold mb-0" id="total-usd-display">$<?php echo number_format($total_usd, 2); ?></div>
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
                                    <option value="Efectivo">Efectivo (Solo Dólares)</option>
                                    <option value="Pago Móvil">Pago Móvil</option>
                                    <option value="Transferencia">Transferencia</option>
                                    <option value="Tarjeta">Tarjeta (Punto de Venta)</option>
                                    <option value="Pago Mixto">Pago Mixto (Ambas monedas)</option>
                                </select>
                            </div>

                            <div id="datos-bancarios" class="p-3 mb-3 border rounded bg-light" style="display: none;">
                                <h6 class="text-primary fw-bold mb-3"><i class="fas fa-university me-1"></i>Datos de la Transacción</h6>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label small fw-bold">Banco de Origen</label>
                                        <select class="form-select" name="banco_origen" id="banco_origen">
                                            <option value="">Seleccione Banco...</option>
                                            <option value="Banco de Venezuela">Banco de Venezuela</option>
                                            <option value="Banco Bicentenario">Banco Bicentenario</option>
                                            <option value="Banco del Tesoro">Banco del Tesoro</option>

                                            <option value="Banesco">Banesco</option>
                                            <option value="Mercantil">Mercantil</option>
                                            <option value="Provincial">Provincial</option>
                                            <option value="Bancamiga">Bancamiga</option>
                                            <option value="BNC">BNC (Nacional de Crédito)</option>

                                            <option value="Bancaribe">Bancaribe</option>
                                            <option value="Banco Exterior">Banco Exterior</option>
                                            <option value="Banco Plaza">Banco Plaza</option>
                                            <option value="Banplus">Banplus</option>
                                            <option value="Banco Activo">Banco Activo</option>
                                            <option value="BFC">BFC (Fondo Común)</option>
                                            <option value="100% Banco">100% Banco</option>
                                            <option value="Banco Caroní">Banco Caroní</option>
                                            <option value="Venezolano de Crédito">Venezolano de Crédito</option>
                                            <option value="Sofitasa">Sofitasa</option>
                                            <option value="Bancrecer">Bancrecer</option>
                                            <option value="Mi Banco">Mi Banco</option>
                                            <option value="Bancor">Bancor</option>

                                            <option value="Otro">Otro</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label small fw-bold">Nro. Referencia (Últimos 4 dígitos)</label>
                                        <input type="text" class="form-control" name="referencia" id="referencia" placeholder="Ej: 1234" maxlength="15">
                                    </div>
                                </div>
                            </div>

                            <div>
                                <div class="mb-3" id="pago-usd-fields">
                                    <label for="monto_recibido_usd" class="form-label fw-bold"><i class="fas fa-dollar-sign me-2 text-primary"></i>Monto Recibido (USD)</label>
                                    <div class="input-group input-group-lg">
                                        <span class="input-group-text">$</span>
                                        <input type="number" class="form-control" id="monto_recibido_usd" name="monto_recibido_usd" step="0.01" min="0">
                                    </div>
                                </div>

                                <div class="mb-3" id="pago-bs-fields">
                                    <label for="monto_recibido_bs" class="form-label fw-bold"><i class="fas fa-piggy-bank me-2 text-success"></i>Monto Recibido (BS)</label>
                                    <div class="input-group input-group-lg">
                                        <span class="input-group-text">Bs.</span>
                                        <input type="number" class="form-control" id="monto_recibido_bs" name="monto_recibido_bs" step="0.01" min="0">
                                    </div>
                                </div>
                            </div>

                            <div class="alert alert-info mt-3">
                                <div class="d-flex justify-content-between">
                                    <span>Total:</span>
                                    <strong id="resumen-recibido">$0.00</strong>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Faltante por Pagar:</span>
                                    <strong id="resumen-faltante" class="text-danger">$<?php echo number_format($total_usd, 2); ?></strong>
                                </div>
                                <div class="text-end">
                                    <small id="resumen-faltante-bs" class="text-danger fw-bold"></small>
                                </div>

                                <hr class="my-2">
                                <div class="d-flex justify-content-between h5 mb-0">
                                    <span>Cambio a devolver (en USD):</span>
                                    <strong id="resumen-cambio" class="text-success">$0.00</strong>
                                </div>
                                <small id="resumen-cambio-bs" class="text-muted d-block text-end"></small>
                            </div>

                            <div class="d-grid gap-2 mt-4">
                                <button type="submit" id="btn-confirmar-pago" class="btn btn-success btn-lg fw-bold" disabled>
                                    <i class="fas fa-check-circle me-2"></i>Confirmar Pago
                                </button>
                                <a href="caja.php" class="btn btn-secondary">Cancelar</a>
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
        const TASA_DOLAR = <?php echo $tasa_dolar; ?>;
        const TOTAL_USD = <?php echo $total_usd; ?>;
        const TOTAL_BS = <?php echo $total_bs; ?>;

        document.addEventListener('DOMContentLoaded', function() {
            const tipoPagoSelect = document.getElementById('tipo_pago_select');
            const usdFields = document.getElementById('pago-usd-fields');
            const bsFields = document.getElementById('pago-bs-fields');

            // Elementos NUEVOS para Bancos
            const datosBancarios = document.getElementById('datos-bancarios');
            const inputBanco = document.getElementById('banco_origen');
            const inputReferencia = document.getElementById('referencia');

            const montoUsdInput = document.getElementById('monto_recibido_usd');
            const montoBsInput = document.getElementById('monto_recibido_bs');
            const btnConfirmar = document.getElementById('btn-confirmar-pago');

            const resumenRecibido = document.getElementById('resumen-recibido');
            const resumenFaltante = document.getElementById('resumen-faltante');
            const resumenFaltanteBs = document.getElementById('resumen-faltante-bs');
            const resumenCambio = document.getElementById('resumen-cambio');
            const resumenCambioBs = document.getElementById('resumen-cambio-bs');

            function manejarVisibilidadCampos() {
                const tipoPago = tipoPagoSelect.value;
                montoUsdInput.value = '';
                montoBsInput.value = '';

                // Resetear visibilidad general
                usdFields.style.display = 'none';
                bsFields.style.display = 'none';

                // Resetear visibilidad bancaria
                datosBancarios.style.display = 'none';
                inputBanco.required = false;
                inputReferencia.required = false;

                switch (tipoPago) {
                    case 'Efectivo':
                        usdFields.style.display = 'block';
                        montoUsdInput.value = TOTAL_USD.toFixed(2);
                        break;

                    case 'Pago Móvil':
                    case 'Transferencia':
                        bsFields.style.display = 'block';
                        montoBsInput.value = TOTAL_BS.toFixed(2);

                        // ACTIVAR CAMPOS BANCARIOS (Obligatorios)
                        datosBancarios.style.display = 'block';
                        inputBanco.required = true;
                        inputReferencia.required = true;
                        break;

                    case 'Tarjeta':
                        bsFields.style.display = 'block';
                        montoBsInput.value = TOTAL_BS.toFixed(2);
                        
                        datosBancarios.style.display = 'none'; 
                        inputBanco.required = false;
                        inputReferencia.required = false;
                        break;

                    case 'Pago Mixto':
                        usdFields.style.display = 'block';
                        bsFields.style.display = 'block';
                        // Mostrar campos bancarios (Opcionales, por si una parte es transferencia)
                        datosBancarios.style.display = 'block';
                        break;
                }
                calcularTotales();
            }

            function calcularTotales() {
                const montoUsd = parseFloat(montoUsdInput.value) || 0;
                const montoBs = parseFloat(montoBsInput.value) || 0;
                const montoBsEnUsd = montoBs / TASA_DOLAR;
                const totalRecibidoUsd = montoUsd + montoBsEnUsd;

                let faltanteUsd = TOTAL_USD - totalRecibidoUsd;
                let cambioUsd = 0;
                const epsilon = 0.001;

                if (faltanteUsd < epsilon) {
                    cambioUsd = -faltanteUsd;
                    faltanteUsd = 0;
                }

                resumenRecibido.textContent = `$${totalRecibidoUsd.toFixed(2)}`;
                resumenFaltante.textContent = `$${faltanteUsd.toFixed(2)}`;
                resumenCambio.textContent = `$${cambioUsd.toFixed(2)}`;

                if (faltanteUsd > 0) {
                    const faltanteBs = faltanteUsd * TASA_DOLAR;
                    resumenFaltanteBs.textContent = `(Bs. ${faltanteBs.toFixed(2)})`;
                } else {
                    resumenFaltanteBs.textContent = '';
                }

                if (cambioUsd > 0) {
                    resumenCambioBs.textContent = `(Equivale a Bs. ${(cambioUsd * TASA_DOLAR).toFixed(2)})`;
                } else {
                    resumenCambioBs.textContent = '';
                }

                if (faltanteUsd < epsilon) {
                    btnConfirmar.disabled = false;
                    resumenFaltante.classList.remove('text-danger');
                    resumenFaltante.classList.add('text-success');
                } else {
                    btnConfirmar.disabled = true;
                    resumenFaltante.classList.add('text-danger');
                    resumenFaltante.classList.remove('text-success');
                }
            }

            tipoPagoSelect.addEventListener('change', manejarVisibilidadCampos);
            montoUsdInput.addEventListener('input', calcularTotales);
            montoBsInput.addEventListener('input', calcularTotales);

            const form = document.getElementById('form-cobro');
            form.addEventListener('submit', function(event) {
                event.preventDefault();
                const montoUsd = parseFloat(montoUsdInput.value) || 0;
                const montoBs = parseFloat(montoBsInput.value) || 0;
                const cambio = parseFloat(resumenCambio.textContent.replace('$', ''));

                let html = `Vas a registrar un pago de:<br>`;
                if (montoUsd > 0) html += `<b class="h5 d-block">$${montoUsd.toFixed(2)}</b>`;
                if (montoBs > 0) html += `<b class="h5 d-block">Bs. ${montoBs.toFixed(2)}</b>`;

                // Mostrar referencia en la alerta de confirmación
                const ref = document.getElementById('referencia').value;
                if (ref) {
                    html += `<small class="text-muted d-block mt-2">Ref: ${ref}</small>`;
                }

                if (cambio > 0) html += `<hr>El cambio a devolver es: <b class="h5 text-success d-block">$${cambio.toFixed(2)}</b>`;

                Swal.fire({
                    title: '¿Confirmar Cobro?',
                    html: html,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#28a745',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: 'Sí, cobrar',
                    cancelButtonText: 'Cancelar'
                }).then((result) => {
                    if (result.isConfirmed) form.submit();
                });
            });

            <?php if (isset($error_msg)): ?>
                Swal.fire({
                    icon: 'error',
                    title: 'Error al Procesar',
                    text: <?php echo json_encode($error_msg); ?>,
                    confirmButtonColor: '#d33'
                });
            <?php endif; ?>

            manejarVisibilidadCampos();
        });
    </script>
</body>

</html>