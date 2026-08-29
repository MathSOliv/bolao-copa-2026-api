<?php

/**
 * wc_backend/get_partidas.php
 *
 * Retorna todas as partidas cadastradas com dados das seleções mandante e visitante.
 * Se o usuário estiver autenticado, inclui o palpite salvo por partida (quando existir).
 *
 * Entrada (GET):
 *   /Painel/wc_backend/get_partidas.php
 *   /Painel/wc_backend/get_partidas.php?token=...
 *
 * Saída (200):
 *   {
 *     "STATUS": "SUCCESS",
 *     "partidas": [
 *       {
 *         "id": 1,
 *         "data_partida": "2026-06-11 16:00:00",
 *         "gols_casa": null,
 *         "gols_fora": null,
 *         "encerrada": 0,
 *         "mandante": { "id": 34, "nome": "México", "sigla": "MEX", "bandeira": "..." },
 *         "visitante": { "id": 2, "nome": "África do Sul", "sigla": "RSA", "bandeira": "..." },
 *         "palpite": {
 *           "id": 10,
 *           "gols_casa": 2,
 *           "gols_fora": 1,
 *           "status": "pendente",
 *           "pontos": 0,
 *           "data_palpite": "2026-06-10 18:47:45"
 *         }
 *       }
 *     ]
 *   }
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

$token = wcExtrairToken();
$usuario = wcAutenticarPorToken($conexao, $token);
$usuarioId = $usuario ? (int) $usuario['id'] : null;

$sqlBase = 'SELECT
            p.id,
            p.data_partida,
            p.gols_casa,
            p.gols_fora,
            p.encerrada,
            p.time_casa,
            p.time_fora,
            p.fase,
            p.ordem,
            p.origem_casa_partida,
            p.origem_fora_partida,
            p.rotulo_casa,
            p.rotulo_fora,
            p.classificado,
            c.nome AS casa_nome,
            c.sigla AS casa_sigla,
            c.bandeira AS casa_bandeira,
            f.nome AS fora_nome,
            f.sigla AS fora_sigla,
            f.bandeira AS fora_bandeira';

if ($usuarioId !== null) {
    $sql = $sqlBase . ',
            pal.id AS palpite_id,
            pal.gols_casa AS palpite_gols_casa,
            pal.gols_fora AS palpite_gols_fora,
            pal.classificado AS palpite_classificado,
            pal.status AS palpite_status,
            pal.pontos AS palpite_pontos,
            pal.data_palpite AS palpite_data
        FROM wc_partidas p
        LEFT JOIN wc_selecoes c ON c.id = p.time_casa
        LEFT JOIN wc_selecoes f ON f.id = p.time_fora
        LEFT JOIN wc_palpites pal ON pal.partida = p.id AND pal.usuario = ?
        ORDER BY p.data_partida ASC';

    $stmt = $conexao->prepare($sql);

    if ($stmt === false) {
        http_response_code(500);
        echo json_encode(['STATUS' => 'ERROR', 'error' => 'Erro ao consultar partidas.']);
        $conexao->close();
        exit;
    }

    $stmt->bind_param('i', $usuarioId);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $sql = $sqlBase . '
        FROM wc_partidas p
        LEFT JOIN wc_selecoes c ON c.id = p.time_casa
        LEFT JOIN wc_selecoes f ON f.id = p.time_fora
        ORDER BY p.data_partida ASC';

    $result = $conexao->query($sql);
}

if ($result === false) {
    http_response_code(500);
    echo json_encode(['STATUS' => 'ERROR', 'error' => 'Erro ao consultar partidas.']);
    $conexao->close();
    exit;
}

$partidas = [];

while ($row = $result->fetch_assoc()) {
    $mandante = $row['time_casa'] !== null ? [
        'id' => (int) $row['time_casa'],
        'nome' => $row['casa_nome'],
        'sigla' => $row['casa_sigla'],
        'bandeira' => $row['casa_bandeira'],
    ] : null;

    $visitante = $row['time_fora'] !== null ? [
        'id' => (int) $row['time_fora'],
        'nome' => $row['fora_nome'],
        'sigla' => $row['fora_sigla'],
        'bandeira' => $row['fora_bandeira'],
    ] : null;

    $partida = [
        'id' => (int) $row['id'],
        'data_partida' => $row['data_partida'],
        'gols_casa' => $row['gols_casa'] !== null ? (int) $row['gols_casa'] : null,
        'gols_fora' => $row['gols_fora'] !== null ? (int) $row['gols_fora'] : null,
        'encerrada' => (int) $row['encerrada'],
        'fase' => $row['fase'] ?? 'grupos',
        'ordem' => $row['ordem'] !== null ? (int) $row['ordem'] : null,
        'origem_casa_partida' => $row['origem_casa_partida'] !== null ? (int) $row['origem_casa_partida'] : null,
        'origem_fora_partida' => $row['origem_fora_partida'] !== null ? (int) $row['origem_fora_partida'] : null,
        'rotulo_casa' => $row['rotulo_casa'],
        'rotulo_fora' => $row['rotulo_fora'],
        'classificado' => $row['classificado'] !== null ? (int) $row['classificado'] : null,
        'mandante' => $mandante,
        'visitante' => $visitante,
        'palpite' => null,
    ];

    if ($usuarioId !== null && $row['palpite_id'] !== null) {
        $partida['palpite'] = [
            'id' => (int) $row['palpite_id'],
            'gols_casa' => (int) $row['palpite_gols_casa'],
            'gols_fora' => (int) $row['palpite_gols_fora'],
            'classificado' => $row['palpite_classificado'] !== null ? (int) $row['palpite_classificado'] : null,
            'status' => $row['palpite_status'],
            'pontos' => (int) $row['palpite_pontos'],
            'data_palpite' => $row['palpite_data'],
        ];
    }

    $partidas[] = $partida;
}

if (isset($stmt)) {
    $stmt->close();
} else {
    $result->free();
}

$conexao->close();

echo json_encode([
    'STATUS' => 'SUCCESS',
    'partidas' => $partidas,
], JSON_UNESCAPED_UNICODE);
