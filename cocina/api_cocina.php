<?php
// cocina/api_cocina.php
require_once __DIR__ . '/../config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

try {
    // 1. Estadísticas
    $stmt_stats = $pdo->query("SELECT SUM(CASE WHEN estado = 'en_preparacion' THEN 1 ELSE 0 END) as total_preparacion, SUM(CASE WHEN estado = 'listo' THEN 1 ELSE 0 END) as total_listo FROM comanda WHERE estado IN ('en_preparacion', 'listo')");
    $stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);
    $total_en_preparacion = (int)($stats['total_preparacion'] ?? 0);
    $total_listos = (int)($stats['total_listo'] ?? 0);

    // 2. Paginación
    $pedidos_por_pagina = 5;
    $pagina_actual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
    if ($pagina_actual < 1) $pagina_actual = 1;
    $offset = ($pagina_actual - 1) * $pedidos_por_pagina;
    $total_pedidos = $total_en_preparacion + $total_listos;
    $total_paginas = ceil($total_pedidos / $pedidos_por_pagina);

   // --- 3. CONSULTA DE PEDIDOS (Lógica: Antiguos primero, Listos al final) ---
    $stmt = $pdo->prepare("
        SELECT 
            c.id, c.estado, c.total, c.fecha_creacion, c.tipo_servicio, 
            c.editando, c.es_anexo,
            u.nombre as mesero_nombre,
            COALESCE(m.numero, 0) as mesa_numero
        FROM comanda c
        JOIN usuario u ON c.usuario_id = u.id
        LEFT JOIN mesa m ON c.mesa_id = m.id
        WHERE c.estado IN ('en_preparacion', 'listo')
        ORDER BY 
            -- Regla 1: Ponemos los 'listo' (2) después de los 'en_preparacion' (1)
            CASE 
                WHEN c.estado = 'en_preparacion' THEN 1
                WHEN c.estado = 'listo' THEN 2
                ELSE 3
            END ASC,
            -- Regla 2: Dentro de cada grupo, el más viejo (fecha menor) va primero
            c.fecha_creacion ASC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindParam(':limit', $pedidos_por_pagina, PDO::PARAM_INT);
    $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $pedidos_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $pedidos_procesados = [];

    if (count($pedidos_raw) > 0) {
        $ids_comandas = array_column($pedidos_raw, 'id');
        $inQuery = implode(',', array_fill(0, count($ids_comandas), '?'));

        // 4. Obtener todos los detalles (incluyendo es_anexo del detalle)
        $stmt_det = $pdo->prepare("
            SELECT dc.comanda_id, dc.id as detalle_id, p.nombre, p.categoria_id, 
                   cp.nombre as categoria, dc.tamanio, dc.cantidad, dc.precio_unitario, dc.es_anexo
            FROM detalle_comanda dc
            JOIN producto p ON dc.producto_id = p.id
            JOIN categoria_producto cp ON p.categoria_id = cp.id
            WHERE dc.comanda_id IN ($inQuery)
            ORDER BY dc.comanda_id, dc.id ASC
        ");
        $stmt_det->execute($ids_comandas);
        $detalles_bd = $stmt_det->fetchAll(PDO::FETCH_ASSOC);

        // 5. Procesar y FILTRAR la información
        foreach ($pedidos_raw as $pedido) {
            $pizzas = [];
            $bebidas = [];
            $current_pizza_index = -1;

            foreach ($detalles_bd as $detalle) {
                if ($detalle['comanda_id'] == $pedido['id']) {
                    
                    // ==========================================
                    // 🔥 EL FILTRO ESTRICTO PARA ANEXOS 🔥
                    // ==========================================
                    $mostrar_item = true; // Por defecto mostramos todo
                    
                    // Validamos si la comanda está marcada como anexo Y está en preparación
                    if ($pedido['es_anexo'] == 1 && $pedido['estado'] === 'en_preparacion') {
                        // Si el detalle NO es un anexo (es decir, es viejo), lo OCULTAMOS
                        if ($detalle['es_anexo'] == 0) {
                            $mostrar_item = false;
                        }
                    }
                    // ==========================================

                    // Solo si pasa el filtro, lo agregamos a la tarjeta del cocinero
                    if ($mostrar_item) {
                        if ($detalle['categoria'] === 'Pizza Base') {
                            $current_pizza_index++;
                            $pizzas[$current_pizza_index] = [
                                'base' => $detalle['nombre'], 
                                'tamanio' => $detalle['tamanio'], 
                                'cantidad' => $detalle['cantidad'], 
                                'ingredientes' => []
                            ];
                        } elseif ($detalle['categoria'] === 'Ingrediente' && $current_pizza_index >= 0) {
                            $pizzas[$current_pizza_index]['ingredientes'][] = $detalle['nombre'];
                        } elseif ($detalle['categoria'] === 'Bebida') {
                            $bebidas[] = [
                                'nombre' => $detalle['nombre'], 
                                'cantidad' => $detalle['cantidad']
                            ];
                        }
                    }
                }
            }

            // Agregamos el pedido formateado a la lista final
            $pedidos_procesados[] = [
                'id' => $pedido['id'], 
                'estado' => $pedido['estado'], 
                'tipo_servicio' => $pedido['tipo_servicio'],
                'mesa_numero' => $pedido['mesa_numero'], 
                'mesero_nombre' => $pedido['mesero_nombre'],
                'hora' => date('H:i', strtotime($pedido['fecha_creacion'])),
                'esta_bloqueado' => ($pedido['editando'] == 1),
                'es_anexo' => ($pedido['es_anexo'] == 1),
                'pizzas' => $pizzas, 
                'bebidas' => $bebidas
            ];
        }
    }

    echo json_encode([
        'success' => true, 
        'paginacion' => ['actual' => $pagina_actual, 'total_paginas' => $total_paginas], 
        'estadisticas' => ['en_preparacion' => $total_en_preparacion, 'listos' => $total_listos], 
        'pedidos' => $pedidos_procesados
    ]);

} catch (Exception $e) { 
    echo json_encode(['success' => false, 'error' => $e->getMessage()]); 
}