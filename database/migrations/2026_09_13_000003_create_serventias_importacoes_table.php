<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O diário das importações. Cada rodada do `serventias:importar` deixa uma
 * linha aqui, inclusive as que abortaram nas travas e os ensaios (--dry-run).
 * É daqui que o farol tira «quando foi a última importação» e «ela deu certo».
 */
return new class extends Migration
{
    public function up(): void
    {
        $tabela = (string) config('serventias.tabela', 'serventias').'_importacoes';
        if (Schema::hasTable($tabela)) {
            return;
        }

        Schema::create($tabela, function (Blueprint $t): void {
            $t->id();
            $t->timestampTz('iniciado_em');
            $t->timestampTz('concluido_em')->nullable();

            $t->string('arquivo', 400);
            $t->string('sha256', 64)->nullable();
            $t->timestampTz('coletado_em')->nullable();   // a coleta que o arquivo carrega
            $t->string('versao', 10)->nullable();          // AAAA-MM-DD, a data da coleta

            $t->boolean('todas')->default(false);
            $t->boolean('com_ruido')->default(false);
            $t->boolean('ensaio')->default(false);
            $t->boolean('forcado')->default(false);

            $t->unsignedInteger('lidos')->default(0);
            $t->unsignedInteger('validos')->default(0);
            $t->unsignedInteger('malformados')->default(0);
            $t->unsignedInteger('filtrados')->default(0);
            $t->unsignedInteger('gravados')->default(0);
            $t->unsignedInteger('tabela_depois')->default(0);

            // ok · ensaio · abortado · falha
            $t->string('resultado', 12)->index();
            $t->text('mensagem')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((string) config('serventias.tabela', 'serventias').'_importacoes');
    }
};
