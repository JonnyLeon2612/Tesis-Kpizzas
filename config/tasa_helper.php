<?php
function obtenerTasaDolarActual($pdo) {
    $stmt = $pdo->query("
        SELECT * FROM tasa_dolar 
        WHERE fecha <= CURDATE() 
        ORDER BY fecha DESC, fecha_actualizacion DESC 
        LIMIT 1
    ");
    $tasa_actual = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $tasa_actual ? (float)$tasa_actual['tasa'] : 1.0; // Devuelve 1.0 si no hay tasa registrada
}


function convertirDolaresABolivares($montoDolares, $tasaDolar) {
    return $montoDolares * $tasaDolar;
}
?>