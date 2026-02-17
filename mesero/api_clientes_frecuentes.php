<?php
require_once '../config/db.php';

$stmt = $pdo->query("
    SELECT c.*, COUNT(co.id) as total_pedidos 
    FROM cliente c
    JOIN comanda co ON c.id = co.cliente_id
    GROUP BY c.id
    ORDER BY total_pedidos DESC
    LIMIT 6
");

$frecuentes = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode($frecuentes);
?>