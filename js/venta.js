class VentaManager {
    constructor() {
        this.preciosBase = window.preciosBase;
        this.tasaDolar = window.tasaDolar || 1; 
        this.orderList = [];
        this.currentTipoServicio = null;
        this.notificacionesMostradas = new Set();
        
        // VARIABLES CRÍTICAS PARA EDICIÓN
        this.editandoComandaId = null; 
        
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
        this.setupCRM();

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

    setupCRM() {
        const inputCedula = document.getElementById('cli_cedula');
        if (!inputCedula) return;

        let timeout = null;
        inputCedula.addEventListener('input', () => {
            clearTimeout(timeout);
            const cedula = inputCedula.value.trim();
            if (cedula.length < 3) return;

            timeout = setTimeout(async () => {
                try {
                    const response = await fetch(`../api/buscar_cliente.php?cedula=${cedula}`);
                    const data = await response.json();
                    if (data.found) {
                        document.getElementById('cli_nombre').value = data.data.nombre;
                        this.showToast(`Cliente encontrado: ${data.data.nombre}`, 'success');
                    }
                } catch(e) { console.error(e); }
            }, 500);
        });
    }

    selectTipoServicio(event) {
        const option = event.currentTarget;
        this.currentTipoServicio = option.getAttribute('data-servicio-type');
        document.getElementById('tipo_servicio_input').value = this.currentTipoServicio;
        document.getElementById('servicio-type-section').style.display = 'none';

        if (this.currentTipoServicio === 'Mesa') {
            document.getElementById('table-layout-section').style.display = 'block';
            document.getElementById('main-title').textContent = 'Selecciona una Mesa';
            document.getElementById('crm-section').style.display = 'none';
        } else {
            this.startTakeAwayOrder();
        }
    }

    startTakeAwayOrder() {
        document.getElementById('mesa_id_input').value = 0;
        document.getElementById('current-servicio-display').textContent = 'Para Llevar';
        document.getElementById('order-form-section').style.display = 'block';
        document.getElementById('main-title').textContent = 'Pedido Para Llevar';
        document.getElementById('crm-section').style.display = 'block';
        document.getElementById('cli_cedula').value = '';
        document.getElementById('cli_nombre').value = '';
        this.showToast('Iniciando pedido para llevar', 'info');
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
        document.getElementById('crm-section').style.display = 'none';
        this.showToast('Tomando pedido para Mesa ' + mesaNumero, 'info');
    }

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

        if (this.indexSiendoEditado !== null) {
            this.orderList[this.indexSiendoEditado] = pizza;
            this.indexSiendoEditado = null; 
            const btn = document.getElementById('agregar-btn');
            if (btn) {
                btn.innerHTML = '<i class="fas fa-plus-circle me-2"></i>Agregar al Pedido';
                btn.classList.replace('btn-primary', 'btn-outline-primary');
            }
            this.showToast('Pizza actualizada', 'success');
        } else {
            this.orderList.push(pizza);
            bebidas.forEach(beb => {
                const precios = JSON.parse(beb.getAttribute('data-precios'));
                this.orderList.push({
                    tipo: 'Bebida',
                    id: beb.value,
                    nombre: beb.getAttribute('data-nombre'),
                    precio: precios['Pequena']
                });
            });
            this.showToast('Agregado al pedido', 'success');
        }
        document.querySelectorAll('.ingrediente-checkbox, .bebida-checkbox').forEach(cb => cb.checked = false);
        this.updateCurrentItemTotal();
        this.updateFullOrderSummary();
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
        if (this.orderList.length === 0) {
            this.showToast('Agrega al menos un artículo al pedido', 'warning');
            return;
        }
        const mesaId = document.getElementById('mesa_id_input').value;
        const tipoServicio = document.getElementById('tipo_servicio_input').value;
        const cliCedula = document.getElementById('cli_cedula').value.trim();
        const cliNombre = document.getElementById('cli_nombre').value.trim();

        if (tipoServicio === 'Llevar' && cliNombre === '') {
            Swal.fire('Faltan datos', 'Debe ingresar el nombre del cliente para pedidos Para Llevar.', 'warning');
            return;
        }

        const orderData = {
            mesa_id: parseInt(mesaId),
            tipo_servicio: tipoServicio,
            pedido: this.orderList,
            edit_id: this.editandoComandaId, 
            cliente_cedula: cliCedula,
            cliente_nombre: cliNombre
        };

        try {
            Swal.fire({ title: 'Enviando pedido...', text: 'Por favor espere', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
            const response = await fetch('venta.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(orderData) });
            const result = await response.json();
            Swal.close();
            if (result.success) {
                this.showSuccessAlert(result.message, result.comanda_id);
                this.orderList = [];
                this.editandoComandaId = null;
                this.indexSiendoEditado = null;
                this.updateFullOrderSummary();
                this.cargarHistorialPedidos(); 
            } else {
                this.showErrorAlert('Error al enviar el pedido', result.error);
            }
        } catch (error) {
            Swal.close();
            this.showErrorAlert('Error de conexión', 'No se pudo conectar con el servidor');
        }
    }

    backToServicioType() {
        document.getElementById('servicio-type-section').style.display = 'block';
        document.getElementById('table-layout-section').style.display = 'none';
        document.getElementById('order-form-section').style.display = 'none';
        document.getElementById('main-title').textContent = 'Tipo de Servicio';
        if (this.editandoComandaId) {
             fetch(`./historial_manager.php?action=desbloquear&id=${this.editandoComandaId}`);
        }
        this.orderList = [];
        this.editandoComandaId = null;
        this.indexSiendoEditado = null;
        this.updateFullOrderSummary();
    }

    backToTables() {
        if (this.currentTipoServicio === 'Mesa') {
            document.getElementById('table-layout-section').style.display = 'block';
            document.getElementById('order-form-section').style.display = 'none';
            document.getElementById('main-title').textContent = 'Selecciona una Mesa';
        } else {
            this.backToServicioType();
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
                this.currentTipoServicio = data.comanda.tipo_servicio;
                document.getElementById('mesa_id_input').value = data.comanda.mesa_id || 0;
                document.getElementById('tipo_servicio_input').value = data.comanda.tipo_servicio;
                document.getElementById('servicio-type-section').style.display = 'none';
                document.getElementById('table-layout-section').style.display = 'none';
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
                    const editable = (p.estado === 'en_preparacion' || p.estado === 'pendiente');
                    const destino = p.tipo_servicio === 'Mesa' ? `Mesa ${p.mesa_id}` : `Llevar (${p.nombre_cliente || 'Cliente'})`;
                    lista.innerHTML += `<div class="history-item p-2 mb-2 border rounded shadow-sm state-${p.estado} bg-white"><div class="d-flex justify-content-between"><strong>#${p.id}</strong><small class="badge ${p.estado === 'listo' ? 'bg-success' : 'bg-warning text-dark'}">${p.estado}</small></div><div class="small fw-bold">${destino}</div><div class="mt-2 d-flex gap-1 justify-content-end">${editable ? `<button class="btn btn-sm btn-outline-danger py-0 px-2" onclick="window.eliminarPedido(${p.id})"><i class="fas fa-trash"></i></button><button class="btn btn-sm btn-outline-primary py-0 px-2" onclick="window.ventaManagerInstance.iniciarModoEdicionPagina(${p.id})"><i class="fas fa-edit"></i></button>` : '<small class="text-muted fst-italic"><i class="fas fa-lock"></i> Bloqueado</small>'}</div></div>`;
                });
            }
        } catch (error) { console.error(error); }
    }
}

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