<?php

/**
 * wc_backend/import_partidas.php
 *
 * Lê o arquivo partidas.json e popula a tabela wc_partidas.
 * Substitui todos os registros existentes a cada execução.
 *
 * Colunas:
 *   time_casa, time_fora, data_partida, gols_casa, gols_fora, encerrada
 *
 * Uso (navegador ou CLI):
 *   http://localhost/Painel/wc_backend/import_partidas.php
 *   php import_partidas.php
 */

declare(strict_types=1);

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
}

include __DIR__ . '/../assets/php/conexao.php';

if (!($conexao instanceof mysqli)) {
    responderErro(500, 'Conexão MySQL indisponível.', $isCli);
}

$conexao->set_charset('utf8mb4');

$jsonPath = resolverArquivoJson();

if ($jsonPath === null) {
    responderErro(400, 'Arquivo partidas.json não encontrado.', $isCli, [
        'candidatos' => [
            getenv('WC_PARTIDAS_JSON') ?: '(variável WC_PARTIDAS_JSON não definida)',
            'C:/Users/MatheusOliveiraPalmo/Projects/copa-2026-bet/src/data/partidas.json',
            __DIR__ . '/data/partidas.json',
        ],
    ]);
}

$conteudo = file_get_contents($jsonPath);

if ($conteudo === false) {
    responderErro(500, 'Não foi possível ler o arquivo JSON.', $isCli, ['arquivo' => $jsonPath]);
}

$partidas = json_decode($conteudo, true);

if (!is_array($partidas)) {
    responderErro(422, 'JSON inválido ou mal formatado.', $isCli, ['arquivo' => $jsonPath]);
}

$validadas = [];

foreach ($partidas as $indice => $partida) {
    if (!is_array($partida)) {
        responderErro(422, "Partida inválida na posição {$indice}.", $isCli);
    }

    $timeCasa = (int) ($partida['mandante']['id'] ?? 0);
    $timeFora = (int) ($partida['visitante']['id'] ?? 0);
    $data = trim((string) ($partida['data'] ?? ''));
    $horario = trim((string) ($partida['horario'] ?? ''));

    if ($timeCasa <= 0 || $timeFora <= 0 || $data === '' || $horario === '') {
        responderErro(422, "Partida incompleta na posição {$indice}.", $isCli, ['item' => $partida]);
    }

    $dataPartida = montarDateTime($data, $horario);

    if ($dataPartida === null) {
        responderErro(422, "Data ou horário inválidos na posição {$indice}.", $isCli, ['item' => $partida]);
    }

    $validadas[] = [
        'time_casa' => $timeCasa,
        'time_fora' => $timeFora,
        'data_partida' => $dataPartida,
    ];
}

$conexao->begin_transaction();

try {
    if (!$conexao->query('DELETE FROM wc_partidas')) {
        throw new RuntimeException('Erro ao limpar a tabela wc_partidas: ' . $conexao->error);
    }

    $stmt = $conexao->prepare(
        'INSERT INTO wc_partidas (time_casa, time_fora, data_partida, gols_casa, gols_fora, encerrada)
         VALUES (?, ?, ?, NULL, NULL, 0)'
    );

    if ($stmt === false) {
        throw new RuntimeException('Erro ao preparar INSERT: ' . $conexao->error);
    }

    foreach ($validadas as $partida) {
        $stmt->bind_param(
            'iis',
            $partida['time_casa'],
            $partida['time_fora'],
            $partida['data_partida']
        );

        if (!$stmt->execute()) {
            throw new RuntimeException(
                "Erro ao inserir partida {$partida['time_casa']} x {$partida['time_fora']}: " . $stmt->error
            );
        }
    }

    $stmt->close();
    $conexao->commit();
    $conexao->close();

    responderSucesso([
        'STATUS' => 'SUCCESS',
        'message' => 'Partidas importadas com sucesso.',
        'arquivo' => $jsonPath,
        'total' => count($validadas),
    ], $isCli);
} catch (Throwable $e) {
    $conexao->rollback();
    $conexao->close();
    responderErro(500, $e->getMessage(), $isCli);
}

function montarDateTime(string $data, string $horario): ?string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
        return null;
    }

    if (!preg_match('/^\d{2}:\d{2}$/', $horario)) {
        return null;
    }

    $dateTime = DateTime::createFromFormat('Y-m-d H:i', "{$data} {$horario}");

    if ($dateTime === false) {
        return null;
    }

    return $dateTime->format('Y-m-d H:i:s');
}

function resolverArquivoJson(): ?string
{
    $candidatos = array_filter([
        getenv('WC_PARTIDAS_JSON') ?: null,
        'C:/Users/MatheusOliveiraPalmo/Projects/copa-2026-bet/src/data/partidas.json',
        __DIR__ . '/data/partidas.json',
    ]);

    foreach ($candidatos as $path) {
        if (is_file($path)) {
            return $path;
        }
    }

    return null;
}

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
