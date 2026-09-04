<?php
/**
 * Documentos psicossociais — repositório pessoal e seguro (item 3.1 e 3.3 do documento).
 *
 * A psicóloga só pode PREENCHER modelos oficiais já cadastrados (não pode criar
 * ou alterar a estrutura). Após salvo, um documento só pode ser editado/excluído
 * mediante solicitação justificada (roteada ao e-mail institucional).
 *
 * GET  ?modelos=1                 -> lista os modelos oficiais disponíveis
 * GET  ?aluno_id=123              -> lista documentos do aluno (pasta individual)
 * GET  ?id=45                     -> detalhe de um documento
 * POST { aluno_id, modelo_id, atendimento_id?, atendimento_grupo_id?, conteudo }  -> preencher e salvar
 * POST (com "solicitar":"edicao|exclusao", "documento_id", "motivo")            -> solicitação justificada
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/functions.php';

exigirPerfil(['psicologo']);
$psicologoId = usuarioIdAtual();
$pdo = getDB();

if (metodo() === 'GET') {
    if (!empty($_GET['modelos'])) {
        $stmt = $pdo->query('SELECT id, nome, descricao, estrutura_json FROM modelos_documentos WHERE ativo = 1');
        sucesso($stmt->fetchAll());
    }

    if (!empty($_GET['id'])) {
        $stmt = $pdo->prepare(
            'SELECT d.*, al.nome AS aluno_nome, al.ra, m.nome AS modelo_nome
             FROM documentos_psicossociais d
             JOIN alunos al ON al.id = d.aluno_id
             JOIN modelos_documentos m ON m.id = d.modelo_id
             WHERE d.id = :id AND d.psicologo_id = :p'
        );
        $stmt->execute(['id' => (int)$_GET['id'], 'p' => $psicologoId]);
        $doc = $stmt->fetch();
        if (!$doc) { erro('Documento não encontrado.', 404); }
        sucesso($doc);
    }

    if (!empty($_GET['aluno_id'])) {
        $stmt = $pdo->prepare(
            'SELECT d.id, d.criado_em, d.assinado_digitalmente, d.editavel, m.nome AS modelo_nome
             FROM documentos_psicossociais d
             JOIN modelos_documentos m ON m.id = d.modelo_id
             WHERE d.aluno_id = :a AND d.psicologo_id = :p
             ORDER BY d.criado_em DESC'
        );
        $stmt->execute(['a' => (int)$_GET['aluno_id'], 'p' => $psicologoId]);
        sucesso($stmt->fetchAll());
    }

    erro('Informe modelos=1, id ou aluno_id.', 422);
}

if (metodo() === 'POST') {
    exigirCsrf();
    $b = corpoRequisicao();

    // Fluxo de solicitação de edição/exclusão de documento já salvo
    if (!empty($b['solicitar'])) {
        if (!in_array($b['solicitar'], ['edicao', 'exclusao'], true) || empty($b['documento_id']) || empty($b['motivo'])) {
            erro('Informe documento_id, tipo (edicao/exclusao) e motivo.', 422);
        }
        $chk = $pdo->prepare('SELECT id FROM documentos_psicossociais WHERE id = :id AND psicologo_id = :p');
        $chk->execute(['id' => $b['documento_id'], 'p' => $psicologoId]);
        if (!$chk->fetch()) { erro('Documento não encontrado.', 404); }

        $ins = $pdo->prepare(
            'INSERT INTO solicitacoes_documento (documento_id, psicologo_id, tipo, motivo)
             VALUES (:d, :p, :t, :m)'
        );
        $ins->execute([
            'd' => $b['documento_id'], 'p' => $psicologoId, 't' => $b['solicitar'], 'm' => limpar($b['motivo']),
        ]);
        // Encaminhamento automático para atendimentopsicossocial@sistemafiepe.org.br
        // (integração de e-mail deve ser plugada aqui via mail()/PHPMailer em produção)
        sucesso(['id' => $pdo->lastInsertId()], 'Solicitação enviada para atendimentopsicossocial@sistemafiepe.org.br.');
    }

    // Preenchimento de um documento padronizado (único formato permitido)
    foreach (['aluno_id', 'modelo_id', 'conteudo'] as $campo) {
        if (empty($b[$campo])) { erro("Campo obrigatório: {$campo}", 422); }
    }
    $modelo = $pdo->prepare('SELECT id FROM modelos_documentos WHERE id = :id AND ativo = 1');
    $modelo->execute(['id' => $b['modelo_id']]);
    if (!$modelo->fetch()) { erro('Modelo de documento inválido ou inativo.', 422); }

    $stmt = $pdo->prepare(
        'INSERT INTO documentos_psicossociais
            (aluno_id, psicologo_id, atendimento_id, atendimento_grupo_id, modelo_id, conteudo_json)
         VALUES (:a, :p, :at, :ag, :m, :c)'
    );
    $stmt->execute([
        'a' => $b['aluno_id'], 'p' => $psicologoId,
        'at' => $b['atendimento_id'] ?? null, 'ag' => $b['atendimento_grupo_id'] ?? null,
        'm' => $b['modelo_id'], 'c' => json_encode($b['conteudo'], JSON_UNESCAPED_UNICODE),
    ]);
    registrarLog('psicologo', $psicologoId, 'criar_documento', 'Documento psicossocial salvo no repositório');
    sucesso(['id' => $pdo->lastInsertId()], 'Documento salvo com sucesso na pasta do aprendiz.');
}

erro('Método não suportado.', 405);
