class VentaManager {
    constructor() {
        this.preciosBase = window.preciosBase;
        this.tasaDolar = window.tasaDolar || 1; // NUEVO: Tasa de cambio
        this.orderList = [];
        this.currentTipoServicio = null;
        this.notificacionesMostradas = new Set();
        this.init();
    }

    init() {
        this.setupEventListeners();
        this.updatePreciosDisplay();
        this.updateCurrentItemTotal();
        
        // Iniciar la revisión de pedidos listos
        setInterval(() => this.verificarPedidosListos(), 5000); // Revisa cada 5 segundos
    }

    setupEventListeners() {
        // Selección de tipo de servicio
        document.querySelectorAll('.servicio-option').forEach(option => {
            option.addEventListener('click', (e) => this.selectTipoServicio(e));
        });

        // Mesas
        document.querySelectorAll('.mesa').forEach(mesa => {
            if (!mesa.hasAttribute('disabled')) {
                mesa.addEventListener('click', (e) => this.selectMesa(e));
            }
        });

        // Botones de navegación
        document.getElementById('back-to-servicio-btn').addEventListener('click', () => this.backToServicioType());
        document.getElementById('back-to-tables-btn').addEventListener('click', () => this.backToTables());
        
        // Formulario
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
    }

    selectTipoServicio(event) {
        const option = event.currentTarget;
        this.currentTipoServicio = option.getAttribute('data-servicio-type');
        
        document.getElementById('tipo_servicio_input').value = this.currentTipoServicio;
        document.getElementById('servicio-type-section').style.display = 'none';

        if (this.currentTipoServicio === 'Mesa') {
            document.getElementById('table-layout-section').style.display = 'block';
            document.getElementById('main-title').textContent = 'Selecciona una Mesa';
        } else {
            this.startTakeAwayOrder();
        }
    }

    startTakeAwayOrder() {
        document.getElementById('mesa_id_input').value = 0;
        document.getElementById('current-servicio-display').textContent = 'Para Llevar';
        document.getElementById('order-form-section').style.display = 'block';
        document.getElementById('main-title').textContent = 'Pedido Para Llevar';
        
        this.showToast('Iniciando pedido para llevar', 'info');
    }

    selectMesa(event) {
        const mesa = event.currentTarget;
        const mesaId = mesa.getAttribute('data-mesa-id');
        const mesaNumero = mesa.getAttribute('data-mesa-numero');
        
        document.getElementById('mesa_id_input').value = mesaId;
        document.getElementById('current-servicio-display').textContent = 'Mesa ' + mesaNumero;
        
        document.getElementById('table-layout-section').style.display = 'none';
        document.getElementById('order-form-section').style.display = 'block';
        document.getElementById('main-title').textContent = 'Pedido Mesa ' + mesaNumero;
        
        this.showToast('Tomando pedido para Mesa ' + mesaNumero, 'info');
    }

    updatePreciosDisplay() {
        const tamanio = document.querySelector('input[name="tamanio"]:checked').value;
        
        document.querySelectorAll('.ingrediente-checkbox').forEach(checkbox => {
            const precios = JSON.parse(checkbox.getAttribute('data-precios'));
            const span = document.querySelector(`.precios-display[data-id="${checkbox.value}"]`);
            if (precios && precios[tamanio]) {
                span.textContent = `$${precios[tamanio].toFixed(2)}`;
            }
        });

        document.querySelectorAll('.bebida-checkbox').forEach(checkbox => {
            const precios = JSON.parse(checkbox.getAttribute('data-precios'));
            const span = document.querySelector(`.precios-display-bebida[data-id="${checkbox.value}"]`);
            if (precios && precios['Pequena']) {
                span.textContent = `$${precios['Pequena'].toFixed(2)}`;
            }
        });
    }

    updateCurrentItemTotal() {
        const tamanio = document.querySelector('input[name="tamanio"]:checked').value;
        let total = this.preciosBase[tamanio];
        const lista = document.getElementById('factura-list-current');
        lista.innerHTML = '';

        // Base de pizza
        const itemBase = document.createElement('li');
        itemBase.className = 'list-group-item d-flex justify-content-between align-items-center px-0';
        itemBase.innerHTML = `
            <span>${this.preciosBase.nombre} (${tamanio})</span>
            <span class="fw-bold">$${total.toFixed(2)}</span>
        `;
        lista.appendChild(itemBase);

        // Ingredientes
        document.querySelectorAll('.ingrediente-checkbox:checked').forEach(checkbox => {
            const precios = JSON.parse(checkbox.getAttribute('data-precios'));
            const nombre = checkbox.getAttribute('data-nombre');
            const precio = precios[tamanio];
            total += precio;

            const item = document.createElement('li');
            item.className = 'list-group-item d-flex justify-content-between align-items-center px-0';
            item.innerHTML = `
                <span>${nombre}</span>
                <span class="fw-bold">$${precio.toFixed(2)}</span>
            `;
            lista.appendChild(item);
        });

        // Bebidas
        document.querySelectorAll('.bebida-checkbox:checked').forEach(checkbox => {
            const precios = JSON.parse(checkbox.getAttribute('data-precios'));
            const nombre = checkbox.getAttribute('data-nombre');
            const precio = precios['Pequena'];
            total += precio;

            const item = document.createElement('li');
            item.className = 'list-group-item d-flex justify-content-between align-items-center px-0';
            item.innerHTML = `
                <span>${nombre}</span>
                <span class="fw-bold">$${precio.toFixed(2)}</span>
            `;
            lista.appendChild(item);
        });

        // NUEVO: Actualizar total en bolívares
        this.actualizarTotalBolivares(total);
    }

    // NUEVO: Método para calcular y mostrar total en bolívares
    actualizarTotalBolivares(totalDolares) {
        const totalBsDisplay = document.getElementById('total-bs-display');
        const totalBolivares = totalDolares * this.tasaDolar;
        totalBsDisplay.textContent = totalBolivares.toFixed(2);
    }

    addToOrder() {
        const tamanio = document.querySelector('input[name="tamanio"]:checked').value;
        const ingredientes = Array.from(document.querySelectorAll('.ingrediente-checkbox:checked'));
        const bebidas = Array.from(document.querySelectorAll('.bebida-checkbox:checked'));

        if (ingredientes.length === 0 && bebidas.length === 0) {
            this.showToast('Selecciona al menos un ingrediente o bebida', 'warning');
            return;
        }

        // Pizza
        if (ingredientes.length > 0) {
            const pizza = {
                tipo: 'Pizza',
                id_base: this.preciosBase.id,
                tamanio: tamanio,
                precio_base: this.preciosBase[tamanio],
                ingredientes: ingredientes.map(ing => {
                    const precios = JSON.parse(ing.getAttribute('data-precios'));
                    return {
                        id: ing.value,
                        nombre: ing.getAttribute('data-nombre'),
                        precio: precios[tamanio]
                    };
                })
            };
            this.orderList.push(pizza);
        }

        // Bebidas
        bebidas.forEach(beb => {
            const precios = JSON.parse(beb.getAttribute('data-precios'));
            const bebida = {
                tipo: 'Bebida',
                id: beb.value,
                nombre: beb.getAttribute('data-nombre'),
                precio: precios['Pequena']
            };
            this.orderList.push(bebida);
        });

        // Limpiar selección
        document.querySelectorAll('.ingrediente-checkbox, .bebida-checkbox').forEach(cb => cb.checked = false);
        
        this.updateCurrentItemTotal();
        this.updateFullOrderSummary();
        this.showToast('Artículo agregado correctamente a la orden', 'success');
    }

    updateFullOrderSummary() {
        const lista = document.getElementById('factura-list-full');
        const totalDisplay = document.getElementById('total-display');
        lista.innerHTML = '';
        let total = 0;

        this.orderList.forEach((item, index) => {
            const li = document.createElement('li');
            li.className = 'list-group-item d-flex justify-content-between align-items-center px-0';
            
            if (item.tipo === 'Pizza') {
                const subtotal = item.precio_base + item.ingredientes.reduce((sum, ing) => sum + ing.precio, 0);
                total += subtotal;
                li.innerHTML = `
                    <span>Pizza ${index + 1} (${item.tamanio})</span>
                    <span class="fw-bold">$${subtotal.toFixed(2)}</span>
                `;
            } else {
                total += item.precio;
                li.innerHTML = `
                    <span>${item.nombre}</span>
                    <span class="fw-bold">$${item.precio.toFixed(2)}</span>
                `;
            }
            lista.appendChild(li);
        });

        totalDisplay.textContent = total.toFixed(2);
        
        // NUEVO: Actualizar total en bolívares
        this.actualizarTotalBolivares(total);
    }

    // --- submitOrder (MODIFICADO) ---
    // Ahora pasa result.comanda_id a showSuccessAlert
    async submitOrder() {
        if (this.orderList.length === 0) {
            this.showToast('Agrega al menos un artículo al pedido', 'warning');
            return;
        }

        const mesaId = document.getElementById('mesa_id_input').value;
        const tipoServicio = document.getElementById('tipo_servicio_input').value;

        const orderData = {
            mesa_id: parseInt(mesaId),
            tipo_servicio: tipoServicio,
            pedido: this.orderList
        };

        try {
            // Mostrar loading
            Swal.fire({
                title: 'Enviando pedido...',
                text: 'Por favor espere',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            const response = await fetch('venta.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(orderData)
            });

            const result = await response.json();
            Swal.close();

            if (result.success) {
                // --- CAMBIO AQUÍ ---
                // Se pasa el message Y el nuevo comanda_id
                this.showSuccessAlert(result.message, result.comanda_id);
                
                // Actualizar estado de mesa si es consumo en mesa
                if (tipoServicio === 'Mesa' && parseInt(mesaId) > 0) {
                    const mesa = document.querySelector(`[data-mesa-id="${mesaId}"]`);
                    if (mesa) {
                        mesa.classList.remove('mesa-disponible');
                        mesa.classList.add('mesa-ocupada');
                        mesa.setAttribute('disabled', 'true');
                    }
                }

                // Limpiar y regresar
                this.orderList = [];
                this.updateFullOrderSummary();
                
            } else {
                this.showErrorAlert('Error al enviar el pedido', result.error);
            }
        } catch (error) {
            Swal.close();
            this.showErrorAlert('Error de conexión', 'No se pudo conectar con el servidor');
            console.error(error);
        }
    }

    backToServicioType() {
        document.getElementById('servicio-type-section').style.display = 'block';
        document.getElementById('table-layout-section').style.display = 'none';
        document.getElementById('order-form-section').style.display = 'none';
        document.getElementById('main-title').textContent = 'Tipo de Servicio';
        document.getElementById('mesa_id_input').value = '';
        document.getElementById('tipo_servicio_input').value = '';
        this.orderList = [];
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

    // ========== SWEETALERT2 NOTIFICATIONS ==========

    showToast(message, type) {
        const Toast = Swal.mixin({
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 7000,
            timerProgressBar: true,
            didOpen: (toast) => {
                toast.addEventListener('mouseenter', Swal.stopTimer)
                toast.addEventListener('mouseleave', Swal.resumeTimer)
            }
        });

        Toast.fire({
            icon: type,
            title: message
        });
    }

    // --- showSuccessAlert (MODIFICADO) ---
    // Ahora acepta 'comandaId' y lo muestra en el HTML de la alerta
    showSuccessAlert(message, comandaId) {
        // Creamos un HTML personalizado para la alerta
        const alertHtml = `
            <p class="fs-5">${message}</p>
            <hr>
            <h3 class="fw-bold text-uppercase">N° Pedido: ${comandaId}</h3>
        `;
    
        Swal.fire({
            icon: 'success',
            title: '¡Éxito!',
            html: alertHtml, // Usamos 'html' en lugar de 'text'
            confirmButtonText: 'Aceptar',
            confirmButtonColor: '#D82626',
            customClass: {
                popup: 'sweetalert-custom',
                confirmButton: 'sweetalert-confirm-btn'
            }
        }).then((result) => {
            if (result.isConfirmed) {
                this.backToServicioType();
            }
        });
    }

    showErrorAlert(title, message) {
        Swal.fire({
            icon: 'error',
            title: title,
            text: message,
            confirmButtonText: 'Entendido',
            confirmButtonColor: '#D82626',
            customClass: {
                popup: 'sweetalert-custom',
                confirmButton: 'sweetalert-confirm-btn'
            }
        });
    }

    showWarningAlert(title, message) {
        Swal.fire({
            icon: 'warning',
            title: title,
            text: message,
            confirmButtonText: 'Entendido',
            confirmButtonColor: '#D82626'
        });
    }

    // --- MÉTODO COMPLETAMENTE NUEVO AÑADIDO ---
    /**
     * Revisa periódicamente si hay pedidos listos para este mesero.
     * Llama al script PHP de notificaciones.
     */
    async verificarPedidosListos() {
        try {
            // Llama a tu nuevo archivo PHP
            // La ruta es relativa a donde se carga el HTML (mesero/venta.php)
            const response = await fetch('../cocina/mesero_notificaciones.php', {
                method: 'GET',
                headers: {
                    'Accept': 'application/json'
                }
            });

            if (!response.ok) {
                console.error('Error al verificar notificaciones.');
                return;
            }

            const result = await response.json();

            if (result.success && result.pedidos.length > 0) {
                // Recorremos todos los pedidos que están listos
                result.pedidos.forEach(pedido => {
                    const pedidoId = pedido.id;

                    // IMPORTANTE: Revisamos si ya mostramos esta notificación
                    if (!this.notificacionesMostradas.has(pedidoId)) {
                        

                        // Construimos el mensaje
                        let mensaje = `¡Pedido ${pedidoId} listo!`;
                        if (pedido.tipo_servicio === 'Mesa') {
                            mensaje = `¡Pedido para Mesa ${pedido.mesa_id} (ID: ${pedidoId}) está listo!`;
                        } else {
                            mensaje = `¡Pedido para llevar (ID: ${pedidoId}) está listo!`;
                        }

                        // Usamos tu función showToast (que ya existe) para notificar
                        this.showToast(mensaje, 'success');

                        // Añadimos el ID al set para no volver a mostrarlo
                        this.notificacionesMostradas.add(pedidoId);
                    }
                });
            }

        } catch (error) {
            console.error('Error de red al verificar pedidos:', error);
        }
    }
}

document.addEventListener('DOMContentLoaded', () => new VentaManager());