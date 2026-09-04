<?php
/**
 * Padroniza as respostas JSON de toda a API.
 */

function jsonResponse(bool $sucesso, $dados = null, string $mensagem = '', int $httpCode = 200): void
{
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $payload = ['sucesso' => $sucesso];
    if ($mensagem !== '') {
        $payload['mensagem'] = $mensagem;
    }
    if ($dados !== null) {
        $payload['dados'] = $dados;
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function erro(string $mensagem, int $httpCode = 400): void
{
    jsonResponse(false, null, $mensagem, $httpCode);
}

function sucesso($dados = null, string $mensagem = ''): void
{
    jsonResponse(true, $dados, $mensagem, 200);
}

/** Lê o corpo JSON da requisição com segurança. */
function corpoRequisicao(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function metodo(): string
{
    return $_SERVER['REQUEST_METHOD'] ?? 'GET';
}

function exigirMetodo(string $esperado): void
{
    if (metodo() !== $esperado) {
        erro('Método não permitido. Use ' . $esperado . '.', 405);
    }
}
