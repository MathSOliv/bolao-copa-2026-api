# Bolão Copa 2026 — API

Backend PHP do bolão interno da Copa 2026. Expõe endpoints JSON para o front
(cadastro, login, palpites, ranking, comparação e painel administrativo).

Pensado para rodar no Painel (`/Painel/wc_backend/`), junto com a conexão
MySQL já usada pelo sistema.

## Requisitos

- PHP 8.1+
- Composer
- MySQL (tabelas `wc_*` listadas abaixo)
- Arquivos de conexão do ambiente (MySQL e, no cadastro, a base de colaboradores).
  Eles ficam **fora** deste repositório e não devem ser commitados.

## Setup

```bash
git clone https://github.com/MathSOliv/bolao-copa-2026-api.git
cd bolao-copa-2026-api
composer install
copy .env.example .env
```

Edite o `.env` com os valores locais. Esse arquivo não vai para o Git.

## Variáveis de ambiente (`.env`)

Copie `.env.example` e preencha. Só esta chave é obrigatória para o painel admin:

| Variável | Obrigatória | Descrição |
|---|---|---|
| `WC_ADMIN_MATRICULAS` | Sim (admin) | Matrículas com permissão administrativa, separadas por vírgula. Ex.: `010630,018191` |

Sem essa variável (ou com ela vazia), ninguém passa em `wcUsuarioEhAdmin()` e o `admin_partidas.php` recusa o acesso.

Variáveis opcionais lidas pelo código (não precisam estar no `.env`; se ausentes, o fallback é a pasta `data/`):

| Variável | Uso |
|---|---|
| `WC_SELECOES_JSON` | Caminho alternativo do JSON em `import_selecoes.php` |
| `WC_PARTIDAS_JSON` | Caminho alternativo do JSON em `import_partidas.php` |
| `WC_JOGADORES_JSON` | Caminho alternativo do JSON de jogadores (palpite especial) |

## Regras de pontuação

O palpite da partida fecha **20 minutos antes do kickoff** (horário de Brasília).
O palpite especial (campeão e artilheiro) fecha em **17/06/2026 23:59:59** (Brasília).

O ranking soma pontos dos jogos + `pontos_campeao` + `pontos_artilheiro`.
Empate de pontos: quem cadastrou primeiro fica na frente.

### Fase de grupos

Uma única faixa: placar exato **ou** resultado **ou** erro (não somam).

| Resultado do palpite | Pontos |
|---|---|
| Placar exato | 15 |
| Acertou o vencedor ou o empate | 5 |
| Errou | 0 |

### Mata-mata

Duas faixas **independentes** que somam. O classificado é quem avançou de fato
(inclusive nos pênaltis), não só quem ganhou no tempo normal.

| Acerto | Pontos |
|---|---|
| Placar exato do tempo normal | 30 |
| Acertou quem se classificou | 20 |
| Os dois | 50 |
| Nenhum | 0 |

Não há pontos por “só acertar o vencedor do tempo normal” no mata-mata.

### Palpite especial

Campeão e artilheiro ficam em `wc_palpites_especiais` com status `pendente` e
pontos 0 até serem processados. Os valores entram no ranking quando
`pontos_campeao` / `pontos_artilheiro` forem preenchidos.

## Endpoints

Token (quando exigido): header `Authorization: Bearer {token}`, query `?token=`
ou campo `"token"` no JSON.

### Públicos

| Método | Arquivo | Descrição |
|---|---|---|
| GET | `get_nome_colaborador.php?matricula=` | Nome do colaborador na base da empresa |
| POST | `cadastro.php` | `{ matricula, email, senha }` — nome vem da base, não do cliente |
| POST | `login.php` | `{ matricula, senha }` → token + user |
| GET | `get_partidas.php` | Lista partidas; com token, inclui o palpite do usuário |
| GET | `get_selecoes.php` | Seleções (id, nome, sigla, bandeira) |
| GET | `get_ranking.php` | Ranking por pontos e data de cadastro |
| GET | `get_termometro_palpites.php` | % de palpites casa / empate / fora por jogo |

### Autenticados

| Método | Arquivo | Descrição |
|---|---|---|
| POST | `save_palpite.php` | Palpite da partida: `{ partida_id, gols_casa, gols_fora }` (mata-mata também envia `classificado`) |
| POST | `save_palpite_especial.php` | `{ selecao_sigla, artilheiro_nome }` |
| GET | `get_palpite_especial.php` | Palpite especial do usuário + `pode_editar` |
| GET | `get_usuarios_comparacao.php` | Usuários disponíveis para comparar (exceto o logado) |
| GET | `get_comparacao_palpites.php?usuario_id=` | Compara palpites; os do outro só aparecem após os 20 min |

### Admin (matrículas de `WC_ADMIN_MATRICULAS`)

`POST admin_partidas.php` + token de um admin:

| `acao` | Body | Efeito |
|---|---|---|
| `salvar_placar` | `partida_id`, `gols_casa`, `gols_fora` (+ `classificado` no mata-mata) | Grava o resultado |
| `zerar_placar` | `partida_id` | Limpa placar e reseta palpites da partida |
| `definir_selecoes` | `partida_id`, `time_casa`, `time_fora` | Define os times de uma vaga do chaveamento |
| `calcular_pontos` | — | Recalcula pontos das partidas encerradas |

### Importação e manutenção

| Arquivo | Uso |
|---|---|
| `import_selecoes.php` | Substitui `wc_selecoes` a partir de `data/selecoes.json` |
| `import_partidas.php` | Substitui `wc_partidas` a partir de `data/partidas.json` |
| `import_mata_mata.php` | Upsert do chaveamento em `data/partidas_mata_mata.json` (não apaga placares) |
| `gerar_partidas_json.php` | Gera JSON de partidas a partir das seleções no banco |
| `calcular_pontos.php` | Processa partidas encerradas ainda não pontuadas (`status = 0`) |

## Tabelas

Migrations incrementais em `_migrations/`. Não há `CREATE` das tabelas-base
neste repo (elas já existiam no banco do Painel). `wc_palpites_especiais`
é a única criada aqui.

### `wc_usuarios`

Colaboradores cadastrados no bolão.

| Coluna | Uso |
|---|---|
| `id` | PK |
| `nome` | Nome oficial (base de colaboradores) |
| `matricula` | Login |
| `email` | E-mail informado no cadastro |
| `senha` | Hash bcrypt |
| `created_at` | Desempate do ranking |

### `wc_selecoes`

Seleções da Copa.

| Coluna | Uso |
|---|---|
| `id` | PK |
| `nome` | Nome |
| `sigla` | Ex.: `BRA` |
| `bandeira` | URL ou path da bandeira |

### `wc_partidas`

Jogos da fase de grupos e do mata-mata.

| Coluna | Uso |
|---|---|
| `id` | PK (no mata-mata é o número do jogo no chaveamento) |
| `time_casa` / `time_fora` | IDs em `wc_selecoes` (podem ser `NULL` se a vaga ainda não existe) |
| `data_partida` | Kickoff |
| `gols_casa` / `gols_fora` | Placar do tempo normal |
| `encerrada` | 1 = jogo encerrado |
| `status` | 0 = pontos pendentes; 1 = `calcular_pontos` já processou |
| `fase` | `grupos`, `16avos`, `oitavas`, `quartas`, `semi`, `terceiro`, `final` |
| `ordem` | Posição na fase (bracket) |
| `origem_casa_partida` / `origem_fora_partida` | De qual jogo vem a vaga |
| `origem_casa_tipo` / `origem_fora_tipo` | `vencedor` ou `perdedor` |
| `rotulo_casa` / `rotulo_fora` | Texto enquanto a vaga está indefinida |
| `classificado` | Seleção que avançou de fato |

### `wc_palpites`

Um palpite por usuário e partida.

| Coluna | Uso |
|---|---|
| `id` | PK |
| `usuario` | `wc_usuarios.id` |
| `partida` | `wc_partidas.id` |
| `gols_casa` / `gols_fora` | Palpite do placar |
| `classificado` | Quem o usuário acha que avança (mata-mata) |
| `status` | `pendente`, `PLACAR EXATO`, `VENCEDOR`, `EMPATE`, `CLASSIFICADO`, `ERRADO`, etc. |
| `pontos` | Pontos daquele jogo |
| `data_palpite` | Última alteração |

### `wc_palpites_especiais`

Um registro por usuário (campeão + artilheiro). Criada em
`_migrations/migration_wc_palpites_especiais.sql`.

| Coluna | Uso |
|---|---|
| `id` | PK |
| `usuario` | `wc_usuarios.id` (único) |
| `selecao_campeao` | `wc_selecoes.id` |
| `artilheiro_nome` | Nome do jogador |
| `status_campeao` / `status_artilheiro` | `pendente` até o processamento |
| `pontos_campeao` / `pontos_artilheiro` | Entram no ranking |
| `data_palpite` / `updated_at` | Datas de gravação |
