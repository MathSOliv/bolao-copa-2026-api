<?php

/**
 * wc_backend/save_palpite.php
 *
 * Salva ou atualiza o palpite de um usuário para uma partida.
 *
 * Entrada (POST JSON + Authorization: Bearer {token}):
 *   {
 *     "partida_id": 6,
 *     "gols_casa": 2,
 *     "gols_fora": 1
 *   }
 *
 * Saída (200):
 *   {
 *     "STATUS": "SUCCESS",
 *     "palpite": {
 *       "id": 1,
 *       "partida_id": 6,
 *       "gols_casa": 2,
 *       "gols_fora": 1,
 *       "status": "pendente",
 *       "data_palpite": "2026-06-10 15:30:00"
 *     }
 *   }
 */

declare(strict_types=1);

const WC_MINUTOS_ANTECEDENCIA_PALPITE = 20;
const WC_FUSO_BRASILIA = 'America/Sao_Paulo';

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/palpite_horario_helper.php';

wcConfigurarCors(['POST', 'OPTIONS']);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$token = wcExtrairToken($input);
$usuario = wcAutenticarPorToken($conexao, $token);

if (!$usuario) {
    http_response_code(401);
    echo json_encode(['STATUS' => 'ERROR', 'error' => 'Sessão inválida ou expirada.']);
    $conexao->close();
    exit;
}

$partidaId = filter_var($input['partida_id'] ?? null, FILTER_VALIDATE_INT);
$golsCasa = filter_var($input['gols_casa'] ?? null, FILTER_VALIDATE_INT);
$golsFora = filter_var($input['gols_fora'] ?? null, FILTER_VALIDATE_INT);
$classificadoInput = array_key_exists('classificado', $input)
    ? filter_var($input['classificado'], FILTER_VALIDATE_INT)
    : null;

if ($partidaId === false || $partidaId <= 0) {
    http_response_code(422);
    echo json_encode(['STATUS' => 'ERROR', 'error' => 'Partida inválida.']);
    $conexao->close();
    exit;
}

if ($golsCasa === false || $golsFora === false) {
    http_response_code(422);
    echo json_encode(['STATUS' => 'ERROR', 'error' => 'Informe a quantidade de gols para os dois times.']);
    $conexao->close();
    exit;
}

if ($golsCasa < 0 || $golsCasa > 99 || $golsFora < 0 || $golsFora > 99) {
    http_response_code(422);
    echo json_encode(['STATUS' => 'ERROR', 'error' => 'Os gols devem estar entre 0 e 99.']);
    $conexao->close();
    exit;
}

$erroHorario = wcValidarHorarioPalpite();

if ($erroHorario !== null) {
    http_response_code(409);
    echo json_encode(['STATUS' => 'ERROR', 'error' => $erroHorario]);
    $conexao->close();
    exit;
}

$stmtPartida = $conexao->prepare(
    'SELECT id, encerrada, data_partida, fase, time_casa, time_fora FROM wc_partidas WHERE id = ? LIMIT 1'
);
$stmtPartida->bind_param('i', $partidaId);
$stmtPartida->execute();
$partida = $stmtPartida->get_result()->fetch_assoc();
$stmtPartida->close();

if (!$partida) {
    http_response_code(404);
    echo json_encode(['STATUS' => 'ERROR', 'error' => 'Partida não encontrada.']);
    $conexao->close();
    exit;
}

if ((int) $partida['encerrada'] === 1) {
    http_response_code(409);
    echo json_encode(['STATUS' => 'ERROR', 'error' => 'Esta partida já foi encerrada.']);
    $conexao->close();
    exit;
}

// No mata-mata o confronto só pode receber palpite quando ambas as seleções
// estiverem definidas, e o usuário precisa indicar quem se classifica.
$fasePartida = (string) ($partida['fase'] ?? 'grupos');
$ehMataMata = $fasePartida !== '' && $fasePartida !== 'grupos';
$timeCasa = $partida['time_casa'] !== null ? (int) $partida['time_casa'] : null;
$timeFora = $partida['time_fora'] !== null ? (int) $partida['time_fora'] : null;
$classificado = null;

if ($ehMataMata) {
    if ($timeCasa === null || $timeFora === null) {
        http_response_code(409);
        echo json_encode(['STATUS' => 'ERROR', 'error' => 'Este confronto ainda não tem as duas seleções definidas.']);
        $conexao->close();
        exit;
    }

    if ($classificadoInput === false || $classificadoInput === null) {
        http_response_code(422);
        echo json_encode(['STATUS' => 'ERROR', 'error' => 'Selecione qual seleção se classifica.']);
        $conexao->close();
        exit;
    }

    if ($classificadoInput !== $timeCasa && $classificadoInput !== $timeFora) {
        http_response_code(422);
        echo json_encode(['STATUS' => 'ERROR', 'error' => 'A seleção que se classifica deve ser uma das duas do confronto.']);
        $conexao->close();
        exit;
    }

    if ($golsCasa !== $golsFora) {
        $vencedorPlacar = $golsCasa > $golsFora ? $timeCasa : $timeFora;

        if ($classificadoInput !== $vencedorPlacar) {
            http_response_code(422);
            echo json_encode([
                'STATUS' => 'ERROR',
                'error' => 'Com placar decisivo, quem se classifica deve ser a seleção vencedora do placar informado.',
            ]);
            $conexao->close();
            exit;
        }
    }

    $classificado = $classificadoInput;
}

// data_partida está armazenada no horário de Brasília; interpretamos e comparamos
// sempre nesse fuso para não depender do timezone padrão do servidor.
$fusoBrasilia = new DateTimeZone(WC_FUSO_BRASILIA);
$dataPartida = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $partida['data_partida'], $fusoBrasilia);

if ($dataPartida === false) {
    http_response_code(500);
    echo json_encode(['STATUS' => 'ERROR', 'error' => 'Data da partida inválida.']);
    $conexao->close();
    exit;
}

$limitePalpite = $dataPartida->modify('-' . WC_MINUTOS_ANTECEDENCIA_PALPITE . ' minutes');

if (new DateTimeImmutable('now', $fusoBrasilia) >= $limitePalpite) {
    http_response_code(409);
    echo json_encode([
        'STATUS' => 'ERROR',
        'error' => 'O prazo para palpitar encerrou ' . WC_MINUTOS_ANTECEDENCIA_PALPITE . ' minutos antes da partida.',
    ]);
    $conexao->close();
    exit;
}

$usuarioId = (int) $usuario['id'];
$status = 'pendente';

$stmtExistente = $conexao->prepare(
    'SELECT id FROM wc_palpites WHERE usuario = ? AND partida = ? LIMIT 1'
);
$stmtExistente->bind_param('ii', $usuarioId, $partidaId);
$stmtExistente->execute();
$palpiteExistente = $stmtExistente->get_result()->fetch_assoc();
$stmtExistente->close();

if ($palpiteExistente) {
    $palpiteId = (int) $palpiteExistente['id'];

    $stmtUpdate = $conexao->prepare(
        'UPDATE wc_palpites
         SET gols_casa = ?, gols_fora = ?, classificado = ?, status = ?, data_palpite = NOW()
         WHERE id = ? AND usuario = ?'
    );
    $stmtUpdate->bind_param('iiisii', $golsCasa, $golsFora, $classificado, $status, $palpiteId, $usuarioId);

    if (!$stmtUpdate->execute()) {
        http_response_code(500);
        echo json_encode(['STATUS' => 'ERROR', 'error' => 'Erro ao atualizar palpite.']);
        $stmtUpdate->close();
        $conexao->close();
        exit;
    }

    $stmtUpdate->close();
} else {
    $stmtInsert = $conexao->prepare(
        'INSERT INTO wc_palpites (usuario, partida, gols_casa, gols_fora, classificado, status, pontos, data_palpite)
         VALUES (?, ?, ?, ?, ?, ?, 0, NOW())'
    );
    $stmtInsert->bind_param('iiiiis', $usuarioId, $partidaId, $golsCasa, $golsFora, $classificado, $status);

    if (!$stmtInsert->execute()) {
        http_response_code(500);
        echo json_encode(['STATUS' => 'ERROR', 'error' => 'Erro ao salvar palpite.']);
        $stmtInsert->close();
        $conexao->close();
        exit;
    }

    $palpiteId = (int) $stmtInsert->insert_id;
    $stmtInsert->close();
}

$stmtRetorno = $conexao->prepare(
    'SELECT id, partida, gols_casa, gols_fora, classificado, status, data_palpite
     FROM wc_palpites
     WHERE id = ? AND usuario = ?
     LIMIT 1'
);
$stmtRetorno->bind_param('ii', $palpiteId, $usuarioId);
$stmtRetorno->execute();
$palpite = $stmtRetorno->get_result()->fetch_assoc();
$stmtRetorno->close();
$conexao->close();

echo json_encode([
    'STATUS' => 'SUCCESS',
    'palpite' => [
        'id' => (int) $palpite['id'],
        'partida_id' => (int) $palpite['partida'],
        'gols_casa' => (int) $palpite['gols_casa'],
        'gols_fora' => (int) $palpite['gols_fora'],
        'classificado' => $palpite['classificado'] !== null ? (int) $palpite['classificado'] : null,
        'status' => $palpite['status'],
        'data_palpite' => $palpite['data_palpite'],
    ],
], JSON_UNESCAPED_UNICODE);
