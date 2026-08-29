<?php

declare(strict_types=1);

const WC_PONTOS_PLACAR_EXATO = 15;
const WC_PONTOS_RESULTADO = 5;
const WC_PONTOS_ERRADO = 0;

// Mata-mata: pontua apenas placar exato + acerto de quem se classifica (somam).
const WC_PONTOS_MM_PLACAR_EXATO = 30;
const WC_PONTOS_CLASSIFICADO = 20;

/**
 * Retorna 'casa', 'fora' ou 'empate' conforme o placar.
 */
function wcResultadoPartida(int $golsCasa, int $golsFora): string
{
    if ($golsCasa > $golsFora) {
        return 'casa';
    }

    if ($golsCasa < $golsFora) {
        return 'fora';
    }

    return 'empate';
}

/**
 * Avalia um palpite contra o placar real da partida.
 *
 * @return array{status: string, pontos: int}
 */
function wcAvaliarPalpite(int $palpiteCasa, int $palpiteFora, int $realCasa, int $realFora): array
{
    if ($palpiteCasa === $realCasa && $palpiteFora === $realFora) {
        return [
            'status' => 'PLACAR EXATO',
            'pontos' => WC_PONTOS_PLACAR_EXATO,
        ];
    }

    $palpiteRes = wcResultadoPartida($palpiteCasa, $palpiteFora);
    $realRes = wcResultadoPartida($realCasa, $realFora);

    if ($palpiteRes === $realRes) {
        return [
            'status' => $realRes === 'empate' ? 'EMPATE' : 'VENCEDOR',
            'pontos' => WC_PONTOS_RESULTADO,
        ];
    }

    return [
        'status' => 'ERRADO',
        'pontos' => WC_PONTOS_ERRADO,
    ];
}

/**
 * Avalia um palpite de mata-mata. A pontuação soma duas componentes
 * independentes: placar exato do tempo normal e acerto de quem se classifica
 * (que pode ter avançado nos pênaltis).
 *
 * @param int|null $palpiteClassificado seleção que o usuário acha que avança
 * @param int|null $realClassificado    seleção que realmente avançou
 *
 * @return array{status: string, pontos: int}
 */
function wcAvaliarPalpiteMataMata(
    int $palpiteCasa,
    int $palpiteFora,
    ?int $palpiteClassificado,
    int $realCasa,
    int $realFora,
    ?int $realClassificado
): array {
    $placarExato = ($palpiteCasa === $realCasa && $palpiteFora === $realFora);
    $acertouClassificado = (
        $palpiteClassificado !== null
        && $realClassificado !== null
        && $palpiteClassificado === $realClassificado
    );

    $pontos = 0;

    if ($placarExato) {
        $pontos += WC_PONTOS_MM_PLACAR_EXATO;
    }

    if ($acertouClassificado) {
        $pontos += WC_PONTOS_CLASSIFICADO;
    }

    if ($placarExato && $acertouClassificado) {
        $status = 'PLACAR EXATO + CLASSIFICADO';
    } elseif ($placarExato) {
        $status = 'PLACAR EXATO';
    } elseif ($acertouClassificado) {
        $status = 'CLASSIFICADO';
    } else {
        $status = 'ERRADO';
    }

    return [
        'status' => $status,
        'pontos' => $pontos,
    ];
}
