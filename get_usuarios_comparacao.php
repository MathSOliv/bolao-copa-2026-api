<?php

/**
 * wc_backend/get_usuarios_comparacao.php
 *
 * Retorna a lista de usuários disponíveis para comparação de palpites
 * (todos exceto o usuário autenticado).
 *
 * Entrada (GET):
 *   /Painel/wc_backend/get_usuarios_comparacao.php?token=...
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

if (!$usuario) {
    http_response_code(401);
    echo json_encode(['STATUS' => 'ERROR', 'error' => 'Sessão inválida ou expirada.']);
    $conexao->close();
    exit;
}

$usuarioId = (int) $usuario['id'];

$stmt = $conexao->prepare(
    'SELECT id, nome FROM wc_usuarios WHERE id != ? ORDER BY nome ASC'
);
$stmt->bind_param('i', $usuarioId);
$stmt->execute();
$result = $stmt->get_result();

$usuarios = [];

while ($row = $result->fetch_assoc()) {
    $usuarios[] = [
        'id' => (int) $row['id'],
        'nome' => $row['nome'],
    ];
}

$stmt->close();
$conexao->close();

echo json_encode([
    'STATUS' => 'SUCCESS',
    'usuarios' => $usuarios,
], JSON_UNESCAPED_UNICODE);
