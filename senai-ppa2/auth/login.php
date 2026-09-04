<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/response.php';

exigirMetodo('POST');
$body = corpoRequisicao();
$perfil = strtolower(trim((string)($body['perfil'] ?? '')));
$identificador = trim((string)($body['identificador'] ?? ''));
$senha = (string)($body['senha'] ?? '');

if ($identificador === '' || $senha === '' || !in_array($perfil, PERFIS_VALIDOS, true)) {
    erro('Selecione um perfil e informe usuário e senha.', 422);
}

$usuario = autenticar($perfil, $identificador, $senha);
if (!$usuario) {
    if ($perfil === 'aluno') {
        erro('Usuário ou senha inválidos. Se sua matrícula estiver inativa, procure a Coordenação Pedagógica da sua unidade.', 401);
    }
    erro('E-mail, perfil ou senha inválidos.', 401);
}

iniciarSessao($perfil, $usuario);
$precisaTrocarSenha = $perfil === 'aluno' && (int)($usuario['senha_trocada'] ?? 0) === 0;

sucesso([
    'perfil' => $perfil,
    'usuario' => $usuario,
    'csrf_token' => csrfToken(),
    'precisa_trocar_senha' => $precisaTrocarSenha,
], 'Login realizado com sucesso.');
