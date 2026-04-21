<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard ALL ACCESS → INFINITY | 5T4D10</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%);
            color: #ffffff;
            min-height: 100vh;
            line-height: 1.6;
        }
        
        .header {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255, 102, 135, 0.3);
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .logo-container {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .logo-img {
            width: 120px;
            height: auto;
            filter: brightness(1.1);
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
            color: #cccccc;
        }
        
        .logout-btn {
            background: rgba(255, 102, 135, 0.2);
            border: 1px solid #FF6687;
            color: #FF6687;
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .logout-btn:hover {
            background: #FF6687;
            color: white;
        }
        
        .main-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }
        
        .dashboard-header {
            text-align: center;
            margin-bottom: 3rem;
        }
        
        .dashboard-title {
            font-size: 2.5rem;
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 0.5rem;
            background: linear-gradient(45deg, #FF6687, #ffffff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .dashboard-subtitle {
            color: #cccccc;
            font-size: 1.1rem;
        }
        
        .controls {
            display: flex;
            justify-content: center;
            margin-bottom: 2rem;
        }
        
        .sync-btn {
            background: linear-gradient(45deg, #FF6687, #FF4567);
            border: none;
            color: white;
            padding: 12px 30px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(255, 102, 135, 0.3);
        }
        
        .sync-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(255, 102, 135, 0.4);
        }
        
        .sync-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        
        .content-section {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
        }
        
        .section-title {
            color: #FF6687;
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .stat-card {
            background: rgba(255, 102, 135, 0.1);
            border: 1px solid rgba(255, 102, 135, 0.3);
            border-radius: 8px;
            padding: 1rem;
            text-align: center;
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: #FF6687;
        }
        
        .stat-label {
            color: #cccccc;
            font-size: 0.9rem;
            margin-top: 0.5rem;
        }
        
        .table-container {
            overflow-x: auto;
            border-radius: 8px;
            background: rgba(0, 0, 0, 0.2);
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        
        .data-table th {
            background: rgba(255, 102, 135, 0.2);
            color: #ffffff;
            padding: 12px 8px;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid #FF6687;
            white-space: nowrap;
        }
        
        .data-table td {
            padding: 10px 8px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 150px;
        }
        
        .data-table tbody tr:hover {
            background: rgba(255, 102, 135, 0.1);
        }
        
        .name { font-weight: 600; color: #ffffff; max-width: 180px; }
        .email { color: #FF6687; max-width: 200px; }
        .country { color: #cccccc; }
        .phone { color: #cccccc; max-width: 120px; }
        .transaction { font-family: monospace; color: #87CEEB; max-width: 120px; }
        .fecha { color: #98fb98; max-width: 140px; }
        .plan { color: #DDA0DD; max-width: 120px; }
        .precio { color: #FFD700; text-align: right; }
        
        .error {
            background: rgba(220, 53, 69, 0.2);
            border: 1px solid #dc3545;
            border-radius: 8px;
            padding: 1rem;
            color: #ff6b6b;
            margin: 1rem 0;
        }
        
        .loading {
            text-align: center;
            padding: 2rem;
            color: #cccccc;
        }
        
        .footer {
            text-align: center;
            margin-top: 3rem;
            color: #666;
            font-size: 0.9rem;
        }
        
        .debug-info {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 6px;
            padding: 1rem;
            margin-top: 1rem;
            font-family: monospace;
            font-size: 12px;
            color: #cccccc;
        }
        
        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                gap: 1rem;
            }
            
            .main-container {
                padding: 1rem;
            }
            
            .dashboard-title {
                font-size: 2rem;
            }
            
            .data-table {
                font-size: 12px;
            }
            
            .data-table th,
            .data-table td {
                padding: 8px 4px;
                max-width: 100px;
            }
            
            .table-container {
                font-size: 11px;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="logo-container">
            <div class="logo">
                <img src="https://raw.githubusercontent.com/hq-gh/dashboard-allaccess/main/5T4D10%20logo.png" alt="5T4D10 Logo" class="logo-img" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                <div style="display:none; color: #FF6687; font-size: 24px; font-weight: 700;">5T4D10</div>
            </div>
        </div>
        <div class="user-info">
            <span>Sesión: 5t4d10soporte</span>
            <a href="logout.php" class="logout-btn">Cerrar Sesión</a>
        </div>
    </header>

    <main class="main-container">
        <div class="dashboard-header">
            <h1 class="dashboard-title">Dashboard ALL ACCESS → INFINITY</h1>
            <p class="dashboard-subtitle">Identifica oportunidades de conversión | 5T4D10 CTO Analytics</p>
        </div>

        <div class="controls">
            <button id="syncBtn" class="sync-btn" onclick="syncData()">
                SINCRONIZAR DATOS
            </button>
        </div>

        <div id="statsSection" class="content-section" style="display: none;">
            <h2 class="section-title">Estadísticas de Conversión</h2>
            <div id="statsContent" class="stats-grid"></div>
        </div>

        <div class="content-section">
            <h2 class="section-title">Usuarios ALL ACCESS sin INFINITY</h2>
            <div id="content"></div>
            <div id="debugInfo" class="debug-info">
                <strong>Query Debug Info</strong><br>
                <span id="debugText">Presiona "SINCRONIZAR DATOS" para comenzar</span>
            </div>
        </div>
    </main>

    <footer class="footer">
        Dashboard v2.4.0 | 5T4D10 CTO Team | Mérida, Yucatán<br>
        Powered by Railway.
    </footer>

    <script>
        let debugDiv = document.getElementById('debugText');
        let contentDiv = document.getElementById('content');
        let statsSection = document.getElementById('statsSection');
        let statsContent = document.getElementById('statsContent');
        let syncBtn = document.getElementById('syncBtn');

        function updateDebug(message) {
            if (debugDiv) {
                debugDiv.innerHTML = message;
            }
        }

        function showError(message) {
            contentDiv.innerHTML = `<div class="error">Error: ${message}</div>`;
        }

        function showLoading() {
            syncBtn.disabled = true;
            syncBtn.textContent = 'SINCRONIZANDO...';
            contentDiv.innerHTML = '<div class="loading">Cargando datos...</div>';
            updateDebug('Realizando petición HTTP...');
        }

        function resetButton() {
            syncBtn.disabled = false;
            syncBtn.textContent = 'SINCRONIZAR DATOS';
        }

        function formatPrice(precio, moneda) {
            if (!precio) return '';
            try {
                const number = parseFloat(precio);
                return `${number.toLocaleString('es-ES', {
                    minimumFractionDigits: 0,
                    maximumFractionDigits: 2
                })} ${moneda || ''}`.trim();
            } catch (e) {
                return `${precio} ${moneda || ''}`.trim();
            }
        }

        function formatDate(fechaHora) {
            if (!fechaHora) return '';
            try {
                const date = new Date(fechaHora);
                return date.toLocaleString('es-ES', {
                    year: 'numeric',
                    month: '2-digit',
                    day: '2-digit',
                    hour: '2-digit',
                    minute: '2-digit'
                });
            } catch (e) {
                return fechaHora;
            }
        }

        function safeString(value, fallback = '') {
            if (value === null || value === undefined) return fallback;
            return String(value).trim() || fallback;
        }

        async function syncData() {
            showLoading();
            
            try {
                const response = await fetch('sync.php', {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'Cache-Control': 'no-cache'
                    }
                });

                updateDebug(`Response status: ${response.status} ${response.statusText}`);

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }

                const rawText = await response.text();
                updateDebug(`Raw response length: ${rawText.length} chars`);

                let data;
                try {
                    data = JSON.parse(rawText);
                } catch (parseError) {
                    updateDebug(`JSON Parse Error: ${parseError.message}<br>Raw text preview: ${rawText.substring(0, 300)}...`);
                    throw new Error(`Respuesta no válida del servidor: ${parseError.message}`);
                }

                updateDebug(`JSON parsed successfully. Success: ${data.success}`);

                if (!data.success) {
                    const errorMsg = data.error || 'Error desconocido del servidor';
                    updateDebug(`Server error: ${errorMsg}`);
                    throw new Error(errorMsg);
                }

                // Verificar estructura de datos
                if (!data.data) {
                    updateDebug(`Missing data property. Available keys: ${Object.keys(data).join(', ')}`);
                    throw new Error('Respuesta del servidor sin datos (data property missing)');
                }

                if (!Array.isArray(data.data)) {
                    updateDebug(`data.data is not an array. Type: ${typeof data.data}, Value: ${JSON.stringify(data.data)}`);
                    throw new Error(`Datos en formato incorrecto (expected array, got ${typeof data.data})`);
                }

                updateDebug(`Found ${data.data.length} users in data array`);

                // Mostrar estadísticas si existen
                if (data.stats) {
                    const stats = data.stats;
                    statsContent.innerHTML = `
                        <div class="stat-card">
                            <div class="stat-number">${safeString(stats.opportunities, 0)}</div>
                            <div class="stat-label">Oportunidades</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number">${safeString(stats.total_all_access, 0)}</div>
                            <div class="stat-label">Total ALL ACCESS</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number">${safeString(stats.converted, 0)}</div>
                            <div class="stat-label">Ya Convertidos</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number">${safeString(stats.conversion_rate, 0)}%</div>
                            <div class="stat-label">Tasa Conversión</div>
                        </div>
                    `;
                    statsSection.style.display = 'block';
                }

                // Mostrar datos si hay resultados
                if (data.data.length > 0) {
                    let html = '<div class="table-container">';
                    html += '<table class="data-table">';
                    html += '<thead><tr><th>Nombre</th><th>Email</th><th>País</th><th>Teléfono</th><th>Código Transacción</th><th>Fecha/Hora</th><th>Plan</th><th>Precio</th></tr></thead>';
                    html += '<tbody>';
                    
                    // Procesar cada usuario de forma segura
                    data.data.forEach((user, index) => {
                        try {
                            const name = safeString(user.name, 'N/A');
                            const email = safeString(user.email, 'N/A');
                            const phone = safeString(user.phone);
                            const country = safeString(user.country);
                            const codigo = safeString(user.codigo_transaccion);
                            const fecha = formatDate(user.fecha_hora);
                            const plan = safeString(user.plan);
                            const precio = formatPrice(user.precio, user.moneda);
                            
                            html += `<tr>
                                <td class="name" title="${name}">${name}</td>
                                <td class="email" title="${email}">${email}</td>
                                <td class="country">${country}</td>
                                <td class="phone">${phone}</td>
                                <td class="transaction">${codigo}</td>
                                <td class="fecha">${fecha}</td>
                                <td class="plan" title="${plan}">${plan}</td>
                                <td class="precio">${precio}</td>
                            </tr>`;
                        } catch (userError) {
                            console.error(`Error processing user ${index}:`, userError, user);
                            updateDebug(`Error processing user ${index}: ${userError.message}`);
                        }
                    });
                    
                    html += '</tbody></table>';
                    html += '</div>';
                    
                    contentDiv.innerHTML = html;
                    updateDebug(`✅ Datos cargados exitosamente: ${data.data.length} usuarios mostrados en tabla`);
                } else {
                    contentDiv.innerHTML = '<div class="loading">No se encontraron usuarios ALL ACCESS sin INFINITY</div>';
                    updateDebug('⚠️ Array de datos vacío - sin usuarios para mostrar');
                }

            } catch (error) {
                console.error('Sync error:', error);
                showError(error.message);
                updateDebug(`❌ Error final: ${error.message}`);
            } finally {
                resetButton();
            }
        }

        // Sincronizar automáticamente al cargar la página
        document.addEventListener('DOMContentLoaded', function() {
            updateDebug('Dashboard cargado - Listo para sincronizar');
            // Comentar la línea siguiente si no quieres carga automática
            // syncData();
        });
    </script>
</body>
</html>
