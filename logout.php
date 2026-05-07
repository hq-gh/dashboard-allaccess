<?php
// ========================================
// LOGOUT - Destruye sesión completa
// ========================================
require_once 'config.php';

$user = $_SESSION['username'] ?? 'unknown';
destroySession();
error_log('[logout] Sesión cerrada: ' . $user);

header('Location: login.php');
exit;
