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
        const url = boton.getAttribute('href');

        Swal.fire({
            title: '¿Eliminar Producto?',
            html: `¿Estás seguro de que deseas eliminar el producto <strong>"${nombreProducto}"</strong>?<br><br>
                  <span class="text-danger">Esta acción no se puede deshacer.</span>`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Sí, eliminar',
            cancelButtonText: 'Cancelar',
            backdrop: true,
            allowOutsideClick: false,
            customClass: {
                popup: 'sweetalert-custom'
            }
        }).then((result) => {
            if (result.isConfirmed) {
                this.procesarEliminacion(url);
            }
        });
    }

    procesarEliminacion(url) {
        Swal.fire({
            title: 'Eliminando producto...',
            text: 'Por favor espere',
            icon: 'info',
            allowOutsideClick: false,
            showConfirmButton: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });

        // Simular redirección (en una aplicación real, esto sería una petición AJAX)
        setTimeout(() => {
            window.location.href = url;
        }, 1000);
    }

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
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
    }
}

// Inicializar cuando el DOM esté listo
document.addEventListener('DOMContentLoaded', () => {
    window.productosManager = new ProductosManager();
});