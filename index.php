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
                    <img src="/5T4D10_logo.png" alt="5T4D10" class="logo" />
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
                    <img src="/5T4D10_logo.png" alt="5T4D10" class="logo-small" />
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
        Dashboard <span class="version">v2.1.0</span> | 5T4D10 CTO Team | Mérida, Yucatán
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
                        html += '<thead><tr><th>Nombre</th><th>Email</th><th>País</th><th>Teléfono</th></tr></thead>';
                        html += '<tbody>';
                        
                        data.data.forEach(user => {
                            const name = (user.name || 'N/A').trim();
                            const email = (user.email || 'N/A').trim();
                            const phone = (user.phone || '').trim() || '';
                            const country = (user.country || '').trim() || '';
                            
                            html += `<tr>
                                <td class="name">${name}</td>
                                <td class="email">${email}</td>
                                <td class="country">${country}</td>
                                <td class="phone">${phone}</td>
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
