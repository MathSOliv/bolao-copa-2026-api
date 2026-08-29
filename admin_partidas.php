<?php

/**
 * wc_backend/admin_partidas.php
 *
 * Painel administrativo de resultados (restrito às matrículas em
 * WC_ADMIN_MATRICULAS). Permite definir/zerar o placar de uma partida e
 * disparar o recálculo de pontos dos palpites.
 *
 * Entrada (POST JSON + Authorization: Bearer {token}):
 *
 *   { "acao": "salvar_placar", "partida_id": 6, "gols_casa": 2, "gols_fora": 1 }
 *   { "acao": "salvar_placar", "partida_id": 89, "gols_casa": 1, "gols_fora": 1, "classificado": 10 }
 *   { "acao": "zerar_placar", "partida_id": 6 }
 *   { "acao": "definir_selecoes", "partida_id": 89, "time_casa": 10, "time_fora": 4 }
 *   { "acao": "calcular_pontos" }
 *
 * Saída (200): { "STATUS": "SUCCESS", ... }
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/admin_helper.php';
require_once __DIR__ . '/pontuacao_core.php';

wcConfigurarCors(['POST', 'OPTIONS']);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$token = wcExtrairToken($input);
$usuario = wcAutenticarPorToken($conexao, $token);

if (!$usuario) {
    http_response_code(401);
    echo json_encode(['STATUS' => 'ERROR', 'error' => 'Sessão inválida ou expirada.']);
    $conexao->close();
    exit;
}

if (!wcUsuarioEhAdmin($usuario)) {
    http_response_code(403);
    echo json_encode(['STATUS' => 'ERROR', 'error' => 'Acesso restrito a administradores.']);
    $conexao->close();
    exit;
}

$acao = trim((string) ($input['acao'] ?? ''));

switch ($acao) {
    case 'salvar_placar':
        adminSalvarPlacar($conexao, $input);
        break;

    case 'zerar_placar':
        adminZerarPlacar($conexao, $input);
        break;

    case 'definir_selecoes':
        adminDefinirSelecoes($conexao, $input);
        break;

    case 'calcular_pontos':
        adminCalcularPontos($conexao);
        break;

    default:
        http_response_code(422);
        echo json_encode(['STATUS' => 'ERROR', 'error' => 'Ação inválida.']);
        $conexao->close();
        exit;
}

/**
 * Confere se a partida existe e devolve o registro; encerra a requisição se não.
 */
function adminObterPartida(mysqli $conexao, int $partidaId): array
{
    $stmt = $conexao->prepare(
        'SELECT id, gols_casa, gols_fora, encerrada, status, fase, time_casa, time_fora, classificado
         FROM wc_partidas WHERE id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $partidaId);
    $stmt->execute();
    $partida = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$partida) {
        http_response_code(404);
        echo json_encode(['STATUS' => 'ERROR', 'error' => 'Partida não encontrada.']);
        $conexao->close();
        exit;
    }

    return $partida;
}

/**
 * Indica se a partida pertence ao mata-mata (qualquer fase diferente de grupos).
 */
function adminEhMataMata(array $partida): bool
{
    $fase = (string) ($partida['fase'] ?? 'grupos');
    return $fase !== '' && $fase !== 'grupos';
}

/**
 * Recalcula as vagas das partidas que dependem do resultado da partida informada.
 *
 * Se a partida de origem estiver encerrada e com classificado definido, preenche
 * a vaga de destino (vencedor ou perdedor, conforme o tipo). Caso contrário, a
 * vaga volta a ficar indefinida. Sempre que a seleção de uma vaga muda, o
 * resultado e os palpites da partida de destino são descartados, e a mudança é
 * propagada recursivamente para as fases seguintes.
 */
function adminRecalcularVagasDestino(mysqli $conexao, int $origemId): void
{
    $stmt = $conexao->prepare(
        'SELECT time_casa, time_fora, encerrada, classificado FROM wc_partidas WHERE id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $origemId);
    $stmt->execute();
    $origem = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$origem) {
        return;
    }

    $vencedor = null;
    $perdedor = null;

    if ((int) $origem['encerrada'] === 1 && $origem['classificado'] !== null) {
        $vencedor = (int) $origem['classificado'];
        $timeCasa = $origem['time_casa'] !== null ? (int) $origem['time_casa'] : null;
        $timeFora = $origem['time_fora'] !== null ? (int) $origem['time_fora'] : null;
        $perdedor = ($vencedor === $timeCasa) ? $timeFora : $timeCasa;
    }

    foreach (['casa', 'fora'] as $slot) {
        $colPartida = "origem_{$slot}_partida";
        $colTipo = "origem_{$slot}_tipo";
        $colTime = "time_{$slot}";

        $stmtDest = $conexao->prepare(
            "SELECT id, {$colTipo} AS tipo, {$colTime} AS atual FROM wc_partidas WHERE {$colPartida} = ?"
        );
        $stmtDest->bind_param('i', $origemId);
        $stmtDest->execute();
        $destinos = $stmtDest->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmtDest->close();

        foreach ($destinos as $destino) {
            $novo = ($destino['tipo'] === 'perdedor') ? $perdedor : $vencedor;
            $atualNorm = $destino['atual'] !== null ? (int) $destino['atual'] : 0;
            $novoNorm = $novo ?? 0;

            if ($atualNorm === $novoNorm) {
                continue;
            }

            $destinoId = (int) $destino['id'];

            $stmtUp = $conexao->prepare(
                "UPDATE wc_partidas
                 SET {$colTime} = ?, gols_casa = NULL, gols_fora = NULL, encerrada = 0, status = 0, classificado = NULL
                 WHERE id = ?"
            );
            $stmtUp->bind_param('ii', $novo, $destinoId);
            $stmtUp->execute();
            $stmtUp->close();

            // A combinação de seleções mudou: palpites anteriores deixam de valer.
            $stmtDel = $conexao->prepare('DELETE FROM wc_palpites WHERE partida = ?');
            $stmtDel->bind_param('i', $destinoId);
            $stmtDel->execute();
            $stmtDel->close();

            adminRecalcularVagasDestino($conexao, $destinoId);
        }
    }
}

/**
 * Restaura os palpites de uma partida ao estado pendente (status e pontos zerados).
 */
function adminResetarPalpites(mysqli $conexao, int $partidaId): int
{
    $stmt = $conexao->prepare(
        "UPDATE wc_palpites SET status = 'pendente', pontos = 0 WHERE partida = ?"
    );
    $stmt->bind_param('i', $partidaId);
    $stmt->execute();
    $afetados = $stmt->affected_rows;
    $stmt->close();

    return $afetados;
}

function adminSalvarPlacar(mysqli $conexao, array $input): void
{
    $partidaId = filter_var($input['partida_id'] ?? null, FILTER_VALIDATE_INT);
    $golsCasa = filter_var($input['gols_casa'] ?? null, FILTER_VALIDATE_INT);
    $golsFora = filter_var($input['gols_fora'] ?? null, FILTER_VALIDATE_INT);

    if ($partidaId === false || $partidaId <= 0) {
        http_response_code(422);
        echo json_encode(['STATUS' => 'ERROR', 'error' => 'Partida inválida.']);
        $conexao->close();
        exit;
    }

    if ($golsCasa === false || $golsFora === false) {
        http_response_code(422);
        echo json_encode(['STATUS' => 'ERROR', 'error' => 'Informe os gols dos dois times.']);
        $conexao->close();
        exit;
    }

    if ($golsCasa < 0 || $golsCasa > 99 || $golsFora < 0 || $golsFora > 99) {
        http_response_code(422);
        echo json_encode(['STATUS' => 'ERROR', 'error' => 'Os gols devem estar entre 0 e 99.']);
        $conexao->close();
        exit;
    }

    $partida = adminObterPartida($conexao, $partidaId);

    $ehMataMata = adminEhMataMata($partida);
    $classificado = null;

    if ($ehMataMata) {
        $timeCasa = $partida['time_casa'] !== null ? (int) $partida['time_casa'] : null;
        $timeFora = $partida['time_fora'] !== null ? (int) $partida['time_fora'] : null;

        if ($timeCasa === null || $timeFora === null) {
            http_response_code(409);
            echo json_encode(['STATUS' => 'ERROR', 'error' => 'Defina as duas seleções do confronto antes de lançar o placar.']);
            $conexao->close();
            exit;
        }

        $classificadoInput = array_key_exists('classificado', $input)
            ? filter_var($input['classificado'], FILTER_VALIDATE_INT)
            : null;

        if ($classificadoInput !== null && $classificadoInput !== false) {
            if ($classificadoInput !== $timeCasa && $classificadoInput !== $timeFora) {
                http_response_code(422);
                echo json_encode(['STATUS' => 'ERROR', 'error' => 'A seleção que avança deve ser uma das duas do confronto.']);
                $conexao->close();
                exit;
            }
            $classificado = $classificadoInput;
        } elseif ($golsCasa !== $golsFora) {
            // Placar decisivo: o vencedor avança automaticamente.
            $classificado = $golsCasa > $golsFora ? $timeCasa : $timeFora;
        } else {
            http_response_code(422);
            echo json_encode(['STATUS' => 'ERROR', 'error' => 'Empate no tempo normal: informe qual seleção avançou (pênaltis).']);
            $conexao->close();
            exit;
        }
    }

    // Define o placar, marca como encerrada e reabre para pontuação (status = 0).
    // Os palpites voltam a pendente: os pontos só são atribuídos ao rodar o cálculo.
    $stmt = $conexao->prepare(
        'UPDATE wc_partidas
         SET gols_casa = ?, gols_fora = ?, classificado = ?, encerrada = 1, status = 0
         WHERE id = ?'
    );
    $stmt->bind_param('iiii', $golsCasa, $golsFora, $classificado, $partidaId);

    if (!$stmt->execute()) {
        $stmt->close();
        http_response_code(500);
        echo json_encode(['STATUS' => 'ERROR', 'error' => 'Erro ao salvar o placar.']);
        $conexao->close();
        exit;
    }
    $stmt->close();

    $palpitesResetados = adminResetarPalpites($conexao, $partidaId);

    if ($ehMataMata) {
        adminRecalcularVagasDestino($conexao, $partidaId);
    }

    $conexao->close();

    echo json_encode([
        'STATUS' => 'SUCCESS',
        'message' => 'Placar salvo. Rode o cálculo de pontos para atualizar o ranking.',
        'partida' => [
            'id' => $partidaId,
            'gols_casa' => $golsCasa,
            'gols_fora' => $golsFora,
            'classificado' => $classificado,
            'encerrada' => 1,
            'status' => 0,
        ],
        'palpites_resetados' => $palpitesResetados,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function adminZerarPlacar(mysqli $conexao, array $input): void
{
    $partidaId = filter_var($input['partida_id'] ?? null, FILTER_VALIDATE_INT);

    if ($partidaId === false || $partidaId <= 0) {
        http_response_code(422);
        echo json_encode(['STATUS' => 'ERROR', 'error' => 'Partida inválida.']);
        $conexao->close();
        exit;
    }

    $partida = adminObterPartida($conexao, $partidaId);
    $ehMataMata = adminEhMataMata($partida);

    // Remove o resultado: gols nulos, classificado limpo, partida reaberta e fora da fila de pontuação.
    $stmt = $conexao->prepare(
        'UPDATE wc_partidas
         SET gols_casa = NULL, gols_fora = NULL, classificado = NULL, encerrada = 0, status = 0
         WHERE id = ?'
    );
    $stmt->bind_param('i', $partidaId);

    if (!$stmt->execute()) {
        $stmt->close();
        http_response_code(500);
        echo json_encode(['STATUS' => 'ERROR', 'error' => 'Erro ao zerar o placar.']);
        $conexao->close();
        exit;
    }
    $stmt->close();

    $palpitesResetados = adminResetarPalpites($conexao, $partidaId);

    if ($ehMataMata) {
        // O resultado sumiu: as vagas seguintes que dependiam dele voltam a indefinidas.
        adminRecalcularVagasDestino($conexao, $partidaId);
    }

    $conexao->close();

    echo json_encode([
        'STATUS' => 'SUCCESS',
        'message' => 'Placar zerado e palpites desta partida voltaram a pendente.',
        'partida' => [
            'id' => $partidaId,
            'gols_casa' => null,
            'gols_fora' => null,
            'classificado' => null,
            'encerrada' => 0,
            'status' => 0,
        ],
        'palpites_resetados' => $palpitesResetados,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Confere se uma seleção existe. Retorna true para valores nulos (vaga indefinida).
 */
function adminSelecaoExiste(mysqli $conexao, ?int $selecaoId): bool
{
    if ($selecaoId === null) {
        return true;
    }

    $stmt = $conexao->prepare('SELECT 1 FROM wc_selecoes WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $selecaoId);
    $stmt->execute();
    $existe = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();

    return $existe;
}

/**
 * Define manualmente as seleções de um confronto do mata-mata (usado para
 * preencher os 16-avos a partir dos classificados dos grupos). Quando a dupla
 * muda, o resultado e os palpites do confronto são descartados e a alteração
 * é propagada para as fases seguintes.
 */
function adminDefinirSelecoes(mysqli $conexao, array $input): void
{
    $partidaId = filter_var($input['partida_id'] ?? null, FILTER_VALIDATE_INT);

    if ($partidaId === false || $partidaId <= 0) {
        http_response_code(422);
        echo json_encode(['STATUS' => 'ERROR', 'error' => 'Partida inválida.']);
        $conexao->close();
        exit;
    }

    $timeCasa = adminLerSelecaoOpcional($input, 'time_casa');
    $timeFora = adminLerSelecaoOpcional($input, 'time_fora');

    if ($timeCasa === false || $timeFora === false) {
        http_response_code(422);
        echo json_encode(['STATUS' => 'ERROR', 'error' => 'Seleção inválida.']);
        $conexao->close();
        exit;
    }

    if ($timeCasa !== null && $timeFora !== null && $timeCasa === $timeFora) {
        http_response_code(422);
        echo json_encode(['STATUS' => 'ERROR', 'error' => 'As duas seleções do confronto devem ser diferentes.']);
        $conexao->close();
        exit;
    }

    $partida = adminObterPartida($conexao, $partidaId);

    if (!adminEhMataMata($partida)) {
        http_response_code(422);
        echo json_encode(['STATUS' => 'ERROR', 'error' => 'Só é possível definir seleções em partidas do mata-mata.']);
        $conexao->close();
        exit;
    }

    if (!adminSelecaoExiste($conexao, $timeCasa) || !adminSelecaoExiste($conexao, $timeFora)) {
        http_response_code(422);
        echo json_encode(['STATUS' => 'ERROR', 'error' => 'Seleção não encontrada.']);
        $conexao->close();
        exit;
    }

    // Trocar a dupla invalida resultado e palpites já feitos para o confronto.
    $stmt = $conexao->prepare(
        'UPDATE wc_partidas
         SET time_casa = ?, time_fora = ?, gols_casa = NULL, gols_fora = NULL, classificado = NULL, encerrada = 0, status = 0
         WHERE id = ?'
    );
    $stmt->bind_param('iii', $timeCasa, $timeFora, $partidaId);

    if (!$stmt->execute()) {
        $stmt->close();
        http_response_code(500);
        echo json_encode(['STATUS' => 'ERROR', 'error' => 'Erro ao definir as seleções.']);
        $conexao->close();
        exit;
    }
    $stmt->close();

    $stmtDel = $conexao->prepare('DELETE FROM wc_palpites WHERE partida = ?');
    $stmtDel->bind_param('i', $partidaId);
    $stmtDel->execute();
    $stmtDel->close();

    adminRecalcularVagasDestino($conexao, $partidaId);

    $conexao->close();

    echo json_encode([
        'STATUS' => 'SUCCESS',
        'message' => 'Confronto atualizado.',
        'partida' => [
            'id' => $partidaId,
            'time_casa' => $timeCasa,
            'time_fora' => $timeFora,
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Lê uma seleção opcional do input: retorna null para vazio, int válido, ou
 * false quando o valor enviado é inválido.
 *
 * @return int|null|false
 */
function adminLerSelecaoOpcional(array $input, string $campo)
{
    if (!array_key_exists($campo, $input)) {
        return null;
    }

    $valor = $input[$campo];

    if ($valor === null || $valor === '' || $valor === 0 || $valor === '0') {
        return null;
    }

    $id = filter_var($valor, FILTER_VALIDATE_INT);

    if ($id === false || $id <= 0) {
        return false;
    }

    return $id;
}

function adminCalcularPontos(mysqli $conexao): void
{
    try {
        $resultado = wcProcessarPontuacao($conexao);
    } catch (Throwable $e) {
        $conexao->close();
        http_response_code(500);
        echo json_encode(['STATUS' => 'ERROR', 'error' => $e->getMessage()]);
        exit;
    }

    $conexao->close();

    $mensagem = $resultado['partidas_processadas'] === 0
        ? 'Nenhuma partida pendente de pontuação.'
        : 'Pontuação processada com sucesso.';

    echo json_encode([
        'STATUS' => 'SUCCESS',
        'message' => $mensagem,
        'partidas_processadas' => $resultado['partidas_processadas'],
        'palpites_atualizados' => $resultado['palpites_atualizados'],
        'detalhes' => $resultado['detalhes'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
