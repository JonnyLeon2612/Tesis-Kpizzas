class DolarManager {
    constructor() {
        this.init();
    }

    init() {
        console.log('🚀 DolarManager inicializado');
        this.setupEventListeners();
        this.setupFormValidation();
        // CAMBIO: Se quitó 'this.autoHideAlerts();'
    }

    setupEventListeners() {
        // Validación en tiempo real del formulario
        const formulario = document.getElementById('form-dolar');
        if (formulario) {
            const inputs = formulario.querySelectorAll('input');
            inputs.forEach(input => {
                input.addEventListener('blur', () => this.validarCampo(input));
                input.addEventListener('input', () => this.limpiarValidacion(input));
            });
        }
    }

    setupFormValidation() {
        const formulario = document.getElementById('form-dolar');
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
        const formulario = document.getElementById('form-dolar');
        let esValido = true;

        const camposRequeridos = formulario.querySelectorAll('[required]');
        camposRequeridos.forEach(campo => {
            if (!this.validarCampo(campo)) {
                esValido = false;
            }
        });

        // Validar tasa
        const tasa = document.getElementById('tasa');
        if (tasa.value && parseFloat(tasa.value) <= 0) {
            this.marcarInvalido(tasa, 'La tasa debe ser mayor a 0');
            esValido = false;
        }

        // Validar fecha (no puede ser futura)
        const fecha = document.getElementById('fecha');
        const hoy = new Date().toISOString().split('T')[0];
        if (fecha.value > hoy) {
            this.marcarInvalido(fecha, 'La fecha no puede ser futura');
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

        if (campo.type === 'number' && campo.value) {
            if (parseFloat(campo.value) <= 0) {
                this.marcarInvalido(campo, 'El valor debe ser mayor a 0');
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

    // CAMBIO: Se eliminó la función 'autoHideAlerts'
}

// Inicializar cuando el DOM esté listo
document.addEventListener('DOMContentLoaded', () => {
    window.dolarManager = new DolarManager();
});