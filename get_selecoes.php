<?php

/**
 * wc_backend/get_selecoes.php
 *
 * Retorna todas as seleções cadastradas (id, nome, sigla, bandeira),
 * ordenadas por nome. Usado, por exemplo, para o admin definir os confrontos
 * do mata-mata a partir dos classificados dos grupos.
 *
 * Entrada (GET):
 *   /Painel/wc_backend/get_selecoes.php
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth_helper.php';

wcConfigurarCors(['GET', 'OPTIONS']);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['STATUS' => 'ERROR', 'error' => 'Método não permitido.']);
    exit;
}

include __DIR__ . '/../assets/php/conexao.php';

if (!($conexao instanceof mysqli)) {
    http_response_code(500);
    echo json_encode(['STATUS' => 'ERROR', 'error' => 'Conexão MySQL indisponível.']);
    exit;
}

$conexao->set_charset('utf8mb4');

$result = $conexao->query('SELECT id, nome, sigla, bandeira FROM wc_selecoes ORDER BY nome ASC');

if ($result === false) {
    http_response_code(500);
    echo json_encode(['STATUS' => 'ERROR', 'error' => 'Erro ao consultar seleções.']);
    $conexao->close();
    exit;
}

$selecoes = [];

while ($row = $result->fetch_assoc()) {
    $selecoes[] = [
        'id' => (int) $row['id'],
        'nome' => $row['nome'],
        'sigla' => $row['sigla'],
        'bandeira' => $row['bandeira'],
    ];
}

$result->free();
$conexao->close();

echo json_encode([
    'STATUS' => 'SUCCESS',
    'selecoes' => $selecoes,
], JSON_UNESCAPED_UNICODE);
