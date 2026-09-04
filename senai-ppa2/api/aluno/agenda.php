<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/functions.php';

exigirPerfil(['aluno']);
$alunoId = usuarioIdAtual();
$pdo = getDB();

$alunoStmt = $pdo->prepare('SELECT id, unidade_id, status_matricula FROM alunos WHERE id = :id');
$alunoStmt->execute(['id' => $alunoId]);
$aluno = $alunoStmt->fetch();
if (!$aluno) erro('Aprendiz não encontrado.', 404);
if ($aluno['status_matricula'] !== 'ativo') erro('Sua matrícula não está ativa.', 403);

$psiStmt = $pdo->prepare('SELECT p.id, p.nome, p.email FROM psicologos p JOIN psicologo_unidades pu ON pu.psicologo_id=p.id WHERE pu.unidade_id=:u AND p.ativo=1 ORDER BY p.id LIMIT 1');
$psiStmt->execute(['u' => $aluno['unidade_id']]);
$psi = $psiStmt->fetch();
if (!$psi) erro('Sua unidade ainda não possui psicóloga responsável cadastrada.', 409);

function validarDataAgendaAluno(string $data): void {
    $dt = DateTime::createFromFormat('Y-m-d', $data);
    $hoje = new DateTime('today');
    if (!$dt || $dt->format('Y-m-d') !== $data || $dt < $hoje) erro('Escolha uma data a partir de hoje.', 422);
}
function validarHoraAgendaAluno(string $hora): void {
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) erro('Horário inválido.', 422);
    [$h,$m] = array_map('intval', explode(':', $hora));
    if ($h < 9 || $h >= 17 || !in_array($m, [0,30], true)) erro('Os atendimentos ocorrem entre 09:00 e 17:00, em blocos de 30 minutos.', 422);
}

if (metodo() === 'GET') {
    $data = $_GET['data'] ?? date('Y-m-d');
    validarDataAgendaAluno($data);

    $slots = horariosDisponiveis((int)$psi['id'], $data);
    $resp = [
        'data' => $data,
        'psicologa' => $psi,
        'horarios_disponiveis' => array_values($slots),
        'modalidade_unidade' => null,
        'minhas_solicitacoes' => []
    ];

    $u = $pdo->prepare('SELECT nome, modalidade FROM unidades WHERE id=:id');
    $u->execute(['id'=>$aluno['unidade_id']]);
    $resp['modalidade_unidade'] = $u->fetch();

    $s = $pdo->prepare('SELECT id, motivo, urgente, status, atendimento_id, criado_em, respondido_em FROM solicitacoes_atendimento WHERE aluno_id=:a ORDER BY criado_em DESC LIMIT 20');
    $s->execute(['a'=>$alunoId]);
    $resp['minhas_solicitacoes'] = $s->fetchAll();

    sucesso($resp);
}

if (metodo() === 'POST') {
    exigirCsrf();
    $b = corpoRequisicao();
    $data = trim((string)($b['data'] ?? ''));
    $hora = substr(trim((string)($b['hora'] ?? '')), 0, 5);
    $modalidade = strtolower(trim((string)($b['modalidade'] ?? '')));
    $motivo = trim((string)($b['motivo'] ?? ''));
    $urgente = !empty($b['urgente']) ? 1 : 0;

    validarDataAgendaAluno($data);
    validarHoraAgendaAluno($hora);

    $unidade = $pdo->prepare('SELECT nome, modalidade FROM unidades WHERE id=:id');
    $unidade->execute(['id'=>$aluno['unidade_id']]);
    $u = $unidade->fetch();
    if (!$u) erro('Unidade do aprendiz não encontrada.', 409);

    $permitidas = match ($u['modalidade']) {
        'PRESENCIAL' => ['presencial'],
        'REMOTO' => ['remoto'],
        default => ['presencial','remoto'],
    };
    if (!in_array($modalidade, $permitidas, true)) erro('Essa modalidade não está disponível para sua unidade.', 422);

    if (!in_array($hora, horariosDisponiveis((int)$psi['id'], $data), true)) {
        erro('Esse horário já não está disponível. Atualize a agenda e escolha outro horário.', 409);
    }

    $dup = $pdo->prepare("SELECT id FROM solicitacoes_atendimento WHERE aluno_id=:a AND status='pendente' LIMIT 1");
    $dup->execute(['a'=>$alunoId]);
    if ($dup->fetch()) erro('Você já possui uma solicitação pendente. Aguarde a avaliação da psicóloga.', 409);

    $dh = $data.' '.$hora.':00';
    $ins = $pdo->prepare('INSERT INTO solicitacoes_atendimento (aluno_id, psicologo_id, motivo, urgente) VALUES (:a,:p,:m,:u)');
    $ins->execute(['a'=>$alunoId,'p'=>$psi['id'],'m'=>$motivo ?: null,'u'=>$urgente]);
    $solId = (int)$pdo->lastInsertId();

    $alert = $pdo->prepare('INSERT INTO alertas (tipo, aluno_id, psicologo_id, unidade_id, mensagem) VALUES (\'solicitacao_pendente\',:a,:p,:u,:m)');
    $alert->execute(['a'=>$alunoId,'p'=>$psi['id'],'u'=>$aluno['unidade_id'],'m'=>'Nova solicitação de atendimento aguardando avaliação.']);

    registrarLog('aluno',$alunoId,'solicitar_atendimento',"Solicitação #{$solId} para {$dh} ({$modalidade})");
    sucesso(['id'=>$solId,'data_hora_solicitada'=>$dh,'modalidade'=>$modalidade], 'Solicitação enviada para a psicóloga responsável.');
}

erro('Método não suportado.',405);
