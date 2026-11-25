<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * As colunas que o CSV do serventias-br passou a trazer em 11/2026, quando a
 * API do Justiça Aberta começou a mandar o regime da serventia, os substitutos
 * e a comarca:
 *
 * - `tipo_cartorio`: PRIVATIZADO, OFICIALIZADO ou PUBLICO (OUTRO, se vier outro);
 * - `substitutos`: os nomes separados por «|», como as atribuições;
 * - `comarca_uf`: a UF da comarca (a `comarca` já existia).
 *
 * Mesmo molde da 2026_09_13_000002: cada coluna só entra se faltar, e o
 * importador só grava nas colunas que a tabela tem.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tabela = (string) config('serventias.tabela', 'serventias');
        if (! Schema::hasTable($tabela)) {
            return;
        }

        $faltam = array_values(array_filter(
            ['tipo_cartorio', 'substitutos', 'comarca_uf'],
            fn (string $c) => ! Schema::hasColumn($tabela, $c),
        ));
        if ($faltam === []) {
            return;
        }

        Schema::table($tabela, function (Blueprint $t) use ($faltam): void {
            if (in_array('tipo_cartorio', $faltam, true)) {
                $t->string('tipo_cartorio', 20)->nullable();
            }
            if (in_array('substitutos', $faltam, true)) {
                $t->text('substitutos')->nullable();
            }
            if (in_array('comarca_uf', $faltam, true)) {
                $t->char('comarca_uf', 2)->nullable();
            }
        });
    }

    public function down(): void
    {
        // As colunas são inofensivas quando vazias; não há por que removê-las.
    }
};
