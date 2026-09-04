<?php
require_once __DIR__.'/../../includes/auth.php';require_once __DIR__.'/../../includes/response.php';
exigirPerfil(['pedagogo']);exigirMetodo('POST');exigirCsrf();$pdo=getDB();$pedId=usuarioIdAtual();
if(empty($_FILES['arquivo'])||$_FILES['arquivo']['error']!==UPLOAD_ERR_OK)erro('Envie uma planilha CSV ou XLSX no campo arquivo.',422);
$file=$_FILES['arquivo'];if($file['size']>10*1024*1024)erro('Arquivo excede o limite de 10 MB.',422);
$ext=strtolower(pathinfo($file['name'],PATHINFO_EXTENSION));if(!in_array($ext,['csv','xlsx'],true))erro('Formato não suportado. Use CSV ou XLSX.',422);
$dest=__DIR__.'/../../uploads/planilhas/'.uniqid('import_',true).'.'.$ext;if(!move_uploaded_file($file['tmp_name'],$dest))erro('Não foi possível armazenar a planilha.',500);

function normalizeHeader($v){$v=trim((string)$v);$v=mb_strtolower($v);$v=str_replace(['á','à','ã','â','ä','é','è','ê','ë','í','ì','î','ï','ó','ò','õ','ô','ö','ú','ù','û','ü','ç'],['a','a','a','a','a','e','e','e','e','i','i','i','i','o','o','o','o','o','u','u','u','u','c'],$v);return preg_replace('/\s+/','_', $v);}
function readRows($path,$ext){
  if($ext==='csv'){
    $h=fopen($path,'r');if(!$h)throw new RuntimeException('Falha ao abrir CSV.');$rows=[];while(($r=fgetcsv($h,0,','))!==false){$rows[]=$r;}fclose($h);return $rows;
  }
  if(!class_exists('ZipArchive')||!function_exists('simplexml_load_string'))throw new RuntimeException('Para importar XLSX, habilite as extensões PHP zip e SimpleXML no servidor.');
  $zip=new ZipArchive();if($zip->open($path)!==true)throw new RuntimeException('XLSX inválido.');
  $shared=[];$sx=$zip->getFromName('xl/sharedStrings.xml');if($sx){$xml=simplexml_load_string($sx);foreach($xml->si as $si){$txt='';foreach($si->t as $t)$txt.=(string)$t;foreach($si->r as $r)$txt.=(string)$r->t;$shared[]=$txt;}}
  $sheet=$zip->getFromName('xl/worksheets/sheet1.xml');if(!$sheet)throw new RuntimeException('Planilha XLSX sem a primeira aba.');$xml=simplexml_load_string($sheet);$rows=[];
  foreach($xml->sheetData->row as $row){$out=[];$last=0;foreach($row->c as $c){$ref=(string)$c['r'];preg_match('/([A-Z]+)/',$ref,$m);$letters=$m[1]??'A';$col=0;for($i=0;$i<strlen($letters);$i++)$col=$col*26+(ord($letters[$i])-64);$col--;while(count($out)<$col)$out[]='';$v=(string)$c->v;if((string)$c['t']==='s')$v=$shared[(int)$v]??'';$out[$col]=$v;$last=max($last,$col);}$rows[]=$out;}
  $zip->close();return $rows;
}

$ped=$pdo->prepare('SELECT unidade_id FROM pedagogos WHERE id=:id');$ped->execute(['id'=>$pedId]);$pedUnidade=(int)$ped->fetchColumn();
$unidades=[];$q=$pdo->query('SELECT id,nome FROM unidades');foreach($q->fetchAll() as $u)$unidades[normalizeHeader($u['nome'])]=$u['id'];
try{$rows=readRows($dest,$ext);}catch(Throwable $e){erro($e->getMessage(),422);}
if(count($rows)<2)erro('A planilha precisa conter cabeçalho e pelo menos um aluno.',422);
$headers=array_map('normalizeHeader',$rows[0]);$expected=['ra','nome','unidade','curso','status_matricula'];if(array_slice($headers,0,5)!==$expected)erro('Cabeçalho inválido. Use: RA, Nome, Unidade, Curso, Status matrícula.',422);
$validStatus=['ativo','inativo','desligado'];$seen=[];$total=0;$ok=0;$errors=[];$pdo->beginTransaction();
try{
 foreach(array_slice($rows,1) as $idx=>$r){if(count($r)<5||trim((string)$r[0])==='')continue;$linha=$idx+2;$total++;$ra=trim((string)$r[0]);$nome=trim((string)$r[1]);$uni=normalizeHeader($r[2]);$curso=trim((string)$r[3]);$status=mb_strtolower(trim((string)$r[4]));
  if(isset($seen[$ra])){$errors[]="Linha {$linha}: RA duplicado na própria planilha ({$ra}).";continue;}$seen[$ra]=true;
  $uid=$unidades[$uni]??0;if(!$uid){$errors[]="Linha {$linha}: unidade inexistente ({$r[2]}).";continue;}if($uid!==$pedUnidade){$errors[]="Linha {$linha}: a planilha contém a unidade {$r[2]}, mas sua conta pedagógica está vinculada a outra unidade.";continue;}
  if($nome===''||$curso===''){$errors[]="Linha {$linha}: nome e curso são obrigatórios.";continue;}if(!in_array($status,$validStatus,true)){$errors[]="Linha {$linha}: status inválido ({$status}).";continue;}
  $chk=$pdo->prepare('SELECT id FROM alunos WHERE ra=:ra');$chk->execute(['ra'=>$ra]);$id=$chk->fetchColumn();
  if($id){$up=$pdo->prepare('UPDATE alunos SET nome=:n,unidade_id=:u,curso=:c,status_matricula=:s,importado_em=NOW() WHERE id=:id');$up->execute(['n'=>$nome,'u'=>$uid,'c'=>$curso,'s'=>$status,'id'=>$id]);}
  else {$in=$pdo->prepare('INSERT INTO alunos(ra,nome,unidade_id,curso,status_matricula,senha_hash,senha_trocada,importado_em) VALUES(:ra,:n,:u,:c,:s,:h,0,NOW())');$in->execute(['ra'=>$ra,'n'=>$nome,'u'=>$uid,'c'=>$curso,'s'=>$status,'h'=>password_hash('senha@123',PASSWORD_DEFAULT)]);}
  $ok++;
 }
 $log=$pdo->prepare('INSERT INTO importacoes_planilha(pedagogo_id,unidade_id,nome_arquivo,total_linhas,total_sucesso,total_erros,erros_json) VALUES(:p,:u,:n,:t,:s,:e,:j)');$log->execute(['p'=>$pedId,'u'=>$pedUnidade,'n'=>$file['name'],'t'=>$total,'s'=>$ok,'e'=>count($errors),'j'=>json_encode($errors,JSON_UNESCAPED_UNICODE)]);$pdo->commit();
 registrarLog('pedagogo',$pedId,'importar_alunos',"{$ok}/{$total} registros processados");sucesso(['total_linhas'=>$total,'importados_com_sucesso'=>$ok,'total_erros'=>count($errors),'erros'=>$errors],'Importação concluída.');
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();erro('Erro ao processar a planilha: '.$e->getMessage(),500);}
