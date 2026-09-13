<?php

declare(strict_types=1);

/*
 | serventias-laravel — configuração
 |
 | O catálogo nasce no repositório serventias-br (CNJ/Justiça Aberta) e chega
 | aqui como um `.csv.gz` assinado por um manifesto. Esta aplicação nunca fala
 | com o CNJ em produção: ela importa o arquivo que tem em mãos e, quando quer
 | saber se envelheceu, lê o manifesto publicado na release.
 */
return [

    /*
     | A tabela onde o catálogo vive. Sem chave de tenant: a lista de cartórios
     | do Brasil é a mesma para todo mundo.
     */
    'tabela' => 'serventias',

    /*
     | O arquivo que viaja DENTRO do seu repositório (recomendado). Copie o
     | serventias.csv.gz da release para, por exemplo, database/data/ e aponte
     | aqui. Assim o deploy sobe sem depender de rede.
     */
    'snapshot' => env('SERVENTIAS_SNAPSHOT'),

    /*
     | Um arquivo avulso, para desenvolvimento. Quando existe, ganha de tudo.
     */
    'csv' => env('SERVENTIAS_CSV'),

    /*
     | Onde `serventias:baixar` guarda o que traz da release. O importador
     | prefere o arquivo baixado mais novo ao snapshot do repositório.
     */
    'armazenamento' => [
        'disco' => env('SERVENTIAS_DISCO', 'local'),
        'pasta' => 'serventias',
    ],

    /*
     | Piso de sanidade da importação. Um CSV meio-gerado é sintaticamente
     | válido e pequeno; sem este piso, uma extração que morreu no meio entraria
     | em silêncio. O catálogo inteiro tem cerca de 12 mil linhas e o recorte de
     | Registro de Imóveis, cerca de 3 mil.
     */
    'minimo_esperado' => (int) env('SERVENTIAS_MINIMO', 2500),

    /*
     | Por padrão o importador traz só as serventias com atribuição de Registro
     | de Imóveis. `--todas` na linha de comando, ou false aqui, traz o catálogo
     | inteiro.
     */
    'so_registro_imoveis' => true,

    // Autocomplete: mínimo de caracteres para disparar e quantos resultados.
    'busca_min' => 3,
    'busca_limite' => 8,
    'atribuicao_padrao' => 'registro_imoveis',

    /*
     | O farol. A URL aponta para o manifesto da última release do
     | serventias-br; o resultado fica em cache para não bater no GitHub a cada
     | carregamento de painel. Os prazos de defasagem são sugestões: aperte ou
     | afrouxe conforme a sua aplicação.
     */
    'manifesto' => [
        'url' => env('SERVENTIAS_MANIFESTO', 'https://github.com/kdbnetworks/serventias-br/releases/latest/download/manifest.json'),
        'cache_minutos' => (int) env('SERVENTIAS_MANIFESTO_CACHE', 360),
        'timeout' => 5,
        'aviso_dias' => 60,
        'alerta_dias' => 120,
    ],

    /*
     | Uma rota JSON opcional com a sanidade, para um monitor externo ou um
     | painel em outra stack. Desligada por padrão; ao ligar, proteja com o
     | middleware que fizer sentido para você.
     */
    'rota' => [
        'ativa' => false,
        'caminho' => '/serventias/beacon',
        'middleware' => ['web', 'auth'],
    ],

];
