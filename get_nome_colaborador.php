<?php

/**
 * wc_backend/get_nome_colaborador.php
 *
 * Busca o nome completo de um colaborador no Protheus (SRA010) a partir
 * da matrícula. Usado pela tela de cadastro do bolão da Copa: quando o
 * usuário digita a matrícula, o front exibe automaticamente o nome.
 *
 * Entrada (GET):
 *   /Painel/wc_backend/get_nome_colaborador.php?matricula=001234
 *
 * Saída (200 OK):
 *   { "STATUS": "SUCCESS", "MATRICULA": "001234", "NOME": "JOÃO DA SILVA" }
 *
 * Saída (404):
 *   { "STATUS": "NO_RESULT", "error": "Matrícula não encontrada." }
 */

header('Content-Type: application/json; charset=utf-8');

$allowedOrigins = [
    'http://localhost:3000',
    'http://localhost:5173',
    'http://localhost:5174',
    'https://sistemaintegrado.palmont.com.br',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');
}
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

include('../assets/php/connect_totvs.php');

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
        'details' => sqlsrv_errors(),
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
