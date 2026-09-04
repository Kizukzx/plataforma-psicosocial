<?php
/**
 * Relatórios e indicadores institucionais para a Diretoria de Educação
 * (item 2.b do documento: acesso restrito a relatórios e indicadores gerais,
 * sem dados pessoais identificáveis dos aprendizes).
 * GET ?unidade_id=  (opcional; sem filtro retorna visão de todas as unidades)
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/response.php';

exigirPerfil(['diretoria']);
exigirMetodo('GET');
$pdo = getDB();

$filtroUnidade = !empty($_GET['unidade_id']) ? (int)$_GET['unidade_id'] : null;
$whereUnidade = $filtroUnidade ? 'AND al.unidade_id = :u' : '';

$sql = "SELECT u.id AS unidade_id, u.nome AS unidade, u.modalidade,
               COUNT(DISTINCT al.id) AS alunos_ativos,
               COUNT(a.id) AS total_atendimentos,
               SUM(CASE WHEN a.status='ausencia' THEN 1 ELSE 0 END) AS total_faltas,
               SUM(CASE WHEN a.prioritario=1 THEN 1 ELSE 0 END) AS casos_prioritarios,
               ROUND(100 * SUM(CASE WHEN a.status='finalizado' THEN 1 ELSE 0 END) / NULLIF(COUNT(a.id),0), 1) AS taxa_comparecimento
        FROM unidades u
        LEFT JOIN alunos al ON al.unidade_id = u.id AND al.status_matricula = 'ativo'
        LEFT JOIN atendimentos a ON a.aluno_id = al.id
        WHERE 1=1 {$whereUnidade}
        GROUP BY u.id
        ORDER BY u.nome";

$stmt = $pdo->prepare($sql);
if ($filtroUnidade) { $stmt->execute(['u' => $filtroUnidade]); } else { $stmt->execute(); }

sucesso(['por_unidade' => $stmt->fetchAll()]);
