<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/functions.php';

exigirPerfil(['psicologo']);
$psicologoId = usuarioIdAtual();
$pdo = getDB();

if (metodo() === 'GET') {
    $status = $_GET['status'] ?? 'pendente';
    $allowed = ['pendente','aprovada','recusada'];
    if (!in_array($status,$allowed,true)) erro('Status inválido.',422);
    $stmt = $pdo->prepare(
        'SELECT s.id,s.aluno_id,s.motivo,s.urgente,s.status,s.atendimento_id,s.criado_em,s.respondido_em,
                al.nome AS aluno_nome,al.ra,al.unidade_id,al.curso,al.status_matricula,
                u.nome AS unidade_nome,u.modalidade AS unidade_modalidade
         FROM solicitacoes_atendimento s
         JOIN alunos al ON al.id=s.aluno_id
         JOIN unidades u ON u.id=al.unidade_id
         WHERE s.psicologo_id=:p AND s.status=:st
         ORDER BY s.urgente DESC,s.criado_em ASC'
    );
    $stmt->execute(['p'=>$psicologoId,'st'=>$status]);
    sucesso($stmt->fetchAll());
}

if (metodo() === 'PUT') {
    exigirCsrf();
    $b = corpoRequisicao();
    $id = (int)($b['id'] ?? 0);
    $acao = strtolower(trim((string)($b['acao'] ?? '')));
    if (!$id || !in_array($acao,['aprovar','recusar'],true)) erro('Informe id e ação aprovar/recusar.',422);

    $stmt = $pdo->prepare('SELECT s.*,al.nome,al.ra,al.status_matricula,al.unidade_id FROM solicitacoes_atendimento s JOIN alunos al ON al.id=s.aluno_id WHERE s.id=:id AND s.psicologo_id=:p LIMIT 1');
    $stmt->execute(['id'=>$id,'p'=>$psicologoId]);
    $sol = $stmt->fetch();
    if (!$sol) erro('Solicitação não encontrada.',404);
    if ($sol['status'] !== 'pendente') erro('Essa solicitação já foi respondida.',409);

    if ($acao === 'recusar') {
        $motivoRecusa = trim((string)($b['motivo_recusa'] ?? ''));
        $u = $pdo->prepare('UPDATE solicitacoes_atendimento SET status=\'recusada\', respondido_em=NOW() WHERE id=:id AND psicologo_id=:p AND status=\'pendente\'');
        $u->execute(['id'=>$id,'p'=>$psicologoId]);
        $a = $pdo->prepare('INSERT INTO alertas (tipo,aluno_id,psicologo_id,unidade_id,mensagem) VALUES (\'reagendamento\',:aluno,:psi,:unidade,:m)');
        $msg = 'Sua solicitação de atendimento foi recusada.' . ($motivoRecusa ? ' Motivo: '.$motivoRecusa : '');
        $a->execute(['aluno'=>$sol['aluno_id'],'psi'=>$psicologoId,'unidade'=>$sol['unidade_id'],'m'=>$msg]);
        registrarLog('psicologo',$psicologoId,'recusar_solicitacao',"Solicitação #{$id}");
        sucesso(null,'Solicitação recusada e aprendiz notificado.');
    }

    if ($sol['status_matricula'] !== 'ativo') erro('A matrícula do aprendiz não está ativa.',403);
    $data = trim((string)($b['data'] ?? ''));
    $hora = substr(trim((string)($b['hora'] ?? '')),0,5);
    $modalidade = strtolower(trim((string)($b['modalidade'] ?? '')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$data) || !preg_match('/^\d{2}:\d{2}$/',$hora)) erro('Informe uma data e horário válidos.',422);
    [$h,$m] = array_map('intval',explode(':',$hora));
    if ($h < 9 || $h >= 17 || !in_array($m,[0,30],true)) erro('A agenda aceita horários entre 09:00 e 17:00 em blocos de 30 minutos.',422);

    $u = $pdo->prepare('SELECT modalidade FROM unidades WHERE id=:id');
    $u->execute(['id'=>$sol['unidade_id']]);
    $modUnidade = $u->fetchColumn();
    $permitidas = match ($modUnidade) {
        'PRESENCIAL'=>['presencial'], 'REMOTO'=>['remoto'], default=>['presencial','remoto']
    };
    if (!in_array($modalidade,$permitidas,true)) erro('Modalidade incompatível com a unidade do aprendiz.',422);

    if (!in_array($hora,horariosDisponiveis($psicologoId,$data),true)) erro('Esse horário não está disponível na sua agenda.',409);

    $dh=$data.' '.$hora.':00';
    $pdo->beginTransaction();
    try {
        $ins=$pdo->prepare('INSERT INTO atendimentos (aluno_id,psicologo_id,modalidade,data_hora,status,sinalizacao,prioritario) VALUES (:a,:p,:m,:dh,\'confirmado\',:sig,:prio)');
        $ins->execute(['a'=>$sol['aluno_id'],'p'=>$psicologoId,'m'=>$modalidade,'dh'=>$dh,'sig'=>$sol['urgente']?'vermelho':'verde','prio'=>$sol['urgente']?1:0]);
        $atId=(int)$pdo->lastInsertId();
        $up=$pdo->prepare('UPDATE solicitacoes_atendimento SET status=\'aprovada\',respondido_em=NOW(),atendimento_id=:at WHERE id=:id AND status=\'pendente\'');
        $up->execute(['at'=>$atId,'id'=>$id]);
        $al=$pdo->prepare('INSERT INTO alertas (tipo,aluno_id,psicologo_id,unidade_id,mensagem) VALUES (\'reagendamento\',:a,:p,:u,:m)');
        $al->execute(['a'=>$sol['aluno_id'],'p'=>$psicologoId,'u'=>$sol['unidade_id'],'m'=>"Seu atendimento foi confirmado para {$data} às {$hora} ({$modalidade})."]);
        $pdo->commit();
        registrarLog('psicologo',$psicologoId,'aprovar_solicitacao',"Solicitação #{$id}; atendimento #{$atId}");
        sucesso(['atendimento_id'=>$atId,'data_hora'=>$dh,'modalidade'=>$modalidade],'Solicitação aprovada e horário reservado.');
    } catch (Throwable $e) {
        if($pdo->inTransaction()) $pdo->rollBack();
        erro('Não foi possível reservar o horário: '.$e->getMessage(),500);
    }
}

erro('Método não suportado.',405);
