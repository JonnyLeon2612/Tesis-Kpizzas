<?php
// mesero/api_mesas.php
require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');

try {
    // Traemos el ID y el ESTADO de todas las mesas
$stmt = $pdo->query("SELECT id, estado FROM mesa WHERE activo = 1 ORDER BY numero ASC");
    $mesas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'mesas' => $mesas]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}