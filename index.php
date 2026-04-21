<?php
session_start();

// Verificar autenticación
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    header('Location: login.php');
    exit;
}

$username = $_SESSION['username'] ?? 'Usuario';
?>
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
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .sync-btn, .export-btn {
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
            text-decoration: none;
            display: inline-block;
        }
        
        .export-btn {
            background: linear-gradient(45deg, #28a745, #20c997);
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
        }
        
        .sync-btn:hover, .export-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(255, 102, 135, 0.4);
        }
        
        .export-btn:hover {
            box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
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
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
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
            color: #ffffff;
            font-size: 1rem;
            margin-top: 0.5rem;
            font-weight: 600;
        }
        
        .stat-sublabel {
            color: #cccccc;
            font-size: 0.8rem;
            margin-top: 0.25rem;
            font-style: italic;
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
        
        .export-section {
            text-align: center;
            margin: 2rem 0;
            padding: 1rem;
            background: rgba(40, 167, 69, 0.1);
            border-radius: 8px;
            border: 1px solid rgba(40, 167, 69, 0.3);
            display: none;
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
            
            .controls {
                flex-direction: column;
                align-items: center;
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
            <span>Sesión: <?= htmlspecialchars($username) ?></span>
            <a href="logout.php" class="logout-btn" onclick="return confirm('¿Estás seguro que deseas cerrar sesión?');">Cerrar Sesión</a>
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
            
            <div id="exportSection" class="export-section">
                <h3 style="color: #28a745; margin-bottom: 1rem;">📊 Exportar Datos</h3>
                <p style="color: #cccccc; margin-bottom: 1rem;">Descarga la información completa en formato Excel (CSV)</p>
                <a href="export-excel.php" class="export-btn" target="_blank">
                    📥 DESCARGAR EXCEL
                </a>
            </div>
            
            <div id="debugInfo" class="debug-info">
                <strong>Query Debug Info</strong><br>
                <span id="debugText">Presiona "SINCRONIZAR DATOS" para comenzar</span>
            </div>
        </div>
    </main>

    <footer class="footer">
        Dashboard v3.0.0 | 5T4D10 CTO Team | Mérida, Yucatán<br>
        Powered by Railway.
    </footer>

    <script>
        async function syncData() {
            const syncBtn = document.getElementById('syncBtn');
            const contentDiv = document.getElementById('content');
            const debugDiv = document.getElementById('debugText');
            const statsSection = document.getElementById('statsSection');
            const statsContent = document.getElementById('statsContent');
            const exportSection = document.getElementById('exportSection');

            syncBtn.disabled = true;
            syncBtn.textContent = 'SINCRONIZANDO...';
            contentDiv.innerHTML = '<div class="loading">Cargando datos...</div>';
            exportSection.style.display = 'none';

            try {
                const response = await fetch('sync.php');
                const data = await response.json();

                if (!response.ok || !data.success) {
                    throw new Error(data.error || `HTTP ${response.status}`);
                }

                // Estadísticas
                if (data.stats) {
                    statsContent.innerHTML = `
                        <div class="stat-card">
                            <div class="stat-number">${data.stats.pecadores || 0}</div>
                            <div class="stat-label">Pecadores</div>
                            <div class="stat-sublabel">Tienen ALL ACCESS y no tienen INFINITY</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number">${data.stats.total_all_access || 0}</div>
                            <div class="stat-label">ALL ACCESS</div>
                            <div class="stat-sublabel">Total de usuarios con ALL ACCESS activo</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number">${data.stats.no_pecadores || 0}</div>
                            <div class="stat-label">No Pecadores</div>
                            <div class="stat-sublabel">Tienen ALL ACCESS y tienen INFINITY</div>
                        </div>
                    `;
                    statsSection.style.display = 'block';
                }

                // Tabla de datos
                if (data.data && data.data.length > 0) {
                    let html = '<div class="table-container"><table class="data-table">';
                    html += '<thead><tr><th>Nombre</th><th>Email</th><th>País</th><th>Teléfono</th><th>Código Transacción</th><th>Fecha/Hora</th><th>Plan</th><th>Precio</th></tr></thead><tbody>';
                    
                    data.data.forEach(user => {
                        const fecha = user.fecha_hora ? new Date(user.fecha_hora).toLocaleString('es-ES') : '';
                        const precio = user.precio ? `${parseFloat(user.precio).toLocaleString()} ${user.moneda || ''}` : '';
                        
                        html += `<tr>
                            <td class="name">${user.name || ''}</td>
                            <td class="email">${user.email || ''}</td>
                            <td class="country">${user.country || ''}</td>
                            <td class="phone">${user.phone || ''}</td>
                            <td class="transaction">${user.codigo_transaccion || ''}</td>
                            <td class="fecha">${fecha}</td>
                            <td class="plan">${user.plan || ''}</td>
                            <td class="precio">${precio}</td>
                        </tr>`;
                    });
                    
                    html += '</tbody></table></div>';
                    contentDiv.innerHTML = html;
                    
                    // Mostrar sección de exportación
                    exportSection.style.display = 'block';
                    
                    debugDiv.innerHTML = `✅ ${data.data.length} usuarios cargados exitosamente`;
                } else {
                    contentDiv.innerHTML = '<div class="loading">No hay datos para mostrar</div>';
                    debugDiv.innerHTML = '⚠️ No hay usuarios para mostrar';
                }

            } catch (error) {
                contentDiv.innerHTML = `<div class="error">Error: ${error.message}</div>`;
                debugDiv.innerHTML = `❌ Error: ${error.message}`;
            } finally {
                syncBtn.disabled = false;
                syncBtn.textContent = 'SINCRONIZAR DATOS';
            }
        }
    </script>
</body>
</html>
