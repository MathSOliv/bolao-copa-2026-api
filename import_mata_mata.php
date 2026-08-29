<?php

/**
 * wc_backend/import_mata_mata.php
 *
 * Lê data/partidas_mata_mata.json e cadastra/atualiza as partidas do mata-mata
 * em wc_partidas. As partidas são identificadas pelo número do jogo (id), de
 * modo que as referências de origem (origem_casa/origem_fora) apontem para os
 * ids corretos.
 *
 * Importação NÃO destrutiva: ao reexecutar, apenas a estrutura do chaveamento
 * (datas, fase, ordem, origens e rótulos) é atualizada. Seleções definidas,
 * placares, classificados e palpites já lançados são preservados.
 *
 * Uso (navegador ou CLI):
 *   http://localhost/Painel/wc_backend/import_mata_mata.php
 *   php import_mata_mata.php
 */

declare(strict_types=1);

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
}

include __DIR__ . '/../assets/php/conexao.php';

if (!($conexao instanceof mysqli)) {
    mmResponderErro(500, 'Conexão MySQL indisponível.', $isCli);
}

$conexao->set_charset('utf8mb4');

$jsonPath = __DIR__ . '/data/partidas_mata_mata.json';

if (!is_file($jsonPath)) {
    mmResponderErro(400, 'Arquivo partidas_mata_mata.json não encontrado.', $isCli, ['arquivo' => $jsonPath]);
}

$conteudo = file_get_contents($jsonPath);

if ($conteudo === false) {
    mmResponderErro(500, 'Não foi possível ler o arquivo JSON.', $isCli, ['arquivo' => $jsonPath]);
}

$partidas = json_decode($conteudo, true);

if (!is_array($partidas)) {
    mmResponderErro(422, 'JSON inválido ou mal formatado.', $isCli, ['arquivo' => $jsonPath]);
}

$validadas = [];

foreach ($partidas as $indice => $partida) {
    if (!is_array($partida)) {
        mmResponderErro(422, "Partida inválida na posição {$indice}.", $isCli);
    }

    $jogo = (int) ($partida['jogo'] ?? 0);
    $fase = trim((string) ($partida['fase'] ?? ''));
    $ordem = (int) ($partida['ordem'] ?? 0);
    $data = trim((string) ($partida['data'] ?? ''));
    $horario = trim((string) ($partida['horario'] ?? ''));

    if ($jogo <= 0 || $fase === '' || $data === '' || $horario === '') {
        mmResponderErro(422, "Partida incompleta na posição {$indice}.", $isCli, ['item' => $partida]);
    }

    $dataPartida = mmMontarDateTime($data, $horario);

    if ($dataPartida === null) {
        mmResponderErro(422, "Data ou horário inválidos na posição {$indice}.", $isCli, ['item' => $partida]);
    }

    $validadas[] = [
        'id' => $jogo,
        'data_partida' => $dataPartida,
        'fase' => $fase,
        'ordem' => $ordem,
        'origem_casa_partida' => isset($partida['origem_casa']) ? (int) $partida['origem_casa'] : null,
        'origem_casa_tipo' => trim((string) ($partida['origem_casa_tipo'] ?? 'vencedor')),
        'origem_fora_partida' => isset($partida['origem_fora']) ? (int) $partida['origem_fora'] : null,
        'origem_fora_tipo' => trim((string) ($partida['origem_fora_tipo'] ?? 'vencedor')),
        'rotulo_casa' => isset($partida['rotulo_casa']) ? (string) $partida['rotulo_casa'] : null,
        'rotulo_fora' => isset($partida['rotulo_fora']) ? (string) $partida['rotulo_fora'] : null,
    ];
}

$conexao->begin_transaction();

try {
    // Insere a partida (vagas indefinidas) ou, se já existir, atualiza somente a
    // estrutura do chaveamento — preservando seleções, placares e palpites.
    $stmt = $conexao->prepare(
        'INSERT INTO wc_partidas
            (id, time_casa, time_fora, data_partida, gols_casa, gols_fora, encerrada, status,
             fase, ordem, origem_casa_partida, origem_casa_tipo, origem_fora_partida, origem_fora_tipo,
             rotulo_casa, rotulo_fora, classificado)
         VALUES
            (?, NULL, NULL, ?, NULL, NULL, 0, 0,
             ?, ?, ?, ?, ?, ?, ?, ?, NULL)
         ON DUPLICATE KEY UPDATE
            data_partida = VALUES(data_partida),
            fase = VALUES(fase),
            ordem = VALUES(ordem),
            origem_casa_partida = VALUES(origem_casa_partida),
            origem_casa_tipo = VALUES(origem_casa_tipo),
            origem_fora_partida = VALUES(origem_fora_partida),
            origem_fora_tipo = VALUES(origem_fora_tipo),
            rotulo_casa = VALUES(rotulo_casa),
            rotulo_fora = VALUES(rotulo_fora)'
    );

    if ($stmt === false) {
        throw new RuntimeException('Erro ao preparar INSERT: ' . $conexao->error);
    }

    foreach ($validadas as $p) {
        $stmt->bind_param(
            'issiisisss',
            $p['id'],
            $p['data_partida'],
            $p['fase'],
            $p['ordem'],
            $p['origem_casa_partida'],
            $p['origem_casa_tipo'],
            $p['origem_fora_partida'],
            $p['origem_fora_tipo'],
            $p['rotulo_casa'],
            $p['rotulo_fora']
        );

        if (!$stmt->execute()) {
            throw new RuntimeException("Erro ao gravar o jogo {$p['id']}: " . $stmt->error);
        }
    }

    $stmt->close();
    $conexao->commit();
    $conexao->close();

    mmResponderSucesso([
        'STATUS' => 'SUCCESS',
        'message' => 'Mata-mata importado/atualizado com sucesso.',
        'arquivo' => $jsonPath,
        'total' => count($validadas),
    ], $isCli);
} catch (Throwable $e) {
    $conexao->rollback();
    $conexao->close();
    mmResponderErro(500, $e->getMessage(), $isCli);
}

function mmMontarDateTime(string $data, string $horario): ?string
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

function mmResponderSucesso(array $payload, bool $isCli): void
{
    if ($isCli) {
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
        exit(0);
    }

    http_response_code(200);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function mmResponderErro(int $status, string $message, bool $isCli, array $extra = []): void
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
