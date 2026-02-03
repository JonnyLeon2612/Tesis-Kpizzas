class CajaManager {
    constructor() {
        this.init();
    }

    init() {
        console.log('CajaManager inicializado');
        this.checkNotification(); 
    }

    toggleDetalles(event, pedidoId) {
        event.stopPropagation(); 
        
        const detalles = document.getElementById('detalles-' + pedidoId);
        const boton = event.target.closest('.btn');
        
        if (!detalles) {
            console.error('No se encontraron detalles para el pedido:', pedidoId);
            return;
        }
        
        if (detalles.classList.contains('mostrar')) {
            this.ocultarDetalles(detalles, boton);
        } else {
            this.mostrarDetalles(detalles, boton);
        }
    }

    mostrarDetalles(detalles, boton) {
        this.ocultarTodosLosDetalles();
        detalles.classList.add('mostrar');
        
        if (boton) {
            boton.innerHTML = '<i class="fas fa-eye-slash me-1"></i>Ocultar';
            boton.classList.add('btn-info');
            boton.classList.remove('btn-light');
        }
        
        setTimeout(() => {
            detalles.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'nearest' });
        }, 100);
    }

    ocultarDetalles(detalles, boton) {
        detalles.classList.remove('mostrar');
        
        if (boton) {
            boton.innerHTML = '<i class="fas fa-list me-1"></i>Detalles';
            boton.classList.remove('btn-info');
            boton.classList.add('btn-light');
        }
    }

    ocultarTodosLosDetalles() {
        document.querySelectorAll('.detalles-pedido.mostrar').forEach(det => {
            det.classList.remove('mostrar');
        });
        
        document.querySelectorAll('.pedido-card .btn.btn-info').forEach(btn => {
            if (btn.innerHTML.includes('Ocultar') || btn.innerHTML.includes('fa-eye-slash')) {
                btn.innerHTML = '<i class="fas fa-list me-1"></i>Detalles';
                btn.classList.remove('btn-info');
                btn.classList.add('btn-light');
            }
        });
    }

    confirmarCobro(event) {
        event.preventDefault();
        const form = event.target.closest('form');
        const comandaId = form.querySelector('input[name="comanda_id"]').value;
        
        Swal.fire({
            title: '¿Confirmar cobro?',
            text: `¿Estás seguro de que deseas ir a cobrar el pedido?`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#28a745',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Sí, ir a cobrar',
            cancelButtonText: 'Cancelar'
        }).then((result) => {
            if (result.isConfirmed) {
                // Asumiendo que el botón es un enlace o submit que lleva al formulario
                // Si es un enlace <a>, el preventDefault evita la navegación, así que redirigimos manualmente
                if (event.target.tagName === 'A' || event.target.closest('a')) {
                     window.location.href = event.target.closest('a').href;
                } else {
                    form.submit();
                }
            }
        });
        
        return false;
    }

    checkNotification() {
        const urlParams = new URLSearchParams(window.location.search);
        const cobroExito = urlParams.get('cobro_exito');

        if (cobroExito === 'true') {
            this.showToast('¡Pedido cobrado exitosamente!', 'success');
            this.clearUrlParams(['cobro_exito']);
        } else if (cobroExito === 'false') {
            const errorMsg = urlParams.get('error_msg') || 'Ocurrió un error al cobrar el pedido.';
            this.showToast(decodeURIComponent(errorMsg), 'error');
            this.clearUrlParams(['cobro_exito', 'error_msg']);
        }
    }

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

document.addEventListener('DOMContentLoaded', () => {
    window.cajaManager = new CajaManager();
    
    // Auto-scroll suave
    const hash = window.location.hash;
    if (hash) {
        const element = document.querySelector(hash);
        if (element) {
            setTimeout(() => {
                element.scrollIntoView({ behavior: 'smooth' });
            }, 100);
        }
    }

    // Cerrar detalles al hacer clic fuera
    document.addEventListener('click', (e) => {
        if (!e.target.closest('.btn') && !e.target.closest('.detalles-pedido')) {
             if (window.cajaManager) {
                window.cajaManager.ocultarTodosLosDetalles();
             }
        }
    });
});