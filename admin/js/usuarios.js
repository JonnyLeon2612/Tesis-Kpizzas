/**
 * usuarios.js - Funcionalidades corregidas para gestión de usuarios
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
        // Confirmación antes de cambiar estado (Soft Delete)
        document.addEventListener('click', (e) => {
            const boton = e.target.closest('.btn-eliminar');
            if (boton) {
                e.preventDefault();
                this.confirmarEliminacion(boton);
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

        // Validar nombre de usuario (sin espacios)
        const usuario = document.getElementById('usuario');
        if (usuario && usuario.value.includes(' ')) {
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

        // Validación mínima de contraseña
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
    }

    limpiarValidacion(campo) {
        campo.classList.remove('is-valid', 'is-invalid');
    }

confirmarEliminacion(boton) {
        const nombre = boton.getAttribute('data-usuario');
        const estado = boton.getAttribute('data-estado');
        const url = boton.getAttribute('href');
        
        const config = {
            titulo: estado === 'activo' ? '¿Desactivar Usuario?' : '¿Activar Usuario?',
            textoBtn: estado === 'activo' ? 'Sí, desactivar' : 'Sí, activar',
            color: estado === 'activo' ? '#d33' : '#28a745',
            icono: estado === 'activo' ? 'warning' : 'question'
        };

        Swal.fire({
            title: config.titulo,
            html: `¿Deseas cambiar el estado de <strong>"${nombre}"</strong>?<br><small class="text-muted">Esto afectará los permisos de acceso al sistema.</small>`,
            icon: config.icono,
            showCancelButton: true,
            confirmButtonColor: config.color,
            cancelButtonColor: '#6c757d',
            confirmButtonText: config.textoBtn,
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
            title: 'Actualizando...',
            text: 'Cambiando privilegios de acceso',
            icon: 'info',
            allowOutsideClick: false,
            showConfirmButton: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });
        window.location.href = url;
    }

    mostrarError(mensaje) {
        Swal.fire({
            title: 'Error',
            text: mensaje,
            icon: 'error',
            confirmButtonColor: '#d32f2f'
        });
    }

    autoHideAlerts() {
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                if (typeof bootstrap !== 'undefined') {
                    const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
                    if (bsAlert) bsAlert.close();
                }
            });
        }, 5000);
    }
}

// Inicializar cuando el DOM esté listo
document.addEventListener('DOMContentLoaded', () => {
    window.usuariosManager = new UsuariosManager();
});

// Función global para notificaciones tipo Toast
window.mostrarNotificacion = function(mensaje, tipo = 'success') {
    const Toast = Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true
    });
    Toast.fire({ icon: tipo, title: mensaje });
};