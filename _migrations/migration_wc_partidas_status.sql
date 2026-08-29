-- =============================================================================
-- Bolão Copa 2026 — Flag de pontuação processada em wc_partidas
--
-- Coluna:
--   status   0 = pontos ainda não calculados pelo script
--            1 = script calcular_pontos.php já processou a partida
-- =============================================================================

SET NAMES utf8mb4;

ALTER TABLE wc_partidas
    ADD COLUMN status TINYINT NOT NULL DEFAULT 0
        COMMENT '0 = pontos pendentes, 1 = pontos calculados'
        AFTER encerrada;
