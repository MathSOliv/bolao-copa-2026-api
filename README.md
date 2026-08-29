# Bolão Copa 2026 — API

Backend PHP do bolão interno da Copa 2026. Expõe endpoints JSON para o front
(cadastro, login, palpites, ranking, comparação e painel administrativo).

Pensado para rodar no Painel (`/Painel/wc_backend/`), junto com a conexão
MySQL já usada pelo sistema.

## Requisitos

- PHP 8.1+
- Composer
- MySQL (tabelas `wc_usuarios`, `wc_partidas`, `wc_palpites`, `wc_selecoes`, …)
- Arquivos de conexão do ambiente (MySQL e, no cadastro, a base de colaboradores).
  Eles ficam **fora** deste repositório e não devem ser commitados.

## Setup

```bash
git clone https://github.com/MathSOliv/bolao-copa-2026-api.git
cd bolao-copa-2026-api
composer install
copy .env.example .env