<?php
require_once __DIR__.'/../../includes/auth.php';require_once __DIR__.'/../../includes/response.php';
exigirPerfil(['aluno']);$pdo=getDB();$id=usuarioIdAtual();
if(metodo()==='GET'){ $s=$pdo->prepare('SELECT id,nivel,texto,criado_em FROM bemestar_registros WHERE aluno_id=:a ORDER BY criado_em DESC LIMIT 30');$s->execute(['a'=>$id]);sucesso($s->fetchAll()); }
if(metodo()==='POST'){ exigirCsrf();$b=corpoRequisicao();$nivel=(string)($b['nivel']??'');$map=['muito_bem','bem','mais_ou_menos','ansioso','muito_mal'];if(!in_array($nivel,$map,true))erro('Nível emocional inválido.',422);$s=$pdo->prepare('INSERT INTO bemestar_registros(aluno_id,nivel,texto) VALUES(:a,:n,:t)');$s->execute(['a'=>$id,'n'=>$nivel,'t'=>trim((string)($b['texto']??''))?:null]);registrarLog('aluno',$id,'registrar_bem_estar','Registro emocional');sucesso(['id'=>(int)$pdo->lastInsertId()],'Registro salvo com segurança.'); }
erro('Método não suportado.',405);
