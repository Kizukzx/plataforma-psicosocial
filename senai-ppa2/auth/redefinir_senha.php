<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/response.php';
exigirMetodo('POST');
$body=corpoRequisicao();$token=trim((string)($body['token']??''));$nova=(string)($body['senha']??'');$confirm=(string)($body['confirmacao']??'');
if($token===''||$nova===''||$nova!==$confirm) erro('Token e senhas são obrigatórios e devem coincidir.',422);
if(strlen($nova)<8||!preg_match('/[A-Za-z]/',$nova)||!preg_match('/\d/',$nova)) erro('A senha deve ter ao menos 8 caracteres, com letras e números.',422);
$pdo=getDB();$hash=hash('sha256',$token);
$stmt=$pdo->prepare('SELECT * FROM password_resets WHERE token_hash=:h AND usado_em IS NULL AND expira_em>NOW() ORDER BY id DESC LIMIT 1');$stmt->execute(['h'=>$hash]);$r=$stmt->fetch();
if(!$r) erro('Link de recuperação inválido ou expirado.',400);
$map=['aluno'=>'alunos','psicologo'=>'psicologos','pedagogo'=>'pedagogos','diretoria'=>'diretoria'];
$upd=$pdo->prepare("UPDATE {$map[$r['perfil']]} SET senha_hash=:h".($r['perfil']==='aluno'?', senha_trocada=1':'')." WHERE id=:id");
$upd->execute(['h'=>password_hash($nova,PASSWORD_DEFAULT),'id'=>$r['usuario_id']]);
$mark=$pdo->prepare('UPDATE password_resets SET usado_em=NOW() WHERE id=:id');$mark->execute(['id'=>$r['id']]);registrarLog($r['perfil'],(int)$r['usuario_id'],'redefinir_senha','Senha redefinida por token');sucesso(null,'Senha redefinida com sucesso.');
