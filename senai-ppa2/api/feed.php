<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/functions.php';

exigirPerfil(['aluno','psicologo','pedagogo','diretoria']);
$pdo=getDB();
$perfil=perfilAtual(); $usuarioId=usuarioIdAtual();
$autorNome=nomeUsuarioPorAutor($perfil,$usuarioId) ?? 'Usuário';

function feedArquivoUpload(): ?string {
    if (empty($_FILES['arquivo']['name'])) return null;
    $max=20*1024*1024;
    if ($_FILES['arquivo']['error'] !== UPLOAD_ERR_OK) erro('Falha ao enviar o arquivo.',422);
    if ((int)$_FILES['arquivo']['size'] > $max) erro('O arquivo deve ter no máximo 20 MB.',422);
    $allowed=['pdf'=>'application/pdf','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','mp4'=>'video/mp4','mp3'=>'audio/mpeg'];
    $ext=strtolower(pathinfo($_FILES['arquivo']['name'],PATHINFO_EXTENSION));
    if (!isset($allowed[$ext])) erro('Formato de arquivo não permitido no feed.',422);
    $finfo=finfo_open(FILEINFO_MIME_TYPE); $mime=finfo_file($finfo,$_FILES['arquivo']['tmp_name']); finfo_close($finfo);
    if ($mime !== $allowed[$ext]) erro('O tipo real do arquivo não corresponde à extensão.',422);
    $dir=__DIR__.'/../uploads/feed';
    if (!is_dir($dir)) mkdir($dir,0750,true);
    $nome=bin2hex(random_bytes(16)).'.'.$ext;
    $dest=$dir.'/'.$nome;
    if (!move_uploaded_file($_FILES['arquivo']['tmp_name'],$dest)) erro('Não foi possível salvar o arquivo.',500);
    return 'uploads/feed/'.$nome;
}

if (metodo()==='GET') {
    $status=$_GET['status'] ?? 'aprovado';
    if (!in_array($status,['aprovado','pendente'],true)) $status='aprovado';
    if ($status==='pendente' && !in_array($perfil,['psicologo','pedagogo'],true)) erro('Acesso às publicações pendentes não autorizado.',403);
    $sql="SELECT f.*, u.nome AS unidade_nome FROM feed_posts f LEFT JOIN unidades u ON u.id=f.unidade_id WHERE f.status=:s";
    $params=['s'=>$status];
    if ($status==='pendente' && $perfil==='psicologo') { $sql.=' AND f.autor_tipo=:autor_tipo AND f.autor_id=:autor_id'; $params['autor_tipo']='psicologo'; $params['autor_id']=$usuarioId; }
    if ($perfil==='aluno') {
        $stmt=$pdo->prepare('SELECT unidade_id FROM alunos WHERE id=:id');$stmt->execute(['id'=>$usuarioId]);$u=$stmt->fetch();$unidadeId=$u['unidade_id']??null;
        $sql.=' AND (f.unidade_id IS NULL OR f.unidade_id=:unidade)';$params['unidade']=$unidadeId;
    }
    $sql.=' ORDER BY f.criado_em DESC LIMIT 100';
    $stmt=$pdo->prepare($sql);$stmt->execute($params);$posts=$stmt->fetchAll();
    foreach($posts as &$post){
        $post['autor_nome']=nomeUsuarioPorAutor($post['autor_tipo'],(int)$post['autor_id']) ?? 'Usuário';
        $r=$pdo->prepare('SELECT COUNT(*) FROM feed_reactions WHERE post_id=:id');$r->execute(['id'=>$post['id']]);$post['reacoes']=(int)$r->fetchColumn();
        $r=$pdo->prepare('SELECT tipo FROM feed_reactions WHERE post_id=:id AND autor_tipo=:t AND autor_id=:u LIMIT 1');$r->execute(['id'=>$post['id'],'t'=>$perfil,'u'=>$usuarioId]);$post['minha_reacao']=$r->fetchColumn() ?: null;
        $r=$pdo->prepare('SELECT c.id,c.texto,c.criado_em,c.autor_tipo,c.autor_id FROM feed_comments c WHERE c.post_id=:id ORDER BY c.criado_em ASC LIMIT 20');$r->execute(['id'=>$post['id']]);$post['comentarios']=$r->fetchAll();
        foreach($post['comentarios'] as &$c){$c['autor_nome']=nomeUsuarioPorAutor($c['autor_tipo'],(int)$c['autor_id']) ?? 'Usuário';}
    }
    unset($post);
    sucesso(['posts'=>$posts,'usuario'=>['perfil'=>$perfil,'id'=>$usuarioId,'nome'=>$autorNome]]);
}

if (metodo()==='POST' && isset($_GET['comentario'])) {
    exigirCsrf(); $b=corpoRequisicao(); $postId=(int)($b['post_id']??0); $texto=trim((string)($b['texto']??''));
    if(!$postId || $texto==='') erro('Informe a publicação e o comentário.',422);
    if(mb_strlen($texto)>1000) erro('O comentário pode ter até 1.000 caracteres.',422);
    $q=$pdo->prepare('SELECT id FROM feed_posts WHERE id=:id AND status="aprovado"');$q->execute(['id'=>$postId]);if(!$q->fetch()) erro('Publicação não encontrada.',404);
    $ins=$pdo->prepare('INSERT INTO feed_comments (post_id,autor_tipo,autor_id,texto) VALUES (:p,:t,:u,:x)');$ins->execute(['p'=>$postId,'t'=>$perfil,'u'=>$usuarioId,'x'=>$texto]); sucesso(['id'=>$pdo->lastInsertId()],'Comentário publicado.');
}

if (metodo()==='POST') {
    exigirCsrf();
    $isMultipart=!empty($_FILES) || isset($_POST['texto']);
    $b=$isMultipart ? $_POST : corpoRequisicao();
    $texto=trim((string)($b['texto']??'')); $titulo=trim((string)($b['titulo']??''));
    $categoria=trim((string)($b['categoria']??'')); $link=trim((string)($b['link_externo']??''));
    if ($texto==='' && $titulo==='') erro('Escreva um texto ou título para publicar.',422);
    if (mb_strlen($texto)>5000) erro('A publicação pode ter até 5.000 caracteres.',422);
    $unidadeId=null;
    if ($status==='pendente' && $perfil==='psicologo') { $sql.=' AND f.autor_tipo=:autor_tipo AND f.autor_id=:autor_id'; $params['autor_tipo']='psicologo'; $params['autor_id']=$usuarioId; }
    if ($perfil==='aluno') { $q=$pdo->prepare('SELECT unidade_id FROM alunos WHERE id=:id');$q->execute(['id'=>$usuarioId]);$unidadeId=$q->fetchColumn()?:null; }
    if ($perfil==='pedagogo') { $q=$pdo->prepare('SELECT unidade_id FROM pedagogos WHERE id=:id');$q->execute(['id'=>$usuarioId]);$unidadeId=$q->fetchColumn()?:null; }
    $arquivo=feedArquivoUpload();
    $status=$perfil==='psicologo'?'pendente':'aprovado';
    $aprovadoPor=$status==='aprovado' ? $autorNome : null;
    $aprovadoEm=$status==='aprovado' ? date('Y-m-d H:i:s') : null;
    $ins=$pdo->prepare('INSERT INTO feed_posts (autor_tipo,autor_id,titulo,texto,categoria,unidade_id,arquivo_path,link_externo,status,aprovado_por,aprovado_em) VALUES (:t,:a,:ti,:tx,:c,:u,:arq,:link,:st,:ap,:ae)');
    $ins->execute(['t'=>$perfil,'a'=>$usuarioId,'ti'=>$titulo?:null,'tx'=>$texto?:($titulo?:'Publicação'),'c'=>$categoria?:null,'u'=>$unidadeId,'arq'=>$arquivo,'link'=>$link?:null,'st'=>$status,'ap'=>$aprovadoPor,'ae'=>$aprovadoEm]);
    $id=$pdo->lastInsertId(); registrarLog($perfil,$usuarioId,'criar_publicacao','Feed #'.$id.' status '.$status);
    if($status==='pendente') sucesso(['id'=>$id],'Publicação enviada e aguardando aprovação institucional.');
    sucesso(['id'=>$id],'Publicação criada com sucesso.');
}

if (metodo()==='PUT') {
    exigirCsrf(); $b=corpoRequisicao(); $id=(int)($b['id']??0); $tipo=$b['tipo']??'';
    if(!$id || !in_array($tipo,['curtir','apoio','parabens','util'],true)) erro('Informe a publicação e o tipo de reação.',422);
    $check=$pdo->prepare('SELECT id FROM feed_posts WHERE id=:id AND status="aprovado"');$check->execute(['id'=>$id]);if(!$check->fetch()) erro('Publicação não encontrada.',404);
    $q=$pdo->prepare('SELECT id,tipo FROM feed_reactions WHERE post_id=:p AND autor_tipo=:t AND autor_id=:u LIMIT 1');$q->execute(['p'=>$id,'t'=>$perfil,'u'=>$usuarioId]);$old=$q->fetch();
    if($old){ if($old['tipo']===$tipo){$del=$pdo->prepare('DELETE FROM feed_reactions WHERE id=:id');$del->execute(['id'=>$old['id']]);$acao='reacao_removida';} else {$up=$pdo->prepare('UPDATE feed_reactions SET tipo=:tipo, criado_em=NOW() WHERE id=:id');$up->execute(['tipo'=>$tipo,'id'=>$old['id']]);$acao='reacao_alterada';} }
    else {$ins=$pdo->prepare('INSERT INTO feed_reactions (post_id,autor_tipo,autor_id,tipo) VALUES (:p,:t,:u,:tipo)');$ins->execute(['p'=>$id,'t'=>$perfil,'u'=>$usuarioId,'tipo'=>$tipo]);$acao='reacao_adicionada';}
    registrarLog($perfil,$usuarioId,$acao,'Feed #'.$id); sucesso(null,'Reação atualizada.');
}

if (metodo()==='DELETE') {
    exigirCsrf(); $id=(int)($_GET['id']??0); if(!$id) erro('Informe a publicação.',422);
    $q=$pdo->prepare('SELECT * FROM feed_posts WHERE id=:id LIMIT 1');$q->execute(['id'=>$id]);$post=$q->fetch();if(!$post) erro('Publicação não encontrada.',404);
    if ($post['autor_tipo']!==$perfil || (int)$post['autor_id']!==$usuarioId) erro('Você só pode excluir suas próprias publicações.',403);
    $del=$pdo->prepare('DELETE FROM feed_posts WHERE id=:id');$del->execute(['id'=>$id]);registrarLog($perfil,$usuarioId,'excluir_publicacao','Feed #'.$id);sucesso(null,'Publicação excluída.');
}

erro('Método não suportado.',405);
