<?php
require_once __DIR__ . '/../includes/auth.php'; require_once __DIR__ . '/../includes/response.php';
exigirPerfil(['psicologo','pedagogo','aluno','diretoria']);
$pdo=getDB();$perfil=perfilAtual();$id=usuarioIdAtual();
function alertScopeSql(string $perfil): array {
  return match($perfil){
    'psicologo'=>['psicologo_id = :uid', ['uid'=>$GLOBALS['id']]],
    'pedagogo'=>['unidade_id = (SELECT unidade_id FROM pedagogos WHERE id = :uid)', ['uid'=>$GLOBALS['id']]],
    'aluno'=>['aluno_id = :uid', ['uid'=>$GLOBALS['id']]],
    'diretoria'=>['1=0', []],
  };
}
if(metodo()==='GET'){
  $lido=isset($_GET['lido'])?(int)$_GET['lido']:0;[$where,$params]=alertScopeSql($perfil);$stmt=$pdo->prepare("SELECT * FROM alertas WHERE {$where} AND lido=:l ORDER BY criado_em DESC LIMIT 100");$params['l']=$lido;$stmt->execute($params);sucesso($stmt->fetchAll());
}
if(metodo()==='PUT'){
  exigirCsrf();$b=corpoRequisicao();$aid=(int)($b['id']??0);if(!$aid)erro('Informe o id do alerta.',422);[$where,$params]=alertScopeSql($perfil);$params['id']=$aid;$stmt=$pdo->prepare("UPDATE alertas SET lido=1 WHERE id=:id AND {$where}");$stmt->execute($params);if(!$stmt->rowCount())erro('Alerta não encontrado.',404);sucesso(null,'Notificação marcada como lida.');
}
erro('Método não suportado.',405);
