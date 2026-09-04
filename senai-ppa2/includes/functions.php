<?php
/**
 * Regras de negócio compartilhadas entre os endpoints da API.
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Verifica se o aluno teve 2 ausências consecutivas e, em caso positivo,
 * gera um alerta para a Unidade SENAI responsável (item 3.2.c.i do documento).
 */
function verificarFaltasConsecutivas(int $alunoId): void
{
    $pdo = getDB();
    $stmt = $pdo->prepare(
        'SELECT status FROM atendimentos WHERE aluno_id = :aluno ORDER BY data_hora DESC LIMIT 2'
    );
    $stmt->execute(['aluno' => $alunoId]);
    $ultimos = $stmt->fetchAll();

    if (count($ultimos) === 2 && $ultimos[0]['status'] === 'ausencia' && $ultimos[1]['status'] === 'ausencia') {
        $aluno = buscarAluno($alunoId);
        if (!$aluno) {
            return;
        }
        // Evita duplicar alerta se já existir um não lido para o mesmo aluno.
        $check = $pdo->prepare(
            "SELECT id FROM alertas WHERE tipo = 'duas_faltas' AND aluno_id = :aluno AND lido = 0"
        );
        $check->execute(['aluno' => $alunoId]);
        if ($check->fetch()) {
            return;
        }
        $ins = $pdo->prepare(
            'INSERT INTO alertas (tipo, aluno_id, unidade_id, mensagem)
             VALUES ("duas_faltas", :aluno, :unidade, :msg)'
        );
        $ins->execute([
            'aluno'   => $alunoId,
            'unidade' => $aluno['unidade_id'],
            'msg'     => "{$aluno['nome']} (RA {$aluno['ra']}) apresenta 2 faltas consecutivas.",
        ]);
    }
}

function buscarAluno(int $id): ?array
{
    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT * FROM alunos WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $r = $stmt->fetch();
    return $r ?: null;
}

/** Calcula a sinalização (verde/amarelo/vermelho) a partir do status do atendimento. */
function sinalizacaoPorStatus(string $status, bool $prioritario = false): string
{
    if ($status === 'ausencia' || $prioritario) {
        return 'vermelho';
    }
    if ($status === 'confirmado' || $status === 'finalizado') {
        return 'verde';
    }
    return 'amarelo'; // pendente, cancelado (reagendamento)
}

/**
 * Retorna os horários (09h-17h, blocos de 30min) disponíveis para uma
 * psicóloga em uma data específica, descontando bloqueios manuais e
 * atendimentos já marcados.
 */
function horariosDisponiveis(int $psicologoId, string $data): array
{
    $pdo = getDB();

    $todos = [];
    $inicio = strtotime("$data 09:00");
    $fim = strtotime("$data 17:00");
    for ($t = $inicio; $t < $fim; $t += 1800) {
        if ($data === date('Y-m-d') && $t <= time()) continue;
        $todos[] = date('H:i', $t);
    }

    $ocupados = [];

    $stmtA = $pdo->prepare(
        "SELECT TIME_FORMAT(data_hora, '%H:%i') AS hora FROM atendimentos
         WHERE psicologo_id = :p AND DATE(data_hora) = :d AND status NOT IN ('cancelado')"
    );
    $stmtA->execute(['p' => $psicologoId, 'd' => $data]);
    foreach ($stmtA->fetchAll() as $row) {
        $ocupados[] = $row['hora'];
    }

    $stmtB = $pdo->prepare(
        'SELECT hora_inicio, hora_fim FROM agenda_bloqueios WHERE psicologo_id = :p AND data = :d'
    );
    $stmtB->execute(['p' => $psicologoId, 'd' => $data]);
    $bloqueios = $stmtB->fetchAll();

    $disponiveis = [];
    foreach ($todos as $hora) {
        if (in_array($hora, $ocupados, true)) {
            continue;
        }
        $bloqueado = false;
        foreach ($bloqueios as $b) {
            if ($hora >= substr($b['hora_inicio'], 0, 5) && $hora < substr($b['hora_fim'], 0, 5)) {
                $bloqueado = true;
                break;
            }
        }
        if (!$bloqueado) {
            $disponiveis[] = $hora;
        }
    }

    return $disponiveis;
}

/** Sanitiza uma string simples (nomes, textos curtos). */
function limpar(?string $v): string
{
    return trim(htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'));
}
