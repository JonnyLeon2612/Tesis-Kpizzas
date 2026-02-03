class VentaManager {
    constructor() {
        this.preciosBase = window.preciosBase;
        this.tasaDolar = window.tasaDolar || 1; 
        this.orderList = [];
        this.currentTipoServicio = null;
        this.notificacionesMostradas = new Set();
        
        // VARIABLES CRÍTICAS PARA EDICIÓN
        this.editandoComandaId = null; 
        this.indexSiendoEditado = null; 
        
        // Solo ejecutamos init() si existen los contenedores de historial (estamos en venta.php)
        if (document.getElementById('history-list')) {
            this.init();
        } else {
            // Si estamos en editar_pedido.php, solo activamos eventos y visualización
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
        setInterval(() => this.verificarPedidosListos(), 5000); 
    }

    setupEventListeners() {
        document.querySelectorAll('.servicio-option').forEach(option => {
            option.addEventListener('click', (e) => this.selectTipoServicio(e));
        });

        document.querySelectorAll('.mesa').forEach(mesa => {
            mesa.addEventListener('click', (e) => this.selectMesa(e));
        });

        document.getElementById('back-to-servicio-btn').addEventListener('click', () => this.backToServicioType());
        document.getElementById('back-to-tables-btn').addEventListener('click', () => this.backToTables());
        
        document.querySelectorAll('input[name="tamanio"]').forEach(input => {
            input.addEventListener('change', () => {
                this.updatePreciosDisplay();
                this.updateCurrentItemTotal();
            });
        });

        document.querySelectorAll('.ingrediente-checkbox, .bebida-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', () => this.updateCurrentItemTotal());
        });

        document.getElementById('agregar-btn').addEventListener('click', () => this.addToOrder());
        document.getElementById('pizzaForm').addEventListener('submit', (e) => {
            e.preventDefault();
            this.submitOrder();
        });

        window.toggleSidebar = (id) => {
            const el = document.getElementById(id);
            if(el) el.classList.toggle('active');
        };

        window.limpiarNotificaciones = () => this.limpiarNotificaciones();
    }

    async iniciarModoEdicionPagina(id) {
    this.editandoComandaId = id;
    try {
        const response = await fetch(`./historial_manager.php?action=obtener_detalle&id=${id}`);
        const data = await response.json();

        if (data.success) {
            this.orderList = [];
            let currentPizza = null;

            data.detalles.forEach(d => {
                if (d.tipo_categoria === 'Pizza Base') {
                    currentPizza = { 
                        tipo: 'Pizza', id_base: d.producto_id, tamanio: d.tamanio, 
                        precio_base: parseFloat(d.precio_unitario), ingredientes: [] 
                    };
                    this.orderList.push(currentPizza);
                } else if (d.tipo_categoria === 'Ingrediente' && currentPizza) {
                    currentPizza.ingredientes.push({ id: d.producto_id, nombre: d.nombre, precio: parseFloat(d.precio_unitario) });
                } else if (d.tipo_categoria === 'Bebida') {
                    this.orderList.push({ tipo: 'Bebida', id: d.producto_id, nombre: d.nombre, precio: parseFloat(d.precio_unitario) });
                }
            });

            this.updateFullOrderSummary(); 
            const info = document.getElementById('info-cabecera');
            if(info) info.textContent = `Pedido #${id} - ${data.comanda.tipo_servicio}`;
        }
    } catch (e) {
        console.error("Error cargando datos:", e);
    }
}

    // --- NUEVO: FUNCIÓN PARA EDITAR INGREDIENTES DE UN ITEM YA AGREGADO ---
cargarItemParaEditar(index) {
this.indexSiendoEditado = index;
    const item = this.orderList[index];
    
    if (item.tipo === 'Bebida') {
        this.showToast('Las bebidas no tienen ingredientes editables', 'warning');
        return;
    }

    // 1. Limpiar todos los checkboxes primero
    document.querySelectorAll('.ingrediente-checkbox').forEach(cb => cb.checked = false);

    // 2. Cargar el tamaño de la pizza
    const radio = document.querySelector(`input[name="tamanio"][value="${item.tamanio}"]`);
    if (radio) {
        radio.checked = true;
        // Forzamos actualización de etiquetas de precios
        this.updatePreciosDisplay();
    }

    // 3. Marcar los ingredientes de esta pizza específica
    if (item.ingredientes && Array.isArray(item.ingredientes)) {
        item.ingredientes.forEach(ing => {
            // Buscamos por el ID del producto (id_ingrediente)
            const checkbox = document.getElementById(`ing-${ing.id}`) || 
                             document.getElementById(`ingrediente-${ing.id}`);
            if (checkbox) checkbox.checked = true;
        });
    }

    // 4. UI: Cambiar botón a modo "Actualizar"
    const btn = document.getElementById('agregar-btn');
    if (btn) {
        btn.innerHTML = `<i class="fas fa-sync me-2"></i>Actualizar Pizza ${index + 1}`;
        btn.classList.replace('btn-outline-primary', 'btn-primary');
    }

    // 5. Scroll suave hacia el formulario para que el mesero vea el cambio
    document.getElementById('pizzaForm').scrollIntoView({ behavior: 'smooth' });
    
    this.updateCurrentItemTotal();
    this.showToast(`Editando ingredientes de Pizza ${index + 1}`, 'info');
}

    // --- NOTIFICACIONES Y TABLERO ---
    async actualizarUI() {
        try {
            const response = await fetch('./notificaciones_manager.php');
            const result = await response.json();
            const lista = document.getElementById('notif-list');
            const contador = document.getElementById('notif-count');
            if(!lista || !contador) return;
            lista.innerHTML = '';
            result.notificaciones.forEach(n => {
                lista.innerHTML += `<div class="notif-item shadow-sm"><strong>${n.mensaje}</strong><br><small class="text-muted">${n.fecha_creacion}</small></div>`;
            });
            contador.textContent = result.notificaciones.length;
            contador.style.display = result.notificaciones.length > 0 ? 'flex' : 'none';
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
                        const msg = `Pedido #${pedido.id} listo!`;
                        const fd = new FormData();
                        fd.append('action', 'guardar'); fd.append('mensaje', msg); fd.append('pedido_id', pedido.id);
                        const res = await fetch('./notificaciones_manager.php', { method: 'POST', body: fd });
                        const data = await res.json();
                        if (data.new) {
                            Swal.fire({ title: '¡Pedido Listo!', text: msg, icon: 'success', timer: 4000, showConfirmButton: false, toast: true, position: 'top-end' });
                            const sonido = document.getElementById('notificacion-sonido');
                            if(sonido) { sonido.currentTime = 0; sonido.play().catch(e => {}); }
                            this.actualizarUI();
                        }
                    }
                }
            }
        } catch (error) { console.error(error); }
    }

    async limpiarNotificaciones() {
        const result = await Swal.fire({ title: '¿Borrar?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#000' });
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
            result.pedidos.forEach(p => {
                const editable = (p.estado === 'en_preparacion' || p.estado === 'pendiente');
                lista.innerHTML += `
                    <div class="history-item p-2 mb-2 border rounded shadow-sm state-${p.estado}">
                        <div class="d-flex justify-content-between">
                            <strong>#${p.id}</strong> 
                            <small class="badge ${p.estado === 'listo' ? 'bg-success' : 'bg-warning text-dark'}">${p.estado}</small>
                        </div>
                        <div class="small">${p.tipo_servicio} ${p.mesa_id ? '- Mesa '+p.mesa_id : ''}</div>
                        <div class="mt-2 d-flex gap-1">
                            ${editable ? `
                                <button class="btn btn-xs btn-outline-danger" onclick="eliminarPedido(${p.id})"><i class="fas fa-trash"></i></button>
                                <button class="btn btn-xs btn-outline-primary" onclick="editarPedido(${p.id})"><i class="fas fa-edit"></i></button>
                            ` : ''}
                        </div>
                    </div>`;
            });
        } catch (error) { console.error(error); }
    }

    // --- LÓGICA DE VENTAS ---
    selectTipoServicio(event) {
        this.currentTipoServicio = event.currentTarget.getAttribute('data-servicio-type');
        document.getElementById('tipo_servicio_input').value = this.currentTipoServicio;
        document.getElementById('servicio-type-section').style.display = 'none';
        if (this.currentTipoServicio === 'Mesa') {
            document.getElementById('table-layout-section').style.display = 'block';
            document.getElementById('main-title').textContent = 'Selecciona una Mesa';
        } else { this.startTakeAwayOrder(); }
    }

    startTakeAwayOrder() {
        document.getElementById('mesa_id_input').value = 0;
        document.getElementById('current-servicio-display').textContent = 'Para Llevar';
        document.getElementById('order-form-section').style.display = 'block';
        document.getElementById('main-title').textContent = 'Pedido Para Llevar';
    }

    selectMesa(event) {
        const mesa = event.currentTarget;
        if (mesa.classList.contains('mesa-ocupada') || mesa.hasAttribute('disabled')) return;
        document.getElementById('mesa_id_input').value = mesa.getAttribute('data-mesa-id');
        document.getElementById('current-servicio-display').textContent = 'Mesa ' + mesa.getAttribute('data-mesa-numero');
        document.getElementById('table-layout-section').style.display = 'none';
        document.getElementById('order-form-section').style.display = 'block';
    }

    updatePreciosDisplay() {
        const radio = document.querySelector('input[name="tamanio"]:checked');
        if(!radio) return;
        const tamanio = radio.value;
        document.querySelectorAll('.ingrediente-checkbox').forEach(checkbox => {
            const precios = JSON.parse(checkbox.getAttribute('data-precios'));
            const span = document.querySelector(`.precios-display[data-id="${checkbox.value}"]`);
            if (precios && precios[tamanio]) span.textContent = `$${precios[tamanio].toFixed(2)}`;
        });
    }

    updateCurrentItemTotal() {
        const radio = document.querySelector('input[name="tamanio"]:checked');
        if(!radio) return;
        const tamanio = radio.value;
        let total = this.preciosBase[tamanio];
        const lista = document.getElementById('factura-list-current');
        lista.innerHTML = `<li>${this.preciosBase.nombre} (${tamanio}) - $${total.toFixed(2)}</li>`;
        
        document.querySelectorAll('.ingrediente-checkbox:checked').forEach(cb => {
            const p = JSON.parse(cb.getAttribute('data-precios'))[tamanio];
            total += p;
            lista.innerHTML += `<li>${cb.getAttribute('data-nombre')} - $${p.toFixed(2)}</li>`;
        });
        document.querySelectorAll('.bebida-checkbox:checked').forEach(cb => {
            const p = JSON.parse(cb.getAttribute('data-precios'))['Pequena'];
            total += p;
            lista.innerHTML += `<li>${cb.getAttribute('data-nombre')} - $${p.toFixed(2)}</li>`;
        });
        this.actualizarTotalBolivares(total);
    }

    actualizarTotalBolivares(totalD) {
        document.getElementById('total-bs-display').textContent = (totalD * this.tasaDolar).toFixed(2);
    }

addToOrder() {
        const radioTamanio = document.querySelector('input[name="tamanio"]:checked');
        if(!radioTamanio) return;
        const tamanio = radioTamanio.value;
        
        const ings = Array.from(document.querySelectorAll('.ingrediente-checkbox:checked'));
        
        // Preparamos el objeto de la pizza
        const pizzaData = {
            tipo: 'Pizza',
            id_base: this.preciosBase.id,
            tamanio: tamanio,
            precio_base: this.preciosBase[tamanio],
            ingredientes: ings.map(i => ({
                id: i.value,
                nombre: i.getAttribute('data-nombre'),
                precio: JSON.parse(i.getAttribute('data-precios'))[tamanio]
            }))
        };

        if (this.indexSiendoEditado !== null) {
            // REEMPLAZO: Sobrescribimos la posición original
            this.orderList[this.indexSiendoEditado] = pizzaData;
            this.indexSiendoEditado = null; 
            
            const btn = document.getElementById('agregar-btn');
            if (btn) {
                btn.innerHTML = '<i class="fas fa-plus-circle me-2"></i>Agregar al Pedido';
                btn.classList.replace('btn-primary', 'btn-outline-primary');
            }
            this.showToast('Pizza actualizada', 'success');
        } else {
            // AGREGAR NUEVO
            if (ings.length > 0) this.orderList.push(pizzaData);
            
            document.querySelectorAll('.bebida-checkbox:checked').forEach(b => {
                this.orderList.push({ 
                    tipo: 'Bebida', id: b.value, nombre: b.getAttribute('data-nombre'), 
                    precio: JSON.parse(b.getAttribute('data-precios'))['Pequena'] 
                });
            });
            this.showToast('Agregado al pedido', 'success');
        }

        // Limpiar formulario y refrescar
        document.querySelectorAll('input[type="checkbox"]').forEach(cb => cb.checked = false);
        this.updateFullOrderSummary();
        this.updateCurrentItemTotal();
    }

updateFullOrderSummary() {
    const lista = document.getElementById('factura-list-full');
    if(!lista) return;
    lista.innerHTML = '';
    let total = 0;

    this.orderList.forEach((item, index) => {
        let subtotal = (item.precio_base || item.precio);
        if (item.ingredientes) item.ingredientes.forEach(i => subtotal += i.precio);
        total += subtotal;

        const li = document.createElement('li');
        li.className = 'list-group-item d-flex justify-content-between align-items-center px-0 bg-transparent';
        li.innerHTML = `
            <div class="d-flex align-items-center">
                <button type="button" class="btn btn-sm btn-link text-primary p-0 me-2" onclick="window.ventaManagerInstance.cargarItemParaEditar(${index})">
                    <i class="fas fa-pen"></i>
                </button>
                <span class="small">${item.tipo === 'Pizza' ? `Pizza ${index + 1} (${item.tamanio})` : item.nombre}</span>
            </div>
            <span class="fw-bold small">$${subtotal.toFixed(2)}</span>
        `;
        lista.appendChild(li);
    });
    
    if(document.getElementById('total-display')) {
        document.getElementById('total-display').textContent = total.toFixed(2);
    }
    this.actualizarTotalBolivares(total);
}

    async submitOrder() {
        if (this.orderList.length === 0) return;
        const data = {
            mesa_id: parseInt(document.getElementById('mesa_id_input').value),
            tipo_servicio: document.getElementById('tipo_servicio_input').value,
            pedido: this.orderList,
            edit_id: this.editandoComandaId // MANDAR EL ID SI ESTAMOS EDITANDO
        };

        try {
            Swal.fire({ title: 'Enviando...', didOpen: () => Swal.showLoading() });
            const response = await fetch('venta.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) });
            const result = await response.json();
            Swal.close();
            if (result.success) {
                this.showSuccessAlert(result.message, result.comanda_id);
                this.orderList = [];
                this.editandoComandaId = null;
                this.updateFullOrderSummary();
                this.cargarHistorialPedidos();
            }
        } catch (error) { Swal.close(); }
    }

    backToServicioType() {
        document.getElementById('servicio-type-section').style.display = 'block';
        document.getElementById('table-layout-section').style.display = 'none';
        document.getElementById('order-form-section').style.display = 'none';
        document.getElementById('main-title').textContent = 'Tipo de Servicio';
        this.orderList = [];
        this.editandoComandaId = null;
        this.updateFullOrderSummary();
    }

    backToTables() {
        if (this.currentTipoServicio === 'Mesa') {
            document.getElementById('table-layout-section').style.display = 'block';
            document.getElementById('order-form-section').style.display = 'none';
        } else { this.backToServicioType(); }
    }

    showToast(m, t) { Swal.mixin({ toast: true, position: 'top-end', showConfirmButton: false, timer: 3000 }).fire({ icon: t, title: m }); }
    showSuccessAlert(m, id) { Swal.fire({ icon: 'success', title: '¡Éxito!', html: `<p>${m}</p><hr><h3>N° Pedido: ${id}</h3>`, confirmButtonColor: '#D82626' }).then(() => this.backToServicioType()); }
}

// --- FUNCIONES GLOBALES ---
window.eliminarPedido = async (id) => {
    const res = await Swal.fire({ title: '¿Eliminar?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#000' });
    if(res.isConfirmed) {
        const fd = new FormData(); fd.append('id', id); fd.append('action', 'eliminar');
        const r = await fetch('./historial_manager.php', { method: 'POST', body: fd });
        const d = await r.json();
        if(d.success) location.reload();
    }
};

window.editarPedido = async (id) => {
    try {
        // 1. Notificar al servidor que se inicia la edición (Cambia 'editando' a 1 en BD)
        const blockRes = await fetch(`./historial_manager.php?action=bloquear&id=${id}`);
        const blockData = await blockRes.json();
        
        if(!blockData.success) {
            Swal.fire('Error', 'El pedido ya está siendo editado por alguien más', 'error');
            return;
        }
        
        // 2. Obtener detalles del pedido
        const response = await fetch(`./historial_manager.php?action=obtener_detalle&id=${id}`);
        const data = await response.json();
        
        if(data.success) {
            const vm = window.ventaManagerInstance;
            vm.orderList = [];
            let currentPizza = null;

            // Mapeo de productos desde la base de datos al formato del orderList
            data.detalles.forEach(d => {
                if (d.tipo_categoria === 'Pizza Base') {
                    currentPizza = { 
                        tipo: 'Pizza', 
                        id_base: d.producto_id, 
                        tamanio: d.tamanio, 
                        precio_base: parseFloat(d.precio_unitario), 
                        ingredientes: [] 
                    };
                    vm.orderList.push(currentPizza);
                } else if (d.tipo_categoria === 'Ingrediente' && currentPizza) {
                    currentPizza.ingredientes.push({ 
                        id: d.producto_id, 
                        nombre: d.nombre, 
                        precio: parseFloat(d.precio_unitario) 
                    });
                } else if (d.tipo_categoria === 'Bebida') {
                    vm.orderList.push({ 
                        tipo: 'Bebida', 
                        id: d.producto_id, 
                        nombre: d.nombre, 
                        precio: parseFloat(d.precio_unitario) 
                    });
                }
            });

            // 3. Preparar la interfaz de Venta
            vm.editandoComandaId = id;
            vm.currentTipoServicio = data.comanda.tipo_servicio;
            document.getElementById('mesa_id_input').value = data.comanda.mesa_id || 0;
            document.getElementById('tipo_servicio_input').value = data.comanda.tipo_servicio;

            // Cambiar vistas
            document.getElementById('servicio-type-section').style.display = 'none';
            document.getElementById('table-layout-section').style.display = 'none';
            document.getElementById('order-form-section').style.display = 'block';
            document.getElementById('main-title').textContent = `Editando Pedido #${id}`;
            
            vm.updateFullOrderSummary();
            if(document.getElementById('history-sidebar')) window.toggleSidebar('history-sidebar');
            
            vm.showToast('Pedido bloqueado para edición segura.', 'success');
        }
    } catch(e) { console.error("Error en editarPedido:", e); }
};

document.addEventListener('DOMContentLoaded', () => { window.ventaManagerInstance = new VentaManager(); });