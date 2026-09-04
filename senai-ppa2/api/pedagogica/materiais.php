<?php
/**
 * Materiais aprovados (equipe pedagógica só visualiza; sem edição) e aprovação
 * de conteúdos pendentes submetidos pelas psicólogas — a aprovação final é
 * institucional (e-mail atendimentopsicossocial@sistemafiepe.org.br); aqui a
 * equipe pedagógica registra a decisão em nome desse fluxo institucional.
 * GET  ?status=pendente|aprovado   -> listar
 * PUT  { id, aprovar: true|false } -> aprovar/recusar conteúdo pendente
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/response.php';

exigirPerfil(['pedagogo']);
$pdo = getDB();

if (metodo() === 'GET') {
    $status = $_GET['status'] ?? 'aprovado';
    $stmt = $pdo->prepare(
        'SELECT c.*, p.nome AS autor FROM conteudos c JOIN psicologos p ON p.id = c.psicologo_id
         WHERE c.status = :s ORDER BY c.criado_em DESC'
    );
    $stmt->execute(['s' => $status]);
    sucesso($stmt->fetchAll());
}

if (metodo() === 'PUT') {
    exigirCsrf();
    $b = corpoRequisicao();
    $id = (int)($b['id'] ?? 0);
    if (!$id || !isset($b['aprovar'])) { erro('Informe id e aprovar (true/false).', 422); }

    $novoStatus = $b['aprovar'] ? 'aprovado' : 'recusado';
    $stmt = $pdo->prepare(
        'UPDATE conteudos SET status = :s, aprovado_por = :ap, aprovado_em = NOW() WHERE id = :id'
    );
    $stmt->execute(['s' => $novoStatus, 'ap' => 'atendimentopsicossocial@sistemafiepe.org.br', 'id' => $id]);
    sucesso(null, "Conteúdo {$novoStatus} com sucesso.");
}

erro('Método não suportado.', 405);
