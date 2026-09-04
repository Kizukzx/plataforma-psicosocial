<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/response.php';
exigirMetodo('POST');
$body=corpoRequisicao();
$perfil=strtolower(trim((string)($body['perfil'] ?? '')));
$identificador=trim((string)($body['identificador'] ?? ''));
$permitidos=['aluno','psicologo','pedagogo','diretoria'];
if (!in_array($perfil,$permitidos,true) || $identificador==='') sucesso(null,'Se o usuário existir, as instruções de recuperação estarão disponíveis.');
$map=['aluno'=>['alunos','ra','nome',null],'psicologo'=>['psicologos','email','nome','ativo'],'pedagogo'=>['pedagogos','email','nome','ativo'],'diretoria'=>['diretoria','email','nome','ativo']];
[$tabela,$campo,$nomeCampo,$ativoCampo]=$map[$perfil];
$fields = "id,{$nomeCampo} AS nome";
if ($perfil === 'aluno') $fields .= ',status_matricula';
if ($ativoCampo) $fields .= ",{$ativoCampo} AS ativo";
$stmt=getDB()->prepare("SELECT {$fields} FROM {$tabela} WHERE {$campo}=:v LIMIT 1");
$stmt->execute(['v'=>$perfil==='aluno'?$identificador:mb_strtolower($identificador)]);
$u=$stmt->fetch();
if (!$u || ($ativoCampo && (int)$u['ativo']===0) || ($perfil==='aluno' && ($u['status_matricula'] ?? '') !== 'ativo')) sucesso(null,'Se o usuário existir, as instruções de recuperação estarão disponíveis.');
$token=bin2hex(random_bytes(32));$hash=hash('sha256',$token);
$pdo=getDB();
$ins=$pdo->prepare('INSERT INTO password_resets (perfil,usuario_id,token_hash,expira_em) VALUES (:p,:u,:h,DATE_ADD(NOW(),INTERVAL 30 MINUTE))');
$ins->execute(['p'=>$perfil,'u'=>$u['id'],'h'=>$hash]);
$base=isset($_SERVER['HTTP_ORIGIN'])?rtrim($_SERVER['HTTP_ORIGIN'],'/'):(isset($_SERVER['HTTP_HOST'])?'http://'.$_SERVER['HTTP_HOST']:'');
$link=$base.'/public/index.html?reset='.urlencode($token);
$out=null;
if($perfil==='aluno'){
  $out=['instrucao'=>'Em ambiente institucional, o fluxo deve enviar o link/código ao canal definido pela organização.','token_debug'=>$token,'link_debug'=>$link];
}else{
  $email=$identificador;$subject='PPA SENAI/PE — Recuperação de senha';$message="Olá {$u['nome']},\n\nUse este link para redefinir sua senha (válido por 30 minutos):\n{$link}\n";$headers='From: atendimentopsicossocial@sistemafiepe.org.br';@mail($email,$subject,$message,$headers);
  if(in_array(getenv('APP_ENV')?:'development',['development','local'],true)) $out=['token_debug'=>$token,'link_debug'=>$link];
}
sucesso($out,'Se o usuário existir, a recuperação foi preparada com segurança.');
