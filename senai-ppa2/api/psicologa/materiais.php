<?php
/**
 * Canal de conteúdos — a psicóloga cria/submete materiais (cartilhas, vídeos,
 * atividades, comunicados) que ficam "pendentes" até aprovação institucional.
 * GET   -> lista os conteúdos submetidos pela própria psicóloga
 * POST  { titulo, tipo, categoria, unidade_id?, descricao?, url_externa? } -> submeter
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/response.php';

exigirPerfil(['psicologo']);
$psicologoId = usuarioIdAtual();
$pdo = getDB();

if (metodo() === 'GET') {
    $stmt = $pdo->prepare('SELECT * FROM conteudos WHERE psicologo_id = :p ORDER BY criado_em DESC');
    $stmt->execute(['p' => $psicologoId]);
    sucesso($stmt->fetchAll());
}

if (metodo() === 'POST') {
    exigirCsrf();
    $b = corpoRequisicao();
    foreach (['titulo', 'tipo'] as $campo) {
        if (empty($b[$campo])) { erro("Campo obrigatório: {$campo}", 422); }
    }
    if (!in_array($b['tipo'], ['cartilha', 'video', 'atividade', 'comunicado'], true)) {
        erro('Tipo de conteúdo inválido.', 422);
    }
    $stmt = $pdo->prepare(
        'INSERT INTO conteudos (titulo, tipo, categoria, unidade_id, descricao, url_externa, psicologo_id, status)
         VALUES (:t, :tp, :c, :u, :d, :url, :p, "pendente")'
    );
    $stmt->execute([
        't' => limpar($b['titulo']), 'tp' => $b['tipo'], 'c' => limpar($b['categoria'] ?? null),
        'u' => $b['unidade_id'] ?? null, 'd' => $b['descricao'] ?? null,
        'url' => $b['url_externa'] ?? null, 'p' => $psicologoId,
    ]);
    sucesso(['id' => $pdo->lastInsertId()], 'Conteúdo submetido. Ficará pendente até aprovação institucional.');
}

erro('Método não suportado.', 405);
