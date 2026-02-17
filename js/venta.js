class VentaManager {
    constructor() {
        this.preciosBase = window.preciosBase;
        this.tasaDolar = window.tasaDolar || 1;
        this.orderList = [];
        this.currentTipoServicio = null;
        this.notificacionesMostradas = new Set();
        
        // --- VARIABLES NUEVAS PARA CLIENTES ---
        this.clienteSeleccionadoId = null;

        // VARIABLES CRÍTICAS PARA EDICIÓN
        this.editandoComandaId = null; 
        // varibale para añadir 
        this.adicionandoComandaId = null;

        window.addEventListener('beforeunload', () => {
            if (this.editandoComandaId) {
                navigator.sendBeacon(`./historial_manager.php?action=desbloquear&id=${this.editandoComandaId}`);
            }
        });

        this.indexSiendoEditado = null; 
        
        if (document.getElementById('history-list')) {
            this.init();
        } else {
            this.setupEventListeners();
            this.updatePreciosDisplay();
        }
    }

    init() {
        this.setupEventListeners();
        this.updatePreciosDisplay();
        this.updateCurrentItemTotal();
        this.actualizarUI();
        this.cargarHistorialPedidos();
        
        // Cargar clientes frecuentes al iniciar
        this.cargarClientesFrecuentes();

        setInterval(() => this.verificarPedidosListos(), 5000); 
    }

    setupEventListeners() {
        // --- LISTENERS GENERALES ---
        document.querySelectorAll('.servicio-option').forEach(option => {
            option.addEventListener('click', (e) => this.selectTipoServicio(e));
        });

        document.querySelectorAll('.mesa').forEach(mesa => {
            mesa.addEventListener('click', (e) => this.selectMesa(e));
        });

        // Botones de "Volver"
        const btnBackServicio = document.getElementById('back-to-servicio-btn');
        if(btnBackServicio) btnBackServicio.addEventListener('click', () => this.backToServicioType());
        
        const btnBackTables = document.getElementById('back-to-tables-btn');
        if(btnBackTables) btnBackTables.addEventListener('click', () => this.backToTables());
        
        // Inputs de Pizza (Tamaño)
        document.querySelectorAll('input[name="tamanio"]').forEach(input => {
            input.addEventListener('change', () => {
                this.updatePreciosDisplay();
                this.updateCurrentItemTotal();
            });
        });

        // Inputs de Ingredientes/Bebidas
        document.querySelectorAll('.ingrediente-checkbox, .bebida-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', () => this.updateCurrentItemTotal());
        });

        // Botones de Acción del Formulario
        document.getElementById('agregar-btn').addEventListener('click', () => this.addToOrder());
        document.getElementById('pizzaForm').addEventListener('submit', (e) => {
            e.preventDefault();
            this.submitOrder();
        });

        // --- NUEVOS LISTENERS PARA GESTIÓN DE CLIENTES ---
        
        // 1. Buscador de Clientes
        const inputBuscar = document.getElementById('input-buscar-cliente');
        if(inputBuscar){
            inputBuscar.addEventListener('input', (e) => this.buscarCliente(e.target.value));
        }

        // 2. Botón "Continuar al Menú" (desde sección cliente)
        const btnSiguiente = document.getElementById('btn-siguiente-menu');
        if(btnSiguiente){
            btnSiguiente.addEventListener('click', () => this.irAlMenu());
        }

        // Helpers globales
        window.toggleSidebar = (id) => {
            const el = document.getElementById(id);
            if(el) el.classList.toggle('active');
        };

        window.limpiarNotificaciones = () => this.limpiarNotificaciones();
        
        // Exponer funciones necesarias al scope global para onclicks en HTML
        window.ventaManagerInstance = this;
        window.toggleNuevoClienteForm = () => this.toggleNuevoClienteForm();
        window.guardarNuevoCliente = () => this.guardarNuevoCliente();
        window.resetClienteSelection = () => this.resetClienteSelection();
        window.irAlMenu = () => this.irAlMenu();
    }

  iniciarModoAdicion(id, mesaId, tipo, nombreCliente, clienteId) { // <--- 1. Agregamos clienteId aquí
        // 1. Limpiar estado previo
        this.adicionandoComandaId = id;
        this.editandoComandaId = null; 
        this.orderList = []; 

        // 2. IMPORTANTE: Guardamos el cliente para pasar la validación
        this.clienteSeleccionadoId = clienteId || null; // <--- 2. Lo guardamos en la variable de clase

        // Configurar UI
        this.currentTipoServicio = tipo;
        document.getElementById('tipo_servicio_input').value = tipo;
        document.getElementById('mesa_id_input').value = mesaId || 0;

        // Mostrar pantalla de pedido
        document.getElementById('servicio-type-section').style.display = 'none';
        document.getElementById('table-layout-section').style.display = 'none';
        const seccionCliente = document.getElementById('seccion-cliente-llevar');
        if(seccionCliente) seccionCliente.style.display = 'none';
        document.getElementById('order-form-section').style.display = 'block';

        // Título distintivo
        const identificador = tipo === 'Mesa' ? `Mesa ${mesaId}` : (nombreCliente || 'Cliente');
        document.getElementById('main-title').innerHTML = `
            <span class="text-warning"><i class="fas fa-plus-circle"></i> AGREGAR A:</span> ${identificador}
        `;
        
        // Si hay nombre, lo mostramos visualmente en el html oculto (opcional pero útil)
        if(nombreCliente) {
             const lbl = document.getElementById('sel-cliente-nombre');
             if(lbl) lbl.innerText = nombreCliente;
        }

        this.updateFullOrderSummary();
        this.showToast('Modo: Agregar productos extra', 'info');
    }

    // ==========================================
    // SECCIÓN 1: SELECCIÓN DE SERVICIO
    // ==========================================

    selectTipoServicio(event) {
        const option = event.currentTarget;
        this.currentTipoServicio = option.getAttribute('data-servicio-type');
        document.getElementById('tipo_servicio_input').value = this.currentTipoServicio;
        document.getElementById('servicio-type-section').style.display = 'none';

        if (this.currentTipoServicio === 'Mesa') {
            document.getElementById('table-layout-section').style.display = 'block';
            document.getElementById('main-title').textContent = 'Selecciona una Mesa';
            // Ocultar sección cliente si estaba visible
            const seccionCliente = document.getElementById('seccion-cliente-llevar');
            if(seccionCliente) seccionCliente.style.display = 'none';
        } else {
            this.startTakeAwayOrder();
        }
    }

    startTakeAwayOrder() {
        document.getElementById('mesa_id_input').value = 0;
        document.getElementById('main-title').textContent = 'Identificar Cliente';
        
        // MOSTRAR LA NUEVA SECCIÓN DE CLIENTES
        const seccionCliente = document.getElementById('seccion-cliente-llevar');
        if(seccionCliente) {
            seccionCliente.style.display = 'block';
            document.getElementById('input-buscar-cliente').focus();
        }
        
        // Asegurar que el formulario de pedido esté oculto por ahora
        document.getElementById('order-form-section').style.display = 'none';
        
        // Resetear selección previa si no estamos editando
        if (!this.editandoComandaId) {
            this.resetClienteSelection();
        }
    }
backToServicioType() {
        // 1. Mostrar pantalla inicial y ocultar TODO lo demás
        document.getElementById('servicio-type-section').style.display = 'block';
        document.getElementById('table-layout-section').style.display = 'none';
        document.getElementById('order-form-section').style.display = 'none';
        document.getElementById('seccion-cliente-llevar').style.display = 'none';
        
        // 2. Restaurar el título original
        document.getElementById('main-title').textContent = 'Tipo de Servicio';
        
        // 3. Desbloquear pedido en BD si estábamos en edición
        if (this.editandoComandaId) {
             fetch(`./historial_manager.php?action=desbloquear&id=${this.editandoComandaId}`);
        }
        
        // 4. Limpiar TODAS las variables de estado
        this.orderList = [];
        this.editandoComandaId = null;
        this.adicionandoComandaId = null; 
        this.indexSiendoEditado = null;
        this.clienteSeleccionadoId = null;
        
        // 5. Limpiar el formulario visualmente (checkboxes, totales)
        this.limpiarFormulario();
        this.updateFullOrderSummary();
    }
backToTables() {
        // SI estamos editando un pedido O agregando productos extra (Anexo)
        // forzamos el regreso al inicio total.
        if (this.editandoComandaId || this.adicionandoComandaId) {
            this.backToServicioType();
        } else {
            // Comportamiento normal para pedidos nuevos
            if (this.currentTipoServicio === 'Mesa') {
                document.getElementById('table-layout-section').style.display = 'block';
                document.getElementById('order-form-section').style.display = 'none';
                document.getElementById('main-title').textContent = 'Selecciona una Mesa';
            } else {
                document.getElementById('order-form-section').style.display = 'none';
                document.getElementById('seccion-cliente-llevar').style.display = 'block';
                document.getElementById('main-title').textContent = 'Identificar Cliente';
            }
        }
    }

    // ==========================================
    // SECCIÓN 2: GESTIÓN DE CLIENTES (NUEVO)
    // ==========================================

    buscarCliente(termino) {
        const lista = document.getElementById('lista-resultados-clientes');
        if (!lista) return;

        if (termino.length < 2) {
            lista.innerHTML = '';
            return;
        }

        fetch(`api_buscar_cliente.php?term=${termino}`)
            .then(res => res.json())
            .then(data => {
                lista.innerHTML = '';
                if (data.length === 0) {
                    lista.innerHTML = '<div class="p-3 text-muted text-center small">No encontrado. <br>¡Crea uno nuevo!</div>';
                    return;
                }
                data.forEach(cliente => {
                    const item = document.createElement('div');
                    item.className = 'result-item';
                    const extra = cliente.cedula ? `C.I: ${cliente.cedula}` : `Tel: ${cliente.telefono}`;
                    item.innerHTML = `<strong>${cliente.nombre}</strong> <small>${extra}</small>`;
                    item.onclick = () => this.seleccionarCliente(cliente);
                    lista.appendChild(item);
                });
            })
            .catch(err => console.error("Error buscando cliente:", err));
    }

    seleccionarCliente(cliente) {
        this.clienteSeleccionadoId = cliente.id;

        // Llenar datos visuales
        document.getElementById('sel-cliente-nombre').innerText = cliente.nombre;
        let detalles = [];
        if(cliente.cedula) detalles.push(`C.I: ${cliente.cedula}`);
        if(cliente.telefono) detalles.push(`Tel: ${cliente.telefono}`);
        document.getElementById('sel-cliente-detalle').innerText = detalles.length > 0 ? detalles.join(' | ') : 'Sin datos extra';
        
        const divDir = document.getElementById('sel-cliente-direccion');
        if(divDir) divDir.innerText = cliente.direccion ? cliente.direccion : '';

        // Input oculto (fallback)
        const hiddenInput = document.getElementById('input_cliente_id_hidden');
        if(hiddenInput) hiddenInput.value = cliente.id;

        // Cambios de UI
        const searchWrapper = document.querySelector('.search-box-wrapper');
        if(searchWrapper) searchWrapper.style.display = 'none';
        
        const frequentSection = document.querySelector('.frequent-clients-section');
        if(frequentSection) frequentSection.style.display = 'none';

        document.getElementById('cliente-seleccionado-info').style.display = 'flex';
        
        // Habilitar botón continuar
        const btnNext = document.getElementById('btn-siguiente-menu');
        if(btnNext) {
            btnNext.disabled = false;
            btnNext.classList.remove('btn-secondary'); // Si tuviera estilo deshabilitado visual
        }
    }

    resetClienteSelection() {
        this.clienteSeleccionadoId = null;
        const hiddenInput = document.getElementById('input_cliente_id_hidden');
        if(hiddenInput) hiddenInput.value = '';
        
        const searchWrapper = document.querySelector('.search-box-wrapper');
        if(searchWrapper) searchWrapper.style.display = 'block';
        
        const inputBuscar = document.getElementById('input-buscar-cliente');
        if(inputBuscar) inputBuscar.value = '';
        
        const listaResultados = document.getElementById('lista-resultados-clientes');
        if(listaResultados) listaResultados.innerHTML = '';

        const frequentSection = document.querySelector('.frequent-clients-section');
        if(frequentSection) frequentSection.style.display = 'block';

        document.getElementById('cliente-seleccionado-info').style.display = 'none';
        
        const btnNext = document.getElementById('btn-siguiente-menu');
        if(btnNext) btnNext.disabled = true;
    }

    cargarClientesFrecuentes() {
        fetch('api_clientes_frecuentes.php')
            .then(res => res.json())
            .then(data => {
                const grid = document.getElementById('grid-clientes-frecuentes');
                if(!grid) return;
                grid.innerHTML = '';
                data.forEach(cliente => {
                    const card = document.createElement('div');
                    card.className = 'freq-card';
                    card.innerHTML = `
                        <div class="mb-2"><i class="fas fa-user-circle fa-2x text-secondary"></i></div>
                        <div style="font-weight:600; font-size:0.85rem; line-height:1.2;">${cliente.nombre}</div>
                    `;
                    card.onclick = () => this.seleccionarCliente(cliente);
                    grid.appendChild(card);
                });
            })
            .catch(e => console.log("No hay clientes frecuentes aún"));
    }

    toggleNuevoClienteForm() {
        const form = document.getElementById('form-nuevo-cliente');
        if(form) form.style.display = (form.style.display === 'none') ? 'block' : 'none';
    }

    guardarNuevoCliente() {
        const nombre = document.getElementById('new-nombre').value;
        const cedula = document.getElementById('new-cedula').value;
        const telefono = document.getElementById('new-telefono').value;
        const direccion = document.getElementById('new-direccion').value;

        if (!nombre) return Swal.fire('Error', "El nombre es obligatorio", 'warning');

        fetch('guardar_cliente.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ nombre, cedula, telefono, direccion })
        })
        .then(res => res.json())
        .then(res => {
            if (res.success) {
                // Seleccionamos automáticamente al nuevo cliente
                this.seleccionarCliente({ 
                    id: res.id, 
                    nombre, 
                    cedula, 
                    telefono, 
                    direccion 
                });
                this.toggleNuevoClienteForm(); 
                
                // Limpiar form
                document.getElementById('new-nombre').value = '';
                document.getElementById('new-cedula').value = '';
                document.getElementById('new-telefono').value = '';
                document.getElementById('new-direccion').value = '';
                
                this.showToast('Cliente registrado correctamente', 'success');
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        });
    }

    irAlMenu() {
        if(!this.clienteSeleccionadoId) {
            return Swal.fire('Atención', 'Debes seleccionar un cliente primero.', 'warning');
        }

        // Ocultar sección cliente
        document.getElementById('seccion-cliente-llevar').style.display = 'none';
        
        // Mostrar Menú
        document.getElementById('order-form-section').style.display = 'block';
        document.getElementById('current-servicio-display').textContent = 'Para Llevar - ' + document.getElementById('sel-cliente-nombre').innerText;
        document.getElementById('main-title').textContent = 'Tomar Pedido';
    }

    selectMesa(event) {
        const mesa = event.currentTarget;
        if (mesa.classList.contains('mesa-ocupada') || mesa.hasAttribute('disabled')) {
            this.showToast('Esta mesa ya está ocupada', 'warning');
            return; 
        }
        const mesaId = mesa.getAttribute('data-mesa-id');
        const mesaNumero = mesa.getAttribute('data-mesa-numero');
        document.getElementById('mesa_id_input').value = mesaId;
        document.getElementById('current-servicio-display').textContent = 'Mesa ' + mesaNumero;
        document.getElementById('table-layout-section').style.display = 'none';
        document.getElementById('order-form-section').style.display = 'block';
        document.getElementById('main-title').textContent = 'Pedido Mesa ' + mesaNumero;
        
        // Asegurar que la sección cliente esté oculta
        const seccionCliente = document.getElementById('seccion-cliente-llevar');
        if(seccionCliente) seccionCliente.style.display = 'none';
        
        this.clienteSeleccionadoId = null; // No hay cliente específico en mesa (por ahora)
        this.showToast('Tomando pedido para Mesa ' + mesaNumero, 'info');
    }

    // ==========================================
    // SECCIÓN 3: LOGICA DEL CARRITO (EXISTENTE)
    // ==========================================

    updatePreciosDisplay() {
        const tamanioElement = document.querySelector('input[name="tamanio"]:checked');
        if (!tamanioElement) return;
        const tamanio = tamanioElement.value;
        document.querySelectorAll('.ingrediente-checkbox').forEach(checkbox => {
            const precios = JSON.parse(checkbox.getAttribute('data-precios'));
            const span = document.querySelector(`.precios-display[data-id="${checkbox.value}"]`);
            if (precios && precios[tamanio] && span) {
                span.textContent = `$${precios[tamanio].toFixed(2)}`;
            }
        });
        document.querySelectorAll('.bebida-checkbox').forEach(checkbox => {
            const precios = JSON.parse(checkbox.getAttribute('data-precios'));
            const span = document.querySelector(`.precios-display-bebida[data-id="${checkbox.value}"]`);
            if (precios && precios['Pequena'] && span) {
                span.textContent = `$${precios['Pequena'].toFixed(2)}`;
            }
        });
    }

    updateCurrentItemTotal() {
        const radio = document.querySelector('input[name="tamanio"]:checked');
        if(!radio) return;
        const tamanio = radio.value;
        let total = this.preciosBase[tamanio];
        const lista = document.getElementById('factura-list-current');
        lista.innerHTML = '';

        const itemBase = document.createElement('li');
        itemBase.className = 'list-group-item d-flex justify-content-between align-items-center px-0';
        itemBase.innerHTML = `<span>${this.preciosBase.nombre} (${tamanio})</span><span class="fw-bold">$${total.toFixed(2)}</span>`;
        lista.appendChild(itemBase);

        document.querySelectorAll('.ingrediente-checkbox:checked').forEach(checkbox => {
            const precios = JSON.parse(checkbox.getAttribute('data-precios'));
            const nombre = checkbox.getAttribute('data-nombre');
            const precio = precios[tamanio];
            total += precio;
            const item = document.createElement('li');
            item.className = 'list-group-item d-flex justify-content-between align-items-center px-0';
            item.innerHTML = `<span>${nombre}</span><span class="fw-bold">$${precio.toFixed(2)}</span>`;
            lista.appendChild(item);
        });

        document.querySelectorAll('.bebida-checkbox:checked').forEach(checkbox => {
            const precios = JSON.parse(checkbox.getAttribute('data-precios'));
            const nombre = checkbox.getAttribute('data-nombre');
            const precio = precios['Pequena'];
            total += precio;
            const item = document.createElement('li');
            item.className = 'list-group-item d-flex justify-content-between align-items-center px-0 text-info';
            item.innerHTML = `<span>${nombre}</span><span class="fw-bold">$${precio.toFixed(2)}</span>`;
            lista.appendChild(item);
        });
        this.actualizarTotalBolivares(total);
    }

    actualizarTotalBolivares(totalDolares) {
        const totalBsDisplay = document.getElementById('total-bs-display');
        const totalBolivares = totalDolares * this.tasaDolar;
        if(totalBsDisplay) totalBsDisplay.textContent = totalBolivares.toFixed(2);
    }

addToOrder() {
        const radioTamanio = document.querySelector('input[name="tamanio"]:checked');
        if(!radioTamanio) return;
        const tamanio = radioTamanio.value;
        const ingredientes = Array.from(document.querySelectorAll('.ingrediente-checkbox:checked'));
        const bebidas = Array.from(document.querySelectorAll('.bebida-checkbox:checked'));

        // Preparamos el objeto Pizza (por si acaso se usa)
        const pizza = {
            tipo: 'Pizza',
            id_base: this.preciosBase.id,
            tamanio: tamanio,
            precio_base: this.preciosBase[tamanio],
            ingredientes: ingredientes.map(ing => ({
                id: ing.value,
                nombre: ing.getAttribute('data-nombre'),
                precio: JSON.parse(ing.getAttribute('data-precios'))[tamanio]
            }))
        };

        // --- CASO 1: EDICIÓN (Se mantiene igual) ---
        if (this.indexSiendoEditado !== null) {
            this.orderList[this.indexSiendoEditado] = pizza;
            this.indexSiendoEditado = null; 
            const btn = document.getElementById('agregar-btn');
            if (btn) {
                btn.innerHTML = '<i class="fas fa-plus-circle me-2"></i>Agregar al Pedido';
                btn.classList.replace('btn-primary', 'btn-outline-secondary');
            }
            this.showToast('Pizza actualizada', 'success');
            this.limpiarFormulario(); 
            this.updateCurrentItemTotal();
            this.updateFullOrderSummary();
            return;
        } 

        // --- CASO 2: AGREGADO AUTOMÁTICO INTELIGENTE ---
        
        // A. Si seleccionaste ingredientes, quieres PIZZA (+ Bebidas si las hay)
        if (ingredientes.length > 0) {
            this.orderList.push(pizza);
            this.agregarBebidasAlCarrito(bebidas);
            this.showToast('Pizza y bebidas agregadas', 'success');
        } 
        // B. Si NO hay ingredientes pero SÍ hay bebidas, quieres SOLO BEBIDAS
        else if (bebidas.length > 0) {
            this.agregarBebidasAlCarrito(bebidas);
            this.showToast('Bebidas agregadas', 'success');
        }
        // C. Si no hay nada seleccionado, asumimos que quieres una Pizza Base sola
        else {
            this.orderList.push(pizza);
            this.showToast('Pizza Base agregada', 'success');
        }

        this.limpiarFormulario();
        this.updateCurrentItemTotal();
        this.updateFullOrderSummary();
    }

// Función auxiliar para meter las bebidas al array
    agregarBebidasAlCarrito(bebidas) {
        bebidas.forEach(beb => {
            const precios = JSON.parse(beb.getAttribute('data-precios'));
            this.orderList.push({
                tipo: 'Bebida',
                id: beb.value,
                nombre: beb.getAttribute('data-nombre'),
                precio: precios['Pequena']
            });
        });
    }

    // Función para limpiar checkboxes y reiniciar formulario
    limpiarFormulario() {
        document.querySelectorAll('.ingrediente-checkbox, .bebida-checkbox').forEach(cb => cb.checked = false);
        // Reseteamos el tamaño a pequeña
        const radioPequena = document.getElementById('pequena');
        if(radioPequena) radioPequena.checked = true;
    }

    updateFullOrderSummary() {
        const lista = document.getElementById('factura-list-full');
        const totalDisplay = document.getElementById('total-display');
        if(!lista) return;
        lista.innerHTML = '';
        let total = 0;

        this.orderList.forEach((item, index) => {
            let subtotal = 0;
            if (item.tipo === 'Pizza') {
                subtotal = item.precio_base + item.ingredientes.reduce((sum, ing) => sum + ing.precio, 0);
                total += subtotal;
                const li = document.createElement('li');
                li.className = 'list-group-item d-flex justify-content-between align-items-center px-0 bg-transparent';
                li.innerHTML = `<div class="d-flex align-items-center"><button type="button" class="btn btn-sm btn-link text-primary p-0 me-2" onclick="window.ventaManagerInstance.cargarItemParaEditar(${index})"><i class="fas fa-pen"></i></button><span>Pizza ${index + 1} (${item.tamanio})</span></div><span class="fw-bold">$${subtotal.toFixed(2)}</span>`;
                lista.appendChild(li);
            } else {
                subtotal = item.precio;
                total += subtotal;
                const li = document.createElement('li');
                li.className = 'list-group-item d-flex justify-content-between align-items-center px-0 bg-transparent';
                li.innerHTML = `<div><button type="button" class="btn btn-sm btn-link text-danger p-0 me-2" onclick="window.ventaManagerInstance.eliminarItemCarrito(${index})"><i class="fas fa-times"></i></button><span>${item.nombre}</span></div><span class="fw-bold">$${subtotal.toFixed(2)}</span>`;
                lista.appendChild(li);
            }
        });
        if(totalDisplay) totalDisplay.textContent = total.toFixed(2);
        this.actualizarTotalBolivares(total);
    }
    
    eliminarItemCarrito(index) {
        this.orderList.splice(index, 1);
        this.updateFullOrderSummary();
    }

async submitOrder() {
        // 1. Validar que haya productos
        if (this.orderList.length === 0) {
            this.showToast('Agrega al menos un artículo al pedido', 'warning');
            return;
        }

        const mesaId = document.getElementById('mesa_id_input').value;
        const tipoServicio = document.getElementById('tipo_servicio_input').value;

        // 2. VALIDACIÓN: Que el cliente esté seleccionado (Solo Para Llevar)
        if (tipoServicio === 'Llevar' && !this.clienteSeleccionadoId) {
            Swal.fire('Atención', 'Debe seleccionar un cliente antes de enviar el pedido.', 'warning');
            return;
        }

        // ============================================================
        // 3. CONFIRMACIÓN VISUAL (SOLO PARA LLEVAR)
        // ============================================================
        if (tipoServicio === 'Llevar') {
            const nombreCliente = document.getElementById('sel-cliente-nombre').innerText;
            const totalDisplay = document.getElementById('total-display').innerText;
            const totalBs = document.getElementById('total-bs-display').innerText;

            const confirmacion = await Swal.fire({
                title: '¿Confirmar Pedido?',
                html: `
                    <div style="text-align: left;">
                        <p class="mb-1"><strong>Cliente:</strong> ${nombreCliente}</p>
                        <p class="mb-1"><strong>Total:</strong> <span class="text-success fw-bold">$${totalDisplay}</span></p>
                        <p class="mb-3 text-muted small">(Bs. ${totalBs})</p>
                        <hr>
                        <div class="alert alert-warning py-2 small">
                            <i class="fas fa-motorcycle"></i> Verifica que los datos sean correctos.
                        </div>
                    </div>
                `,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Sí, Enviar a Cocina',
                cancelButtonText: 'Revisar',
                confirmButtonColor: '#ff9800', // Naranja Kpizzas
                cancelButtonColor: '#6c757d',
                reverseButtons: true
            });

            if (!confirmacion.isConfirmed) return; // Si cancela, no hacemos nada
        }
        // ============================================================

        
        // 4. Preparar datos para el envío
        const orderData = {
            mesa_id: parseInt(mesaId),
            tipo_servicio: tipoServicio,
            pedido: this.orderList,
            edit_id: this.editandoComandaId,
            
            // --- NUEVO: Enviamos el ID si es un anexo ---
            adicion_id: this.adicionandoComandaId,
            // --------------------------------------------
            
            // Enviamos el ID del cliente seleccionado
            cliente_id: this.clienteSeleccionadoId,
            
            // Campos legacy (vacíos porque usamos ID)
            cliente_cedula: '',
            cliente_nombre: ''
        };

        // 5. Enviar al Backend
        try {
            Swal.fire({ 
                title: 'Enviando pedido...', 
                text: 'Por favor espere', 
                allowOutsideClick: false, 
                didOpen: () => { Swal.showLoading(); } 
            });
            
            const response = await fetch('venta.php', { 
                method: 'POST', 
                headers: { 'Content-Type': 'application/json' }, 
                body: JSON.stringify(orderData) 
            });
            
            const result = await response.json();
            Swal.close();
            
            if (result.success) {
                this.showSuccessAlert(result.message, result.comanda_id);
                
                // Resetear todo
                this.orderList = [];
                this.editandoComandaId = null;
                
                // --- NUEVO: Limpiamos la variable de anexo ---
                this.adicionandoComandaId = null;
                // ---------------------------------------------

                this.indexSiendoEditado = null;
                this.clienteSeleccionadoId = null;
                this.updateFullOrderSummary();
                this.cargarHistorialPedidos(); 
                
                // Actualizar lista de frecuentes
                this.cargarClientesFrecuentes();
            } else {
                this.showErrorAlert('Error al enviar el pedido', result.error);
            }
        } catch (error) {
            Swal.close();
            console.error(error);
            this.showErrorAlert('Error de conexión', 'No se pudo conectar con el servidor');
        }
    }

    showToast(message, type) {
        const Toast = Swal.mixin({ toast: true, position: 'top-end', showConfirmButton: false, timer: 3000, timerProgressBar: true });
        Toast.fire({ icon: type, title: message });
    }

    showSuccessAlert(message, comandaId) {
        const alertHtml = `<p class="fs-5">${message}</p><hr><h3 class="fw-bold text-uppercase">N° Pedido: ${comandaId}</h3>`;
        Swal.fire({ icon: 'success', title: '¡Éxito!', html: alertHtml, confirmButtonText: 'Aceptar', confirmButtonColor: '#D82626' }).then((result) => {
            if (result.isConfirmed) { this.backToServicioType(); }
        });
    }

    showErrorAlert(title, message) {
        Swal.fire({ icon: 'error', title: title, text: message, confirmButtonColor: '#D82626' });
    }
    
    // ==========================================
    // SECCIÓN 4: EDICIÓN DE PEDIDOS
    // ==========================================

    async iniciarModoEdicionPagina(id) {
        try {
            const blockRes = await fetch(`./historial_manager.php?action=bloquear&id=${id}`);
            const blockData = await blockRes.json();
            if (!blockData.success) {
                Swal.fire('Error', blockData.error || 'El pedido ya está siendo editado', 'error');
                return;
            }
            this.editandoComandaId = id;
            const response = await fetch(`./historial_manager.php?action=obtener_detalle&id=${id}`);
            const data = await response.json();

            if (data.success) {
                this.orderList = [];
                let currentPizza = null;
                data.detalles.forEach(d => {
                    if (d.tipo_categoria === 'Pizza Base') {
                        currentPizza = { tipo: 'Pizza', id_base: d.producto_id, tamanio: d.tamanio, precio_base: parseFloat(d.precio_unitario), ingredientes: [] };
                        this.orderList.push(currentPizza);
                    } else if (d.tipo_categoria === 'Ingrediente' && currentPizza) {
                        currentPizza.ingredientes.push({ id: d.producto_id, nombre: d.nombre, precio: parseFloat(d.precio_unitario) });
                    } else if (d.tipo_categoria === 'Bebida') {
                        this.orderList.push({ tipo: 'Bebida', id: d.producto_id, nombre: d.nombre, precio: parseFloat(d.precio_unitario) });
                    }
                });
                
                // Configurar entorno de edición
                this.currentTipoServicio = data.comanda.tipo_servicio;
                document.getElementById('mesa_id_input').value = data.comanda.mesa_id || 0;
                document.getElementById('tipo_servicio_input').value = data.comanda.tipo_servicio;
                
                // Si es para llevar, cargar el cliente
                if(data.comanda.tipo_servicio === 'Llevar' && data.comanda.cliente_id) {
                     this.clienteSeleccionadoId = data.comanda.cliente_id;
                     // Podríamos hacer un fetch para traer el nombre del cliente si es necesario mostrarlo
                     // Por ahora mostramos "Cliente ID: X" o lo que venga en data.comanda si el backend lo envía
                     // Si data.comanda tiene nombre_cliente:
                     if(data.comanda.nombre_cliente) {
                         document.getElementById('sel-cliente-nombre').innerText = data.comanda.nombre_cliente;
                     }
                }

                document.getElementById('servicio-type-section').style.display = 'none';
                document.getElementById('table-layout-section').style.display = 'none';
                // En edición saltamos la selección de cliente y vamos directo al menú
                document.getElementById('seccion-cliente-llevar').style.display = 'none';
                document.getElementById('order-form-section').style.display = 'block';
                
                document.getElementById('main-title').textContent = `Editando Pedido #${id}`;
                this.updateFullOrderSummary();
                this.showToast('Pedido cargado para edición', 'info');
            }
        } catch (e) {
            if (this.editandoComandaId) {
                fetch(`./historial_manager.php?action=desbloquear&id=${this.editandoComandaId}`);
                this.editandoComandaId = null;
            }
        }
    }

    cargarItemParaEditar(index) {
        this.indexSiendoEditado = index;
        const item = this.orderList[index];
        if (item.tipo === 'Bebida') {
            this.showToast('Las bebidas no tienen ingredientes editables', 'warning');
            return;
        }
        document.querySelectorAll('.ingrediente-checkbox').forEach(cb => cb.checked = false);
        const radio = document.querySelector(`input[name="tamanio"][value="${item.tamanio}"]`);
        if (radio) {
            radio.checked = true;
            this.updatePreciosDisplay();
        }
        if (item.ingredientes) {
            item.ingredientes.forEach(ing => {
                const checkbox = document.querySelector(`.ingrediente-checkbox[value="${ing.id}"]`);
                if (checkbox) checkbox.checked = true;
            });
        }
        const btn = document.getElementById('agregar-btn');
        if (btn) {
            btn.innerHTML = `<i class="fas fa-sync me-2"></i>Actualizar Pizza ${index + 1}`;
            btn.classList.replace('btn-outline-secondary', 'btn-primary');
        }
        document.getElementById('pizzaForm').scrollIntoView({ behavior: 'smooth' });
        this.updateCurrentItemTotal();
    }

    // ==========================================
    // SECCIÓN 5: UI & NOTIFICACIONES
    // ==========================================

    async actualizarUI() {
        try {
            const response = await fetch('./notificaciones_manager.php');
            const result = await response.json();
            const lista = document.getElementById('notif-list');
            const contador = document.getElementById('notif-count');
            if(!lista || !contador) return;
            lista.innerHTML = '';
            if (result.notificaciones) {
                result.notificaciones.forEach(n => {
                    lista.innerHTML += `<div class="notif-item shadow-sm p-2 mb-2 bg-white border rounded"><strong>${n.mensaje}</strong><br><small class="text-muted">${n.fecha_creacion}</small></div>`;
                });
                contador.textContent = result.notificaciones.length;
                contador.style.display = result.notificaciones.length > 0 ? 'flex' : 'none';
            }
        } catch (error) { console.error(error); }
    }

    async verificarPedidosListos() {
        try {
            const response = await fetch('../cocina/mesero_notificaciones.php');
            const result = await response.json();
            if (result.success && result.pedidos.length > 0) {
                for (const pedido of result.pedidos) {
                    if (!this.notificacionesMostradas.has(pedido.id)) {
                        this.notificacionesMostradas.add(pedido.id);
                        let identificador = pedido.tipo_servicio === 'Mesa' ? `Mesa #${pedido.mesa_id}` : (pedido.cliente_nombre || 'Cliente');
                        const msg = `¡Pedido de ${identificador} listo!`;
                        const fd = new FormData();
                        fd.append('action', 'guardar'); fd.append('mensaje', msg); fd.append('pedido_id', pedido.id);
                        await fetch('./notificaciones_manager.php', { method: 'POST', body: fd });
                        Swal.fire({ title: '¡Pedido Listo!', text: msg, icon: 'success', timer: 5000, showConfirmButton: false, toast: true, position: 'top-end' });
                        const sonido = document.getElementById('notificacion-sonido');
                        if(sonido) { sonido.currentTime = 0; sonido.play().catch(e => {}); }
                        this.actualizarUI();
                    }
                }
            }
        } catch (error) { console.error(error); }
    }

    async limpiarNotificaciones() {
        const result = await Swal.fire({ title: '¿Borrar todo?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#000' });
        if (result.isConfirmed) {
            const fd = new FormData(); fd.append('action', 'borrar_todo');
            await fetch('./notificaciones_manager.php', { method: 'POST', body: fd });
            this.actualizarUI();
        }
    }
async cargarHistorialPedidos() {
        try {
            const response = await fetch('./historial_manager.php');
            const result = await response.json();
            const lista = document.getElementById('history-list');
            if(!lista) return;
            
            lista.innerHTML = '';
            if (result.pedidos) {
                result.pedidos.forEach(p => {
                    // 1. Configuración de Estados y Colores (Bootstrap classes)
                    const estadoConfig = {
                        'pendiente':      { color: 'bg-secondary', texto: 'Pendiente', icono: 'fa-clock', borde: '#6c757d' },
                        'en_preparacion': { color: 'bg-warning text-dark', texto: 'Cocinando', icono: 'fa-fire', borde: '#ffc107' },
                        'listo':          { color: 'bg-success', texto: '¡Listo!', icono: 'fa-check', borde: '#198754' },
                        'cobrado':        { color: 'bg-primary', texto: 'Cobrado', icono: 'fa-dollar-sign', borde: '#0d6efd' }
                    };

                    // Seleccionamos la config actual (o gris por defecto si el estado es desconocido)
                    const config = estadoConfig[p.estado] || { color: 'bg-secondary', texto: p.estado, icono: 'fa-circle', borde: '#ccc' };

                    // 2. Lógica de Botones
                    const editable = (p.estado === 'en_preparacion' || p.estado === 'pendiente');
                    // El botón "Extra" solo sale si el pedido ya está LISTO (esperando en caja)
                    const permiteAdicion = (p.estado === 'listo');
                    
                    const destino = p.tipo_servicio === 'Mesa' ? `Mesa ${p.mesa_id}` : `Llevar (${p.nombre_cliente || 'Cliente'})`;
                    
                    let botones = '';

                    if (editable) {
                        botones = `
                            <button class="btn btn-sm btn-outline-danger py-0 px-2" onclick="window.eliminarPedido(${p.id})" title="Cancelar"><i class="fas fa-trash"></i></button>
                            <button class="btn btn-sm btn-outline-primary py-0 px-2" onclick="window.ventaManagerInstance.iniciarModoEdicionPagina(${p.id})" title="Editar"><i class="fas fa-edit"></i></button>
                        `;
                    } else if (permiteAdicion) {
                        // Botón Extra (pasando ID cliente)
                        botones = `
                            <button class="btn btn-sm btn-warning fw-bold py-0 px-2" 
                                onclick="window.ventaManagerInstance.iniciarModoAdicion(${p.id}, '${p.mesa_id}', '${p.tipo_servicio}', '${p.nombre_cliente || ''}', ${p.cliente_id})"
                                title="Agregar productos extra">
                                <i class="fas fa-plus"></i> Extra
                            </button>
                        `;
                    } else if (p.estado === 'cobrado') {
                        botones = `<small class="text-primary fw-bold"><i class="fas fa-check-double"></i> Pagado</small>`;
                    } else {
                        botones = `<small class="text-muted fst-italic"><i class="fas fa-lock"></i> Cerrado</small>`;
                    }

                    // 3. Renderizado de la Tarjeta con Colores Dinámicos
                    // Nota el estilo inline para el borde izquierdo de color
                    lista.innerHTML += `
                        <div class="history-item p-2 mb-2 border rounded shadow-sm bg-white" style="border-left: 5px solid ${config.borde} !important;">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <strong class="fs-6">#${p.id}</strong>
                                <span class="badge ${config.color} d-flex align-items-center gap-1">
                                    <i class="fas ${config.icono} small"></i> ${config.texto}
                                </span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="small fw-bold text-dark text-truncate" style="max-width: 60%;" title="${destino}">
                                    ${destino}
                                </div>
                                <div class="mt-1 d-flex gap-1 justify-content-end align-items-center">
                                    ${botones}
                                </div>
                            </div>
                        </div>`;
                });
            }
        } catch (error) { console.error(error); }
    }




}

// Función global externa para eliminar
window.eliminarPedido = async (id) => {
    const res = await Swal.fire({ title: '¿Cancelar Pedido?', text: 'Esta acción no se puede deshacer', icon: 'warning', showCancelButton: true, confirmButtonColor: '#d33', confirmButtonText: 'Sí, cancelar' });
    if(res.isConfirmed) {
        const fd = new FormData(); fd.append('id', id); fd.append('action', 'eliminar');
        const r = await fetch('./historial_manager.php', { method: 'POST', body: fd });
        const d = await r.json();
        if(d.success) {
            Swal.fire('Cancelado', '', 'success');
            window.ventaManagerInstance.cargarHistorialPedidos();
        } else {
            Swal.fire('Error', d.error, 'error');
        }
    }
};

document.addEventListener('DOMContentLoaded', () => { 
    window.ventaManagerInstance = new VentaManager(); 
});