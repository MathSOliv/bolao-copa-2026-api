-- =============================================================================
-- Bolão Copa 2026 — Palpites especiais (campeão e artilheiro)
--
-- Tabela:
--   wc_palpites_especiais   Um registro por usuário com palpite de campeão
--                           e artilheiro da Copa.
--
-- Dependências:
--   wc_usuarios, wc_selecoes
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS wc_palpites_especiais (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    usuario             INT          NOT NULL,
    selecao_campeao     INT          NOT NULL,
    artilheiro_nome     VARCHAR(120) NOT NULL,
    status_campeao      VARCHAR(20)  NOT NULL DEFAULT 'pendente',
    status_artilheiro   VARCHAR(20)  NOT NULL DEFAULT 'pendente',
    pontos_campeao      INT          NOT NULL DEFAULT 0,
    pontos_artilheiro   INT          NOT NULL DEFAULT 0,
    data_palpite        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_pe_usuario (usuario),
    KEY idx_pe_selecao_campeao (selecao_campeao),
    KEY idx_pe_artilheiro_nome (artilheiro_nome),

    CONSTRAINT fk_pe_usuario
        FOREIGN KEY (usuario) REFERENCES wc_usuarios (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_pe_selecao_campeao
        FOREIGN KEY (selecao_campeao) REFERENCES wc_selecoes (id)
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
