<?php

/**
 * wc_backend/get_nome_colaborador.php
 *
 * Busca o nome completo de um colaborador no Protheus (SRA010) a partir
 * da matrícula. Usado pela tela de cadastro do bolão da Copa: quando o
 * usuário digita a matrícula, o front exibe automaticamente o nome.
 *
 * Entrada (GET + Authorization: Bearer {token}):
 *   /Painel/wc_backend/get_nome_colaborador.php?matricula=001234
 *   /Painel/wc_backend/get_nome_colaborador.php?matricula=001234&token=...
 *
 * Saída (200 OK):
 *   { "STATUS": "SUCCESS", "MATRICULA": "001234", "NOME": "JOÃO DA SILVA" }
 *
 * Saída (404):
 *   { "STATUS": "NO_RESULT", "error": "Matrícula não encontrada." }
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

$conexao->close();

include __DIR__ . '/../assets/php/connect_totvs.php';

$matricula = isset($_GET['matricula']) ? trim((string) $_GET['matricula']) : '';

if ($matricula === '' || !preg_match('/^\d{1,6}$/', $matricula)) {
    http_response_code(422);
    echo json_encode(['STATUS' => 'INVALID', 'error' => 'Matrícula inválida.']);
    exit;
}

$sql = "SELECT TOP 1
            TRIM(RA_MAT)     AS MATRICULA,
            TRIM(RA_NOMECMP) AS NOME
        FROM SRA010
        WHERE D_E_L_E_T_ <> '*'
          AND TRIM(RA_MAT) = ?";

$stmt = sqlsrv_query($conn, $sql, [$matricula]);

if ($stmt === false) {
    http_response_code(500);
    echo json_encode([
        'STATUS' => 'ERROR',
        'error' => 'Erro ao consultar colaborador.',
    ]);
    sqlsrv_close($conn);
    exit;
}

$colab = sqlsrv_fetch_object($stmt);

if (!$colab) {
    http_response_code(404);
    echo json_encode([
        'STATUS' => 'NO_RESULT',
        'error' => 'Matrícula não encontrada.',
    ], JSON_UNESCAPED_UNICODE);
    sqlsrv_close($conn);
    exit;
}

echo json_encode([
    'STATUS' => 'SUCCESS',
    'MATRICULA' => trim((string) $colab->MATRICULA),
    'NOME' => $colab->NOME,
], JSON_UNESCAPED_UNICODE);

sqlsrv_close($conn);