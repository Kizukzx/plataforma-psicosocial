<?php
/**
 * POST /auth/trocar_senha.php
 * Troca obrigatória de senha no primeiro acesso do aprendiz (item 4.c do documento)
 * e também disponível para os demais perfis alterarem a própria senha.
 * Body: { "senha_atual": "...", "senha_nova": "..." }
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/response.php';

exigirMetodo('POST');
exigirPerfil(['aluno', 'psicologo', 'pedagogo', 'diretoria']);
exigirCsrf();

$body = corpoRequisicao();
$senhaAtual = $body['senha_atual'] ?? '';
$senhaNova = $body['senha_nova'] ?? '';

if (strlen($senhaNova) < 8 || !preg_match('/[A-Za-z]/', $senhaNova) || !preg_match('/\d/', $senhaNova)) {
    erro('A nova senha deve ter ao menos 8 caracteres, com letras e números.', 422);
}
if ($senhaNova === $senhaAtual) {
    erro('A nova senha deve ser diferente da atual.', 422);
}

$tabela = ['aluno' => 'alunos', 'psicologo' => 'psicologos', 'pedagogo' => 'pedagogos', 'diretoria' => 'diretoria'][perfilAtual()];

$pdo = getDB();
$stmt = $pdo->prepare("SELECT senha_hash FROM {$tabela} WHERE id = :id");
$stmt->execute(['id' => usuarioIdAtual()]);
$row = $stmt->fetch();

if (!$row || !password_verify($senhaAtual, $row['senha_hash'])) {
    erro('Senha atual incorreta.', 401);
}

$novoHash = password_hash($senhaNova, PASSWORD_BCRYPT);
$extra = perfilAtual() === 'aluno' ? ', senha_trocada = 1' : '';
$upd = $pdo->prepare("UPDATE {$tabela} SET senha_hash = :h{$extra} WHERE id = :id");
$upd->execute(['h' => $novoHash, 'id' => usuarioIdAtual()]);

registrarLog(perfilAtual(), usuarioIdAtual(), 'troca_senha', 'Senha alterada pelo usuário');

sucesso(null, 'Senha alterada com sucesso.');
