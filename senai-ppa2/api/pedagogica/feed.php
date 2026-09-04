<?php
require_once __DIR__.'/../../includes/auth.php'; require_once __DIR__.'/../../includes/response.php';
exigirPerfil(['pedagogo']);
$pdo=getDB();
if(metodo()==='GET'){ $q=$pdo->query("SELECT f.*, u.nome unidade_nome FROM feed_posts f LEFT JOIN unidades u ON u.id=f.unidade_id WHERE f.status='pendente' ORDER BY f.criado_em ASC"); $rows=$q->fetchAll(); foreach($rows as &$r){$r['autor_nome']=nomeUsuarioPorAutor($r['autor_tipo'],(int)$r['autor_id']);} unset($r); sucesso($rows); }
if(metodo()==='PUT'){ exigirCsrf(); $b=corpoRequisicao();$id=(int)($b['id']??0);$aprovar=!empty($b['aprovar']); if(!$id) erro('Informe id.',422); $st=$aprovar?'aprovado':'recusado';$u=$pdo->prepare('UPDATE feed_posts SET status=:s, aprovado_por=:p, aprovado_em=NOW() WHERE id=:id AND status=\'pendente\'');$u->execute(['s'=>$st,'p'=>'atendimentopsicossocial@sistemafiepe.org.br','id'=>$id]); if(!$u->rowCount()) erro('Publicação pendente não encontrada.',404); sucesso(null,'Publicação '.$st.'.'); }
erro('Método não suportado.',405);
