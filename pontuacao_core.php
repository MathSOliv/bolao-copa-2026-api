<?php

declare(strict_types=1);

/**
 * wc_backend/pontuacao_core.php
 *
 * Núcleo reaproveitável do cálculo de pontos. Processa partidas encerradas
 * que ainda não foram pontuadas (encerrada = 1, status = 0) e atualiza
 * status + pontos em wc_palpites, marcando wc_partidas.status = 1.
 *
 * Usado por calcular_pontos.php (script CLI/navegador) e admin_partidas.php.
 *
 * Regras:
 *   PLACAR EXATO  → 15 pts
 *   VENCEDOR      →  5 pts
 *   EMPATE        →  5 pts
 *   ERRADO        →  0 pts
 */

require_once __DIR__ . '/palpite_pontuacao_helper.php';

/**
 * Processa todas as partidas pendentes de pontuação.
 *
 * @return array{partidas_processadas: int, palpites_atualizados: int, detalhes: array}
 *
 * @throws RuntimeException em caso de falha de banco.
 */
function wcProcessarPontuacao(mysqli $conexao): array
{
    $stmtPartidas = $conexao->prepare(
        'SELECT
            p.id,
            p.gols_casa,
            p.gols_fora,
            p.fase,
            p.classificado,
            c.nome AS casa_nome,
            f.nome AS fora_nome
         FROM wc_partidas p
         INNER JOIN wc_selecoes c ON c.id = p.time_casa
         INNER JOIN wc_selecoes f ON f.id = p.time_fora
         WHERE p.encerrada = 1
           AND p.status = 0
           AND p.gols_casa IS NOT NULL
           AND p.gols_fora IS NOT NULL
         ORDER BY p.data_partida ASC'
    );

    if ($stmtPartidas === false) {
        throw new RuntimeException('Erro ao consultar partidas pendentes.');
    }

    $stmtPartidas->execute();
    $resultPartidas = $stmtPartidas->get_result();

    $partidasPendentes = [];
    while ($row = $resultPartidas->fetch_assoc()) {
        $partidasPendentes[] = $row;
    }
    $stmtPartidas->close();

    if ($partidasPendentes === []) {
        return [
            'partidas_processadas' => 0,
            'palpites_atualizados' => 0,
            'detalhes' => [],
        ];
    }

    $stmtPalpites = $conexao->prepare(
        'SELECT id, gols_casa, gols_fora, classificado
         FROM wc_palpites
         WHERE partida = ?'
    );

    $stmtAtualizarPalpite = $conexao->prepare(
        'UPDATE wc_palpites
         SET status = ?, pontos = ?
         WHERE id = ?'
    );

    $stmtMarcarPartida = $conexao->prepare(
        'UPDATE wc_partidas
         SET status = 1
         WHERE id = ? AND encerrada = 1 AND status = 0'
    );

    if ($stmtPalpites === false || $stmtAtualizarPalpite === false || $stmtMarcarPartida === false) {
        throw new RuntimeException('Erro ao preparar consultas de pontuação.');
    }

    $detalhes = [];
    $totalPalpites = 0;

    foreach ($partidasPendentes as $partida) {
        $partidaId = (int) $partida['id'];
        $golsCasa = (int) $partida['gols_casa'];
        $golsFora = (int) $partida['gols_fora'];
        $fase = (string) ($partida['fase'] ?? 'grupos');
        $ehMataMata = $fase !== '' && $fase !== 'grupos';
        $classificadoReal = $partida['classificado'] !== null ? (int) $partida['classificado'] : null;

        $conexao->begin_transaction();

        try {
            $stmtPalpites->bind_param('i', $partidaId);
            $stmtPalpites->execute();
            $resultPalpites = $stmtPalpites->get_result();

            $resumoPartida = [
                'placar_exato' => 0,
                'vencedor' => 0,
                'empate' => 0,
                'classificado' => 0,
                'errado' => 0,
            ];
            $palpitesPartida = 0;

            while ($palpite = $resultPalpites->fetch_assoc()) {
                if ($ehMataMata) {
                    $avaliacao = wcAvaliarPalpiteMataMata(
                        (int) $palpite['gols_casa'],
                        (int) $palpite['gols_fora'],
                        $palpite['classificado'] !== null ? (int) $palpite['classificado'] : null,
                        $golsCasa,
                        $golsFora,
                        $classificadoReal
                    );
                } else {
                    $avaliacao = wcAvaliarPalpite(
                        (int) $palpite['gols_casa'],
                        (int) $palpite['gols_fora'],
                        $golsCasa,
                        $golsFora
                    );
                }

                $palpiteId = (int) $palpite['id'];
                $statusPalpite = $avaliacao['status'];
                $pontosPalpite = $avaliacao['pontos'];

                $stmtAtualizarPalpite->bind_param('sii', $statusPalpite, $pontosPalpite, $palpiteId);

                if (!$stmtAtualizarPalpite->execute()) {
                    throw new RuntimeException('Erro ao atualizar palpite ' . $palpiteId . ': ' . $stmtAtualizarPalpite->error);
                }

                $palpitesPartida++;
                $totalPalpites++;

                if ($statusPalpite === 'PLACAR EXATO + CLASSIFICADO') {
                    $resumoPartida['placar_exato']++;
                    $resumoPartida['classificado']++;
                } elseif ($statusPalpite === 'PLACAR EXATO') {
                    $resumoPartida['placar_exato']++;
                } elseif ($statusPalpite === 'CLASSIFICADO') {
                    $resumoPartida['classificado']++;
                } elseif ($statusPalpite === 'VENCEDOR') {
                    $resumoPartida['vencedor']++;
                } elseif ($statusPalpite === 'EMPATE') {
                    $resumoPartida['empate']++;
                } else {
                    $resumoPartida['errado']++;
                }
            }

            $resultPalpites->free();

            $stmtMarcarPartida->bind_param('i', $partidaId);

            if (!$stmtMarcarPartida->execute()) {
                throw new RuntimeException('Erro ao marcar partida ' . $partidaId . ' como processada: ' . $stmtMarcarPartida->error);
            }

            if ($stmtMarcarPartida->affected_rows !== 1) {
                throw new RuntimeException('Partida ' . $partidaId . ' não pôde ser marcada como processada.');
            }

            $conexao->commit();

            $detalhes[] = [
                'partida_id' => $partidaId,
                'confronto' => $partida['casa_nome'] . ' x ' . $partida['fora_nome'],
                'placar' => $golsCasa . 'x' . $golsFora,
                'palpites_atualizados' => $palpitesPartida,
                'resumo' => $resumoPartida,
            ];
        } catch (Throwable $e) {
            $conexao->rollback();
            $stmtPalpites->close();
            $stmtAtualizarPalpite->close();
            $stmtMarcarPartida->close();
            throw $e;
        }
    }

    $stmtPalpites->close();
    $stmtAtualizarPalpite->close();
    $stmtMarcarPartida->close();

    return [
        'partidas_processadas' => count($detalhes),
        'palpites_atualizados' => $totalPalpites,
        'detalhes' => $detalhes,
    ];
}
