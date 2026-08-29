<?php

/**
 * Gera src/data/partidas.json com as partidas agendadas da fase de grupos,
 * usando os IDs reais da tabela wc_selecoes.
 *
 * Uso: php gerar_partidas_json.php
 */

declare(strict_types=1);

include __DIR__ . '/../assets/php/conexao.php';

$conexao->set_charset('utf8mb4');

$result = $conexao->query('SELECT id, nome, sigla FROM wc_selecoes');
$porSigla = [];

while ($row = $result->fetch_assoc()) {
    $porSigla[$row['sigla']] = [
        'id' => (int) $row['id'],
        'nome' => $row['nome'],
    ];
}

$conexao->close();

function selecao(array $mapa, string $sigla): array
{
    if (!isset($mapa[$sigla])) {
        throw new RuntimeException("Seleção não encontrada no banco: {$sigla}");
    }

    return $mapa[$sigla];
}

function partida(
    int $jogo,
    string $grupo,
    int $rodada,
    string $data,
    string $horario,
    string $local,
    string $mandanteSigla,
    string $visitanteSigla,
    array $mapa
): array {
    return [
        'jogo' => $jogo,
        'fase' => 'grupos',
        'grupo' => $grupo,
        'rodada' => $rodada,
        'data' => $data,
        'horario' => $horario,
        'local' => $local,
        'mandante' => selecao($mapa, $mandanteSigla),
        'visitante' => selecao($mapa, $visitanteSigla),
    ];
}

$partidas = [
    partida(1, 'A', 1, '2026-06-11', '16:00', 'Cidade do México, México', 'MEX', 'RSA', $porSigla),
    partida(2, 'A', 1, '2026-06-11', '23:00', 'Guadalajara, México', 'KOR', 'CZE', $porSigla),
    partida(3, 'B', 1, '2026-06-12', '16:00', 'Toronto, Canadá', 'CAN', 'BIH', $porSigla),
    partida(4, 'D', 1, '2026-06-12', '22:00', 'Los Angeles, EUA', 'USA', 'PAR', $porSigla),
    partida(5, 'B', 1, '2026-06-13', '16:00', 'Santa Clara, EUA', 'QAT', 'SUI', $porSigla),
    partida(6, 'C', 1, '2026-06-13', '19:00', 'Nova York/Nova Jersey, EUA', 'BRA', 'MAR', $porSigla),
    partida(7, 'C', 1, '2026-06-13', '22:00', 'Boston, EUA', 'HAI', 'SCO', $porSigla),
    partida(8, 'D', 1, '2026-06-14', '01:00', 'Vancouver, Canadá', 'AUS', 'TUR', $porSigla),
    partida(9, 'E', 1, '2026-06-14', '14:00', 'Houston, EUA', 'GER', 'CUW', $porSigla),
    partida(10, 'E', 1, '2026-06-14', '20:00', 'Filadélfia, EUA', 'CIV', 'ECU', $porSigla),
    partida(11, 'F', 1, '2026-06-14', '17:00', 'Dallas, EUA', 'NED', 'JPN', $porSigla),
    partida(12, 'F', 1, '2026-06-14', '23:00', 'Monterrey, México', 'SWE', 'TUN', $porSigla),
    partida(13, 'H', 1, '2026-06-15', '13:00', 'Atlanta, EUA', 'ESP', 'CPV', $porSigla),
    partida(14, 'H', 1, '2026-06-15', '19:00', 'Miami, EUA', 'KSA', 'URU', $porSigla),
    partida(15, 'G', 1, '2026-06-15', '16:00', 'Seattle, EUA', 'BEL', 'EGY', $porSigla),
    partida(16, 'G', 1, '2026-06-15', '22:00', 'Los Angeles, EUA', 'IRN', 'NZL', $porSigla),
    partida(17, 'J', 1, '2026-06-17', '01:00', 'Santa Clara, EUA', 'AUT', 'JOR', $porSigla),
    partida(18, 'I', 1, '2026-06-16', '16:00', 'Nova York/Nova Jersey, EUA', 'FRA', 'SEN', $porSigla),
    partida(19, 'I', 1, '2026-06-16', '19:00', 'Boston, EUA', 'IRQ', 'NOR', $porSigla),
    partida(20, 'J', 1, '2026-06-16', '22:00', 'Kansas City, EUA', 'ARG', 'ALG', $porSigla),
    partida(21, 'K', 1, '2026-06-17', '14:00', 'Houston, EUA', 'POR', 'COD', $porSigla),
    partida(22, 'L', 1, '2026-06-17', '17:00', 'Dallas, EUA', 'ENG', 'CRO', $porSigla),
    partida(23, 'L', 1, '2026-06-17', '20:00', 'Toronto, Canadá', 'GHA', 'PAN', $porSigla),
    partida(24, 'K', 1, '2026-06-17', '21:00', 'Cidade do México, México', 'UZB', 'COL', $porSigla),

    partida(25, 'A', 2, '2026-06-18', '13:00', 'Atlanta, EUA', 'CZE', 'RSA', $porSigla),
    partida(26, 'B', 2, '2026-06-18', '16:00', 'Los Angeles, EUA', 'SUI', 'BIH', $porSigla),
    partida(27, 'B', 2, '2026-06-18', '19:00', 'Vancouver, Canadá', 'CAN', 'QAT', $porSigla),
    partida(28, 'A', 2, '2026-06-18', '22:00', 'Guadalajara, México', 'MEX', 'KOR', $porSigla),
    partida(29, 'D', 2, '2026-06-20', '00:00', 'Santa Clara, EUA', 'TUR', 'PAR', $porSigla),
    partida(30, 'D', 2, '2026-06-19', '16:00', 'Seattle, EUA', 'USA', 'AUS', $porSigla),
    partida(31, 'C', 2, '2026-06-19', '19:00', 'Boston, EUA', 'SCO', 'MAR', $porSigla),
    partida(32, 'C', 2, '2026-06-19', '21:30', 'Filadélfia, EUA', 'BRA', 'HAI', $porSigla),
    partida(33, 'F', 2, '2026-06-20', '23:00', 'Monterrey, México', 'TUN', 'JPN', $porSigla),
    partida(34, 'F', 2, '2026-06-20', '14:00', 'Houston, EUA', 'NED', 'SWE', $porSigla),
    partida(35, 'E', 2, '2026-06-20', '17:00', 'Toronto, Canadá', 'GER', 'CIV', $porSigla),
    partida(36, 'E', 2, '2026-06-20', '21:00', 'Kansas City, EUA', 'ECU', 'CUW', $porSigla),
    partida(37, 'H', 2, '2026-06-21', '13:00', 'Atlanta, EUA', 'ESP', 'KSA', $porSigla),
    partida(38, 'G', 2, '2026-06-21', '16:00', 'Los Angeles, EUA', 'BEL', 'IRN', $porSigla),
    partida(39, 'H', 2, '2026-06-21', '19:00', 'Miami, EUA', 'URU', 'CPV', $porSigla),
    partida(40, 'G', 2, '2026-06-21', '22:00', 'Vancouver, Canadá', 'NZL', 'EGY', $porSigla),
    partida(41, 'J', 2, '2026-06-22', '14:00', 'Dallas, EUA', 'ARG', 'AUT', $porSigla),
    partida(42, 'I', 2, '2026-06-22', '18:00', 'Filadélfia, EUA', 'FRA', 'IRQ', $porSigla),
    partida(43, 'I', 2, '2026-06-22', '21:00', 'Nova York/Nova Jersey, EUA', 'NOR', 'SEN', $porSigla),
    partida(44, 'J', 2, '2026-06-23', '00:00', 'Santa Clara, EUA', 'JOR', 'ALG', $porSigla),
    partida(45, 'K', 2, '2026-06-23', '14:00', 'Houston, EUA', 'POR', 'UZB', $porSigla),
    partida(46, 'L', 2, '2026-06-23', '17:00', 'Boston, EUA', 'ENG', 'GHA', $porSigla),
    partida(47, 'L', 2, '2026-06-23', '20:00', 'Toronto, Canadá', 'PAN', 'CRO', $porSigla),
    partida(48, 'K', 2, '2026-06-23', '23:00', 'Guadalajara, México', 'COL', 'COD', $porSigla),

    partida(49, 'B', 3, '2026-06-24', '16:00', 'Vancouver, Canadá', 'SUI', 'CAN', $porSigla),
    partida(50, 'B', 3, '2026-06-24', '16:00', 'Seattle, EUA', 'BIH', 'QAT', $porSigla),
    partida(51, 'C', 3, '2026-06-24', '19:00', 'Miami, EUA', 'SCO', 'BRA', $porSigla),
    partida(52, 'C', 3, '2026-06-24', '19:00', 'Atlanta, EUA', 'MAR', 'HAI', $porSigla),
    partida(53, 'A', 3, '2026-06-24', '22:00', 'Cidade do México, México', 'CZE', 'MEX', $porSigla),
    partida(54, 'A', 3, '2026-06-24', '22:00', 'Monterrey, México', 'RSA', 'KOR', $porSigla),
    partida(55, 'E', 3, '2026-06-25', '17:00', 'Nova York/Nova Jersey, EUA', 'ECU', 'GER', $porSigla),
    partida(56, 'E', 3, '2026-06-25', '17:00', 'Filadélfia, EUA', 'CUW', 'CIV', $porSigla),
    partida(57, 'F', 3, '2026-06-25', '20:00', 'Dallas, EUA', 'JPN', 'SWE', $porSigla),
    partida(58, 'F', 3, '2026-06-25', '20:00', 'Kansas City, EUA', 'TUN', 'NED', $porSigla),
    partida(59, 'D', 3, '2026-06-25', '23:00', 'Los Angeles, EUA', 'TUR', 'USA', $porSigla),
    partida(60, 'D', 3, '2026-06-25', '23:00', 'Santa Clara, EUA', 'PAR', 'AUS', $porSigla),
    partida(61, 'I', 3, '2026-06-26', '16:00', 'Boston, EUA', 'NOR', 'FRA', $porSigla),
    partida(62, 'I', 3, '2026-06-26', '16:00', 'Toronto, Canadá', 'SEN', 'IRQ', $porSigla),
    partida(63, 'H', 3, '2026-06-26', '21:00', 'Houston, EUA', 'CPV', 'KSA', $porSigla),
    partida(64, 'H', 3, '2026-06-26', '21:00', 'Guadalajara, México', 'URU', 'ESP', $porSigla),
    partida(65, 'G', 3, '2026-06-27', '00:00', 'Seattle, EUA', 'EGY', 'IRN', $porSigla),
    partida(66, 'G', 3, '2026-06-27', '00:00', 'Vancouver, Canadá', 'NZL', 'BEL', $porSigla),
    partida(67, 'L', 3, '2026-06-27', '18:00', 'Nova York/Nova Jersey, EUA', 'PAN', 'ENG', $porSigla),
    partida(68, 'L', 3, '2026-06-27', '18:00', 'Filadélfia, EUA', 'CRO', 'GHA', $porSigla),
    partida(69, 'K', 3, '2026-06-27', '20:30', 'Miami, EUA', 'COL', 'POR', $porSigla),
    partida(70, 'K', 3, '2026-06-27', '20:30', 'Atlanta, EUA', 'COD', 'UZB', $porSigla),
    partida(71, 'J', 3, '2026-06-27', '23:00', 'Kansas City, EUA', 'ALG', 'AUT', $porSigla),
    partida(72, 'J', 3, '2026-06-28', '23:00', 'Dallas, EUA', 'JOR', 'ARG', $porSigla), // 21h local em 27/06
];

$destinos = [
    __DIR__ . '/data/partidas.json',
    'C:/Users/MatheusOliveiraPalmo/Projects/copa-2026-bet/src/data/partidas.json',
];

$json = json_encode($partidas, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

if ($json === false) {
    fwrite(STDERR, "Erro ao gerar JSON.\n");
    exit(1);
}

foreach ($destinos as $destino) {
    $dir = dirname($destino);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($destino, $json . PHP_EOL);
    echo "Gerado: {$destino}\n";
}

echo 'Total: ' . count($partidas) . " partidas\n";
