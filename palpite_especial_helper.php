<?php

declare(strict_types=1);

const WC_FUSO_BRASILIA_ESPECIAL = 'America/Sao_Paulo';
const WC_PRAZO_PALPITE_ESPECIAL = '2026-06-17 23:59:59';

/**
 * @return list<string>
 */
function wcResolverCaminhosJogadoresJson(): array
{
    return array_values(array_filter([
        getenv('WC_JOGADORES_JSON') ?: null,
        'C:/Users/MatheusOliveiraPalmo/Projects/copa-2026-bet/src/data/jogadores.json',
        __DIR__ . '/data/jogadores.json',
    ]));
}

function wcResolverArquivoJogadoresJson(): ?string
{
    foreach (wcResolverCaminhosJogadoresJson() as $path) {
        if (is_file($path)) {
            return $path;
        }
    }

    return null;
}

/**
 * @return array<int, array{nome: string, selecao: string, sigla: string, posicao: string}>
 */
function wcCarregarJogadoresJson(): array
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $jsonPath = wcResolverArquivoJogadoresJson();

    if ($jsonPath === null) {
        throw new RuntimeException('Arquivo jogadores.json não encontrado.');
    }

    $conteudo = file_get_contents($jsonPath);

    if ($conteudo === false) {
        throw new RuntimeException('Não foi possível ler o arquivo jogadores.json.');
    }

    $jogadores = json_decode($conteudo, true);

    if (!is_array($jogadores)) {
        throw new RuntimeException('JSON de jogadores inválido ou mal formatado.');
    }

    $validados = [];

    foreach ($jogadores as $indice => $jogador) {
        if (!is_array($jogador)) {
            throw new RuntimeException("Jogador inválido na posição {$indice}.");
        }

        $nome = trim((string) ($jogador['nome'] ?? ''));
        $selecao = trim((string) ($jogador['selecao'] ?? ''));
        $sigla = strtoupper(trim((string) ($jogador['sigla'] ?? '')));
        $posicao = trim((string) ($jogador['posicao'] ?? ''));

        if ($nome === '' || $selecao === '' || $sigla === '' || $posicao === '') {
            throw new RuntimeException("Jogador incompleto na posição {$indice}.");
        }

        $validados[] = [
            'nome' => $nome,
            'selecao' => $selecao,
            'sigla' => $sigla,
            'posicao' => $posicao,
        ];
    }

    $cache = $validados;

    return $cache;
}

function wcArtilheiroPermitido(string $nome): bool
{
    $nome = trim($nome);

    if ($nome === '') {
        return false;
    }

    foreach (wcCarregarJogadoresJson() as $jogador) {
        if ($jogador['nome'] === $nome) {
            return true;
        }
    }

    return false;
}

/**
 * Retorna mensagem de erro se o prazo estiver encerrado, ou null se ainda puder palpitar.
 */
function wcValidarPrazoPalpiteEspecial(): ?string
{
    $fusoBrasilia = new DateTimeZone(WC_FUSO_BRASILIA_ESPECIAL);
    $limite = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', WC_PRAZO_PALPITE_ESPECIAL, $fusoBrasilia);

    if ($limite === false) {
        return 'Data limite para palpites especiais inválida.';
    }

    if (new DateTimeImmutable('now', $fusoBrasilia) > $limite) {
        return 'O prazo para palpites especiais encerrou em 17/06/2026 às 23:59 (horário de Brasília), último dia da primeira rodada.';
    }

    return null;
}

function wcPrazoPalpiteEspecialEncerrado(): bool
{
    return wcValidarPrazoPalpiteEspecial() !== null;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function wcFormatarPalpiteEspecial(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'selecao_campeao' => [
            'id' => (int) $row['selecao_id'],
            'nome' => $row['selecao_nome'],
            'sigla' => $row['selecao_sigla'],
            'bandeira' => $row['selecao_bandeira'],
        ],
        'artilheiro_nome' => $row['artilheiro_nome'],
        'status_campeao' => $row['status_campeao'],
        'status_artilheiro' => $row['status_artilheiro'],
        'pontos_campeao' => (int) $row['pontos_campeao'],
        'pontos_artilheiro' => (int) $row['pontos_artilheiro'],
        'data_palpite' => $row['data_palpite'],
        'updated_at' => $row['updated_at'],
    ];
}

function wcBuscarPalpiteEspecialPorUsuario(mysqli $conexao, int $usuarioId): ?array
{
    $stmt = $conexao->prepare(
        'SELECT
            pe.id,
            pe.artilheiro_nome,
            pe.status_campeao,
            pe.status_artilheiro,
            pe.pontos_campeao,
            pe.pontos_artilheiro,
            pe.data_palpite,
            pe.updated_at,
            s.id AS selecao_id,
            s.nome AS selecao_nome,
            s.sigla AS selecao_sigla,
            s.bandeira AS selecao_bandeira
         FROM wc_palpites_especiais pe
         INNER JOIN wc_selecoes s ON s.id = pe.selecao_campeao
         WHERE pe.usuario = ?
         LIMIT 1'
    );

    if ($stmt === false) {
        throw new RuntimeException('Erro ao consultar palpite especial.');
    }

    $stmt->bind_param('i', $usuarioId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return null;
    }

    return wcFormatarPalpiteEspecial($row);
}
