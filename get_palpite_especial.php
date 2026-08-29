<?php

/**
 * wc_backend/get_palpite_especial.php
 *
 * Retorna o palpite especial do usuário autenticado (campeão e artilheiro).
 *
 * Entrada (GET):
 *   /Painel/wc_backend/get_palpite_especial.php
 *   /Painel/wc_backend/get_palpite_especial.php?token=...
 *
 * Saída (200):
 *   {
 *     "STATUS": "SUCCESS",
 *     "palpite": { ... } | null,
 *     "pode_editar": true
 *   }
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/palpite_especial_helper.php';
require_once __DIR__ . '/palpite_horario_helper.php';

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

if (!$usuario) {
    http_response_code(401);
    echo json_encode(['STATUS' => 'ERROR', 'error' => 'Sessão inválida ou expirada.']);
    $conexao->close();
    exit;
}

try {
    $usuarioId = (int) $usuario['id'];
    $palpite = wcBuscarPalpiteEspecialPorUsuario($conexao, $usuarioId);
    $erroPrazo = wcValidarPrazoPalpiteEspecial();
    $erroHorario = wcValidarHorarioPalpite();
    $podeEditar = $erroPrazo === null && $erroHorario === null;
    $mensagemPrazo = $erroPrazo ?? $erroHorario;

    $conexao->close();

    echo json_encode([
        'STATUS' => 'SUCCESS',
        'palpite' => $palpite,
        'pode_editar' => $podeEditar,
        'mensagem_prazo' => $mensagemPrazo,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $conexao->close();
    http_response_code(500);
    echo json_encode(['STATUS' => 'ERROR', 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
