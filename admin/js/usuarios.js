/**
 * usuarios.js - Funcionalidades para gestión de usuarios
 * Kpizza's Admin Panel
 */

class UsuariosManager {
    constructor() {
        this.init();
    }

    init() {
        console.log('🚀 UsuariosManager inicializado');
        this.setupEventListeners();
        this.setupFormValidation();
        this.autoHideAlerts();
    }

    setupEventListeners() {
        // Confirmación antes de eliminar usuario
        document.addEventListener('click', (e) => {
            if (e.target.closest('.btn-eliminar')) {
                e.preventDefault();
                this.confirmarEliminacion(e.target.closest('.btn-eliminar'));
            }
        });

        // Validación en tiempo real del formulario
        const formulario = document.getElementById('form-usuario');
        if (formulario) {
            const inputs = formulario.querySelectorAll('input, select');
            inputs.forEach(input => {
                input.addEventListener('blur', () => this.validarCampo(input));
                input.addEventListener('input', () => this.limpiarValidacion(input));
            });
        }
    }

    setupFormValidation() {
        const formulario = document.getElementById('form-usuario');
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
        const formulario = document.getElementById('form-usuario');
        let esValido = true;

        const camposRequeridos = formulario.querySelectorAll('[required]');
        camposRequeridos.forEach(campo => {
            if (!this.validarCampo(campo)) {
                esValido = false;
            }
        });

        // Validar contraseña para nuevos usuarios
        const contrasena = document.getElementById('contrasena');
        const estaEditando = document.querySelector('input[name="id"]');
        
        if (!estaEditando && contrasena.value.length < 6) {
            this.marcarInvalido(contrasena, 'La contraseña debe tener al menos 6 caracteres');
            esValido = false;
        }

        // Validar nombre de usuario
        const usuario = document.getElementById('usuario');
        if (usuario.value.includes(' ')) {
            this.marcarInvalido(usuario, 'El nombre de usuario no puede contener espacios');
            esValido = false;
        }

        formulario.classList.add('was-validated');
        return esValido;
    }

    validarCampo(campo) {
        if (campo.hasAttribute('required') && !campo.value.trim()) {
            this.marcarInvalido(campo, 'Este campo es obligatorio');
            return false;
        }

        if (campo.type === 'email' && campo.value) {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(campo.value)) {
                this.marcarInvalido(campo, 'Por favor ingresa un email válido');
                return false;
            }
        }

        if (campo.id === 'contrasena' && campo.value.length > 0 && campo.value.length < 6) {
            this.marcarInvalido(campo, 'La contraseña debe tener al menos 6 caracteres');
            return false;
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
        const nombreUsuario = boton.getAttribute('data-usuario');
        const url = boton.getAttribute('href');

        Swal.fire({
            title: '¿Eliminar Usuario?',
            html: `¿Estás seguro de que deseas eliminar al usuario <strong>"${nombreUsuario}"</strong>?<br><br>
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
            title: 'Eliminando usuario...',
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
    window.usuariosManager = new UsuariosManager();
});

// Función global para mostrar notificaciones
window.mostrarNotificacion = function(mensaje, tipo = 'success') {
    const Toast = Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true,
        didOpen: (toast) => {
            toast.addEventListener('mouseenter', Swal.stopTimer);
            toast.addEventListener('mouseleave', Swal.resumeTimer);
        }
    });

    Toast.fire({
        icon: tipo,
        title: mensaje
    });
};