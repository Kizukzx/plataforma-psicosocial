<?php
/**
 * Indicadores agregados para a equipe pedagógica (item 4.2.a do documento):
 * acesso restrito a estatísticas gerais, SEM dados pessoais identificáveis.
 * GET ?unidade_id=  (opcional; por padrão usa a unidade da própria pedagoga)
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/response.php';

exigirPerfil(['pedagogo']);
exigirMetodo('GET');
$pdo = getDB();

$stmtP = $pdo->prepare('SELECT unidade_id FROM pedagogos WHERE id = :id');
$stmtP->execute(['id' => usuarioIdAtual()]);
$unidadeId = (int)($_GET['unidade_id'] ?? $stmtP->fetch()['unidade_id']);

$totais = $pdo->prepare(
    "SELECT
        (SELECT COUNT(*) FROM alunos WHERE unidade_id = :u AND status_matricula = 'ativo') AS alunos_ativos,
        (SELECT COUNT(*) FROM atendimentos a JOIN alunos al ON al.id=a.aluno_id
            WHERE al.unidade_id = :u AND MONTH(a.data_hora)=MONTH(CURDATE()) AND YEAR(a.data_hora)=YEAR(CURDATE())) AS atendimentos_mes,
        (SELECT COUNT(*) FROM atendimentos a JOIN alunos al ON al.id=a.aluno_id
            WHERE al.unidade_id = :u AND a.status='ausencia' AND a.data_hora >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) AS faltas_30_dias,
        (SELECT COUNT(*) FROM atendimentos a JOIN alunos al ON al.id=a.aluno_id
            WHERE al.unidade_id = :u AND a.prioritario = 1 AND a.status NOT IN ('finalizado','cancelado')) AS casos_prioritarios_abertos
    "
);
$totais->execute(['u' => $unidadeId]);
$resumo = $totais->fetch();

// Consulta à agenda de forma resumida (sem conteúdo de formulários) — item 4.2.b
$agenda = $pdo->prepare(
    "SELECT DATE(a.data_hora) AS dia, COUNT(*) AS total, a.status
     FROM atendimentos a JOIN alunos al ON al.id = a.aluno_id
     WHERE al.unidade_id = :u AND a.data_hora >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
     GROUP BY DATE(a.data_hora), a.status
     ORDER BY dia DESC"
);
$agenda->execute(['u' => $unidadeId]);

sucesso([
    'unidade_id' => $unidadeId,
    'resumo_geral' => $resumo,
    'agenda_resumida_30_dias' => $agenda->fetchAll(),
]);
