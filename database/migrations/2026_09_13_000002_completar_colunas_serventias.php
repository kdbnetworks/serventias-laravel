<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Completa uma tabela `serventias` que já existia antes do pacote com as
 * colunas que o CSV do serventias-br passou a trazer. Cada coluna só entra se
 * faltar, então rodar de novo é inofensivo. O importador grava apenas nas
 * colunas que a tabela tem; esta migration é o que permite gravar em todas.
 */
return new class extends Migration
{
    private const COLUNAS = [
        'comarca' => 160,
        'circunscricao' => 240,
        'codigo_ibge' => 12,
        'latitude' => 30,
        'longitude' => 30,
        'id_cnj' => 20,
    ];

    public function up(): void
    {
        $tabela = (string) config('serventias.tabela', 'serventias');
        if (! Schema::hasTable($tabela)) {
            return;
        }

        $faltam = array_filter(
            array_keys(self::COLUNAS),
            fn (string $c) => ! Schema::hasColumn($tabela, $c),
        );
        if ($faltam === []) {
            return;
        }

        Schema::table($tabela, function (Blueprint $t) use ($faltam): void {
            foreach ($faltam as $coluna) {
                $t->string($coluna, self::COLUNAS[$coluna])->nullable();
            }
        });
    }

    public function down(): void
    {
        // As colunas são inofensivas quando vazias; não há por que removê-las.
    }
};
