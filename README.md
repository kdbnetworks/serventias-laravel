# serventias-laravel

O catálogo das serventias extrajudiciais do Brasil dentro da sua aplicação
Laravel: uma tabela com os cartórios, um importador com travas, uma busca
pronta para autocomplete, e um farol que diz no painel se o catálogo está em
dia. Os dados vêm do [serventias-br](https://github.com/kdbnetworks/serventias-br),
que por sua vez os coleta do Justiça Aberta, do CNJ.

Este pacote nunca fala com o CNJ em produção. Ele importa um arquivo que você
tem em mãos e, quando quer saber se envelheceu, lê um manifesto publicado na
release do dataset. Foi desenhado para um SaaS que precisa subir sem depender
de rede e continuar funcionando quando o portal do CNJ mudar de forma.

## Instalar

```bash
composer require kdbnetworks/serventias-laravel
php artisan vendor:publish --tag=serventias-config
php artisan migrate
```

A migration cria a tabela `serventias` e um diário `serventias_importacoes`.
Se a sua aplicação já tinha uma tabela `serventias`, ela fica como está e
recebe só as colunas que faltarem.

## Colocar os dados

Há dois jeitos, e dá para usar os dois.

**O que recomendamos:** baixe o `serventias.csv.gz` da
[última release](https://github.com/kdbnetworks/serventias-br/releases/latest)
e guarde dentro do seu repositório, em `database/data/` por exemplo. São
cerca de 850 KB. Aponte o caminho no `.env`:

```
SERVENTIAS_SNAPSHOT=/caminho/absoluto/database/data/serventias.csv.gz
```

Ou, no `config/serventias.php`, `'snapshot' => database_path('data/serventias.csv.gz')`.
Depois:

```bash
php artisan serventias:importar
```

Assim o deploy sobe sem depender de nada externo, e você decide quando trocar
o arquivo.

**O atalho:** deixe o pacote buscar a release mais nova.

```bash
php artisan serventias:baixar        # lê o manifesto, baixa o csv.gz, confere o sha256
php artisan serventias:importar      # o arquivo baixado ganha do snapshot
```

`serventias:importar --baixar` faz os dois de uma vez.

Por padrão entram só as serventias com atribuição de Registro de Imóveis,
cerca de 3 mil. `--todas` traz o catálogo inteiro, cerca de 12 mil. As
desativadas e extintas entram de propósito: uma matrícula antiga cita o
cartório que existia na época.

### As travas do importador

Um CSV gerado pela metade é sintaticamente válido e pequeno. Sem trava, ele
entraria em silêncio e o seu localizador ficaria com quarenta cartórios.
Então o importador se recusa a gravar quando:

1. faltam colunas essenciais no cabeçalho;
2. o arquivo traz menos linhas aproveitáveis que `serventias.minimo_esperado`;
3. o arquivo traz 20% menos que o que já está na tabela.

`--force` passa por cima das três, para o dia em que o encolhimento for real.
`--dry-run` faz o percurso inteiro sem gravar. A importação é um upsert por
CNS: rodar a cada deploy é seguro, e nada é apagado.

Cada rodada, gravando ou não, deixa uma linha em `serventias_importacoes`.

## O farol

```bash
php artisan serventias:status
```

```
Catálogo de serventias: VERDE

  Na tabela            3.012
  Registro de Imóveis  3.012
  Ativadas             2.575
  Coleta importada     2026-08-06 (38 dias)
  Última importação    13/09/2026 20:41 · ok
  Coleta publicada     2026-08-06
```

O nível vem de regras simples:

| nível | quando |
|---|---|
| verde | há dados, a última importação deu certo, a coleta é recente e é a mesma da release |
| amarelo | a coleta passou de 60 dias, ou há uma release mais nova, ou o manifesto não respondeu, ou a última importação abortou depois de uma que deu certo |
| vermelho | a tabela está vazia, ou a coleta passou de 120 dias, ou a última importação abortou e nunca houve uma boa |

Os prazos ficam em `config/serventias.php`. O código de saída do comando é 0
para verde e amarelo, 1 para vermelho, então ele cabe num cron ou num
healthcheck. `--json` imprime a mesma fotografia em JSON.

### No painel

```blade
<x-serventias::beacon />
```

Renderiza um card com a cor, os números e os motivos. O estilo vem embutido e
é mínimo, para o card ficar legível em qualquer stack de CSS. Se já tiver a
sanidade em mãos, passe `:sanidade="$s"`; se preferir um título seu,
`titulo="Cartórios"`.

Em código:

```php
use KdbNetworks\Serventias\Beacon;

$s = app(Beacon::class)->sanidade();
$s->nivel;             // 'verde' | 'amarelo' | 'vermelho'
$s->motivos;           // list<string>
$s->defasagemDias;     // int|null
$s->releaseMaisNova;   // bool
$s->toArray();
```

O manifesto remoto vai para o cache por seis horas. Um painel aberto cem
vezes por dia bate uma vez no GitHub.

### Uma rota JSON, se você quiser

```php
// config/serventias.php
'rota' => ['ativa' => true, 'caminho' => '/serventias/beacon', 'middleware' => ['web', 'auth']],
```

Responde a sanidade em JSON com HTTP 200 para verde e amarelo e 503 para
vermelho, que é o que um monitor externo entende sem ler o corpo.

## Busca

```php
use KdbNetworks\Serventias\Busca;

app(Busca::class)->resultados('registro itajai');
// [['cns_digits' => '107441', 'cns' => '10.744-1', 'denominacao' => '1º Ofício de Registro de Imóveis…',
//   'contexto' => 'Itajaí/SC · ATIVADA', 'ativa' => true, ...], ...]

app(Busca::class)->resultados('1044');                 // CNS parcial, a partir de 4 dígitos
app(Busca::class)->resultados('itajai', 20, 'notas');  // limite e filtro por atribuição
app(Busca::class)->porCns('10.449-7');                 // ?Serventia
app(Busca::class)->consulta('itajai');                 // o query builder, para paginar
```

A busca trabalha sobre `search_text`, que é desacentuado e minúsculo e já vem
pronto da importação. Cada palavra do termo vira um `LIKE` próprio, e as
ativas aparecem antes das demais.

Para o CNS há um helper:

```php
use KdbNetworks\Serventias\Cns;

Cns::limpar('12.345-6');   // '123456'
Cns::formatar('123456');   // '12.345-6'
Cns::valido('9876-1');     // true (zero à esquerda restaurado)
Cns::noTexto('… CNS: 12.345-6, Livro 2 …');   // ['123456']
```

Só o formato é validado. O algoritmo do dígito verificador não é público.

## Sondar a fonte

```bash
php artisan serventias:sondar
```

Faz uma única requisição, com um registro, na API do Justiça Aberta e diz se
a rota ainda responde JSON no formato que o pipeline espera, e se apareceu
alguma chave nova. É a única coisa neste pacote que fala com o CNJ, e só
acontece quando alguém digita o comando.

## O modelo

`KdbNetworks\Serventias\Models\Serventia`, chave primária `cns_digits`.
Se a sua aplicação já tem um `App\Models\Serventia`, estenda o do pacote e
o resto do código continua igual.

Ao citar uma serventia num imóvel ou num confrontante, guarde o CNS, e nunca
uma chave estrangeira: se a reimportação mudar a linha, a citação antiga
continua verdadeira.

## Compatibilidade

PHP 8.3 ou 8.4, Laravel 12 ou 13. Testado em SQLite, e a migration cria
índice de trigrama no PostgreSQL quando a extensão `pg_trgm` está disponível.

## Licença

MIT. Os dados são públicos e vêm do CNJ/Justiça Aberta, pelo serventias-br;
ao redistribuir, cite a fonte e a data de coleta, que está no manifesto.
