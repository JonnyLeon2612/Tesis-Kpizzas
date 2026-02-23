document.addEventListener('DOMContentLoaded', function() {
    // ---------------------------------------------------------
    // 1. OBTENCIÓN DE DATOS Y COLORES
    // ---------------------------------------------------------
    const { 
        ventas, mensuales, servicios, productos, 
        meseros, moneda, tipoPago, tasaActual
    } = window.reportData || {};

    const kpizzaRed = '#d32f2f';
    const kpizzaBlue = '#2196f3';
    const kpizzaGreen = '#4caf50';
    const kpizzaOrange = '#ff9800';
    const kpizzaPurple = '#6f42c1';
    const kpizzaGrey = '#607d8b';

    const tooltipCallbacksUSD = {
        label: function(context) {
            let label = context.dataset.label || context.label || '';
            if (label) label += ': ';
            let value = context.parsed.y !== undefined ? context.parsed.y : context.parsed.x;
            if (value !== null) {
                label += new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(value);
            }
            return label;
        }
    };
    
    let chartInstances = {}; 

    function destroyChart(chartId) {
        if (chartInstances[chartId]) {
            chartInstances[chartId].destroy();
            chartInstances[chartId] = null;
        }
    }

    // ---------------------------------------------------------
    // 2. CONFIGURACIÓN MAESTRA
    // ---------------------------------------------------------
    function initChart(chartId, chartType, config) {
        const canvas = document.getElementById(chartId);
        if (!canvas) {
            console.warn(`Canvas con ID ${chartId} no encontrado`);
            return null;
        }

        destroyChart(chartId);
        const ctx = canvas.getContext('2d');
        
        const baseConfig = {
            responsive: true,
            maintainAspectRatio: false,
            layout: {
                padding: {
                    top: 10, right: 25, bottom: 10, left: 10
                }
            },
            plugins: {
                legend: { 
                    position: 'bottom',
                    labels: {
                        usePointStyle: true,
                        padding: 20,
                        boxWidth: 10,
                        font: { size: window.innerWidth < 768 ? 10 : 12 }
                    }
                },
                tooltip: {
                    padding: 12, 
                    caretSize: 6, 
                    cornerRadius: 6,
                    enabled: true
                }
            }
        };
        
        let typeSpecificConfig = {};
        
        switch(chartType) {
            case 'line':
                typeSpecificConfig = {
                    scales: {
                        x: { 
                            grid: { display: false }, 
                            ticks: { font: { size: 11 } } 
                        },
                        y: { 
                            beginAtZero: true, 
                            ticks: { 
                                callback: v => '$' + v 
                            }
                        }
                    }
                };
                break;
            case 'bar':
                const isHorizontal = config.options?.indexAxis === 'y';
                typeSpecificConfig = {
                    scales: {
                        x: {
                            beginAtZero: true,
                            grid: { display: !isHorizontal },
                            ticks: {
                                font: { size: 11 },
                                callback: function(value) {
                                    const label = config.data.datasets[0].label || '';
                                    if (label.includes('Transacciones') || label.includes('Vendidos')) {
                                        return Math.floor(value) === value ? value : '';
                                    }
                                    return '$' + value;
                                }
                            }
                        },
                        y: {
                            grid: { display: isHorizontal },
                            ticks: {
                                font: { size: 11 },
                                autoSkip: false
                            }
                        }
                    }
                };
                break;
            case 'pie':
            case 'doughnut':
                typeSpecificConfig = {
                    cutout: chartType === 'doughnut' ? '60%' : '0%',
                    plugins: { 
                        legend: { 
                            position: window.innerWidth < 768 ? 'bottom' : 'right' 
                        } 
                    }
                };
                break;
        }
        
        const finalConfig = {
            ...baseConfig,
            ...typeSpecificConfig,
            ...config.options,
            plugins: { 
                ...baseConfig.plugins, 
                ...typeSpecificConfig.plugins, 
                ...config.options?.plugins 
            }
        };

        try {
            chartInstances[chartId] = new Chart(ctx, {
                type: chartType,
                data: config.data,
                options: finalConfig
            });
            
            console.log(`Gráfica ${chartId} inicializada correctamente`);
            return chartInstances[chartId];
        } catch (error) {
            console.error(`Error inicializando gráfica ${chartId}:`, error);
            return null;
        }
    }

    // ---------------------------------------------------------
    // 3. DEFINICIÓN DE PESTAÑAS
    // ---------------------------------------------------------
    const tabs = {
        'ventas-tab': () => {
            console.log('Inicializando pestaña Ventas');
            
            // Gráfica de tendencia diaria
            if (ventas && ventas.length > 0) {
                initChart('ventasChart', 'line', {
                    data: {
                        labels: ventas.map(d => d.fecha),
                        datasets: [{
                            label: 'Ventas (USD)',
                            data: ventas.map(d => d.ventas),
                            borderColor: kpizzaRed,
                            backgroundColor: 'rgba(211, 47, 47, 0.05)',
                            fill: true,
                            tension: 0.3,
                            borderWidth: 2
                        }]
                    },
                    options: { 
                        plugins: { 
                            tooltip: { 
                                callbacks: tooltipCallbacksUSD 
                            } 
                        } 
                    }
                });
            } else {
                console.warn('No hay datos de ventas para mostrar');
            }
            
            // Gráfica mensual
            if (mensuales && mensuales.length > 0) {
                initChart('ventasMensualesChart', 'bar', {
                    data: {
                        labels: mensuales.map(d => d.mes),
                        datasets: [{
                            label: 'Ventas (USD)',
                            data: mensuales.map(d => d.ventas),
                            backgroundColor: kpizzaBlue,
                            borderRadius: 4,
                            borderWidth: 0
                        }]
                    },
                    options: { 
                        plugins: { 
                            legend: { display: false }, 
                            tooltip: { callbacks: tooltipCallbacksUSD } 
                        } 
                    }
                });
            }
        },
        
        'pagos-tab': () => {
            console.log('Inicializando pestaña Pagos');
            
            if (tipoPago && tipoPago.length > 0) {
                initChart('tipoPagoChart', 'bar', {
                    data: {
                        labels: tipoPago.map(d => d.tipo_pago),
                        datasets: [{
                            label: 'Transacciones',
                            data: tipoPago.map(d => d.total_transacciones),
                            backgroundColor: [kpizzaPurple, kpizzaBlue, kpizzaGreen, kpizzaOrange, kpizzaRed],
                            borderRadius: 4
                        }]
                    },
                    options: {
                        indexAxis: 'y',
                        plugins: { 
                            legend: { display: false }, 
                            tooltip: { 
                                callbacks: { 
                                    label: (c) => `${c.parsed.x} transacciones` 
                                } 
                            } 
                        }
                    }
                });
            }
            
            if (moneda && moneda.length > 0) {
                const usdData = moneda.find(m => m.moneda === 'USD')?.total_convertido_usd || 0;
                const bsData = moneda.find(m => m.moneda === 'BS')?.total_convertido_usd || 0;
                
                initChart('monedaChart', 'pie', {
                    data: {
                        labels: ['USD', 'BS (Convertido)'],
                        datasets: [{
                            data: [usdData, bsData],
                            backgroundColor: [kpizzaBlue, kpizzaGreen],
                            borderWidth: 1
                        }]
                    },
                    options: {
                        plugins: {
                            tooltip: {
                                callbacks: {
                                    label: (c) => {
                                        const val = c.parsed;
                                        const total = c.dataset.data.reduce((a, b) => a + b, 0);
                                        const perc = total > 0 ? ((val / total) * 100).toFixed(1) : 0;
                                        return `${c.label}: $${val.toFixed(2)} (${perc}%)`;
                                    }
                                }
                            }
                        }
                    }
                });
            }
        },
        
        'productos-tab': () => {
            console.log('Inicializando pestaña Productos');
            
            if (productos && productos.length > 0) {
                initChart('productosChart', 'doughnut', {
                    data: {
                        labels: productos.map(d => d.producto),
                        datasets: [{
                            label: 'Vendidos',
                            data: productos.map(d => d.vendidos),
                            backgroundColor: [kpizzaRed, kpizzaBlue, kpizzaGreen, kpizzaOrange, kpizzaPurple, kpizzaGrey]
                        }]
                    }
                });
                
                initChart('ingresosProductosChart', 'bar', {
                    data: {
                        labels: productos.map(d => d.producto),
                        datasets: [{
                            label: 'Ingresos ($)',
                            data: productos.map(d => d.ingresos),
                            backgroundColor: kpizzaGreen,
                            borderRadius: 4
                        }]
                    },
                    options: { 
                        indexAxis: 'y', 
                        plugins: { 
                            legend: { display: false }, 
                            tooltip: { callbacks: tooltipCallbacksUSD } 
                        } 
                    }
                });
            }
        },
        
        'servicios-tab': () => {
            console.log('Inicializando pestaña Servicios');
            
            if (servicios && servicios.length > 0) {
                initChart('serviciosChart', 'pie', {
                    data: {
                        labels: servicios.map(d => d.servicio),
                        datasets: [{
                            data: servicios.map(d => d.ventas),
                            backgroundColor: [kpizzaRed, kpizzaBlue],
                            borderWidth: 1
                        }]
                    },
                    options: { 
                        plugins: { 
                            tooltip: { callbacks: tooltipCallbacksUSD } 
                        } 
                    }
                });
            }
        },
        
        'meseros-tab': () => {
            console.log('Inicializando pestaña Meseros');
            
            if (meseros && meseros.length > 0) {
                initChart('meserosChart', 'bar', {
                    data: {
                        labels: meseros.map(d => d.mesero),
                        datasets: [
                            { 
                                label: 'Mesa ($)', 
                                data: meseros.map(d => d.Mesa), 
                                backgroundColor: kpizzaRed, 
                                borderRadius: 2 
                            },
                            { 
                                label: 'Llevar ($)', 
                                data: meseros.map(d => d.Llevar), 
                                backgroundColor: kpizzaBlue, 
                                borderRadius: 2 
                            }
                        ]
                    },
                    options: {
                        scales: { 
                            x: { stacked: true }, 
                            y: { 
                                stacked: true, 
                                beginAtZero: true, 
                                ticks: { callback: v => '$' + v } 
                            } 
                        },
                        plugins: { 
                            tooltip: { callbacks: tooltipCallbacksUSD } 
                        }
                    }
                });
            }
        }
    };

    // ---------------------------------------------------------
    // 4. LÓGICA DE EVENTOS Y CARGA INICIAL - FIX CRÍTICO
    // ---------------------------------------------------------
    
    // A. Función para forzar la inicialización de gráficas
    function initializeActiveTab() {
        // Siempre inicializar la pestaña Ventas primero (es la principal)
        if (tabs['ventas-tab']) {
            console.log('Inicializando gráficas de Ventas por defecto');
            tabs['ventas-tab']();
        }
        
        // Luego, si hay otra pestaña activa que no sea ventas, inicializarla también
        const activeTabElement = document.querySelector('.nav-link.active');
        if (activeTabElement && activeTabElement.id !== 'ventas-tab' && tabs[activeTabElement.id]) {
            console.log(`También inicializando pestaña activa: ${activeTabElement.id}`);
            tabs[activeTabElement.id]();
        }
        
        // Forzar redimensionado después de un pequeño delay
        setTimeout(() => {
            Object.keys(chartInstances).forEach(k => {
                if (chartInstances[k]) {
                    chartInstances[k].resize();
                }
            });
        }, 300);
    }
    
    // B. Inicializar inmediatamente al cargar la página
    console.log('Iniciando inicialización de gráficas...');
    initializeActiveTab();
    
    // C. Evento al cambiar pestaña
    document.querySelectorAll('.nav-pills .nav-link').forEach(tabEl => {
        tabEl.addEventListener('shown.bs.tab', (event) => {
            const tabId = event.target.id;
            console.log(`Cambiando a pestaña: ${tabId}`);
            
            if (tabs[tabId]) {
                // Pequeño delay para asegurar que el tab está visible
                setTimeout(() => {
                    tabs[tabId]();
                    // Redimensionar todas las gráficas
                    Object.keys(chartInstances).forEach(k => {
                        if (chartInstances[k]) {
                            chartInstances[k].resize();
                        }
                    });
                }, 100);
            }
        });
    });

    // D. Manejo de Resize de ventana
    window.addEventListener('resize', () => {
        clearTimeout(window.resizeTimer);
        window.resizeTimer = setTimeout(() => {
            Object.keys(chartInstances).forEach(k => {
                if (chartInstances[k]) {
                    chartInstances[k].resize();
                }
            });
        }, 200);
    });

    // E. Cierre automático de alertas
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert-dismissible');
        alerts.forEach(alert => {
            if (typeof bootstrap !== 'undefined') {
                const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
                if (bsAlert) bsAlert.close();
            }
        });
    }, 5000);
});

// ==========================================
// MÓDULO DE EXPORTACIÓN (EXCEL Y PDF)
// ==========================================
// MÓDULO DE EXPORTACIÓN PROFESIONAL (EXCEL Y PDF)
// ==========================================

// --- FUNCIÓN AUXILIAR: Obtener fechas del filtro ---
function getFechasReporte() {
    const inputDesde = document.querySelector('input[name="desde"]');
    const inputHasta = document.querySelector('input[name="hasta"]');
    
    // Función para formatear de AAAA-MM-DD a DD/MM/AAAA
    const formatFecha = (fechaStr) => {
        if (!fechaStr) return 'N/A';
        const p = fechaStr.split('-');
        return `${p[2]}/${p[1]}/${p[0]}`;
    };

    const rawDesde = inputDesde ? inputDesde.value : '';
    const rawHasta = inputHasta ? inputHasta.value : '';

    return {
        rawDesde: rawDesde, // Para el nombre del archivo (AAAA-MM-DD)
        rawHasta: rawHasta,
        formatDesde: formatFecha(rawDesde), // Para el texto del PDF (DD/MM/AAAA)
        formatHasta: formatFecha(rawHasta)
    };
}

// --- EXPORTAR A EXCEL (CON COLOR Y AUTO-ANCHO) ---
window.exportarExcel = function(tableId, prefijoNombre) {
    const tabla = document.getElementById(tableId);
    if (!tabla) {
        alert('Error: No se encontró la tabla para exportar');
        return;
    }
    
    // Generar nombre de archivo dinámico
    const fechas = getFechasReporte();
    const nombreArchivo = `Kpizzas_${prefijoNombre}_${fechas.rawDesde}_al_${fechas.rawHasta}`;
    
    // Convertir la tabla HTML a un libro de Excel
    const wb = XLSX.utils.table_to_book(tabla, { sheet: "Reporte Kpizzas" });
    const ws = wb.Sheets["Reporte Kpizzas"];
    
    if (ws['!ref']) {
        const range = XLSX.utils.decode_range(ws['!ref']);
        let colsWidths = []; 

        for (let C = range.s.c; C <= range.e.c; ++C) {
            let maxWidth = 12; 

            for (let R = range.s.r; R <= range.e.r; ++R) {
                const address = XLSX.utils.encode_cell({ r: R, c: C });
                const cell = ws[address];
                
                if (!cell) continue;

                // Cabecera: Rojo Kpizzas corporativo
                if (R === 0) {
                    cell.s = {
                        fill: { fgColor: { rgb: "D82626" } },
                        font: { color: { rgb: "FFFFFF" }, bold: true }
                    };
                }

                // Auto-ajuste de columnas
                if (cell.v) {
                    let textLength = String(cell.v).length;
                    if (R === 0) textLength += 3; // Extra para negritas
                    if (textLength > maxWidth) maxWidth = textLength;
                }
            }
            colsWidths.push({ wch: Math.round(maxWidth + 5) });
        }
        ws['!cols'] = colsWidths;
    }
    
    // Descargar
    XLSX.writeFile(wb, `${nombreArchivo}.xlsx`);
};

// --- EXPORTAR A PDF (FORMATO CORPORATIVO) ---
window.exportarPDF = function(tableId, prefijoNombre, tituloReporte) {
    const tabla = document.getElementById(tableId);
    if (!tabla) {
        alert('Error: No se encontró la tabla para exportar');
        return;
    }

    const { jsPDF } = window.jspdf;
    const doc = new jsPDF();
    
    // Datos dinámicos
    const fechas = getFechasReporte();
    const nombreArchivo = `Kpizzas_${prefijoNombre}_${fechas.rawDesde}_al_${fechas.rawHasta}`;
    
    const ahora = new Date();
    const fechaImpresion = ahora.toLocaleDateString('es-VE');
    const horaImpresion = ahora.toLocaleTimeString('es-VE', {hour: '2-digit', minute:'2-digit'});

    const pageWidth = doc.internal.pageSize.getWidth();
    const centerX = pageWidth / 2;

    // 1. MEMBRETE: Logo / Nombre de Empresa (CENTRADO)
    doc.setFontSize(22);
    doc.setTextColor(216, 38, 38); // Rojo Kpizzas
    doc.setFont("helvetica", "bold");
    // Se pasa centerX como coordenada X, y la opción align: "center"
    doc.text("Kpizza's", centerX, 20, { align: "center" });
    
    doc.setFontSize(10);
    doc.setTextColor(100, 100, 100);
    doc.setFont("helvetica", "normal");
    doc.text("Sistema de Gestión y Control", centerX, 25, { align: "center" });

    // 2. TÍTULO DEL DOCUMENTO
    doc.setFontSize(16);
    doc.setTextColor(40, 40, 40);
    doc.setFont("helvetica", "bold");
    doc.text(tituloReporte.toUpperCase(), 14, 37);

    // 3. METADATOS DEL REPORTE
    doc.setFontSize(9);
    doc.setFont("helvetica", "normal");
    doc.setTextColor(80, 80, 80);
    doc.text(`Período analizado: ${fechas.formatDesde} al ${fechas.formatHasta}`, 14, 43);
    doc.text(`Generado el: ${fechaImpresion} a las ${horaImpresion}`, 14, 48);

    // 4. LÍNEA SEPARADORA ELEGANTE
    doc.setDrawColor(216, 38, 38); // Línea roja
    doc.setLineWidth(0.5);
    doc.line(14, 52, 196, 52);

    // 5. GENERACIÓN DE LA TABLA
    doc.autoTable({
        html: `#${tableId}`,
        startY: 58, // Empezar debajo de la línea
        theme: 'grid',
        styles: {
            fontSize: 8,
            cellPadding: 3,
            font: "helvetica"
        },
        headStyles: {
            fillColor: [216, 38, 38], // Rojo
            textColor: 255,
            fontStyle: 'bold',
            halign: 'center'
        },
        alternateRowStyles: {
            fillColor: [248, 249, 250] // Gris muy claro
        },
        
        // 6. PIE DE PÁGINA PROFESIONAL EN CADA PÁGINA
        didDrawPage: function (data) {
            const pageSize = doc.internal.pageSize;
            const pageHeight = pageSize.height ? pageSize.height : pageSize.getHeight();
            
            doc.setFontSize(8);
            doc.setFont("helvetica", "italic");
            doc.setTextColor(150, 150, 150);
            
            // Texto Confidencial a la izquierda
            doc.text("Reporte Confidencial - Solo Uso Interno", data.settings.margin.left, pageHeight - 10);
            
            // Número de página a la derecha
            const pageNumberStr = "Página " + doc.internal.getNumberOfPages();
            doc.text(pageNumberStr, pageSize.width - data.settings.margin.right - 20, pageHeight - 10);
        }
    });

    // 7. DESCARGAR ARCHIVO
    doc.save(`${nombreArchivo}.pdf`);
};