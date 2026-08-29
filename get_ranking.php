<?php

/**
 * wc_backend/get_ranking.php
 *
 * Retorna o ranking de colaboradores ordenado por pontos e data de cadastro.
 *
 * Ordenação:
 *   1. Pontos (maior primeiro)
 *   2. Data de criação do usuário (mais antigo primeiro)
 *
 * Entrada (GET + Authorization: Bearer {token}):
 *   /Painel/wc_backend/get_ranking.php
 *   /Painel/wc_backend/get_ranking.php?token=...
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

$sql = 'SELECT
    u.id,
    u.nome,
    u.matricula,
    u.created_at,
    COALESCE(j.pontos_jogos, 0)
      + COALESCE(e.pontos_campeao, 0)
      + COALESCE(e.pontos_artilheiro, 0) AS pontos
FROM wc_usuarios u
LEFT JOIN (
    SELECT usuario, SUM(pontos) AS pontos_jogos
    FROM wc_palpites
    GROUP BY usuario
) j ON j.usuario = u.id
LEFT JOIN wc_palpites_especiais e ON e.usuario = u.id
ORDER BY pontos DESC, u.created_at ASC';

$result = $conexao->query($sql);

if ($result === false) {
    http_response_code(500);
    echo json_encode(['STATUS' => 'ERROR', 'error' => 'Erro ao consultar ranking.']);
    $conexao->close();
    exit;
}

$ranking = [];
$posicao = 1;
$pontosAnterior = null;
$createdAtAnterior = null;
$indice = 0;

while ($row = $result->fetch_assoc()) {
    $indice++;
    $pontos = (int) $row['pontos'];
    $createdAt = $row['created_at'];

    if ($pontosAnterior === null || $pontos !== $pontosAnterior || $createdAt !== $createdAtAnterior) {
        $posicao = $indice;
    }

    $ranking[] = [
        'posicao' => $posicao,
        'id' => (int) $row['id'],
        'nome' => $row['nome'],
        'matricula' => $row['matricula'],
        'pontos' => $pontos,
        'created_at' => $createdAt,
    ];

    $pontosAnterior = $pontos;
    $createdAtAnterior = $createdAt;
}

$result->free();
$conexao->close();

echo json_encode([
    'STATUS' => 'SUCCESS',
    'ranking' => $ranking,
], JSON_UNESCAPED_UNICODE);
