class CajaManager {
    constructor() {
        this.init();
    }

    init() {
        console.log('CajaManager inicializado');
        // No es necesario el event listener 'click' en el document
        // ya que el onclick en el HTML es más directo.
        // this.setupEventListeners(); // Se puede remover o dejar vacío
        this.checkNotification(); 
    }

    setupEventListeners() {
        // Esta función ya no es necesaria para el toggle
        // pero la dejamos por si se añaden otras funcionalidades.
    }

    /**
     * Muestra u oculta los detalles de un pedido.
     * @param {Event} event - El objeto 'event' del clic, pasado desde el HTML.
     * @param {number} pedidoId - El ID del pedido a mostrar/ocultar.
     */
    // CAMBIO AQUÍ: Aceptamos 'event' como primer parámetro
    toggleDetalles(event, pedidoId) {
        // Detener la propagación para que el document.click no lo cierre
        event.stopPropagation(); 
        
        const detalles = document.getElementById('detalles-' + pedidoId);
        
        // CAMBIO AQUÍ: Usamos .closest('.btn') para que funcione tanto
        // si tiene la clase .btn-light o .btn-info
        const boton = event.target.closest('.btn');
        
        if (!detalles) {
            console.error('No se encontraron detalles para el pedido:', pedidoId);
            return;
        }
        
        if (detalles.classList.contains('mostrar')) {
            // Si ya está mostrado, ocultarlo
            this.ocultarDetalles(detalles, boton);
        } else {
            // Si está oculto, mostrarlo (y ocultar los demás)
            this.mostrarDetalles(detalles, boton);
        }
    }

    mostrarDetalles(detalles, boton) {
        // Cerrar cualquier otro detalle que esté abierto
        this.ocultarTodosLosDetalles();
        
        // Mostrar este detalle
        detalles.classList.add('mostrar');
        
        // Actualizar el botón
        boton.innerHTML = '<i class="fas fa-eye-slash me-1"></i>Ocultar';
        boton.classList.add('btn-info');
        boton.classList.remove('btn-light');
        
        // Scroll suave a los detalles (opcional pero bueno para UX)
        setTimeout(() => {
            detalles.scrollIntoView({ 
                behavior: 'smooth', 
                block: 'nearest',
                inline: 'nearest'
            });
        }, 100);
    }

    ocultarDetalles(detalles, boton) {
        detalles.classList.remove('mostrar');
        
        // Solo resetear el botón si existe (puede ser llamado desde ocultarTodos)
        if (boton) {
            boton.innerHTML = '<i class="fas fa-list me-1"></i>Detalles';
            boton.classList.remove('btn-info');
            boton.classList.add('btn-light');
        }
    }

    ocultarTodosLosDetalles() {
        // Ocultar todos los paneles de detalles
        document.querySelectorAll('.detalles-pedido.mostrar').forEach(det => {
            det.classList.remove('mostrar');
        });
        
        // CAMBIO AQUÍ (LÓGICA MEJORADA):
        // Resetear todos los botones que estén en modo "Ocultar".
        // Estos botones ahora tienen la clase 'btn-info', no 'btn-light'.
        document.querySelectorAll('.pedido-card .btn.btn-info').forEach(btn => {
            // Nos aseguramos que sea un botón de detalles (por el texto o ícono)
            if (btn.innerHTML.includes('Ocultar') || btn.innerHTML.includes('fa-eye-slash')) {
                btn.innerHTML = '<i class="fas fa-list me-1"></i>Detalles';
                btn.classList.remove('btn-info');
                btn.classList.add('btn-light');
            }
        });
    }

    confirmarCobro(event) {
        event.preventDefault(); // Evitar que el formulario se envíe de inmediato
        const form = event.target.closest('form');
        const comandaId = form.querySelector('input[name="comanda_id"]').value;
        
        Swal.fire({
            title: '¿Confirmar cobro?',
            text: `¿Estás seguro de que deseas marcar el pedido #${comandaId} como cobrado? Esta acción no se podrá deshacer.`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#28a745',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Sí, cobrar pedido',
            cancelButtonText: 'Cancelar',
            backdrop: true,
            allowOutsideClick: false,
        }).then((result) => {
            if (result.isConfirmed) {
                // Si el usuario confirma, ahora sí enviamos el formulario
                this.procesarCobro(form);
            }
        });
        
        return false; // Prevenir el envío (ya lo manejamos en .then)
    }

    procesarCobro(form) {
        Swal.fire({
            title: 'Procesando cobro...',
            text: 'Por favor espere un momento',
            icon: 'info',
            allowOutsideClick: false,
            showConfirmButton: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });

        // Enviar el formulario. El servidor redirigirá.
        form.submit();
    }

    // Método para verificar notificaciones en la URL (ej. después de cobrar)
    checkNotification() {
        const urlParams = new URLSearchParams(window.location.search);
        const cobroExito = urlParams.get('cobro_exito');

        if (cobroExito === 'true') {
            this.showToast('¡Pedido cobrado exitosamente!', 'success');
            // Limpiar la URL para que no se muestre el toast en recargas
            this.clearUrlParams(['cobro_exito']);
        } else if (cobroExito === 'false') {
            const errorMsg = urlParams.get('error_msg') || 'Ocurrió un error al cobrar el pedido.';
            this.showToast(decodeURIComponent(errorMsg), 'error');
            this.clearUrlParams(['cobro_exito', 'error_msg']);
        }
    }

    // Método auxiliar para limpiar parámetros de la URL
    clearUrlParams(paramsToRemove) {
        const url = new URL(window.location);
        paramsToRemove.forEach(param => {
            url.searchParams.delete(param);
        });
        window.history.replaceState({}, document.title, url.toString());
    }

    showToast(message, type = 'success') {
        const Toast = Swal.mixin({
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 3000,
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
}

// Inicializar cuando el DOM esté listo
document.addEventListener('DOMContentLoaded', () => {
    window.cajaManager = new CajaManager();
    
    // Auto-scroll suave para mejor UX
    const hash = window.location.hash;
    if (hash) {
        const element = document.querySelector(hash);
        if (element) {
            setTimeout(() => {
                element.scrollIntoView({ behavior: 'smooth' });
            }, 100);
        }
    }

    // Añadir listener global para cerrar detalles si se hace clic fuera
    document.addEventListener('click', (e) => {
        // Si el clic NO fue en un botón de detalles Y NO fue dentro de un panel de detalles...
        if (!e.target.closest('.btn') && !e.target.closest('.detalles-pedido')) {
             // Ocultar todos los detalles abiertos
             if (window.cajaManager) {
                window.cajaManager.ocultarTodosLosDetalles();
             }
        }
    });
});