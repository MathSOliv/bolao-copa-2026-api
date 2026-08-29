<?php

/**
 * wc_backend/cadastro.php
 *
 * Cadastro de colaborador no bolão da Copa.
 *
 * Fluxo:
 *   1. Valida os campos recebidos (matrícula, e-mail, senha).
 *   2. Confirma que a matrícula existe no Protheus (SRA010) e usa o
 *      nome oficial (TRIM(RA_NOMECMP)) — o nome NÃO é confiado ao cliente.
 *   3. Garante que a matrícula ainda não foi cadastrada em wc_usuarios.
 *   4. Salva a senha com hash (password_hash / bcrypt).
 *
 * Entrada (POST JSON):
 *   { "matricula": "001234", "email": "fulano@exemplo.com", "senha": "..." }
 *
 * Saída (201):
 *   { "STATUS": "SUCCESS", "user": { "id": 1, "nome": "...", "matricula": "001234", "email": "..." } }
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
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['STATUS' => 'ERROR', 'error' => 'Método não permitido.']);
    exit;
}

include('../assets/php/connect_totvs.php'); // $conn  (Protheus / SQL Server)
include('../assets/php/conexao.php');       // $conexao (MySQL / mysqli)

$conexao->set_charset('utf8mb4');

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$matricula = trim((string) ($input['matricula'] ?? ''));
$email = trim((string) ($input['email'] ?? ''));
$senha = (string) ($input['senha'] ?? '');

if ($matricula === '' || $email === '' || $senha === '') {
    http_response_code(400);
    echo json_encode(['STATUS' => 'ERROR', 'error' => 'Preencha matrícula, e-mail e senha.']);
    exit;
}

if (!preg_match('/^\d{1,6}$/', $matricula)) {
    http_response_code(422);
    echo json_encode(['STATUS' => 'ERROR', 'error' => 'Matrícula inválida.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['STATUS' => 'ERROR', 'error' => 'E-mail inválido.']);
    exit;
}

if (strlen($senha) < 4) {
    http_response_code(422);
    echo json_encode(['STATUS' => 'ERROR', 'error' => 'A senha deve ter ao menos 4 caracteres.']);
    exit;
}

/* 1. Confirma a matrícula no Protheus e obtém o nome oficial. */
$sqlNome = "SELECT TOP 1
                TRIM(RA_NOMECMP) AS NOME
            FROM SRA010
            WHERE D_E_L_E_T_ <> '*'
              AND TRIM(RA_MAT) = ?";

$stmtNome = sqlsrv_query($conn, $sqlNome, [$matricula]);

if ($stmtNome === false) {
    http_response_code(500);
    echo json_encode([
        'STATUS' => 'ERROR',
        'error' => 'Erro ao consultar colaborador.',
    ]);
    sqlsrv_close($conn);
    exit;
}

$colab = sqlsrv_fetch_object($stmtNome);

if (!$colab) {
    http_response_code(404);
    echo json_encode(['STATUS' => 'NO_RESULT', 'error' => 'Matrícula não encontrada.'], JSON_UNESCAPED_UNICODE);
    sqlsrv_close($conn);
    exit;
}

$nome = $colab->NOME;
sqlsrv_close($conn);

/* 2. Impede cadastro duplicado da mesma matrícula. */
$check = $conexao->prepare('SELECT id FROM wc_usuarios WHERE matricula = ? LIMIT 1');
$check->bind_param('s', $matricula);
$check->execute();
$check->store_result();

if ($check->num_rows > 0) {
    http_response_code(409);
    echo json_encode(['STATUS' => 'EXISTS', 'error' => 'Esta matrícula já possui cadastro.']);
    $check->close();
    $conexao->close();
    exit;
}
$check->close();

/* 3. Insere o usuário com a senha protegida por hash. */
$hash = password_hash($senha, PASSWORD_DEFAULT);

$insert = $conexao->prepare(
    'INSERT INTO wc_usuarios (nome, matricula, email, senha, created_at) VALUES (?, ?, ?, ?, NOW())'
);
$insert->bind_param('ssss', $nome, $matricula, $email, $hash);

if (!$insert->execute()) {
    http_response_code(500);
    echo json_encode(['STATUS' => 'ERROR', 'error' => 'Erro ao cadastrar usuário.']);
    $insert->close();
    $conexao->close();
    exit;
}

$novoId = $insert->insert_id;
$insert->close();
$conexao->close();

http_response_code(201);
echo json_encode([
    'STATUS' => 'SUCCESS',
    'user' => [
        'id' => $novoId,
        'nome' => $nome,
        'matricula' => $matricula,
        'email' => $email,
    ],
], JSON_UNESCAPED_UNICODE);
