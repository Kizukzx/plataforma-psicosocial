<?php
/**
 * Autenticação e controle de acesso por perfil.
 *
 * A plataforma tem 4 perfis, cada um com sua própria tabela no banco:
 *   - aluno      -> tabela `alunos`      (login: RA)
 *   - psicologo  -> tabela `psicologos`  (login: e-mail institucional)
 *   - pedagogo   -> tabela `pedagogos`   (login: e-mail institucional)
 *   - diretoria  -> tabela `diretoria`   (login: e-mail institucional)
 *
 * A sessão guarda o tipo de usuário + id, e cada endpoint da API declara
 * quais perfis podem acessá-lo com exigirPerfil().
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/response.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'samesite' => 'Lax',
    ]);
    session_start();
}

const PERFIS_VALIDOS = ['aluno', 'psicologo', 'pedagogo', 'diretoria'];

/**
 * Tenta autenticar um usuário em um dos 4 grupos.
 * $identificador = RA (aluno) ou e-mail institucional (demais perfis).
 */
function autenticar(string $perfil, string $identificador, string $senha): ?array
{
    if (!in_array($perfil, PERFIS_VALIDOS, true)) {
        return null;
    }

    $pdo = getDB();
    $identificador = trim($identificador);
    if ($perfil !== 'aluno') {
        $identificador = mb_strtolower($identificador);
    }
    $tabela = [
        'aluno'     => 'alunos',
        'psicologo' => 'psicologos',
        'pedagogo'  => 'pedagogos',
        'diretoria' => 'diretoria',
    ][$perfil];

    $campoLogin = $perfil === 'aluno' ? 'ra' : 'email';

    $stmt = $pdo->prepare("SELECT * FROM {$tabela} WHERE {$campoLogin} = :id LIMIT 1");
    $stmt->execute(['id' => $identificador]);
    $usuario = $stmt->fetch();

    if (!$usuario) {
        return null;
    }
    if ($perfil !== 'aluno' && (int)($usuario['ativo'] ?? 1) === 0) {
        return null;
    }
    if ($perfil === 'aluno' && $usuario['status_matricula'] !== 'ativo') {
        return null;
    }
    if (!password_verify($senha, $usuario['senha_hash'])) {
        return null;
    }

    unset($usuario['senha_hash']);
    return $usuario;
}

function iniciarSessao(string $perfil, array $usuario): void
{
    session_regenerate_id(true);
    $_SESSION['perfil'] = $perfil;
    $_SESSION['usuario_id'] = (int)$usuario['id'];
    $_SESSION['nome'] = $usuario['nome'];
    $_SESSION['unidade_id'] = $usuario['unidade_id'] ?? null;
    registrarLog($perfil, (int)$usuario['id'], 'login', 'Login realizado com sucesso');
}

function usuarioLogado(): bool
{
    return isset($_SESSION['perfil'], $_SESSION['usuario_id']);
}

function perfilAtual(): ?string
{
    return $_SESSION['perfil'] ?? null;
}

function usuarioIdAtual(): ?int
{
    return $_SESSION['usuario_id'] ?? null;
}

/** Interrompe a execução se o usuário não estiver logado com um dos perfis permitidos. */
function exigirPerfil(array $perfisPermitidos): void
{
    if (!usuarioLogado()) {
        erro('Não autenticado. Faça login novamente.', 401);
    }
    if (!in_array(perfilAtual(), $perfisPermitidos, true)) {
        erro('Acesso não autorizado para este perfil.', 403);
    }
}


function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function exigirCsrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals(csrfToken(), $token)) {
        erro('Token de segurança inválido ou ausente.', 419);
    }
}

function tipoAutor(): string
{
    return perfilAtual() ?? '';
}

function nomeUsuarioPorAutor(string $tipo, int $id): ?string
{
    $map = [
        'aluno' => ['alunos', 'nome'],
        'psicologo' => ['psicologos', 'nome'],
        'pedagogo' => ['pedagogos', 'nome'],
        'diretoria' => ['diretoria', 'nome'],
    ];
    if (!isset($map[$tipo])) return null;
    [$tabela, $campo] = $map[$tipo];
    $stmt = getDB()->prepare("SELECT {$campo} FROM {$tabela} WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $id]);
    $nome = $stmt->fetchColumn();
    return $nome !== false ? $nome : null;
}

function registrarLog(string $perfil, int $usuarioId, string $acao, string $detalhes = ''): void
{
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare(
            'INSERT INTO logs_acesso (usuario_tipo, usuario_id, acao, detalhes, ip) VALUES (:tipo, :id, :acao, :det, :ip)'
        );
        $stmt->execute([
            'tipo' => $perfil,
            'id'   => $usuarioId,
            'acao' => $acao,
            'det'  => $detalhes,
            'ip'   => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
        // Log nunca deve derrubar a requisição principal.
    }
}

/**
 * Retorna a(s) unidade(s) que a psicóloga logada atende.
 * Usado para restringir consultas de alunos/atendimentos por unidade.
 */
function unidadesDaPsicologa(int $psicologoId): array
{
    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT unidade_id FROM psicologo_unidades WHERE psicologo_id = :id');
    $stmt->execute(['id' => $psicologoId]);
    return array_column($stmt->fetchAll(), 'unidade_id');
}
