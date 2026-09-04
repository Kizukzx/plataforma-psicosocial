<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/response.php';
exigirMetodo('GET');
if (!usuarioLogado()) erro('Não autenticado.', 401);
$perfil = perfilAtual();
$id = usuarioIdAtual();
$map = [
 'aluno'=>['alunos','ra, nome, unidade_id, curso, status_matricula, senha_trocada'],
 'psicologo'=>['psicologos','email, nome, ativo'],
 'pedagogo'=>['pedagogos','email, nome, unidade_id, ativo'],
 'diretoria'=>['diretoria','email, nome, ativo'],
];
[$tabela,$cols] = $map[$perfil];
$stmt=getDB()->prepare("SELECT id, {$cols} FROM {$tabela} WHERE id=:id LIMIT 1");
$stmt->execute(['id'=>$id]);
$usuario=$stmt->fetch();
if (!$usuario) erro('Usuário não encontrado.',404);
sucesso(['perfil'=>$perfil,'usuario'=>$usuario,'csrf_token'=>csrfToken(),'precisa_trocar_senha'=>$perfil==='aluno' && (int)$usuario['senha_trocada']===0]);
