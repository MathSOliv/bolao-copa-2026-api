<?php

declare(strict_types=1);

/**
 * wc_backend/admin_helper.php
 *
 * Controle de acesso administrativo do bolão. As matrículas vêm de
 * WC_ADMIN_MATRICULAS no .env (separadas por vírgula).
 */

require_once __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

/**
 * @return list<string>
 */
function wcAdminMatriculas(): array
{
    $raw = (string) (getenv('WC_ADMIN_MATRICULAS') ?: '');
    $matriculas = array_map('trim', explode(',', $raw));

    return array_values(array_filter($matriculas, static fn (string $item): bool => $item !== ''));
}

/**
 * Indica se o usuário autenticado possui permissão administrativa.
 *
 * @param array|null $usuario Registro retornado por wcAutenticarPorToken().
 */
function wcUsuarioEhAdmin(?array $usuario): bool
{
    if (!$usuario) {
        return false;
    }

    $matricula = trim((string) ($usuario['matricula'] ?? ''));

    return in_array($matricula, wcAdminMatriculas(), true);
}