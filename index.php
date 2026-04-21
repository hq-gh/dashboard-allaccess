<?php
// ========================================
// DASHBOARD ALL ACCESS → INFINITY
// Pantalla principal con autenticación
// ========================================

require_once 'config.php';

// Procesar logout si se solicita
if (isset($_GET['logout'])) {
    logout();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Procesar login si hay datos POST
$error_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    
    if (processLogin($username, $password)) {
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    } else {
        $error_message = 'Credenciales incorrectas';
    }
}

// Verificar autenticación
$authenticated = isAuthenticated();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard ALL ACCESS → INFINITY | 5T4D10</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@300;400;500;600;700&family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Roboto', sans-serif;
            background: linear-gradient(135deg, #0a0a0a 0%, #1a1a1a 50%, #000000 100%);
            color: #ffffff;
            min-height: 100vh;
        }
        
        .login-container {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            position: relative;
        }
        
        .dashboard-container {
            padding: 2rem;
            max-width: 1400px;
            margin: 0 auto;
            width: 100%;
        }
        
        .card {
            background: rgba(30, 30, 30, 0.95);
            border-radius: 20px;
            padding: 3rem;
            width: 100%;
            max-width: 480px;
            box-shadow: 0 20px 60px rgba(255, 102, 135, 0.1);
            border: 1px solid rgba(255, 102, 135, 0.2);
            backdrop-filter: blur(10px);
        }
        
        .logo-container {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .logo {
            height: 60px;
            width: auto;
            margin: 0 auto;
            display: block;
            border-radius: 8px;
        }
        
        .logo-small {
            height: 40px;
            width: auto;
            margin: 0;
            border-radius: 6px;
        }
        
        .header {
            text-align: center;
            margin-bottom: 2.5rem;
        }
        
        .header h1 {
            font-family: 'Oswald', sans-serif;
            font-size: 1.8rem;
            font-weight: 600;
            color: #FF6687;
            margin-bottom: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .header p {
            font-size: 0.95rem;
            color: #b0b0b0;
            font-weight: 300;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #e0e0e0;
            font-size: 0.9rem;
        }
        
        .form-group input {
            width: 100%;
            padding: 1rem;
            background: rgba(40, 40, 40, 0.8);
            border: 2px solid transparent;
            border-radius: 12px;
            color: #ffffff;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #FF6687;
            background: rgba(40, 40, 40, 1);
            box-shadow: 0 0 0 4px rgba(255, 102, 135, 0.1);
        }
        
        .btn {
            width: 100%;
            padding: 1.2rem;
            background: linear-gradient(135deg, #FF6687, #FF4D6D);
            border: none;
            border-radius: 12px;
            color: white;
            font-size: 1.1rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            cursor: pointer;
            transition: all 0.3s ease;
            margin: 1rem 0;
        }
        
        .btn:hover {
            background: linear-gradient(135deg, #FF4D6D, #FF6687);
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(255, 102, 135, 0.3);
        }
        
        .btn:active {
            transform: translateY(0);
        }
        
        .btn-sync {
            background: linear-gradient(135deg, #28a745, #20c997);
            margin-bottom: 2rem;
        }
        
        .btn-sync:hover {
            background: linear-gradient(135deg, #20c997, #28a745);
        }
        
        .error {
            background: rgba(220, 53, 69, 0.1);
            border: 1px solid rgba(220, 53, 69, 0.3);
            color: #ff6b6b;
            padding: 1rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            text-align: center;
            font-size: 0.9rem;
        }
        
        .header-auth {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding: 1.5rem;
            background: rgba(30, 30, 30, 0.8);
            border-radius: 12px;
            border: 1px solid rgba(255, 102, 135, 0.1);
        }
        
        .user-info {
            font-size: 0.9rem;
            color: #b0b0b0;
        }
        
        .user-info strong {
            color: #FF6687;
        }
        
        .logout-btn {
            background: rgba(108, 117, 125, 0.8);
            border: 1px solid rgba(108, 117, 125, 0.3);
            color: #ffffff;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.85rem;
            transition: all 0.3s ease;
        }
        
        .logout-btn:hover {
            background: rgba(108, 117, 125, 1);
            text-decoration: none;
            color: #ffffff;
        }
        
        .welcome {
            margin-bottom: 2rem;
            text-align: center;
        }
        
        .welcome h2 {
            font-family: 'Oswald', sans-serif;
            font-size: 2.5rem;
            color: #FF6687;
            margin-bottom: 0.5rem;
            text-transform: uppercase;
        }
        
        .welcome p {
            color: #b0b0b0;
            font-size: 1.1rem;
        }
        
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: rgba(255, 102, 135, 0.1);
            border: 1px solid rgba(255, 102, 135, 0.2);
            border-radius: 12px;
            padding: 2rem;
            text-align: center;
        }
        
        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            color: #FF6687;
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            font-size: 0.9rem;
            color: #b0b0b0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .results {
            margin-top: 2rem;
            background: rgba(30, 30, 30, 0.8);
            border-radius: 16px;
            border: 1px solid rgba(255, 102, 135, 0.1);
            overflow: hidden;
        }
        
        .results-header {
            background: rgba(255, 102, 135, 0.1);
            padding: 1.5rem;
            border-bottom: 1px solid rgba(255, 102, 135, 0.1);
        }
        
        .results-header h3 {
            color: #FF6687;
            font-family: 'Oswald', sans-serif;
            font-size: 1.3rem;
            text-transform: uppercase;
            margin: 0;
        }
        
        .loading {
            text-align: center;
            color: #b0b0b0;
            padding: 3rem;
            font-size: 1.1rem;
        }
        
        .table-container {
            overflow-x: auto;
            padding: 0;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }
        
        .data-table th {
            background: rgba(255, 102, 135, 0.05);
            color: #FF6687;
            font-weight: 600;
            padding: 1rem 1.5rem;
            text-align: left;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 0.8rem;
            border-bottom: 2px solid rgba(255, 102, 135, 0.2);
        }
        
        .data-table td {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            color: #e0e0e0;
            vertical-align: middle;
        }
        
        .data-table tr:hover {
            background: rgba(255, 102, 135, 0.02);
        }
        
        .data-table .name {
            font-weight: 500;
            color: #ffffff;
        }
        
        .data-table .email {
            color: #b0b0b0;
            font-family: monospace;
            font-size: 0.85rem;
        }
        
        .data-table .phone {
            color: #FF6687;
            font-weight: 500;
        }
        
        .data-table .country {
            color: #20c997;
            font-size: 0.85rem;
            text-transform: uppercase;
        }
        
        .debug-section {
            margin-top: 2rem;
            background: rgba(40, 40, 40, 0.8);
            border-radius: 12px;
            border: 1px solid rgba(108, 117, 125, 0.2);
            overflow: hidden;
        }
        
        .debug-header {
            background: rgba(108, 117, 125, 0.2);
            padding: 1rem;
            border-bottom: 1px solid rgba(108, 117, 125, 0.2);
        }
        
        .debug-header h4 {
            color: #6c757d;
            margin: 0;
            font-size: 0.9rem;
            text-transform: uppercase;
        }
        
        .debug-content {
            padding: 1rem;
            font-family: monospace;
            font-size: 0.8rem;
            color: #b0b0b0;
            white-space: pre-wrap;
        }
        
        .footer {
            position: fixed;
            bottom: 1rem;
            right: 1rem;
            font-size: 0.8rem;
            color: #666;
            z-index: 1000;
        }
        
        .footer .version {
            color: #FF6687;
            font-weight: 500;
        }
        
        @media (max-width: 768px) {
            .dashboard-container {
                padding: 1rem;
            }
            
            .stats {
                grid-template-columns: 1fr;
            }
            
            .data-table {
                font-size: 0.8rem;
            }
            
            .data-table th,
            .data-table td {
                padding: 0.75rem 1rem;
            }
            
            .welcome h2 {
                font-size: 2rem;
            }
            
            .logo {
                height: 50px;
            }
            
            .logo-small {
                height: 35px;
            }
        }
    </style>
</head>
<body>
    <?php if (!$authenticated): ?>
        <!-- PANTALLA DE LOGIN -->
        <div class="login-container">
            <div class="card">
                <div class="logo-container">
                    <img src="data:image/jpeg;base64,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" alt="5T4D10" class="logo" />
                </div>
                
                <div class="header">
                    <h1>Dashboard Access</h1>
                    <p>ALL ACCESS → INFINITY Analytics</p>
                </div>
                
                <?php if ($error_message): ?>
                    <div class="error"><?php echo htmlspecialchars($error_message); ?></div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="form-group">
                        <label for="username">Usuario</label>
                        <input type="text" id="username" name="username" required autocomplete="username">
                    </div>
                    
                    <div class="form-group">
                        <label for="password">Contraseña</label>
                        <input type="password" id="password" name="password" required autocomplete="current-password">
                    </div>
                    
                    <button type="submit" class="btn">ACCEDER</button>
                </form>
            </div>
        </div>
    <?php else: ?>
        <!-- PANTALLA PRINCIPAL AUTENTICADA -->
        <div class="dashboard-container">
            <div class="header-auth">
                <div class="logo-container">
                    <img src="data:image/jpeg;base64,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" alt="5T4D10" class="logo-small" />
                </div>
                <div class="user-info">
                    Sesión: <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong>
                </div>
                <a href="?logout=1" class="logout-btn">Cerrar Sesión</a>
            </div>
            
            <div class="welcome">
                <h2>Dashboard ALL ACCESS → INFINITY</h2>
                <p>Identifica oportunidades de conversión | 5T4D10 CTO Analytics</p>
            </div>
            
            <button id="syncBtn" class="btn btn-sync" onclick="syncData()">SINCRONIZAR DATOS</button>
            
            <div id="stats" class="stats" style="display: none;"></div>
            
            <div id="results" class="results" style="display: none;">
                <div class="results-header">
                    <h3>Usuarios ALL ACCESS sin INFINITY</h3>
                </div>
                <div id="loading" class="loading">Cargando datos...</div>
                <div id="content"></div>
            </div>
            
            <div id="debug" class="debug-section" style="display: none;">
                <div class="debug-header">
                    <h4>Query Debug Info</h4>
                </div>
                <div id="debug-content" class="debug-content"></div>
            </div>
        </div>
    <?php endif; ?>
    
    <div class="footer">
        Dashboard <span class="version">v2.2.0</span> | 5T4D10 CTO Team | Mérida, Yucatán
        <br>Powered by Railway.
    </div>
    
    <script>
        async function syncData() {
            const resultsDiv = document.getElementById('results');
            const statsDiv = document.getElementById('stats');
            const debugDiv = document.getElementById('debug');
            const loadingDiv = document.getElementById('loading');
            const contentDiv = document.getElementById('content');
            const debugContentDiv = document.getElementById('debug-content');
            const syncBtn = document.getElementById('syncBtn');
            
            // Mostrar loading
            resultsDiv.style.display = 'block';
            loadingDiv.style.display = 'block';
            contentDiv.innerHTML = '';
            statsDiv.style.display = 'none';
            debugDiv.style.display = 'block';
            syncBtn.disabled = true;
            syncBtn.textContent = 'SINCRONIZANDO...';
            
            try {
                const response = await fetch('/sync.php');
                const data = await response.json();
                
                loadingDiv.style.display = 'none';
                
                // Mostrar debug info
                let debugInfo = `Query ejecutado exitosamente\n`;
                debugInfo += `Timestamp: ${data.timestamp}\n`;
                if (data.meta) {
                    debugInfo += `ALL ACCESS Product ID: ${data.meta.all_access_product_id}\n`;
                    debugInfo += `INFINITY Product IDs: ${data.meta.infinity_product_ids.join(', ')}\n`;
                    debugInfo += `Active Status: ${data.meta.active_statuses.join(', ')}\n`;
                }
                debugContentDiv.textContent = debugInfo;
                
                if (data.success) {
                    // Mostrar estadísticas
                    const stats = data.stats;
                    let statsHtml = '';
                    statsHtml += `<div class="stat-card"><div class="stat-value">${stats.opportunities}</div><div class="stat-label">Oportunidades</div></div>`;
                    statsHtml += `<div class="stat-card"><div class="stat-value">${stats.total_all_access}</div><div class="stat-label">Total ALL ACCESS</div></div>`;
                    statsHtml += `<div class="stat-card"><div class="stat-value">${stats.converted}</div><div class="stat-label">Ya Convertidos</div></div>`;
                    statsHtml += `<div class="stat-card"><div class="stat-value">${stats.conversion_rate}%</div><div class="stat-label">Tasa Conversión</div></div>`;
                    
                    statsDiv.innerHTML = statsHtml;
                    statsDiv.style.display = 'grid';
                    
                    // Mostrar datos si hay resultados
                    if (data.data && data.data.length > 0) {
                        let html = '<div class="table-container">';
                        html += '<table class="data-table">';
                        html += '<thead><tr><th>Nombre</th><th>Email</th><th>País</th><th>Teléfono</th><th>Código Transacción</th><th>Fecha/Hora</th><th>Plan</th><th>Precio</th></tr></thead>';
                        html += '<tbody>';
                        
                        data.data.forEach(user => {
                            const name = (user.name || 'N/A').trim();
                            const email = (user.email || 'N/A').trim();
                            const phone = (user.phone || '').trim() || '';
                            const country = (user.country || '').trim() || '';
                            const codigo = (user.codigo_transaccion || '').trim() || '';
                            const fecha = user.fecha_hora ? new Date(user.fecha_hora).toLocaleString('es-ES') : '';
                            const plan = (user.plan || '').trim() || '';
                            const precio = user.precio ? `${parseFloat(user.precio).toLocaleString()} ${user.moneda || ''}` : '';
                            
                            html += `<tr>
                                <td class="name">${name}</td>
                                <td class="email">${email}</td>
                                <td class="country">${country}</td>
                                <td class="phone">${phone}</td>
                                <td class="transaction">${codigo}</td>
                                <td class="fecha">${fecha}</td>
                                <td class="plan">${plan}</td>
                                <td class="precio">${precio}</td>
                            </tr>`;
                        });
                        
                        html += '</tbody></table>';
                        html += '</div>';
                        
                        contentDiv.innerHTML = html;
                    } else {
                        contentDiv.innerHTML = '<div class="loading">No se encontraron usuarios para conversión.</div>';
                    }
                    
                } else {
                    contentDiv.innerHTML = `<div class="error" style="margin: 2rem;">Error: ${data.error || 'Error desconocido'}</div>`;
                    debugContentDiv.textContent += `\nError: ${data.error || 'Error desconocido'}`;
                }
                
            } catch (error) {
                loadingDiv.style.display = 'none';
                contentDiv.innerHTML = `<div class="error" style="margin: 2rem;">Error HTTP: ${error.message}</div>`;
                debugContentDiv.textContent += `\nError HTTP: ${error.message}`;
            } finally {
                syncBtn.disabled = false;
                syncBtn.textContent = 'SINCRONIZAR DATOS';
            }
        }
    </script>
</body>
</html>
