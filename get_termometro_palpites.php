<?php

/**
 * wc_backend/get_termometro_palpites.php
 *
 * Retorna o termômetro de palpites por partida (vitória mandante, empate, vitória visitante).
 *
 * Entrada (GET):
 *   /Painel/wc_backend/get_termometro_palpites.php
 *
 * Saída (200):
 *   {
 *     "STATUS": "SUCCESS",
 *     "termometro": [
 *       {
 *         "partida_id": 1,
 *         "data_partida": "2026-06-11 16:00:00",
 *         "encerrada": 0,
 *         "mandante": { "sigla": "BRA", "bandeira": "..." },
 *         "visitante": { "sigla": "MAR", "bandeira": "..." },
 *         "total_palpites": 100,
 *         "vitoria_mandante": 62,
 *         "empate": 18,
 *         "vitoria_visitante": 20,
 *         "pct_mandante": 62.0,
 *         "pct_empate": 18.0,
 *         "pct_visitante": 20.0
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

$sql = 'SELECT
            p.id AS partida_id,
            c.sigla AS mandante_sigla,
            c.bandeira AS mandante_bandeira,
            f.sigla AS visitante_sigla,
            f.bandeira AS visitante_bandeira,
            p.data_partida,
            p.encerrada,
            COUNT(pal.id) AS total_palpites,
            COALESCE(SUM(CASE WHEN pal.gols_casa > pal.gols_fora THEN 1 ELSE 0 END), 0) AS vitoria_mandante,
            COALESCE(SUM(CASE WHEN pal.gols_casa = pal.gols_fora THEN 1 ELSE 0 END), 0) AS empate,
            COALESCE(SUM(CASE WHEN pal.gols_casa < pal.gols_fora THEN 1 ELSE 0 END), 0) AS vitoria_visitante,
            COALESCE(
                ROUND(
                    100 * SUM(CASE WHEN pal.gols_casa > pal.gols_fora THEN 1 ELSE 0 END)
                    / NULLIF(COUNT(pal.id), 0),
                    1
                ),
                0
            ) AS pct_mandante,
            COALESCE(
                ROUND(
                    100 * SUM(CASE WHEN pal.gols_casa = pal.gols_fora THEN 1 ELSE 0 END)
                    / NULLIF(COUNT(pal.id), 0),
                    1
                ),
                0
            ) AS pct_empate,
            COALESCE(
                ROUND(
                    100 * SUM(CASE WHEN pal.gols_casa < pal.gols_fora THEN 1 ELSE 0 END)
                    / NULLIF(COUNT(pal.id), 0),
                    1
                ),
                0
            ) AS pct_visitante
        FROM wc_partidas p
        INNER JOIN wc_selecoes c ON c.id = p.time_casa
        INNER JOIN wc_selecoes f ON f.id = p.time_fora
        LEFT JOIN wc_palpites pal ON pal.partida = p.id
        GROUP BY
            p.id,
            c.sigla,
            c.bandeira,
            f.sigla,
            f.bandeira,
            p.data_partida,
            p.encerrada
        ORDER BY p.data_partida ASC';

$result = $conexao->query($sql);

if ($result === false) {
    http_response_code(500);
    echo json_encode(['STATUS' => 'ERROR', 'error' => 'Erro ao consultar termômetro de palpites.']);
    $conexao->close();
    exit;
}

$termometro = [];

while ($row = $result->fetch_assoc()) {
    $termometro[] = [
        'partida_id' => (int) $row['partida_id'],
        'data_partida' => $row['data_partida'],
        'encerrada' => (int) $row['encerrada'],
        'mandante' => [
            'sigla' => $row['mandante_sigla'],
            'bandeira' => $row['mandante_bandeira'],
        ],
        'visitante' => [
            'sigla' => $row['visitante_sigla'],
            'bandeira' => $row['visitante_bandeira'],
        ],
        'total_palpites' => (int) $row['total_palpites'],
        'vitoria_mandante' => (int) $row['vitoria_mandante'],
        'empate' => (int) $row['empate'],
        'vitoria_visitante' => (int) $row['vitoria_visitante'],
        'pct_mandante' => (float) $row['pct_mandante'],
        'pct_empate' => (float) $row['pct_empate'],
        'pct_visitante' => (float) $row['pct_visitante'],
    ];
}

$result->free();
$conexao->close();

echo json_encode([
    'STATUS' => 'SUCCESS',
    'termometro' => $termometro,
], JSON_UNESCAPED_UNICODE);
