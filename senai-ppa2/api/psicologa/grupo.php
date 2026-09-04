<?php
/**
 * Atendimentos em grupo.
 * GET    ?id=1              -> detalhe + participantes
 * GET                        -> lista sessões de grupo da psicóloga
 * POST   { unidade_id, tema, data_hora, duracao_min, participantes:[aluno_id,...] } -> criar sessão
 * PUT    { id, resumo_atividades, status, presencas:[{aluno_id,presente,justificativa_falta}] } -> registrar presença/fechamento
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/response.php';

exigirPerfil(['psicologo']);
$psicologoId = usuarioIdAtual();
$pdo = getDB();

switch (metodo()) {
    case 'GET':
        if (!empty($_GET['id'])) {
            $g = $pdo->prepare('SELECT * FROM atendimentos_grupo WHERE id = :id AND psicologo_id = :p');
            $g->execute(['id' => (int)$_GET['id'], 'p' => $psicologoId]);
            $grupo = $g->fetch();
            if (!$grupo) { erro('Sessão de grupo não encontrada.', 404); }
            $p = $pdo->prepare(
                'SELECT gp.*, al.nome, al.ra FROM grupo_participantes gp
                 JOIN alunos al ON al.id = gp.aluno_id WHERE gp.atendimento_grupo_id = :id'
            );
            $p->execute(['id' => $grupo['id']]);
            $grupo['participantes'] = $p->fetchAll();
            sucesso($grupo);
        }
        $stmt = $pdo->prepare('SELECT * FROM atendimentos_grupo WHERE psicologo_id = :p ORDER BY data_hora DESC');
        $stmt->execute(['p' => $psicologoId]);
        sucesso($stmt->fetchAll());
        break;

    case 'POST':
        exigirCsrf();
        $b = corpoRequisicao();
        foreach (['unidade_id', 'data_hora', 'participantes'] as $campo) {
            if (empty($b[$campo])) { erro("Campo obrigatório: {$campo}", 422); }
        }
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO atendimentos_grupo (psicologo_id, unidade_id, tema, data_hora, duracao_min)
                 VALUES (:p, :u, :t, :dh, :dur)'
            );
            $stmt->execute([
                'p' => $psicologoId, 'u' => $b['unidade_id'], 't' => $b['tema'] ?? null,
                'dh' => $b['data_hora'], 'dur' => $b['duracao_min'] ?? 60,
            ]);
            $grupoId = $pdo->lastInsertId();

            $insPart = $pdo->prepare('INSERT INTO grupo_participantes (atendimento_grupo_id, aluno_id) VALUES (:g, :a)');
            foreach ($b['participantes'] as $alunoId) {
                $insPart->execute(['g' => $grupoId, 'a' => (int)$alunoId]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            erro('Erro ao criar sessão em grupo: ' . $e->getMessage(), 500);
        }
        sucesso(['id' => $grupoId], 'Sessão em grupo criada com sucesso.');
        break;

    case 'PUT':
        exigirCsrf();
        $b = corpoRequisicao();
        $id = (int)($b['id'] ?? 0);
        if (!$id) { erro('Informe o id da sessão.', 422); }

        $chk = $pdo->prepare('SELECT id FROM atendimentos_grupo WHERE id = :id AND psicologo_id = :p');
        $chk->execute(['id' => $id, 'p' => $psicologoId]);
        if (!$chk->fetch()) { erro('Sessão não encontrada.', 404); }

        $upd = $pdo->prepare(
            'UPDATE atendimentos_grupo SET resumo_atividades = COALESCE(:r, resumo_atividades),
             status = COALESCE(:s, status) WHERE id = :id'
        );
        $upd->execute(['r' => $b['resumo_atividades'] ?? null, 's' => $b['status'] ?? null, 'id' => $id]);

        if (!empty($b['presencas']) && is_array($b['presencas'])) {
            $updP = $pdo->prepare(
                'UPDATE grupo_participantes SET presente = :pres, justificativa_falta = :j
                 WHERE atendimento_grupo_id = :g AND aluno_id = :a'
            );
            foreach ($b['presencas'] as $p) {
                $updP->execute([
                    'pres' => isset($p['presente']) ? (int)!!$p['presente'] : null,
                    'j' => $p['justificativa_falta'] ?? null,
                    'g' => $id, 'a' => $p['aluno_id'],
                ]);
            }
        }
        sucesso(null, 'Sessão em grupo atualizada com sucesso.');
        break;

    default:
        erro('Método não suportado.', 405);
}
