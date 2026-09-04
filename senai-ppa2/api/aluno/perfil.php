<?php
/**
 * GET -> perfil do próprio aprendiz logado + agenda de atendimentos
 * (o aluno só acessa suas próprias informações — item 5.e.ii do documento).
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/response.php';

exigirPerfil(['aluno']);
exigirMetodo('GET');
$alunoId = usuarioIdAtual();
$pdo = getDB();

$stmt = $pdo->prepare(
    'SELECT al.id, al.ra, al.nome, al.curso, al.status_matricula, u.nome AS unidade_nome, u.modalidade
     FROM alunos al JOIN unidades u ON u.id = al.unidade_id WHERE al.id = :id'
);
$stmt->execute(['id' => $alunoId]);
$perfil = $stmt->fetch();

$agenda = $pdo->prepare(
    "SELECT a.id, a.data_hora, a.modalidade, a.status, a.sinalizacao, p.nome AS psicologo_nome
     FROM atendimentos a JOIN psicologos p ON p.id = a.psicologo_id
     WHERE a.aluno_id = :id ORDER BY a.data_hora DESC"
);
$agenda->execute(['id' => $alunoId]);
$perfil['atendimentos'] = $agenda->fetchAll();

sucesso($perfil);
