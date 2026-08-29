<?php

/**
 * wc_backend/get_comparacao_palpites.php
 *
 * Compara os palpites do usuário autenticado com os de outro usuário.
 * Os palpites do usuário logado retornam para todas as partidas.
 * Os palpites do outro usuário só são expostos em jogos fechados
 * (20 minutos antes do início, horário de Brasília).
 *
 * Entrada (GET):
 *   /Painel/wc_backend/get_comparacao_palpites.php?usuario_id=2&token=...
 */

declare(strict_types=1);

const WC_MINUTOS_ANTECEDENCIA_PALPITE = 20;
const WC_FUSO_BRASILIA = 'America/Sao_Paulo';

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
$usuarioComparadoId = filter_var($_GET['usuario_id'] ?? null, FILTER_VALIDATE_INT);

if ($usuarioComparadoId === false || $usuarioComparadoId <= 0) {
    http_response_code(422);
    echo json_encode(['STATUS' => 'ERROR', 'error' => 'Usuário inválido.']);
    $conexao->close();
    exit;
}

if ($usuarioComparadoId === $usuarioId) {
    http_response_code(422);
    echo json_encode(['STATUS' => 'ERROR', 'error' => 'Selecione outro usuário para comparar.']);
    $conexao->close();
    exit;
}

$stmtUsuario = $conexao->prepare(
    'SELECT id, nome FROM wc_usuarios WHERE id = ? LIMIT 1'
);
$stmtUsuario->bind_param('i', $usuarioComparadoId);
$stmtUsuario->execute();
$usuarioComparado = $stmtUsuario->get_result()->fetch_assoc();
$stmtUsuario->close();

if (!$usuarioComparado) {
    http_response_code(404);
    echo json_encode(['STATUS' => 'ERROR', 'error' => 'Usuário não encontrado.']);
    $conexao->close();
    exit;
}

$fusoBrasilia = new DateTimeZone(WC_FUSO_BRASILIA);
$agora = new DateTimeImmutable('now', $fusoBrasilia);

$stmt = $conexao->prepare(
    'SELECT
        p.id,
        p.data_partida,
        p.encerrada,
        c.nome AS casa_nome,
        c.sigla AS casa_sigla,
        c.bandeira AS casa_bandeira,
        f.nome AS fora_nome,
        f.sigla AS fora_sigla,
        f.bandeira AS fora_bandeira,
        meu.gols_casa AS meu_gols_casa,
        meu.gols_fora AS meu_gols_fora,
        outro.gols_casa AS outro_gols_casa,
        outro.gols_fora AS outro_gols_fora
     FROM wc_partidas p
     INNER JOIN wc_selecoes c ON c.id = p.time_casa
     INNER JOIN wc_selecoes f ON f.id = p.time_fora
     LEFT JOIN wc_palpites meu ON meu.partida = p.id AND meu.usuario = ?
     LEFT JOIN wc_palpites outro ON outro.partida = p.id AND outro.usuario = ?
     ORDER BY p.data_partida ASC'
);
$stmt->bind_param('ii', $usuarioId, $usuarioComparadoId);
$stmt->execute();
$result = $stmt->get_result();

$partidas = [];

while ($row = $result->fetch_assoc()) {
    $dataPartida = DateTimeImmutable::createFromFormat(
        'Y-m-d H:i:s',
        (string) $row['data_partida'],
        $fusoBrasilia
    );

    $jogoFechado = false;

    if ($dataPartida !== false) {
        $limitePalpite = $dataPartida->modify('-' . WC_MINUTOS_ANTECEDENCIA_PALPITE . ' minutes');
        $jogoFechado = $agora >= $limitePalpite;
    }

    $meuPalpite = null;

    if ($row['meu_gols_casa'] !== null && $row['meu_gols_fora'] !== null) {
        $meuPalpite = [
            'gols_casa' => (int) $row['meu_gols_casa'],
            'gols_fora' => (int) $row['meu_gols_fora'],
        ];
    }

    $palpiteComparado = null;

    if (
        $jogoFechado &&
        $row['outro_gols_casa'] !== null &&
        $row['outro_gols_fora'] !== null
    ) {
        $palpiteComparado = [
            'gols_casa' => (int) $row['outro_gols_casa'],
            'gols_fora' => (int) $row['outro_gols_fora'],
        ];
    }

    $partidas[] = [
        'id' => (int) $row['id'],
        'data_partida' => $row['data_partida'],
        'encerrada' => (int) $row['encerrada'],
        'jogo_fechado' => $jogoFechado,
        'mandante' => [
            'nome' => $row['casa_nome'],
            'sigla' => $row['casa_sigla'],
            'bandeira' => $row['casa_bandeira'],
        ],
        'visitante' => [
            'nome' => $row['fora_nome'],
            'sigla' => $row['fora_sigla'],
            'bandeira' => $row['fora_bandeira'],
        ],
        'meu_palpite' => $meuPalpite,
        'palpite_comparado' => $palpiteComparado,
    ];
}

$stmt->close();
$conexao->close();

echo json_encode([
    'STATUS' => 'SUCCESS',
    'usuario_comparado' => [
        'id' => (int) $usuarioComparado['id'],
        'nome' => $usuarioComparado['nome'],
    ],
    'partidas' => $partidas,
], JSON_UNESCAPED_UNICODE);
