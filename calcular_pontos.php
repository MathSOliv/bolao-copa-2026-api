<?php

/**
 * wc_backend/calcular_pontos.php
 *
 * Processa partidas encerradas que ainda não tiveram pontos calculados
 * (encerrada = 1, status = 0) e atualiza status + pontos em wc_palpites.
 *
 * A lógica de processamento vive em pontuacao_core.php (wcProcessarPontuacao),
 * reaproveitada também pelo painel administrativo (admin_partidas.php).
 *
 * Regras:
 *   PLACAR EXATO  → 15 pts
 *   VENCEDOR      →  5 pts
 *   EMPATE        →  5 pts
 *   ERRADO        →  0 pts
 *
 * Uso (navegador ou CLI):
 *   http://localhost/Painel/wc_backend/calcular_pontos.php
 *   php calcular_pontos.php
 */

declare(strict_types=1);

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
}

require_once __DIR__ . '/pontuacao_core.php';

include __DIR__ . '/../assets/php/conexao.php';

if (!($conexao instanceof mysqli)) {
    responderErro(500, 'Conexão MySQL indisponível.', $isCli);
}

$conexao->set_charset('utf8mb4');

try {
    $resultado = wcProcessarPontuacao($conexao);
} catch (Throwable $e) {
    $conexao->close();
    responderErro(500, $e->getMessage(), $isCli);
}

$conexao->close();

$mensagem = $resultado['partidas_processadas'] === 0
    ? 'Nenhuma partida pendente de pontuação.'
    : 'Pontuação processada com sucesso.';

responderSucesso([
    'STATUS' => 'SUCCESS',
    'message' => $mensagem,
    'partidas_processadas' => $resultado['partidas_processadas'],
    'palpites_atualizados' => $resultado['palpites_atualizados'],
    'detalhes' => $resultado['detalhes'],
], $isCli);

function responderSucesso(array $payload, bool $isCli): void
{
    if ($isCli) {
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
        exit(0);
    }

    http_response_code(200);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function responderErro(int $status, string $message, bool $isCli, array $extra = []): void
{
    $payload = array_merge([
        'STATUS' => 'ERROR',
        'error' => $message,
    ], $extra);

    if ($isCli) {
        fwrite(STDERR, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL);
        exit(1);
    }

    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
