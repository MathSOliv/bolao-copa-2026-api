<?php

declare(strict_types=1);

const WC_FUSO_BRASILIA_HORARIO = 'America/Sao_Paulo';

/**
 * Verifica se o horário atual em Brasília está em um período bloqueado para palpites.
 * Bloqueado: 7h30–12h e 13h–17h30, de segunda a sábado.
 * Aos domingos os palpites ficam liberados o dia todo.
 */
function wcHorarioPalpiteBloqueado(?DateTimeImmutable $agora = null): bool
{
    $agora ??= new DateTimeImmutable('now', new DateTimeZone(WC_FUSO_BRASILIA_HORARIO));

    // Domingo (0) não tem bloqueio de horário.
    if ((int) $agora->format('w') === 0) {
        return false;
    }

    $minutos = (int) $agora->format('G') * 60 + (int) $agora->format('i');

    return (
        ($minutos >= 7 * 60 + 30 && $minutos < 12 * 60) ||
        ($minutos >= 13 * 60 && $minutos <= 17 * 60 + 30)
    );
}

function wcMensagemHorarioPalpiteBloqueado(): string
{
    return 'Palpites não podem ser enviados entre 7h30 e 12h e entre 13h e 17h30 (horário de Brasília).';
}

/**
 * Retorna mensagem de erro se o horário estiver bloqueado, ou null se permitido.
 */
function wcValidarHorarioPalpite(): ?string
{
    return wcHorarioPalpiteBloqueado() ? wcMensagemHorarioPalpiteBloqueado() : null;
}
