<?php
/**
 * Relatórios consolidados da psicóloga: atendimentos, faltas, cancelamentos,
 * casos prioritários. Exportação em JSON (o front converte para PDF/Excel)
 * ou diretamente em CSV via ?formato=csv.
 * GET ?data_inicio=&data_fim=&formato=json|csv
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/response.php';

exigirPerfil(['psicologo']);
exigirMetodo('GET');
$psicologoId = usuarioIdAtual();
$pdo = getDB();

$inicio = $_GET['data_inicio'] ?? date('Y-m-01');
$fim = $_GET['data_fim'] ?? date('Y-m-d');

$stmt = $pdo->prepare(
    "SELECT al.nome AS aluno, al.ra, a.modalidade, a.data_hora, a.status, a.prioritario,
            a.justificativa_cancelamento
     FROM atendimentos a
     JOIN alunos al ON al.id = a.aluno_id
     WHERE a.psicologo_id = :p AND DATE(a.data_hora) BETWEEN :i AND :f
     ORDER BY a.data_hora"
);
$stmt->execute(['p' => $psicologoId, 'i' => $inicio, 'f' => $fim]);
$linhas = $stmt->fetchAll();

$resumo = [
    'total_atendimentos' => count($linhas),
    'finalizados' => count(array_filter($linhas, fn($l) => $l['status'] === 'finalizado')),
    'ausencias' => count(array_filter($linhas, fn($l) => $l['status'] === 'ausencia')),
    'cancelados' => count(array_filter($linhas, fn($l) => $l['status'] === 'cancelado')),
    'prioritarios' => count(array_filter($linhas, fn($l) => (int)$l['prioritario'] === 1)),
];

if (($_GET['formato'] ?? 'json') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="relatorio_atendimentos.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Aluno', 'RA', 'Modalidade', 'Data/Hora', 'Status', 'Prioritário', 'Justificativa Cancelamento']);
    foreach ($linhas as $l) {
        fputcsv($out, [$l['aluno'], $l['ra'], $l['modalidade'], $l['data_hora'], $l['status'], $l['prioritario'] ? 'Sim' : 'Não', $l['justificativa_cancelamento']]);
    }
    fclose($out);
    exit;
}

sucesso(['periodo' => [$inicio, $fim], 'resumo' => $resumo, 'atendimentos' => $linhas]);
