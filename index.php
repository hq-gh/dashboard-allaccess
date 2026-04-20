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
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }
        
        .container {
            background: rgba(30, 30, 30, 0.95);
            border-radius: 20px;
            padding: 3rem;
            width: 100%;
            max-width: 480px;
            box-shadow: 0 20px 60px rgba(255, 102, 135, 0.1);
            border: 1px solid rgba(255, 102, 135, 0.2);
            backdrop-filter: blur(10px);
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
        
        .success-container {
            text-align: center;
        }
        
        .welcome {
            margin-bottom: 2rem;
        }
        
        .welcome h2 {
            font-family: 'Oswald', sans-serif;
            font-size: 2rem;
            color: #FF6687;
            margin-bottom: 1rem;
            text-transform: uppercase;
        }
        
        .welcome p {
            color: #b0b0b0;
            font-size: 1rem;
        }
        
        .sync-section {
            background: rgba(40, 40, 40, 0.6);
            border-radius: 16px;
            padding: 2rem;
            margin: 2rem 0;
            border: 1px solid rgba(255, 102, 135, 0.1);
        }
        
        .sync-btn {
            background: linear-gradient(135deg, #28a745, #20c997);
            margin-bottom: 1rem;
        }
        
        .sync-btn:hover {
            background: linear-gradient(135deg, #20c997, #28a745);
        }
        
        .sync-info {
            font-size: 0.9rem;
            color: #b0b0b0;
            text-align: center;
            line-height: 1.5;
        }
        
        .header-auth {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
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
        
        .footer {
            position: absolute;
            bottom: 1rem;
            right: 1rem;
            font-size: 0.8rem;
            color: #666;
        }
        
        .footer .version {
            color: #FF6687;
            font-weight: 500;
        }
        
        .results {
            margin-top: 2rem;
            padding: 2rem;
            background: rgba(40, 40, 40, 0.6);
            border-radius: 16px;
            border: 1px solid rgba(255, 102, 135, 0.1);
            min-height: 200px;
        }
        
        .loading {
            text-align: center;
            color: #b0b0b0;
            padding: 2rem;
        }
        
        .data-table {
            width: 100%;
            margin-top: 1rem;
        }
        
        .data-table th,
        .data-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid rgba(255, 102, 135, 0.1);
            font-size: 0.9rem;
        }
        
        .data-table th {
            background: rgba(255, 102, 135, 0.1);
            color: #FF6687;
            font-weight: 600;
        }
        
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: rgba(255, 102, 135, 0.1);
            border: 1px solid rgba(255, 102, 135, 0.2);
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
        }
        
        .stat-value {
            font-size: 2rem;
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
        
        @media (max-width: 768px) {
            .container {
                margin: 1rem;
                padding: 2rem;
            }
            
            .stats {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if (!$authenticated): ?>
            <!-- PANTALLA DE LOGIN -->
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
        <?php else: ?>
            <!-- PANTALLA PRINCIPAL AUTENTICADA -->
            <div class="header-auth">
                <div class="user-info">
                    Sesión: <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong> <span class="version">Railway</span>
                </div>
                <a href="?logout=1" class="logout-btn">Cerrar Sesión</a>
            </div>
            
            <div class="welcome">
                <h2>Dashboard ALL ACCESS → INFINITY</h2>
                <p>Identifica oportunidades de conversión | 5T4D10</p>
            </div>
            
            <div class="sync-section">
                <button id="syncBtn" class="btn sync-btn" onclick="syncData()">SINCRONIZAR DATOS</button>
                <div class="sync-info">
                    Consulta usuarios con ALL ACCESS que no han migrado a INFINITY
                </div>
            </div>
            
            <div id="results" class="results" style="display: none;">
                <div id="loading" class="loading">Cargando datos...</div>
                <div id="content"></div>
            </div>
        <?php endif; ?>
    </div>
    
    <div class="footer">
        Dashboard <span class="version">v1.1.0</span> | 5T4D10 CTO Team | Mérida, Yucatán
        <br>Powered by Railway.
    </div>
    
    <script>
        async function syncData() {
            const resultsDiv = document.getElementById('results');
            const loadingDiv = document.getElementById('loading');
            const contentDiv = document.getElementById('content');
            const syncBtn = document.getElementById('syncBtn');
            
            // Mostrar loading
            resultsDiv.style.display = 'block';
            loadingDiv.style.display = 'block';
            contentDiv.innerHTML = '';
            syncBtn.disabled = true;
            syncBtn.textContent = 'SINCRONIZANDO...';
            
            try {
                const response = await fetch('/sync.php');
                const data = await response.json();
                
                loadingDiv.style.display = 'none';
                
                if (data.success) {
                    // Mostrar estadísticas
                    const stats = data.stats;
                    let html = '<div class="stats">';
                    html += `<div class="stat-card"><div class="stat-value">${stats.opportunities}</div><div class="stat-label">Oportunidades</div></div>`;
                    html += `<div class="stat-card"><div class="stat-value">${stats.already_converted}</div><div class="stat-label">Ya Convertidos</div></div>`;
                    html += `<div class="stat-card"><div class="stat-value">${stats.conversion_rate}%</div><div class="stat-label">Tasa Conversión</div></div>`;
                    html += `<div class="stat-card"><div class="stat-value">${data.query_time_ms}ms</div><div class="stat-label">Tiempo Query</div></div>`;
                    html += '</div>';
                    
                    // Mostrar datos si hay resultados
                    if (data.data && data.data.length > 0) {
                        html += '<h3 style="color: #FF6687; margin-bottom: 1rem;">Usuarios ALL ACCESS sin INFINITY</h3>';
                        html += '<table class="data-table">';
                        html += '<thead><tr><th>Nombre</th><th>Email</th><th>Teléfono</th><th>País</th></tr></thead>';
                        html += '<tbody>';
                        
                        data.data.slice(0, 50).forEach(user => {
                            html += `<tr>
                                <td>${user.name || 'N/A'}</td>
                                <td>${user.email || 'N/A'}</td>
                                <td>${user.phone || 'N/A'}</td>
                                <td>${user.country || 'N/A'}</td>
                            </tr>`;
                        });
                        
                        html += '</tbody></table>';
                        
                        if (data.data.length > 50) {
                            html += `<p style="margin-top: 1rem; color: #b0b0b0; font-size: 0.9rem;">
                                Mostrando primeros 50 resultados de ${data.data.length} total.
                            </p>`;
                        }
                    } else {
                        html += '<p style="color: #b0b0b0; text-align: center;">No se encontraron usuarios para conversión.</p>';
                    }
                    
                    contentDiv.innerHTML = html;
                } else {
                    contentDiv.innerHTML = `<div class="error">Error: ${data.error || 'Error desconocido'}</div>`;
                }
                
            } catch (error) {
                loadingDiv.style.display = 'none';
                contentDiv.innerHTML = `<div class="error">Error HTTP: ${error.message}</div>`;
            } finally {
                syncBtn.disabled = false;
                syncBtn.textContent = 'SINCRONIZAR DATOS';
            }
        }
    </script>
</body>
</html>
