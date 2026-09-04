<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/response.php';

exigirMetodo('POST');
if (usuarioLogado()) {
    registrarLog(perfilAtual(), usuarioIdAtual(), 'logout', 'Sessão encerrada');
}
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool)($params['secure'] ?? false), (bool)($params['httponly'] ?? true));
}
session_destroy();
sucesso(null, 'Sessão encerrada.');
