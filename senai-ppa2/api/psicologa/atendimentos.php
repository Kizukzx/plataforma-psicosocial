<?php
/**
 * CRUD de atendimentos individuais (presencial e remoto/EaD) da psicóloga.
 *
 * GET    ?busca=&status=&unidade=&data=       -> busca rápida / listagem com filtros
 * GET    ?id=123                              -> detalhe de um atendimento
 * POST   { aluno_id, modalidade, data_hora, prioritario, observacoes }  -> agendar
 * PUT    { id, status, justificativa_cancelamento, observacoes, prioritario, reentrada }
 * DELETE ?id=123                               -> cancelamento (mantém histórico; usar PUT com status=cancelado é preferível)
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/functions.php';

exigirPerfil(['psicologo']);
$psicologoId = usuarioIdAtual();
$pdo = getDB();

switch (metodo()) {
    case 'GET':
        if (!empty($_GET['id'])) {
            $stmt = $pdo->prepare(
                'SELECT a.*, al.nome AS aluno_nome, al.ra, al.curso, u.nome AS unidade_nome
                 FROM atendimentos a
                 JOIN alunos al ON al.id = a.aluno_id
                 JOIN unidades u ON u.id = al.unidade_id
                 WHERE a.id = :id AND a.psicologo_id = :p'
            );
            $stmt->execute(['id' => (int)$_GET['id'], 'p' => $psicologoId]);
            $r = $stmt->fetch();
            if (!$r) { erro('Atendimento não encontrado.', 404); }
            sucesso($r);
        }

        // Busca rápida: nome, unidade, situação (ativo/cancelado/prioritario/arquivado) ou data
        $sql = 'SELECT a.id, a.data_hora, a.modalidade, a.status, a.sinalizacao, a.prioritario,
                       al.nome AS aluno_nome, al.ra, u.nome AS unidade_nome
                FROM atendimentos a
                JOIN alunos al ON al.id = a.aluno_id
                JOIN unidades u ON u.id = al.unidade_id
                WHERE a.psicologo_id = :p';
        $params = ['p' => $psicologoId];

        if (!empty($_GET['busca'])) {
            $sql .= ' AND (al.nome LIKE :busca OR al.ra LIKE :busca)';
            $params['busca'] = '%' . $_GET['busca'] . '%';
        }
        if (!empty($_GET['status'])) {
            $sql .= ' AND a.status = :status';
            $params['status'] = $_GET['status'];
        }
        if (!empty($_GET['unidade'])) {
            $sql .= ' AND u.id = :unidade';
            $params['unidade'] = (int)$_GET['unidade'];
        }
        if (!empty($_GET['data'])) {
            $sql .= ' AND DATE(a.data_hora) = :data';
            $params['data'] = $_GET['data'];
        }
        if (!empty($_GET['prioritario'])) {
            $sql .= ' AND a.prioritario = 1';
        }
        $sql .= ' ORDER BY a.data_hora DESC LIMIT 200';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        sucesso($stmt->fetchAll());
        break;

    case 'POST':
        exigirCsrf();
        $b = corpoRequisicao();
        foreach (['aluno_id', 'modalidade', 'data_hora'] as $campo) {
            if (empty($b[$campo])) { erro("Campo obrigatório: {$campo}", 422); }
        }
        if (!in_array($b['modalidade'], ['presencial', 'remoto'], true)) {
            erro('Modalidade inválida.', 422);
        }

        $alunoChk=$pdo->prepare('SELECT id,unidade_id,status_matricula FROM alunos WHERE id=:id LIMIT 1'); $alunoChk->execute(['id'=>(int)$b['aluno_id']]); $alunoDados=$alunoChk->fetch();
        if(!$alunoDados || $alunoDados['status_matricula']!=='ativo') erro('Aprendiz não encontrado ou matrícula inativa.',422);
        if (!in_array((int)$alunoDados['unidade_id'], unidadesDaPsicologa($psicologoId), true)) erro('A psicóloga não atende a unidade deste aprendiz.',403);
        $ts=strtotime($b['data_hora']); if($ts===false) erro('Data/horário inválidos.',422);
        $hora=date('H:i',$ts); if($hora<'09:00' || $hora>'16:30' || ((int)date('i',$ts)%30)!==0) erro('Atendimentos devem começar entre 09:00 e 16:30 em blocos de 30 minutos.',422);
        $dh=date('Y-m-d H:i:s',$ts);
        $slot=$pdo->prepare("SELECT id FROM atendimentos WHERE psicologo_id=:p AND data_hora=:dh AND status<>'cancelado' LIMIT 1");$slot->execute(['p'=>$psicologoId,'dh'=>$dh]); if($slot->fetch()) erro('Esse horário já está ocupado.',409);
        $blocked=$pdo->prepare("SELECT id FROM agenda_bloqueios WHERE psicologo_id=:p AND data=:d AND hora_inicio <= :h AND hora_fim > :h LIMIT 1");$blocked->execute(['p'=>$psicologoId,'d'=>date('Y-m-d',$ts),'h'=>$hora.':00']); if($blocked->fetch()) erro('Esse horário está bloqueado na agenda.',409);
        $unidade=$pdo->prepare('SELECT modalidade FROM unidades WHERE id=:u');$unidade->execute(['u'=>$alunoDados['unidade_id']]);$modalidadeUnidade=$unidade->fetchColumn(); if($modalidadeUnidade==='REMOTO' && $b['modalidade']==='presencial') erro('Esta unidade oferece apenas atendimento remoto.',422); if($modalidadeUnidade==='PRESENCIAL' && $b['modalidade']==='remoto') erro('Esta unidade oferece apenas atendimento presencial.',422);
        $prioritario = !empty($b['prioritario']) ? 1 : 0;
        $status = 'confirmado';
        $sinal = sinalizacaoPorStatus($status, (bool)$prioritario);

        $stmt = $pdo->prepare(
            'INSERT INTO atendimentos (aluno_id, psicologo_id, modalidade, data_hora, duracao_min, status, sinalizacao, prioritario, observacoes)
             VALUES (:aluno, :psi, :mod, :dh, 30, :st, :sinal, :prio, :obs)'
        );
        $stmt->execute([
            'aluno' => (int)$b['aluno_id'], 'psi' => $psicologoId, 'mod' => $b['modalidade'],
            'dh' => $dh, 'st' => $status, 'sinal' => $sinal,
            'prio' => $prioritario, 'obs' => limpar($b['observacoes'] ?? null),
        ]);
        $novoId = $pdo->lastInsertId();

        if ($prioritario) {
            $aluno = buscarAluno((int)$b['aluno_id']);
            $ins = $pdo->prepare('INSERT INTO alertas (tipo, aluno_id, psicologo_id, unidade_id, mensagem) VALUES ("caso_prioritario", :a, :p, :u, :m)');
            $ins->execute([
                'a' => $b['aluno_id'], 'p' => $psicologoId, 'u' => $aluno['unidade_id'] ?? null,
                'm' => "Caso prioritário registrado para {$aluno['nome']}.",
            ]);
        }

        registrarLog('psicologo', $psicologoId, 'criar_atendimento', "Atendimento #{$novoId} agendado");
        sucesso(['id' => $novoId], 'Atendimento agendado com sucesso.');
        break;

    case 'PUT':
        exigirCsrf();
        $b = corpoRequisicao();
        $id = (int)($b['id'] ?? 0);
        if (!$id) { erro('Informe o id do atendimento.', 422); }

        $stmt = $pdo->prepare('SELECT * FROM atendimentos WHERE id = :id AND psicologo_id = :p');
        $stmt->execute(['id' => $id, 'p' => $psicologoId]);
        $atual = $stmt->fetch();
        if (!$atual) { erro('Atendimento não encontrado.', 404); }

        $status = $b['status'] ?? $atual['status'];
        $validos = ['confirmado', 'pendente', 'cancelado', 'ausencia', 'finalizado'];
        if (!in_array($status, $validos, true)) { erro('Status inválido.', 422); }

        if ($status === 'cancelado' && empty($b['justificativa_cancelamento'])) {
            erro('Cancelamento exige justificativa (item 3.2.c.ii do documento).', 422);
        }

        $prioritario = isset($b['prioritario']) ? (int)!!$b['prioritario'] : $atual['prioritario'];
        $sinal = sinalizacaoPorStatus($status, (bool)$prioritario);

        $upd = $pdo->prepare(
            'UPDATE atendimentos SET status = :st, sinalizacao = :sinal, prioritario = :prio,
             justificativa_cancelamento = :just, observacoes = COALESCE(:obs, observacoes),
             reentrada = :reen
             WHERE id = :id'
        );
        $upd->execute([
            'st' => $status, 'sinal' => $sinal, 'prio' => $prioritario,
            'just' => limpar($b['justificativa_cancelamento'] ?? null),
            'obs' => $b['observacoes'] ?? null,
            'reen' => !empty($b['reentrada']) ? 1 : 0,
            'id' => $id,
        ]);

        if ($status === 'ausencia') {
            verificarFaltasConsecutivas((int)$atual['aluno_id']);
        }

        registrarLog('psicologo', $psicologoId, 'atualizar_atendimento', "Atendimento #{$id} -> status {$status}");
        sucesso(null, 'Atendimento atualizado com sucesso.');
        break;

    default:
        erro('Método não suportado.', 405);
}
