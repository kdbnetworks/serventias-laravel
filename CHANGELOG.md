# Changelog

## 0.2.1 — 2026-11-25

- A coleta baixada num disco que não é local (S3, por exemplo) agora é a que
  se importa. O importador perguntava `is_file()` sobre a chave do bucket, não
  achava e caía no snapshot do repositório em silêncio: o «baixar e importar»
  baixava a coleta nova e importava a antiga. Agora o arquivo vem para um
  temporário local antes da leitura.

## 0.2.0 — 2026-11-25

- Colunas novas, numa migration que só acrescenta o que faltar:
  `tipo_cartorio`, `substitutos` e `comarca_uf`. O importador as grava quando
  o CSV as traz (o serventias-br publica desde 11/2026) e segue importando o
  CSV antigo, e numa tabela sem elas, como antes.
- `Serventia::substitutosLista()`.
- `serventias:sondar` conhece os quatro campos que a API passou a mandar e
  diz, sem falhar, a chave conhecida que deixou de vir. `id`, `latitude`,
  `longitude`, `cidade_id` e `complemento` saíram da API em 11/2026 e ficam
  em `CHAVES_APOSENTADAS`: o serventias-br herda o valor deles da coleta
  anterior.

## 0.1.1 — 2026-11-25

- Os quatro comandos passam a ser registrados também fora do console. O
  provider só os registrava sob `runningInConsole()`, e um painel que os chama
  por `Artisan::call()` num pedido web recebia «The command does not exist».

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
