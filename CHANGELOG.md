# Changelog

## 0.1.0 — 2026-09-13

Primeira versão.

- Migrations: `serventias` (criada só se não existir), colunas complementares
  para tabelas antigas, e o diário `serventias_importacoes`.
- `serventias:importar` com as três travas, `--dry-run`, `--force`, `--todas`,
  `--com-ruido` e `--baixar`; cada rodada registrada no diário.
- `serventias:baixar` lê o manifesto da release e confere o sha256.
- `serventias:status`, o farol no terminal, com código de saída.
- `Beacon` e `Sanidade`, o farol em código; `<x-serventias::beacon />` no painel;
  rota JSON opcional.
- `Busca` e `Cns`.
- `serventias:sondar`, uma requisição de diagnóstico na API do Justiça Aberta.
