<?php

/**
 * wc_backend/save_palpite_especial.php
 *
 * Salva ou atualiza o palpite especial (campeão e artilheiro) do usuário.
 *
 * Entrada (POST JSON + Authorization: Bearer {token}):
 *   {
 *     "selecao_sigla": "BRA",
 *     "artilheiro_nome": "Neymar"
 *   }
 *
 * Saída (200):
 *   {
 *     "STATUS": "SUCCESS",
 *     "palpite": { ... }
 *   }
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/palpite_especial_helper.php';
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

$selecaoSigla = strtoupper(trim((string) ($input['selecao_sigla'] ?? '')));
$artilheiroNome = trim((string) ($input['artilheiro_nome'] ?? ''));

if ($selecaoSigla === '') {
    http_response_code(422);
    echo json_encode(['STATUS' => 'ERROR', 'error' => 'Selecione a seleção campeã.']);
    $conexao->close();
    exit;
}

if ($artilheiroNome === '') {
    http_response_code(422);
    echo json_encode(['STATUS' => 'ERROR', 'error' => 'Selecione o artilheiro.']);
    $conexao->close();
    exit;
}

$erroPrazo = wcValidarPrazoPalpiteEspecial();
$erroHorario = wcValidarHorarioPalpite();
$erroBloqueio = $erroPrazo ?? $erroHorario;

if ($erroBloqueio !== null) {
    http_response_code(409);
    echo json_encode(['STATUS' => 'ERROR', 'error' => $erroBloqueio]);
    $conexao->close();
    exit;
}

try {
    if (!wcArtilheiroPermitido($artilheiroNome)) {
        http_response_code(422);
        echo json_encode(['STATUS' => 'ERROR', 'error' => 'Jogador inválido para artilheiro.']);
        $conexao->close();
        exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['STATUS' => 'ERROR', 'error' => $e->getMessage()]);
    $conexao->close();
    exit;
}

$stmtSelecao = $conexao->prepare(
    'SELECT id FROM wc_selecoes WHERE sigla = ? LIMIT 1'
);
$stmtSelecao->bind_param('s', $selecaoSigla);
$stmtSelecao->execute();
$selecao = $stmtSelecao->get_result()->fetch_assoc();
$stmtSelecao->close();

if (!$selecao) {
    http_response_code(422);
    echo json_encode(['STATUS' => 'ERROR', 'error' => 'Seleção campeã inválida.']);
    $conexao->close();
    exit;
}

$usuarioId = (int) $usuario['id'];
$selecaoId = (int) $selecao['id'];
$statusCampeao = 'pendente';
$statusArtilheiro = 'pendente';

$stmtExistente = $conexao->prepare(
    'SELECT id FROM wc_palpites_especiais WHERE usuario = ? LIMIT 1'
);
$stmtExistente->bind_param('i', $usuarioId);
$stmtExistente->execute();
$palpiteExistente = $stmtExistente->get_result()->fetch_assoc();
$stmtExistente->close();

if ($palpiteExistente) {
    $palpiteId = (int) $palpiteExistente['id'];

    $stmtUpdate = $conexao->prepare(
        'UPDATE wc_palpites_especiais
         SET selecao_campeao = ?, artilheiro_nome = ?, status_campeao = ?, status_artilheiro = ?, data_palpite = NOW()
         WHERE id = ? AND usuario = ?'
    );
    $stmtUpdate->bind_param(
        'isssii',
        $selecaoId,
        $artilheiroNome,
        $statusCampeao,
        $statusArtilheiro,
        $palpiteId,
        $usuarioId
    );

    if (!$stmtUpdate->execute()) {
        http_response_code(500);
        echo json_encode(['STATUS' => 'ERROR', 'error' => 'Erro ao atualizar palpite especial.']);
        $stmtUpdate->close();
        $conexao->close();
        exit;
    }

    $stmtUpdate->close();
} else {
    $stmtInsert = $conexao->prepare(
        'INSERT INTO wc_palpites_especiais
            (usuario, selecao_campeao, artilheiro_nome, status_campeao, status_artilheiro, pontos_campeao, pontos_artilheiro, data_palpite)
         VALUES (?, ?, ?, ?, ?, 0, 0, NOW())'
    );
    $stmtInsert->bind_param(
        'iisss',
        $usuarioId,
        $selecaoId,
        $artilheiroNome,
        $statusCampeao,
        $statusArtilheiro
    );

    if (!$stmtInsert->execute()) {
        http_response_code(500);
        echo json_encode(['STATUS' => 'ERROR', 'error' => 'Erro ao salvar palpite especial.']);
        $stmtInsert->close();
        $conexao->close();
        exit;
    }

    $palpiteId = (int) $stmtInsert->insert_id;
    $stmtInsert->close();
}

try {
    $palpite = wcBuscarPalpiteEspecialPorUsuario($conexao, $usuarioId);
} catch (Throwable $e) {
    $conexao->close();
    http_response_code(500);
    echo json_encode(['STATUS' => 'ERROR', 'error' => $e->getMessage()]);
    exit;
}

$conexao->close();

echo json_encode([
    'STATUS' => 'SUCCESS',
    'palpite' => $palpite,
], JSON_UNESCAPED_UNICODE);
