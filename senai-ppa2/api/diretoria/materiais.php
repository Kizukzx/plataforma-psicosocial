<?php
/**
 * Acesso da Diretoria a conteúdos institucionais aprovados (item 6.d do documento).
 * GET -> lista conteúdos aprovados
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/response.php';

exigirPerfil(['diretoria']);
exigirMetodo('GET');
$pdo = getDB();

$stmt = $pdo->query("SELECT id, titulo, tipo, categoria, criado_em FROM conteudos WHERE status='aprovado' ORDER BY criado_em DESC");
sucesso($stmt->fetchAll());
