<?php
/**
 * Canal de conteúdos para o aprendiz: acesso imediato a cartilhas, vídeos e
 * atividades aprovadas. Registra engajamento (visualização/download).
 * GET  -> lista conteúdos aprovados relevantes à unidade do aluno
 * POST { conteudo_id, download: bool } -> registrar engajamento
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/response.php';

exigirPerfil(['aluno']);
$alunoId = usuarioIdAtual();
$pdo = getDB();

if (metodo() === 'GET') {
    $aluno = $pdo->prepare('SELECT unidade_id FROM alunos WHERE id = :id');
    $aluno->execute(['id' => $alunoId]);
    $unidadeId = $aluno->fetch()['unidade_id'];

    $stmt = $pdo->prepare(
        "SELECT id, titulo, tipo, categoria, descricao, arquivo_path, url_externa, criado_em
         FROM conteudos WHERE status = 'aprovado' AND (unidade_id IS NULL OR unidade_id = :u)
         ORDER BY criado_em DESC"
    );
    $stmt->execute(['u' => $unidadeId]);
    sucesso($stmt->fetchAll());
}

if (metodo() === 'POST') {
    $b = corpoRequisicao();
    if (empty($b['conteudo_id'])) { erro('Informe conteudo_id.', 422); }
    $stmt = $pdo->prepare(
        'INSERT INTO conteudo_engajamento (conteudo_id, aluno_id, download) VALUES (:c, :a, :d)'
    );
    $stmt->execute(['c' => $b['conteudo_id'], 'a' => $alunoId, 'd' => !empty($b['download']) ? 1 : 0]);
    sucesso(null, 'Engajamento registrado.');
}

erro('Método não suportado.', 405);
