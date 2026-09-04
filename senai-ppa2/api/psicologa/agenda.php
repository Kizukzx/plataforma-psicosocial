<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/functions.php';

exigirPerfil(['psicologo']);
$psicologoId=usuarioIdAtual();
$pdo=getDB();

if(metodo()==='GET'){
  $data=$_GET['data']??date('Y-m-d');
  if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$data)) erro('Data inválida.',422);
  $stmt=$pdo->prepare("SELECT a.id,DATE_FORMAT(a.data_hora,'%Y-%m-%d') data,TIME_FORMAT(a.data_hora,'%H:%i') hora,a.modalidade,a.status,a.sinalizacao,a.prioritario,al.nome aluno_nome,al.ra,u.nome unidade_nome FROM atendimentos a JOIN alunos al ON al.id=a.aluno_id JOIN unidades u ON u.id=al.unidade_id WHERE a.psicologo_id=:p AND DATE(a.data_hora)=:d ORDER BY a.data_hora");
  $stmt->execute(['p'=>$psicologoId,'d'=>$data]);
  $b=$pdo->prepare('SELECT id,DATE_FORMAT(data,\'%Y-%m-%d\') data,TIME_FORMAT(hora_inicio,\'%H:%i\') hora_inicio,TIME_FORMAT(hora_fim,\'%H:%i\') hora_fim,motivo FROM agenda_bloqueios WHERE psicologo_id=:p AND data=:d ORDER BY hora_inicio');
  $b->execute(['p'=>$psicologoId,'d'=>$data]);
  sucesso(['data'=>$data,'horarios_disponiveis'=>horariosDisponiveis($psicologoId,$data),'atendimentos_do_dia'=>$stmt->fetchAll(),'bloqueios'=>$b->fetchAll()]);
}
if(metodo()==='POST'){
  exigirCsrf();
  $b=corpoRequisicao();
  $data=trim((string)($b['data']??''));$hi=substr(trim((string)($b['hora_inicio']??'')),0,5);$hf=substr(trim((string)($b['hora_fim']??'')),0,5);
  if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$data)||!preg_match('/^\d{2}:\d{2}$/',$hi)||!preg_match('/^\d{2}:\d{2}$/',$hf)) erro('Data/horário inválidos.',422);
  $ti=strtotime($data.' '.$hi);$tf=strtotime($data.' '.$hf);$t9=strtotime($data.' 09:00');$t17=strtotime($data.' 17:00');
  if($ti<$t9||$tf>$t17||$tf<=$ti||(($tf-$ti)%1800)!==0||(($ti-$t9)%1800)!==0) erro('Bloqueios devem estar entre 09:00 e 17:00 em blocos de 30 minutos.',422);
  $over=$pdo->prepare('SELECT id FROM agenda_bloqueios WHERE psicologo_id=:p AND data=:d AND hora_inicio<:hf AND hora_fim>:hi LIMIT 1');$over->execute(['p'=>$psicologoId,'d'=>$data,'hi'=>$hi.':00','hf'=>$hf.':00']);if($over->fetch()) erro('Já existe bloqueio sobreposto.',409);
  $occ=$pdo->prepare("SELECT id FROM atendimentos WHERE psicologo_id=:p AND DATE(data_hora)=:d AND TIME(data_hora)>=:hi AND TIME(data_hora)<:hf AND status NOT IN ('cancelado') LIMIT 1");$occ->execute(['p'=>$psicologoId,'d'=>$data,'hi'=>$hi.':00','hf'=>$hf.':00']);if($occ->fetch()) erro('Não é possível bloquear um período que possui atendimento reservado.',409);
  $ins=$pdo->prepare('INSERT INTO agenda_bloqueios(psicologo_id,data,hora_inicio,hora_fim,motivo) VALUES(:p,:d,:hi,:hf,:m)');$ins->execute(['p'=>$psicologoId,'d'=>$data,'hi'=>$hi.':00','hf'=>$hf.':00','m'=>trim((string)($b['motivo']??''))?:null]);
  registrarLog('psicologo',$psicologoId,'bloquear_agenda',"{$data} {$hi}-{$hf}");sucesso(['id'=>(int)$pdo->lastInsertId()],'Horário bloqueado com sucesso.');
}
if(metodo()==='DELETE'){
  exigirCsrf();$id=(int)($_GET['id']??0);if(!$id) erro('Informe o id do bloqueio.',422);
  $stmt=$pdo->prepare('DELETE FROM agenda_bloqueios WHERE id=:id AND psicologo_id=:p');$stmt->execute(['id'=>$id,'p'=>$psicologoId]);if(!$stmt->rowCount()) erro('Bloqueio não encontrado.',404);registrarLog('psicologo',$psicologoId,'desbloquear_agenda',"Bloqueio #{$id}");sucesso(null,'Horário desbloqueado.');
}
erro('Método não suportado.',405);
