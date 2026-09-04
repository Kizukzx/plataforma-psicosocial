<?php
/**
 * Consulta de aprendizes vinculados às unidades atendidas pela psicóloga.
 * GET ?busca=&situacao=ativo|cancelado|prioritario|arquivado&data=
 * GET ?id=123  -> perfil completo do aprendiz (histórico, faltas, documentos)
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/response.php';

exigirPerfil(['psicologo']);
$psicologoId = usuarioIdAtual();
$pdo = getDB();
exigirMetodo('GET');

$unidades = unidadesDaPsicologa($psicologoId);
if (empty($unidades)) { sucesso([]); }
$placeholders = implode(',', array_fill(0, count($unidades), '?'));

if (!empty($_GET['id'])) {
    $sql = "SELECT al.*, u.nome AS unidade_nome FROM alunos al
            JOIN unidades u ON u.id = al.unidade_id
            WHERE al.id = ? AND al.unidade_id IN ($placeholders)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([(int)$_GET['id']], $unidades));
    $aluno = $stmt->fetch();
    if (!$aluno) { erro('Aprendiz não encontrado nas suas unidades.', 404); }

    $hist = $pdo->prepare('SELECT * FROM atendimentos WHERE aluno_id = :id ORDER BY data_hora DESC');
    $hist->execute(['id' => $aluno['id']]);
    $aluno['historico_atendimentos'] = $hist->fetchAll();

    $faltas = $pdo->prepare("SELECT COUNT(*) AS total FROM atendimentos WHERE aluno_id = :id AND status = 'ausencia'");
    $faltas->execute(['id' => $aluno['id']]);
    $aluno['total_faltas'] = (int)$faltas->fetch()['total'];

    $docs = $pdo->prepare('SELECT COUNT(*) AS total FROM documentos_psicossociais WHERE aluno_id = :id AND psicologo_id = :p');
    $docs->execute(['id' => $aluno['id'], 'p' => $psicologoId]);
    $aluno['total_documentos'] = (int)$docs->fetch()['total'];

    sucesso($aluno);
}

$sql = "SELECT al.id, al.ra, al.nome, al.curso, al.status_matricula, u.nome AS unidade_nome,
               DATE_FORMAT(MAX(a.data_hora), '%d/%m/%Y %H:%i') AS ultima_sessao
        FROM alunos al JOIN unidades u ON u.id = al.unidade_id
        LEFT JOIN atendimentos a ON a.aluno_id = al.id
        WHERE al.unidade_id IN ($placeholders)
        GROUP BY al.id, al.ra, al.nome, al.curso, al.status_matricula, u.nome";
$params = $unidades;

if (!empty($_GET['busca'])) {
    $sql .= ' AND (al.nome LIKE ? OR al.ra LIKE ?)';
    $params[] = '%' . $_GET['busca'] . '%';
    $params[] = '%' . $_GET['busca'] . '%';
}
$sql .= ' ORDER BY al.nome';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
sucesso($stmt->fetchAll());
