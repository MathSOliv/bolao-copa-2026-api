<?php

/**
 * wc_backend/login.php
 *
 * Autenticação do bolão da Copa. Recebe matrícula + senha, valida contra
 * wc_usuarios (senha verificada com password_verify) e devolve os dados
 * do usuário e um token simples para a sessão no front.
 *
 * Entrada (POST JSON):
 *   { "matricula": "001234", "senha": "..." }
 *
 * Saída (200):
 *   {
 *     "STATUS": "SUCCESS",
 *     "token": "...",
 *     "user": { "id": 1, "nome": "...", "matricula": "001234", "email": "..." }
 *   }
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

include('../assets/php/conexao.php'); // $conexao (MySQL / mysqli)

$conexao->set_charset('utf8mb4');

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$matricula = trim((string) ($input['matricula'] ?? ''));
$senha = (string) ($input['senha'] ?? '');

if ($matricula === '' || $senha === '') {
    http_response_code(400);
    echo json_encode(['STATUS' => 'ERROR', 'error' => 'Informe matrícula e senha.']);
    exit;
}

$stmt = $conexao->prepare(
    'SELECT id, nome, matricula, email, senha FROM wc_usuarios WHERE matricula = ? LIMIT 1'
);
$stmt->bind_param('s', $matricula);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

/* Mensagem genérica: não revela se foi a matrícula ou a senha que falhou. */
if (!$user || !password_verify($senha, $user['senha'])) {
    http_response_code(401);
    echo json_encode(['STATUS' => 'INVALID', 'error' => 'Matrícula ou senha incorreta.']);
    $conexao->close();
    exit;
}

$conexao->close();

$token = base64_encode($user['id'] . ':' . $user['matricula'] . ':' . time());

echo json_encode([
    'STATUS' => 'SUCCESS',
    'token' => $token,
    'user' => [
        'id' => (int) $user['id'],
        'nome' => $user['nome'],
        'matricula' => $user['matricula'],
        'email' => $user['email'],
    ],
], JSON_UNESCAPED_UNICODE);
