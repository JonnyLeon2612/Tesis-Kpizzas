<?php
// --- AUTENTICACIÓN Y CONFIGURACIÓN ---
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../config/db.php'; 
require_once __DIR__ . '/../fpdf186/fpdf.php'; 
require_once __DIR__ . '/../config/tasa_helper.php';

session_start();
if (!isset($_SESSION['user']) || ($_SESSION['user']['rol'] !== 'caja' && $_SESSION['user']['rol'] !== 'admin')) {
    die('Acceso denegado.');
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) { die('ID inválido.'); }
$comanda_id = (int)$_GET['id'];

// --- OBTENER TASA HISTÓRICA DEL PAGO (Desde la BD) ---
$stmt_tasa = $pdo->prepare("SELECT tasa_cambio FROM pago WHERE comanda_id = ? ORDER BY id DESC LIMIT 1");
$stmt_tasa->execute([$comanda_id]);
$pago_data = $stmt_tasa->fetch(PDO::FETCH_ASSOC);

// Si tiene tasa guardada, la usamos. Si no (viejo), usamos la actual.
if ($pago_data && $pago_data['tasa_cambio'] > 0) {
    $tasa_aplicada = $pago_data['tasa_cambio'];
} else {
    $tasa_aplicada = obtenerTasaDolarActual($pdo);
}

// --- OBTENER DATOS COMANDA ---
$stmt_comanda = $pdo->prepare("
    SELECT c.id, c.total, c.fecha_creacion, u.nombre as mesero_nombre, c.tipo_servicio, m.numero as mesa_numero
    FROM comanda c JOIN usuario u ON c.usuario_id = u.id LEFT JOIN mesa m ON c.mesa_id = m.id
    WHERE c.id = ?
");
$stmt_comanda->execute([$comanda_id]);
$comanda = $stmt_comanda->fetch(PDO::FETCH_ASSOC); 
if (!$comanda) { die('Comanda no encontrada.'); }

// --- DETALLES ---
$stmt_detalle = $pdo->prepare("
    SELECT p.nombre, cp.nombre as categoria, dc.tamanio, dc.cantidad, dc.precio_unitario, (dc.cantidad * dc.precio_unitario) as subtotal
    FROM detalle_comanda dc JOIN producto p ON dc.producto_id = p.id JOIN categoria_producto cp ON p.categoria_id = cp.id
    WHERE dc.comanda_id = ? ORDER BY dc.id ASC
");
$stmt_detalle->execute([$comanda_id]);
$detalles_db = $stmt_detalle->fetchAll(PDO::FETCH_ASSOC);

$pizzas_agrupadas = []; $bebidas_agrupadas = []; $pizza_counter = 0;
foreach ($detalles_db as $detalle) {
    if ($detalle['categoria'] === 'Pizza Base') {
        $pizza_counter++;
        $pizzas_agrupadas[$pizza_counter] = ['base' => $detalle, 'ingredientes' => [], 'subtotal_pizza' => $detalle['subtotal']];
    } elseif ($detalle['categoria'] === 'Ingrediente' && $pizza_counter > 0) {
        $pizzas_agrupadas[$pizza_counter]['ingredientes'][] = $detalle;
        $pizzas_agrupadas[$pizza_counter]['subtotal_pizza'] += $detalle['subtotal']; 
    } elseif ($detalle['categoria'] === 'Bebida' || $detalle['categoria'] === 'Adicional') {
        $bebidas_agrupadas[] = $detalle;
    }
}

class PDF extends FPDF {
    private $comanda_info;
    function setComandaInfo($info) { $this->comanda_info = $info; }
    function Header() {
        $this->SetFont('Arial','B',20); $this->Cell(0,10, utf8_decode('Kpizza\'s - Recibo'), 0, 1, 'C');
        $this->SetFont('Arial','',10); $this->Cell(0,5, utf8_decode('Dirección: Calle Principal 123, Ciudad'), 0, 1, 'C');
        $this->Cell(0,5, utf8_decode('Teléfono: 555-1234'), 0, 1, 'C'); $this->Ln(10); 
        if ($this->comanda_info) {
            $this->SetFont('Arial','B',12); $this->Cell(30, 7, utf8_decode('Recibo Nro:'), 0);
            $this->SetFont('Arial','',12); $this->Cell(50, 7, $this->comanda_info['id'], 0);
            $this->SetFont('Arial','B',12); $this->Cell(25, 7, 'Fecha:', 0);
            $this->SetFont('Arial','',12); $this->Cell(0, 7, date('d/m/Y H:i', strtotime($this->comanda_info['fecha_creacion'])), 0, 1);
            $this->SetFont('Arial','B',12); $this->Cell(30, 7, 'Mesero:', 0);
            $this->SetFont('Arial','',12); $this->Cell(50, 7, utf8_decode($this->comanda_info['mesero_nombre']), 0);
            $this->SetFont('Arial','B',12); $this->Cell(25, 7, 'Servicio:', 0);
            $this->SetFont('Arial','',12); 
            $serv = $this->comanda_info['tipo_servicio']; if($serv === 'Mesa') $serv .= ' '.$this->comanda_info['mesa_numero'];
            $this->Cell(0, 7, utf8_decode($serv), 0, 1); $this->Ln(5);
        }
    }
    function Footer() { $this->SetY(-15); $this->SetFont('Arial','I',8); $this->Cell(0,10, utf8_decode('Gracias por tu compra en Kpizza\'s'), 0, 0, 'C'); }
    function ProductoRow($cant, $desc, $pu, $sub) {
        $this->SetFont('Arial', '', 10); $this->Cell(15, 7, $cant, 1, 0, 'C'); $this->Cell(100, 7, utf8_decode($desc), 1, 0, 'L');
        $this->Cell(35, 7, '$'.number_format($pu, 2), 1, 0, 'R'); $this->Cell(40, 7, '$'.number_format($sub, 2), 1, 1, 'R');
    }
}

$pdf = new PDF('P', 'mm', 'A4'); $pdf->AliasNbPages(); $pdf->setComandaInfo($comanda); $pdf->AddPage();
$pdf->SetFillColor(230, 230, 230); $pdf->SetFont('Arial','B',11);
$pdf->Cell(15, 7, 'Cant.', 1, 0, 'C', true); $pdf->Cell(100, 7, 'Descripcion', 1, 0, 'C', true);
$pdf->Cell(35, 7, 'P. Unit.', 1, 0, 'C', true); $pdf->Cell(40, 7, 'Subtotal', 1, 1, 'C', true); 

if (!empty($pizzas_agrupadas)) {
    $pdf->SetFont('Arial','B',10); $pdf->SetFillColor(245, 245, 245); $pdf->Cell(190, 8, utf8_decode('Pizzas'), 1, 1, 'L', true);
    foreach ($pizzas_agrupadas as $index => $pizza) {
        $pdf->SetFont('Arial','B',10);
        $desc = "Pizza " . $index . " (" . $pizza['base']['tamanio'] . ") - " . $pizza['base']['nombre'];
        $pdf->Cell(15, 7, $pizza['base']['cantidad'], 'LR', 0, 'C'); 
        $pdf->Cell(100, 7, utf8_decode($desc), 'R', 0, 'L');
        $pdf->Cell(35, 7, '$'.number_format($pizza['base']['precio_unitario'], 2), 'R', 0, 'R');
        $pdf->Cell(40, 7, '$'.number_format($pizza['base']['subtotal'], 2), 'R', 1, 'R');
        if (!empty($pizza['ingredientes'])) {
            $pdf->SetFont('Arial','I',9);
            foreach ($pizza['ingredientes'] as $ing) {
                $ing_desc = "  + " . $ing['nombre'] . ($ing['cantidad'] > 1 ? " (x".$ing['cantidad'].")" : "");
                $pdf->Cell(15, 6, '', 'LR', 0, 'C'); $pdf->Cell(100, 6, utf8_decode($ing_desc), 'R', 0, 'L');
                $pdf->Cell(35, 6, '$'.number_format($ing['precio_unitario'], 2), 'R', 0, 'R');
                $pdf->Cell(40, 6, '$'.number_format($ing['subtotal'], 2), 'R', 1, 'R');
            }
        }
        $pdf->Cell(190, 0, '', 'T', 1, 'C'); 
    }
}

if (!empty($bebidas_agrupadas)) {
    $pdf->SetFont('Arial','B',10); $pdf->SetFillColor(245, 245, 245); $pdf->Cell(190, 8, 'Bebidas / Otros', 1, 1, 'L', true);
    $pdf->SetFont('Arial','',10);
    foreach ($bebidas_agrupadas as $bebida) {
        $pdf->ProductoRow($bebida['cantidad'], $bebida['nombre'], $bebida['precio_unitario'], $bebida['subtotal']);
    }
}

$pdf->Ln(5);

// TOTAL USD
$pdf->SetFont('Arial','B',14); $pdf->Cell(150, 10, 'TOTAL (USD):', 0, 0, 'R');
$pdf->SetFillColor(255, 255, 204); $pdf->Cell(40, 10, '$'.number_format($comanda['total'], 2), 1, 1, 'R', true);

// CALCULO DE TOTAL BS CON LA TASA HISTÓRICA
$total_bs = $comanda['total'] * $tasa_aplicada;

$pdf->SetFont('Arial','B',12); $pdf->Cell(150, 10, 'TOTAL (Bs.):', 0, 0, 'R');
$pdf->SetFillColor(230, 230, 230); $pdf->Cell(40, 10, 'Bs. '.number_format($total_bs, 2), 1, 1, 'R', true);

// NOTA DE LA TASA
$pdf->SetFont('Arial','I',8); $pdf->Cell(190, 5, utf8_decode('Tasa de cambio aplicada: '.number_format($tasa_aplicada, 2) . ' BS/$'), 0, 1, 'R');

$pdf->Output('I', 'Recibo_Kpizza_'.$comanda['id'].'.pdf');
exit;
?>