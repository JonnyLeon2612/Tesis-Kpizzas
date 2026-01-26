document.addEventListener('DOMContentLoaded', function() {
    
    const { 
        ventas, 
        mensuales, 
        servicios, 
        productos, 
        meseros,
        moneda,
        tipoPago,
        tasaActual
    } = window.reportData || {};

    const kpizzaRed = '#d32f2f';
    const kpizzaBlue = '#2196f3';
    const kpizzaGreen = '#4caf50';
    const kpizzaOrange = '#ff9800';
    const kpizzaPurple = '#6f42c1';

    const tooltipCallbacksUSD = {
        label: function(context) {
            let label = context.dataset.label || context.label || '';
            if (label) {
                label += ': ';
            }
            let value = context.parsed.y;
            if (context.parsed.x !== null && context.parsed.y === undefined) { 
                value = context.parsed.x;
            }
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

    const tabs = {
        'ventas-tab': () => {
            destroyChart('ventasChart');
            if (ventas && document.getElementById('ventasChart')) {
                chartInstances['ventasChart'] = new Chart(document.getElementById('ventasChart').getContext('2d'), {
                    type: 'line',
                    data: {
                        labels: ventas.map(d => d.fecha),
                        datasets: [{
                            label: 'Ventas (USD)',
                            data: ventas.map(d => d.ventas),
                            borderColor: kpizzaRed,
                            backgroundColor: 'rgba(211, 47, 47, 0.1)',
                            borderWidth: 3,
                            fill: true,
                            tension: 0.4,
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: { y: { beginAtZero: true, ticks: { callback: v => '$' + v }}},
                        plugins: { tooltip: { callbacks: tooltipCallbacksUSD } }
                    }
                });
            }
            
            destroyChart('ventasMensualesChart');
            if (mensuales && document.getElementById('ventasMensualesChart')) {
                chartInstances['ventasMensualesChart'] = new Chart(document.getElementById('ventasMensualesChart').getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: mensuales.map(d => d.mes),
                        datasets: [{
                            label: 'Ventas (USD)',
                            data: mensuales.map(d => d.ventas),
                            backgroundColor: kpizzaBlue,
                            borderRadius: 5
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: { y: { beginAtZero: true, ticks: { callback: v => '$' + v }}},
                        plugins: { legend: { display: false }, tooltip: { callbacks: tooltipCallbacksUSD } }
                    }
                });
            }
        },
        'pagos-tab': () => {
            destroyChart('tipoPagoChart');
            if (tipoPago && document.getElementById('tipoPagoChart')) {
                chartInstances['tipoPagoChart'] = new Chart(document.getElementById('tipoPagoChart').getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: tipoPago.map(d => d.tipo_pago),
                        datasets: [{
                            label: 'Nro. de Transacciones',
                            data: tipoPago.map(d => d.total_transacciones),
                            backgroundColor: [kpizzaPurple, kpizzaBlue, kpizzaGreen, kpizzaOrange, kpizzaRed],
                            borderRadius: 5
                        }]
                    },
                    options: {
                        responsive: true,
                        indexAxis: 'y', 
                        scales: { x: { beginAtZero: true, ticks: { stepSize: 1 } } }, 
                        plugins: { 
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: (context) => ` ${context.parsed.x} transacciones`
                                }
                            }
                        }
                    }
                });
            }
            
            destroyChart('monedaChart');
            if (moneda && document.getElementById('monedaChart') && tasaActual) {
                chartInstances['monedaChart'] = new Chart(document.getElementById('monedaChart').getContext('2d'), {
                    type: 'pie',
                    data: {
                        labels: ['Pagos en Dólares (USD)', 'Pagos en Bolívares (convertido a USD)'],
                        datasets: [{
                            data: [
                                moneda.find(m => m.moneda === 'USD')?.total_convertido_usd || 0,
                                moneda.find(m => m.moneda === 'BS')?.total_convertido_usd || 0
                            ],
                            backgroundColor: [kpizzaBlue, kpizzaGreen],
                            borderColor: '#fff',
                            borderWidth: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: { position: 'bottom' },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const label = context.label || '';
                                        const value = context.parsed || 0;
                                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                        const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                        return `${label}: $${value.toFixed(2)} (${percentage}%)`;
                                    }
                                }
                            }
                        }
                    }
                });
            }
        },
        'productos-tab': () => {
            destroyChart('productosChart');
            if (productos && document.getElementById('productosChart')) {
                chartInstances['productosChart'] = new Chart(document.getElementById('productosChart').getContext('2d'), {
                    type: 'doughnut',
                    data: {
                        labels: productos.map(d => d.producto),
                        datasets: [{
                            data: productos.map(d => d.vendidos),
                            backgroundColor: [kpizzaRed, kpizzaBlue, kpizzaGreen, kpizzaOrange, kpizzaPurple, '#607d8b', '#f44336'],
                            borderColor: '#fff',
                            borderWidth: 2
                        }]
                    },
                    options: { responsive: true, cutout: '60%', plugins: { legend: { position: 'right' } } }
                });
            }

            destroyChart('ingresosProductosChart');
            if (productos && document.getElementById('ingresosProductosChart')) {
                chartInstances['ingresosProductosChart'] = new Chart(document.getElementById('ingresosProductosChart').getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: productos.map(d => d.producto),
                        datasets: [{
                            label: 'Ingresos ($)',
                            data: productos.map(d => d.ingresos),
                            backgroundColor: kpizzaGreen,
                            borderRadius: 5
                        }]
                    },
                    options: {
                        responsive: true,
                        indexAxis: 'y',
                        scales: { x: { beginAtZero: true, ticks: { callback: v => '$' + v } } },
                        plugins: { legend: { display: false }, tooltip: { callbacks: tooltipCallbacksUSD } }
                    }
                });
            }
        },
        'servicios-tab': () => {
            destroyChart('serviciosChart');
            if (servicios && document.getElementById('serviciosChart')) {
                chartInstances['serviciosChart'] = new Chart(document.getElementById('serviciosChart').getContext('2d'), {
                    type: 'pie',
                    data: {
                        labels: servicios.map(d => d.servicio),
                        datasets: [{
                            data: servicios.map(d => d.ventas),
                            backgroundColor: [kpizzaRed, kpizzaBlue],
                            borderColor: '#fff',
                            borderWidth: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: tooltipCallbacksUSD } }
                    }
                });
            }
        },
        'meseros-tab': () => {
            destroyChart('meserosChart');
            if (meseros && document.getElementById('meserosChart')) {
                chartInstances['meserosChart'] = new Chart(document.getElementById('meserosChart'), {
                    type: 'bar',
                    data: {
                        labels: meseros.map(d => d.mesero),
                        datasets: [
                            {
                                label: 'Ventas Mesa ($)',
                                data: meseros.map(d => d.Mesa),
                                backgroundColor: kpizzaRed,
                                borderRadius: 5
                            },
                            {
                                label: 'Ventas Llevar ($)',
                                data: meseros.map(d => d.Llevar),
                                backgroundColor: kpizzaBlue,
                                borderRadius: 5
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            x: { stacked: true },
                            y: { stacked: true, beginAtZero: true, ticks: { callback: v => '$' + v } }
                        },
                        plugins: { tooltip: { callbacks: tooltipCallbacksUSD } }
                    }
                });
            }
        }
    };

    document.querySelectorAll('.nav-pills .nav-link').forEach(tabEl => {
        tabEl.addEventListener('shown.bs.tab', (event) => {
            const tabId = event.target.id;
            if (tabs[tabId]) {
                tabs[tabId]();
            }
        });
    });

    tabs['ventas-tab']();
    
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert-dismissible');
        alerts.forEach(alert => {
            if (typeof bootstrap !== 'undefined') {
                const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
                if (bsAlert) {
                    bsAlert.close();
                }
            }
        });
    }, 5000);
});

