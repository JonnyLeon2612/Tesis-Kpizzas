// cocina.js - Versión con SweetAlert2

// Definimos una clase para organizar toda la lógica de la cocina.
class CocinaManager {
    constructor() {
        // El constructor llama a init() al crear una nueva instancia.
        this.init();
    }

    init() {
        // Método de inicialización (actualmente solo imprime en consola).
        console.log('CocinaManager inicializado');
    }

    // Método para mostrar u ocultar los detalles de un pedido.
    toggleDetalles(pedidoId) {
        // Obtiene el elemento div de detalles usando el ID.
        const detalles = document.getElementById('detalles-' + pedidoId);
        // Obtiene el botón que fue presionado.
        const boton = event.target;
        
        if (!detalles) {
            console.error('No se encontraron detalles para el pedido:', pedidoId);
            return;
        }
        
        // Comprueba si los detalles ya están visibles (tienen la clase 'mostrar').
        if (detalles.classList.contains('mostrar')) {
            this.ocultarDetalles(detalles, boton);
        } else {
            this.mostrarDetalles(detalles, boton);
        }
    }

    // Muestra los detalles de un pedido.
    mostrarDetalles(detalles, boton) {
        // Primero, oculta cualquier otro detalle que esté abierto.
        this.ocultarTodosLosDetalles();
        
        // Añade la clase 'mostrar' para hacerlo visible (controlado por CSS).
        detalles.classList.add('mostrar');
        // Cambia el texto y el icono del botón.
        boton.innerHTML = '<i class="fas fa-times me-1"></i>Cerrar';
        
        // Hace scroll suave para que el detalle sea visible en la pantalla.
        setTimeout(() => {
            detalles.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }, 100);
    }

    // Oculta los detalles de un pedido.
    ocultarDetalles(detalles, boton) {
        detalles.classList.remove('mostrar');
        boton.innerHTML = '<i class="fas fa-list me-1"></i>Detalles';
    }

    // Cierra todos los detalles abiertos en la página.
    ocultarTodosLosDetalles() {
        document.querySelectorAll('.detalles-pedido.mostrar').forEach(det => {
            det.classList.remove('mostrar');
        });
        
        // Restaura el texto de todos los botones de "Detalles".
        document.querySelectorAll('.btn-info').forEach(btn => {
            if (btn.innerHTML.includes('Cerrar')) {
                btn.innerHTML = '<i class="fas fa-list me-1"></i>Detalles';
            }
        });
    }

    // Muestra un pop-up de confirmación para marcar un pedido como 'listo'.
    marcarComoListo(comandaId) {
        Swal.fire({
            title: '¿Marcar pedido como listo?',
            text: "El pedido será marcado como listo para servir",
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#198754', // Verde
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Sí, marcar como listo',
            cancelButtonText: 'Cancelar',
        }).then((result) => {
            // Si el usuario confirma...
            if (result.isConfirmed) {
                // Llama a enviarEstado() para procesar el cambio.
                this.enviarEstado(comandaId, 'listo');
            }
        });
    }

    // Muestra un pop-up de confirmación para devolver un pedido a 'en_preparacion'.
    volverAPreparacion(comandaId) {
        Swal.fire({
            title: '¿Volver a preparación?',
            text: "El pedido volverá a estado de preparación",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ffc107', // Amarillo
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Sí, volver a preparar',
            cancelButtonText: 'Cancelar',
        }).then((result) => {
            // Si el usuario confirma...
            if (result.isConfirmed) {
                this.enviarEstado(comandaId, 'en_preparacion');
            }
        });
    }

    // Envía el nuevo estado al servidor (a tablero.php).
    enviarEstado(comandaId, estado) {
        // Muestra un pop-up de "Cargando...".
        Swal.fire({
            title: 'Procesando...',
            text: 'Actualizando estado del pedido',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });

        // Crea un objeto FormData para enviar los datos como un formulario POST.
        const formData = new FormData();
        formData.append('comanda_id', comandaId);
        formData.append('estado', estado);

        // Envía los datos usando fetch a 'tablero.php'.
        fetch('tablero.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            // Si el servidor responde 'ok' (200), muestra éxito.
            if (response.ok) {
                this.mostrarExito();
            } else {
                throw new Error('Error en la respuesta del servidor');
            }
        })
        .catch(error => {
            // Si hay un error, muestra un pop-up de error.
            console.error('Error:', error);
            this.mostrarError();
        });
    }

    // Muestra un pop-up de éxito y recarga la página.
    mostrarExito() {
        Swal.fire({
            title: '¡Éxito!',
            text: 'Estado del pedido actualizado correctamente',
            icon: 'success',
            confirmButtonColor: '#198754',
            timer: 2000, // Se cierra automáticamente después de 2 segundos.
            showConfirmButton: false
        }).then(() => {
            // Recarga la página para mostrar el pedido actualizado
            // (movido o con el estado cambiado).
            location.reload();
        });
    }

    // Muestra un pop-up de error.
    mostrarError() {
        Swal.fire({
            title: 'Error',
            text: 'No se pudo actualizar el estado del pedido',
            icon: 'error',
            confirmButtonColor: '#dc3545',
            confirmButtonText: 'Entendido'
        });
    }
}

// Se ejecuta cuando el HTML de la página está completamente cargado.
document.addEventListener('DOMContentLoaded', () => {
    // Crea una instancia global de CocinaManager para que
    // los botones 'onclick' en el HTML puedan usarla.
    window.cocinaManager = new CocinaManager();
    
    // Cierra cualquier detalle abierto si el usuario hace clic
    // fuera de la tarjeta de pedido.
    document.addEventListener('click', function(event) {
        if (!event.target.closest('.btn-info') && 
            !event.target.closest('.detalles-pedido') &&
            !event.target.closest('.btn-success') &&
            !event.target.closest('.btn-warning')) {
            window.cocinaManager.ocultarTodosLosDetalles();
        }
    });
    
    // Auto-recarga la página cada 30 segundos para
    // buscar nuevos pedidos automáticamente.
    setTimeout(() => {
        location.reload();
    }, 30000); // 30000 ms = 30 segundos
});