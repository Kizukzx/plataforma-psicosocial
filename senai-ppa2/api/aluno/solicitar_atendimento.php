<?php
/**
 * Solicitação de atendimento pelo aprendiz (item 4.2.b do documento).
 * O psicólogo responsável é definido automaticamente pela unidade do aluno.
 * GET   -> minhas solicitações e status (aprovada/recusada/pendente)
 * POST  { motivo, urgente } -> nova solicitação
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/response.php';

exigirPerfil(['aluno']);
$alunoId = usuarioIdAtual();
$pdo = getDB();

if (metodo() === 'GET') {
    $stmt = $pdo->prepare('SELECT * FROM solicitacoes_atendimento WHERE aluno_id = :id ORDER BY criado_em DESC');
    $stmt->execute(['id' => $alunoId]);
    sucesso($stmt->fetchAll());
}

if (metodo() === 'POST') {
    exigirCsrf();
    $b = corpoRequisicao();

    $aluno = $pdo->prepare('SELECT unidade_id, status_matricula FROM alunos WHERE id = :id');
    $aluno->execute(['id' => $alunoId]);
    $dadosAluno = $aluno->fetch();
    if ($dadosAluno['status_matricula'] !== 'ativo') {
        erro('Somente aprendizes com matrícula ativa podem solicitar atendimento.', 403);
    }

    // Psicólogo responsável definido pela unidade do aluno
    $psi = $pdo->prepare('SELECT psicologo_id FROM psicologo_unidades WHERE unidade_id = :u LIMIT 1');
    $psi->execute(['u' => $dadosAluno['unidade_id']]);
    $psicologoId = $psi->fetch()['psicologo_id'] ?? null;

    if (!$psicologoId) {
        erro('A unidade do aprendiz ainda não possui psicóloga responsável cadastrada.', 409);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO solicitacoes_atendimento (aluno_id, psicologo_id, motivo, urgente)
         VALUES (:a, :p, :m, :u)'
    );
    $stmt->execute([
        'a' => $alunoId, 'p' => $psicologoId, 'm' => limpar($b['motivo'] ?? null),
        'u' => !empty($b['urgente']) ? 1 : 0,
    ]);
    $novoId = $pdo->lastInsertId();

    if ($psicologoId) {
        $ins = $pdo->prepare(
            'INSERT INTO alertas (tipo, aluno_id, psicologo_id, unidade_id, mensagem) VALUES ("solicitacao_pendente", :a, :p, :u, :m)'
        );
        $ins->execute([
            'a' => $alunoId, 'p' => $psicologoId, 'u' => $dadosAluno['unidade_id'],
            'm' => 'Nova solicitação de atendimento recebida.',
        ]);
    }

    sucesso(['id' => $novoId], 'Solicitação enviada. Você será notificado sobre o status.');
}

erro('Método não suportado.', 405);
