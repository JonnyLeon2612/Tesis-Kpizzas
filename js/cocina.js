class CocinaManager {
    constructor() {
        this.init();
    }

    init() {
        console.log('Cocina SPA (JSON Engine) inicializado');
        this.cargarDatos(); // Carga inicial
        this.iniciarAutoUpdate(); // Inicia el ciclo
    }

    // Pide los datos a la API JSON
    cargarDatos() {
        const urlParams = new URLSearchParams(window.location.search);
        const pagina = urlParams.get('pagina') || 1;

        fetch(`api_cocina.php?pagina=${pagina}&t=${Date.now()}`, { cache: "no-store" })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    this.renderizarTablero(data);
                } else {
                    console.error('Error de API:', data.error);
                }
            })
            .catch(err => console.error('Error de conexión:', err));
    }

    iniciarAutoUpdate() {
        setInterval(() => {
            this.cargarDatos();
        }, 3000); // Revisa la API cada 3 segundos
    }

    // --- EL DIBUJANTE ---
    renderizarTablero(data) {
        // 1. Actualizar Estadísticas
        document.getElementById('stat-preparacion').innerText = data.estadisticas.en_preparacion;
        document.getElementById('stat-listos').innerText = data.estadisticas.listos;

        const wrapper = document.getElementById('pedidos-wrapper');
        const nuevosIds = [];

        // 2. Si no hay pedidos
        if (data.pedidos.length === 0) {
            wrapper.innerHTML = `
                <div class="col-12">
                    <div class="alert alert-success text-center">
                        <h4 class="alert-heading">¡Todo al día!</h4>
                        <p class="mb-0">No hay pedidos en la cola.</p>
                    </div>
                </div>`;
            document.getElementById('paginacion-wrapper').innerHTML = '';
            return;
        }

        // Si antes había el mensaje de "Cargando" o "Todo al día", lo limpiamos
        if (wrapper.innerHTML.includes('alert-success') || wrapper.innerHTML.includes('spinner-border')) {
            wrapper.innerHTML = '';
        }

        // 3. Procesar cada pedido
        data.pedidos.forEach(pedido => {
            const cardId = `pedido-${pedido.id}`;
            nuevosIds.push(cardId);
            let cardEl = document.getElementById(cardId);

            // Creamos una firma (hash) del pedido. Si algo cambia en BD, la firma cambia.
            const firmaActual = JSON.stringify(pedido);

            // Si el pedido no existe en pantalla, lo creamos
            if (!cardEl) {
                cardEl = document.createElement('div');
                cardEl.className = 'col-12 mb-3 pedido-item';
                cardEl.id = cardId;
                cardEl.dataset.firma = firmaActual; // Guardamos la firma
                cardEl.innerHTML = this.generarHTMLTarjeta(pedido);
                cardEl.style.animation = "fadeIn 0.5s";
                wrapper.appendChild(cardEl);
            } 
            // Si ya existe, comparamos la firma. ¡Si es igual, NO HACEMOS NADA! (Cero parpadeo)
            else if (cardEl.dataset.firma !== firmaActual) {
                console.log(`Actualizando pedido ${pedido.id} (Cambio detectado)`);
                
                // Guardamos si el cocinero tenía los detalles abiertos
                const detallesAbiertos = cardEl.querySelector('.detalles-pedido').classList.contains('mostrar');
                
                // Actualizamos el contenido
                cardEl.dataset.firma = firmaActual;
                cardEl.innerHTML = this.generarHTMLTarjeta(pedido);

                // Si estaban abiertos, los volvemos a abrir al instante
                if (detallesAbiertos) {
                    cardEl.querySelector('.detalles-pedido').classList.add('mostrar');
                    const btn = cardEl.querySelector('.btn-info');
                    if (btn) btn.innerHTML = '<i class="fas fa-times me-1"></i>Cerrar';
                }
            }
        });

        // 4. Eliminar pedidos viejos que ya no vinieron en el JSON (ej. marcados como listos)
        Array.from(wrapper.querySelectorAll('.pedido-item')).forEach(el => {
            if (!nuevosIds.includes(el.id)) {
                el.remove();
            }
        });

        // 5. Dibujar Paginación
        this.renderizarPaginacion(data.paginacion);
    }

    // --- CONSTRUCTOR DE HTML (TEMPLATES) ---
// --- CONSTRUCTOR DE HTML (TEMPLATES BLINDADO) ---
    generarHTMLTarjeta(p) {
        // 🔥 FORZAMOS LA LECTURA DEL BLOQUEO (Atrapa booleanos, strings o números)
        const estaBloqueado = (p.esta_bloqueado === true || p.esta_bloqueado === "true" || p.esta_bloqueado == 1);
        
        const claseBloqueo = estaBloqueado ? 'border-danger' : '';
        const opacidad = estaBloqueado ? 'opacity-50' : '';
        
        // Solo mostramos advertencia amarilla si es anexo y NO está listo
        const esAnexoActivo = (p.es_anexo == 1 && p.estado === 'en_preparacion');
        const claseAnexo = esAnexoActivo ? 'border-warning border-4' : '';
        
        const badgeColor = p.estado === 'en_preparacion' ? 'warning' : 'success';
        const badgeText = p.estado === 'en_preparacion' ? 'En Preparación' : 'Listo';
        const iconTitle = p.tipo_servicio === 'Llevar' ? '<i class="fas fa-shopping-bag me-2 text-success"></i>Pedido Para Llevar' : `<i class="fas fa-utensils me-2 text-primary"></i>Mesa ${p.mesa_numero}`;

        // EL BLOQUEO GRIS (Debe salir SIEMPRE que esté bloqueado)
        let overlayHTML = estaBloqueado ? `
            <div class="bloqueo-overlay d-flex align-items-center justify-content-center" 
                 style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; 
                         ">
                <div class="text-center">
                    <div class="spinner-grow text-light mb-2" role="status"></div>
                    <br>
                    <span class="badge bg-danger p-3 fs-5 shadow-lg border border-light">
                        <i class="fas fa-user-edit me-2"></i>MESERO EDITANDO...
                    </span>
                </div>
            </div>` : '';

        // EL MENSAJE DE ANEXO
        let anexoHTML = esAnexoActivo ? `
            <div class="alert alert-warning py-1 px-2 fw-bold text-center mb-3 animate__animated animate__flash">
                <i class="fas fa-exclamation-circle me-2"></i> ATENCIÓN: NUEVOS PRODUCTOS 
            </div>` : '';

        let btnAccion = p.estado === 'en_preparacion' 
            ? `<button type="button" class="btn btn-success btn-sm" ${estaBloqueado ? 'disabled' : ''} onclick="cocinaManager.marcarComoListo(${p.id})"><i class="fas fa-check me-1"></i>Marcar Listo</button>`
            : `<button type="button" class="btn btn-warning btn-sm" ${estaBloqueado ? 'disabled' : ''} onclick="cocinaManager.volverAPreparacion(${p.id})"><i class="fas fa-undo me-1"></i>Volver</button>`;

        // Construir lista de pizzas
        let pizzasHTML = '';
        if (p.pizzas && p.pizzas.length > 0) {
            pizzasHTML += `<h6 class="fw-bold border-bottom pb-2 mb-3 text-warning"><i class="fas fa-pizza-slice me-2"></i>Pizzas del Pedido</h6>`;
            p.pizzas.forEach((pizza, index) => {
                let ingHTML = '';
                if (pizza.ingredientes.length > 0) {
                    ingHTML = `<div class="ms-3 mt-2"><strong class="text-success small">Ingredientes:</strong><div>`;
                    pizza.ingredientes.forEach(ing => {
                        ingHTML += `<span class="badge bg-secondary me-1 mb-1">${ing}</span>`;
                    });
                    ingHTML += `</div></div>`;
                }
                pizzasHTML += `
                    <div class="pizza-individual mb-3 p-3 border rounded bg-light">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="fw-bold text-primary mb-0">Pizza ${index + 1} - <span class="badge bg-warning text-dark">${pizza.tamanio}</span></h6>
                        </div>
                        ${ingHTML}
                    </div>`;
            });
        }

        // Construir lista de bebidas
        let bebidasHTML = '';
        if (p.bebidas && p.bebidas.length > 0) {
            bebidasHTML += `<h6 class="fw-bold border-bottom pb-2 mb-3 text-info mt-3"><i class="fas fa-wine-bottle me-2"></i>Bebidas</h6><div class="row">`;
            p.bebidas.forEach(bebida => {
                bebidasHTML += `
                    <div class="col-md-6 col-lg-4 mb-2">
                        <div class="d-flex justify-content-between align-items-center p-2 border rounded bg-white">
                            <span class="small">${bebida.nombre}</span>
                            <span class="badge bg-dark">x${bebida.cantidad}</span>
                        </div>
                    </div>`;
            });
            bebidasHTML += `</div>`;
        }

        return `
            <div class="card pedido-card shadow-sm estado-${p.estado} ${claseBloqueo} ${claseAnexo}" style="position: relative; overflow: hidden;">
                ${overlayHTML}
                <div class="card-body">
                    ${anexoHTML}
                    <div class="row align-items-center ${opacidad}">
                        <div class="col-md-8">
                            <div class="d-flex align-items-center mb-2">
                                <h5 class="card-title mb-0 me-3">${iconTitle}</h5>
                                <span class="badge bg-${badgeColor}">${badgeText}</span>
                            </div>
                            <div class="row text-muted small">
                                <div class="col-sm-4"><strong>Mesero:</strong> ${p.mesero_nombre}</div>
                                <div class="col-sm-4"><strong>Hora:</strong> ${p.hora}</div>
                            </div>
                        </div>
                        <div class="col-md-4 text-end">
                            ${btnAccion}
                            <button class="btn btn-info btn-sm ms-1" ${estaBloqueado ? 'disabled' : ''} onclick="cocinaManager.toggleDetalles(${p.id})"><i class="fas fa-list me-1"></i>Detalles</button>
                        </div>
                    </div>
                    <div class="detalles-pedido mt-3" id="detalles-${p.id}">
                        ${pizzasHTML}
                        ${bebidasHTML}
                    </div>
                </div>
            </div>`;
    }

    renderizarPaginacion(pag) {
        const pagWrapper = document.getElementById('paginacion-wrapper');
        if (pag.total_paginas <= 1) {
            pagWrapper.innerHTML = '';
            return;
        }

        let html = '<ul class="pagination">';
        html += `<li class="page-item ${pag.actual <= 1 ? 'disabled' : ''}"><a class="page-link" href="?pagina=${pag.actual - 1}">Anterior</a></li>`;
        
        for (let i = 1; i <= pag.total_paginas; i++) {
            html += `<li class="page-item ${i === pag.actual ? 'active' : ''}"><a class="page-link" href="?pagina=${i}">${i}</a></li>`;
        }
        
        html += `<li class="page-item ${pag.actual >= pag.total_paginas ? 'disabled' : ''}"><a class="page-link" href="?pagina=${pag.actual + 1}">Siguiente</a></li>`;
        html += '</ul>';
        pagWrapper.innerHTML = html;
    }

    // --- ACCIONES DE USUARIO ---
    toggleDetalles(pedidoId) {
        const detalles = document.getElementById(`detalles-${pedidoId}`);
        const boton = event.currentTarget;
        if (!detalles) return;

        if (detalles.classList.contains('mostrar')) {
            detalles.classList.remove('mostrar');
            boton.innerHTML = '<i class="fas fa-list me-1"></i>Detalles';
        } else {
            this.ocultarTodosLosDetalles();
            detalles.classList.add('mostrar');
            boton.innerHTML = '<i class="fas fa-times me-1"></i>Cerrar';
            setTimeout(() => detalles.scrollIntoView({ behavior: 'smooth', block: 'nearest' }), 100);
        }
    }

    ocultarTodosLosDetalles() {
        document.querySelectorAll('.detalles-pedido.mostrar').forEach(det => det.classList.remove('mostrar'));
        document.querySelectorAll('.btn-info').forEach(btn => {
            if (btn.innerHTML.includes('Cerrar')) btn.innerHTML = '<i class="fas fa-list me-1"></i>Detalles';
        });
    }

    marcarComoListo(id) { this.confirmarAccion(id, 'listo', '¿Marcar listo?', 'success', '#198754'); }
    volverAPreparacion(id) { this.confirmarAccion(id, 'en_preparacion', '¿Volver a preparación?', 'warning', '#ffc107'); }

    confirmarAccion(id, estado, titulo, icono, color) {
        Swal.fire({
            title: titulo, icon: icono, showCancelButton: true, confirmButtonColor: color, confirmButtonText: 'Sí', cancelButtonText: 'No'
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({ title: 'Procesando...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
                
                const fd = new FormData();
                fd.append('comanda_id', id);
                fd.append('estado', estado);
                
                fetch('tablero.php', { method: 'POST', body: fd })
                .then(() => {
                    Swal.close();
                    // NO RECARGAMOS LA PÁGINA. Simplemente pedimos los datos nuevos a la API al instante.
                    this.cargarDatos(); 
                });
            }
        });
    }
}

// Iniciar cuando el DOM cargue
document.addEventListener('DOMContentLoaded', () => {
    window.cocinaManager = new CocinaManager();
});