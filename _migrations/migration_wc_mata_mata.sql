-- =============================================================================
-- Bolão Copa 2026 — Estrutura do mata-mata (chaveamento / bracket)
--
-- wc_partidas:
--   time_casa / time_fora        passam a aceitar NULL (vagas indefinidas)
--   fase                         'grupos','16avos','oitavas','quartas','semi','terceiro','final'
--   ordem                        posição da partida dentro da fase (ordenação do bracket)
--   origem_casa_partida          a vaga mandante vem do resultado desta partida
--   origem_casa_tipo             'vencedor' | 'perdedor' (perdedor usado na disputa de 3º)
--   origem_fora_partida          a vaga visitante vem do resultado desta partida
--   origem_fora_tipo             'vencedor' | 'perdedor'
--   rotulo_casa / rotulo_fora    texto exibido enquanto a vaga está indefinida
--   classificado                 seleção REAL que avançou (define o vencedor mesmo em pênaltis)
--
-- wc_palpites:
--   classificado                 seleção que o usuário acha que avança (obrigatório no mata-mata)
--
-- Observação: o projeto não utiliza FOREIGN KEYs nessas tabelas; a integridade
-- é garantida na aplicação. A migração mantém esse padrão.
-- =============================================================================

ALTER TABLE wc_partidas
    MODIFY time_casa INT NULL,
    MODIFY time_fora INT NULL,
    ADD COLUMN fase                VARCHAR(20) NULL AFTER status,
    ADD COLUMN ordem               INT         NULL AFTER fase,
    ADD COLUMN origem_casa_partida INT         NULL AFTER ordem,
    ADD COLUMN origem_casa_tipo    VARCHAR(10) NOT NULL DEFAULT 'vencedor' AFTER origem_casa_partida,
    ADD COLUMN origem_fora_partida INT         NULL AFTER origem_casa_tipo,
    ADD COLUMN origem_fora_tipo    VARCHAR(10) NOT NULL DEFAULT 'vencedor' AFTER origem_fora_partida,
    ADD COLUMN rotulo_casa         VARCHAR(40) NULL AFTER origem_fora_tipo,
    ADD COLUMN rotulo_fora         VARCHAR(40) NULL AFTER rotulo_casa,
    ADD COLUMN classificado        INT         NULL AFTER rotulo_fora;

UPDATE wc_partidas SET fase = 'grupos' WHERE fase IS NULL;

ALTER TABLE wc_palpites
    ADD COLUMN classificado INT NULL AFTER gols_fora;
