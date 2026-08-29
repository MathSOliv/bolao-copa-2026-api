<?php

declare(strict_types=1);

/**
 * Extrai o token Bearer do header, query string ou corpo JSON.
 */
function wcExtrairToken(?array $input = null): ?string
{
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

    if (preg_match('/^Bearer\s+(\S+)$/i', $authHeader, $matches)) {
        return $matches[1];
    }

    $tokenQuery = trim((string) ($_GET['token'] ?? ''));

    if ($tokenQuery !== '') {
        return $tokenQuery;
    }

    if ($input !== null) {
        $tokenBody = trim((string) ($input['token'] ?? ''));

        if ($tokenBody !== '') {
            return $tokenBody;
        }
    }

    return null;
}

/**
 * Valida o token simples gerado no login e retorna os dados do usuário.
 */
function wcAutenticarPorToken(mysqli $conexao, ?string $token): ?array
{
    if ($token === null || $token === '') {
        return null;
    }

    $decoded = base64_decode($token, true);

    if ($decoded === false) {
        return null;
    }

    $partes = explode(':', $decoded, 3);

    if (count($partes) !== 3 || !ctype_digit($partes[0])) {
        return null;
    }

    $usuarioId = (int) $partes[0];
    $matricula = $partes[1];

    $stmt = $conexao->prepare(
        'SELECT id, nome, matricula, email FROM wc_usuarios WHERE id = ? AND matricula = ? LIMIT 1'
    );
    $stmt->bind_param('is', $usuarioId, $matricula);
    $stmt->execute();
    $result = $stmt->get_result();
    $usuario = $result->fetch_assoc();
    $stmt->close();

    return $usuario ?: null;
}

function wcConfigurarCors(array $metodosPermitidos = ['GET', 'POST', 'OPTIONS']): void
{
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

    header('Access-Control-Allow-Methods: ' . implode(', ', $metodosPermitidos));
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
}
