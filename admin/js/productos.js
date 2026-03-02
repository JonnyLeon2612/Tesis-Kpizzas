/**
 * productos.js - Funcionalidades para gestión de productos
 * Kpizza's Admin Panel
 */

class ProductosManager {
    constructor() {
        this.init();
    }

    init() {
        console.log('🚀 ProductosManager inicializado');
        this.setupEventListeners();
        this.setupFormValidation();
        this.autoHideAlerts();
    }

    setupEventListeners() {
        // Confirmación antes de eliminar producto
        document.addEventListener('click', (e) => {
            if (e.target.closest('.btn-eliminar')) {
                e.preventDefault();
                this.confirmarEliminacion(e.target.closest('.btn-eliminar'));
            }
        });

        // Validación en tiempo real del formulario
        const formulario = document.getElementById('form-producto');
        if (formulario) {
            const inputs = formulario.querySelectorAll('input, select, textarea');
            inputs.forEach(input => {
                input.addEventListener('blur', () => this.validarCampo(input));
                input.addEventListener('input', () => this.limpiarValidacion(input));
            });
        }
    }

    setupFormValidation() {
        const formulario = document.getElementById('form-producto');
        if (formulario) {
            formulario.addEventListener('submit', (e) => {
                if (!this.validarFormulario()) {
                    e.preventDefault();
                    e.stopPropagation();
                    this.mostrarError('Por favor, corrige los errores en el formulario.');
                }
            });
        }
    }

    validarFormulario() {
        const formulario = document.getElementById('form-producto');
        let esValido = true;

        const camposRequeridos = formulario.querySelectorAll('[required]');
        camposRequeridos.forEach(campo => {
            if (!this.validarCampo(campo)) {
                esValido = false;
            }
        });

        // Validar precios
        const precios = ['precio_pequena', 'precio_mediana', 'precio_familiar'];
        precios.forEach(nombre => {
            const campo = document.getElementById(nombre);
            if (campo && campo.value && parseFloat(campo.value) < 0) {
                this.marcarInvalido(campo, 'El precio no puede ser negativo');
                esValido = false;
            }
        });

        formulario.classList.add('was-validated');
        return esValido;
    }

    validarCampo(campo) {
        if (campo.hasAttribute('required') && !campo.value.trim()) {
            this.marcarInvalido(campo, 'Este campo es obligatorio');
            return false;
        }

        if (campo.type === 'number' && campo.value) {
            if (parseFloat(campo.value) < 0) {
                this.marcarInvalido(campo, 'El valor no puede ser negativo');
                return false;
            }
        }

        this.marcarValido(campo);
        return true;
    }

    marcarInvalido(campo, mensaje) {
        campo.classList.remove('is-valid');
        campo.classList.add('is-invalid');
        
        // Mostrar mensaje de error
        let feedback = campo.parentNode.querySelector('.invalid-feedback');
        if (!feedback) {
            feedback = document.createElement('div');
            feedback.className = 'invalid-feedback';
            campo.parentNode.appendChild(feedback);
        }
        feedback.textContent = mensaje;
    }

    marcarValido(campo) {
        campo.classList.remove('is-invalid');
        campo.classList.add('is-valid');
        
        // Limpiar mensaje de error
        const feedback = campo.parentNode.querySelector('.invalid-feedback');
        if (feedback) {
            feedback.textContent = '';
        }
    }

    limpiarValidacion(campo) {
        campo.classList.remove('is-valid', 'is-invalid');
    }

confirmarEliminacion(boton) {
        const nombreProducto = boton.getAttribute('data-producto');
        const estadoActual = boton.getAttribute('data-estado');
        const url = boton.getAttribute('href');
        
        const Titulo = estadoActual === 'activo' ? '¿Desactivar Producto?' : '¿Activar Producto?';
        const TextoBoton = estadoActual === 'activo' ? 'Sí, desactivar' : 'Sí, activar';
        const ColorBoton = estadoActual === 'activo' ? '#d33' : '#28a745';

        Swal.fire({
            title: Titulo,
            html: `¿Deseas cambiar el estado de <strong>"${nombreProducto}"</strong>?<br><br><small class="text-muted">Esto afectará la visibilidad en el menú del mesero.</small>`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: ColorBoton,
            cancelButtonColor: '#6c757d',
            confirmButtonText: TextoBoton,
            cancelButtonText: 'Cancelar',
            reverseButtons: true
        }).then((result) => {
            if (result.isConfirmed) {
                this.procesarEliminacion(url);
            }
        });
    }

    procesarEliminacion(url) {
        Swal.fire({
            title: 'Actualizando estado...',
            text: 'Por favor espere un momento',
            icon: 'info',
            allowOutsideClick: false,
            showConfirmButton: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });

        // Forzamos el viaje a la URL para que el PHP ejecute el UPDATE
        window.location.href = url;
    }

    // Estas funciones ahora pintan las alertas bonitas de SweetAlert
    mostrarError(mensaje) {
        Swal.fire({
            title: 'Error',
            text: mensaje,
            icon: 'error',
            confirmButtonColor: '#d32f2f',
            confirmButtonText: 'Entendido'
        });
    }

    mostrarExito(mensaje) {
        Swal.fire({
            title: '¡Éxito!',
            text: mensaje,
            icon: 'success',
            confirmButtonColor: '#28a745',
            confirmButtonText: 'Continuar'
        });
    }

    autoHideAlerts() {
        // Esta función cierra las alertas de Bootstrap (las de arriba del formulario) solas después de 5 seg
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
                if (bsAlert) bsAlert.close();
            });
        }, 5000);
    }
}

// Inicializar cuando el DOM esté listo
document.addEventListener('DOMContentLoaded', () => {
    window.productosManager = new ProductosManager();
});