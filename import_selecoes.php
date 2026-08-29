<?php

/**
 * wc_backend/import_selecoes.php
 *
 * Lê o arquivo selecoes.json e popula a tabela wc_selecoes (nome, sigla, bandeira).
 * Substitui todos os registros existentes a cada execução.
 *
 * Uso (navegador ou CLI):
 *   http://localhost/Painel/wc_backend/import_selecoes.php
 *   php import_selecoes.php
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
    responderErro(400, 'Arquivo selecoes.json não encontrado.', $isCli, [
        'candidatos' => [
            getenv('WC_SELECOES_JSON') ?: '(variável WC_SELECOES_JSON não definida)',
            'C:/Users/MatheusOliveiraPalmo/Projects/copa-2026-bet/src/data/selecoes.json',
            __DIR__ . '/data/selecoes.json',
        ],
    ]);
}

$conteudo = file_get_contents($jsonPath);

if ($conteudo === false) {
    responderErro(500, 'Não foi possível ler o arquivo JSON.', $isCli, ['arquivo' => $jsonPath]);
}

$selecoes = json_decode($conteudo, true);

if (!is_array($selecoes)) {
    responderErro(422, 'JSON inválido ou mal formatado.', $isCli, ['arquivo' => $jsonPath]);
}

$validadas = [];

foreach ($selecoes as $indice => $selecao) {
    if (!is_array($selecao)) {
        responderErro(422, "Item inválido na posição {$indice}.", $isCli);
    }

    $nome = trim((string) ($selecao['nome'] ?? ''));
    $sigla = strtoupper(trim((string) ($selecao['sigla'] ?? '')));
    $bandeira = trim((string) ($selecao['bandeira'] ?? ''));

    if ($nome === '' || $sigla === '' || $bandeira === '') {
        responderErro(422, "Seleção incompleta na posição {$indice}.", $isCli, ['item' => $selecao]);
    }

    $validadas[] = [
        'nome' => $nome,
        'sigla' => $sigla,
        'bandeira' => $bandeira,
    ];
}

$conexao->begin_transaction();

try {
    if (!$conexao->query('DELETE FROM wc_selecoes')) {
        throw new RuntimeException('Erro ao limpar a tabela wc_selecoes: ' . $conexao->error);
    }

    $stmt = $conexao->prepare(
        'INSERT INTO wc_selecoes (nome, sigla, bandeira) VALUES (?, ?, ?)'
    );

    if ($stmt === false) {
        throw new RuntimeException('Erro ao preparar INSERT: ' . $conexao->error);
    }

    foreach ($validadas as $selecao) {
        $stmt->bind_param('sss', $selecao['nome'], $selecao['sigla'], $selecao['bandeira']);

        if (!$stmt->execute()) {
            throw new RuntimeException(
                "Erro ao inserir {$selecao['sigla']}: " . $stmt->error
            );
        }
    }

    $stmt->close();
    $conexao->commit();
    $conexao->close();

    responderSucesso([
        'STATUS' => 'SUCCESS',
        'message' => 'Seleções importadas com sucesso.',
        'arquivo' => $jsonPath,
        'total' => count($validadas),
    ], $isCli);
} catch (Throwable $e) {
    $conexao->rollback();
    $conexao->close();
    responderErro(500, $e->getMessage(), $isCli);
}

function resolverArquivoJson(): ?string
{
    $candidatos = array_filter([
        getenv('WC_SELECOES_JSON') ?: null,
        'C:/Users/MatheusOliveiraPalmo/Projects/copa-2026-bet/src/data/selecoes.json',
        __DIR__ . '/data/selecoes.json',
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
