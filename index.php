<?php
// ========================================
// AUTENTICACIÓN SIMPLE - Dashboard ALL ACCESS → INFINITY
// Railway Version
// ========================================

require_once 'config.php';

session_start();

// Verificar si está autenticado
function isAuthenticated() {
    return isset($_SESSION['authenticated']) && 
           $_SESSION['authenticated'] === true &&
           isset($_SESSION['last_activity']) &&
           (time() - $_SESSION['last_activity']) < SESSION_TIMEOUT;
}

// Procesar login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $user = $_POST['username'] ?? '';
    $pass = $_POST['password'] ?? '';
    
    if ($user === DASHBOARD_USER && $pass === DASHBOARD_PASS) {
        $_SESSION['authenticated'] = true;
        $_SESSION['last_activity'] = time();
        $_SESSION['user'] = $user;
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    } else {
        $loginError = 'Credenciales incorrectas';
    }
}

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Actualizar última actividad
if (isAuthenticated()) {
    $_SESSION['last_activity'] = time();
}

// Si no está autenticado, mostrar formulario de login
if (!isAuthenticated()) {
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Login | 5T4D10</title>
    <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;500;600&family=Roboto:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #FF6687;
            --bg-dark: #0a0a0a;
            --bg-card: #1a1a1a;
            --text-primary: #ffffff;
            --text-secondary: #b3b3b3;
            --border-color: #333333;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Roboto', sans-serif;
            background: var(--bg-dark);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .login-container {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 40px;
            max-width: 400px;
            width: 100%;
            margin: 20px;
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .login-header h1 {
            font-family: 'Oswald', sans-serif;
            font-size: 1.8rem;
            color: var(--primary-color);
            margin-bottom: 10px;
        }
        
        .login-header p {
            color: var(--text-secondary);
            font-size: 0.9rem;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            font-size: 0.9rem;
        }
        
        input[type="text"], input[type="password"] {
            width: 100%;
            padding: 12px;
            background: var(--bg-dark);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            color: var(--text-primary);
            font-size: 1rem;
            transition: border-color 0.3s ease;
        }
        
        input[type="text"]:focus, input[type="password"]:focus {
            outline: none;
            border-color: var(--primary-color);
        }
        
        .login-btn {
            width: 100%;
            background: linear-gradient(45deg, var(--primary-color), #e6004d);
            color: white;
            border: none;
            padding: 12px;
            font-size: 1rem;
            font-weight: 500;
            border-radius: 6px;
            cursor: pointer;
            transition: transform 0.3s ease;
            font-family: 'Oswald', sans-serif;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .login-btn:hover {
            transform: translateY(-2px);
        }
        
        .error {
            background: #ff4757;
            color: white;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 0.9rem;
        }
        
        .railway-badge {
            text-align: center;
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid var(--border-color);
            font-size: 0.8rem;
            color: var(--text-secondary);
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <h1>Dashboard Access</h1>
            <p>ALL ACCESS → INFINITY Analytics</p>
        </div>
        
        <?php if (isset($loginError)): ?>
            <div class="error"><?= htmlspecialchars($loginError) ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label for="username">Usuario</label>
                <input type="text" id="username" name="username" required>
            </div>
            
            <div class="form-group">
                <label for="password">Contraseña</label>
                <input type="password" id="password" name="password" required>
            </div>
            
            <button type="submit" name="login" class="login-btn">Acceder</button>
        </form>
        
        <div class="railway-badge">
            🚂 Hosted on Railway | v<?= APP_VERSION ?>
        </div>
    </div>
</body>
</html>
<?php
    exit;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard ALL ACCESS → INFINITY | 5T4D10</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;500;600&family=Roboto:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #FF6687;
            --primary-dark: #e6004d;
            --bg-dark: #0a0a0a;
            --bg-card: #1a1a1a;
            --text-primary: #ffffff;
            --text-secondary: #b3b3b3;
            --border-color: #333333;
            --success-color: #00ff88;
            --shadow: 0 4px 20px rgba(255, 102, 135, 0.1);
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Roboto', sans-serif;
            background: var(--bg-dark);
            color: var(--text-primary);
            line-height: 1.6;
            min-height: 100vh;
        }
        
        .auth-bar {
            background: rgba(255, 102, 135, 0.1);
            padding: 10px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.9rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        .auth-info {
            display: flex;
            gap: 20px;
            align-items: center;
        }
        
        .auth-bar a {
            color: var(--primary-color);
            text-decoration: none;
        }
        
        .auth-bar a:hover {
            text-decoration: underline;
        }
        
        .railway-pro-badge {
            background: linear-gradient(45deg, #6366f1, #8b5cf6);
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .container { max-width: 1400px; margin: 0 auto; padding: 20px; }
        .header { text-align: center; margin-bottom: 40px; }
        .header h1 {
            font-family: 'Oswald', sans-serif;
            font-size: 2.5rem;
            font-weight: 600;
            background: linear-gradient(45deg, var(--primary-color), var(--primary-dark));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 10px;
        }
        .header p { color: var(--text-secondary); font-size: 1.1rem; }
        .sync-section {
            background: var(--bg-card);
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 30px;
            border: 1px solid var(--border-color);
            text-align: center;
        }
        .sync-btn {
            background: linear-gradient(45deg, var(--primary-color), var(--primary-dark));
            color: white;
            border: none;
            padding: 15px 40px;
            font-size: 1.1rem;
            font-weight: 500;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-family: 'Oswald', sans-serif;
            text-transform: uppercase;
            letter-spacing: 1px;
            box-shadow: var(--shadow);
        }
        .sync-btn:hover { transform: translateY(-2px); }
        .sync-btn:disabled { opacity: 0.6; cursor: not-allowed; }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: var(--bg-card);
            border-radius: 12px;
            padding: 25px;
            border: 1px solid var(--border-color);
            text-align: center;
        }
        .stat-number {
            font-family: 'Oswald', sans-serif;
            font-size: 2.5rem;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 10px;
        }
        .stat-label {
            color: var(--text-secondary);
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .results-section {
            background: var(--bg-card);
            border-radius: 12px;
            padding: 30px;
            border: 1px solid var(--border-color);
            display: none;
        }
        .results-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }
        .results-title {
            font-family: 'Oswald', sans-serif;
            font-size: 1.5rem;
            color: var(--primary-color);
        }
        .results-info { color: var(--text-secondary); font-size: 0.9rem; }
        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 15px; text-align: left; border-bottom: 1px solid var(--border-color); }
        th {
            background: rgba(255, 102, 135, 0.1);
            color: var(--primary-color);
            font-family: 'Oswald', sans-serif;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-size: 0.9rem;
        }
        td { color: var(--text-primary); }
        tr:hover { background: rgba(255, 102, 135, 0.05); }
        .loading { display: inline-flex; align-items: center; gap: 10px; }
        .spinner {
            width: 20px; height: 20px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top: 2px solid var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .error {
            background: #ff4757; color: white; padding: 15px;
            border-radius: 8px; margin-bottom: 20px; display: none;
        }
        .success {
            background: var(--success-color); color: var(--bg-dark);
            padding: 15px; border-radius: 8px; margin-bottom: 20px;
            font-weight: 500; display: none;
        }
        @media (max-width: 768px) {
            .container { padding: 15px; }
            .header h1 { font-size: 2rem; }
            .auth-info { flex-direction: column; gap: 10px; align-items: flex-start; }
        }
        .footer {
            text-align: center; margin-top: 40px; padding-top: 20px;
            border-top: 1px solid var(--border-color);
            color: var(--text-secondary); font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <div class="auth-bar">
        <div class="auth-info">
            <span>👤 Sesión: <?= htmlspecialchars($_SESSION['user']) ?></span>
            <span class="railway-pro-badge">Railway</span>
        </div>
        <a href="?logout">Cerrar Sesión</a>
    </div>
    
    <div class="container">
        <div class="header">
            <h1>Dashboard ALL ACCESS → INFINITY</h1>
            <p>Identifica oportunidades de conversión | 5T4D10</p>
        </div>
        
        <div class="sync-section">
            <button id="syncBtn" class="sync-btn">
                <span id="syncText">SINCRONIZAR DATOS</span>
            </button>
            <p style="margin-top: 15px; color: var(--text-secondary); font-size: 0.9rem;">
                Consulta usuarios con ALL ACCESS que no han migrado a INFINITY
            </p>
        </div>
        
        <div id="error" class="error"></div>
        <div id="success" class="success"></div>
        
        <div id="statsSection" class="stats-grid" style="display: none;">
            <div class="stat-card">
                <div id="totalAllAccess" class="stat-number">-</div>
                <div class="stat-label">Total ALL ACCESS</div>
            </div>
            <div class="stat-card">
                <div id="alreadyConverted" class="stat-number">-</div>
                <div class="stat-label">Ya Convertidos</div>
            </div>
            <div class="stat-card">
                <div id="opportunities" class="stat-number">-</div>
                <div class="stat-label">Oportunidades</div>
            </div>
            <div class="stat-card">
                <div id="conversionRate" class="stat-number">-%</div>
                <div class="stat-label">Tasa Conversión</div>
            </div>
        </div>
        
        <div id="resultsSection" class="results-section">
            <div class="results-header">
                <div class="results-title">Usuarios Target</div>
                <div id="resultsInfo" class="results-info"></div>
            </div>
            
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Email</th>
                            <th>Teléfono</th>
                            <th>País</th>
                        </tr>
                    </thead>
                    <tbody id="resultsTable"></tbody>
                </table>
            </div>
        </div>
        
        <div class="footer">
            <p>Dashboard v<?= APP_VERSION ?> | 5T4D10 CTO Team | Mérida, Yucatán</p>
            <p style="margin-top: 5px; color: var(--border-color);">Powered by Railway</p>
        </div>
    </div>
    
    <script>
        const syncBtn = document.getElementById('syncBtn');
        const syncText = document.getElementById('syncText');
        const errorDiv = document.getElementById('error');
        const successDiv = document.getElementById('success');
        const statsSection = document.getElementById('statsSection');
        const resultsSection = document.getElementById('resultsSection');
        const resultsTable = document.getElementById('resultsTable');
        const resultsInfo = document.getElementById('resultsInfo');
        
        const totalAllAccessEl = document.getElementById('totalAllAccess');
        const alreadyConvertedEl = document.getElementById('alreadyConverted');
        const opportunitiesEl = document.getElementById('opportunities');
        const conversionRateEl = document.getElementById('conversionRate');
        
        function showError(message) {
            errorDiv.textContent = message;
            errorDiv.style.display = 'block';
            successDiv.style.display = 'none';
        }
        
        function showSuccess(message) {
            successDiv.textContent = message;
            successDiv.style.display = 'block';
            errorDiv.style.display = 'none';
        }
        
        function hideMessages() {
            errorDiv.style.display = 'none';
            successDiv.style.display = 'none';
        }
        
        function formatNumber(num) {
            return new Intl.NumberFormat('es-MX').format(num);
        }
        
        function formatPhone(phone) {
            if (!phone) return 'N/A';
            const cleaned = phone.replace(/\D/g, '');
            if (cleaned.length >= 10) {
                return `+${cleaned}`;
            }
            return phone;
        }
        
        async function syncData() {
            hideMessages();
            
            syncBtn.disabled = true;
            syncText.innerHTML = '<div class="loading"><div class="spinner"></div> Sincronizando...</div>';
            
            try {
                const response = await fetch('sync.php', {
                    method: 'GET',
                    headers: { 'Content-Type': 'application/json' }
                });
                
                if (!response.ok) {
                    throw new Error(`Error HTTP: ${response.status}`);
                }
                
                const data = await response.json();
                
                if (!data.success) {
                    throw new Error(data.error || 'Error desconocido en el servidor');
                }
                
                totalAllAccessEl.textContent = formatNumber(data.stats.total_all_access);
                alreadyConvertedEl.textContent = formatNumber(data.stats.already_converted);
                opportunitiesEl.textContent = formatNumber(data.stats.opportunities);
                conversionRateEl.textContent = `${data.stats.conversion_rate}%`;
                
                statsSection.style.display = 'grid';
                
                resultsTable.innerHTML = '';
                
                if (data.data && data.data.length > 0) {
                    data.data.forEach(user => {
                        const row = document.createElement('tr');
                        row.innerHTML = `
                            <td>${user.name || 'N/A'}</td>
                            <td>${user.email || 'N/A'}</td>
                            <td>${formatPhone(user.phone)}</td>
                            <td>${user.country || 'N/A'}</td>
                        `;
                        resultsTable.appendChild(row);
                    });
                    
                    resultsInfo.textContent = `${formatNumber(data.data.length)} usuarios encontrados | Consulta: ${data.query_time_ms}ms`;
                    resultsSection.style.display = 'block';
                    
                    showSuccess(`✅ Sincronización completada: ${formatNumber(data.data.length)} oportunidades de conversión encontradas`);
                } else {
                    resultsSection.style.display = 'none';
                    showSuccess('✅ Sincronización completada: No hay usuarios ALL ACCESS sin INFINITY en este momento');
                }
                
            } catch (error) {
                console.error('Error en sincronización:', error);
                showError(`❌ Error: ${error.message}`);
                
                statsSection.style.display = 'none';
                resultsSection.style.display = 'none';
            } finally {
                syncBtn.disabled = false;
                syncText.textContent = 'SINCRONIZAR DATOS';
            }
        }
        
        syncBtn.addEventListener('click', syncData);
    </script>
</body>
</html>
