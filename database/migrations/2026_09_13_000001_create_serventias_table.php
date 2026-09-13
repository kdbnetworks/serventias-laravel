<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * O catálogo de serventias, espelho local do Justiça Aberta/CNJ.
 *
 * A chave é o CNS, e só o CNS. O Código Nacional da Serventia é único e
 * estável por delegação, ativa ou não (art. 136-G do Código Nacional de
 * Normas, Prov. CNJ 149/2023); um id sintético só embaralharia as
 * reimportações. Guardamos os 6 dígitos como chave e o formato NN.NNN-N
 * como coluna de exibição.
 *
 * Validamos o formato, nunca o dígito verificador: o algoritmo do DV não é
 * público, e inventar um aqui rejeitaria cartório legítimo.
 *
 * `status` (operacional) e `situacao_juridica` (da delegação) são campos
 * distintos na fonte. Uma serventia pode estar ATIVADA e VAGA ao mesmo tempo.
 *
 * Inativas e extintas entram: matrícula antiga cita a serventia que existia
 * à época. Quem filtra é a consulta.
 *
 * Se a tabela já existe (uma aplicação que tinha o catálogo antes do pacote),
 * esta migration não a toca; a seguinte completa as colunas que faltarem.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tabela = (string) config('serventias.tabela', 'serventias');
        if (Schema::hasTable($tabela)) {
            return;
        }

        Schema::create($tabela, function (Blueprint $t): void {
            $t->char('cns_digits', 6)->primary();
            $t->string('cns', 9);

            $t->string('denominacao', 240);

            $t->string('status', 20)->nullable()->index();
            $t->string('status_raw', 40)->nullable();
            $t->string('situacao_juridica', 40)->nullable();
            $t->string('situacao_juridica_raw', 80)->nullable();

            $t->string('tipo_registro', 24)->nullable();

            $t->string('municipio', 160)->nullable();
            $t->char('uf', 2)->nullable();

            $t->string('atribuicoes', 240)->nullable();
            $t->string('atribuicoes_raw', 400)->nullable();

            $t->string('endereco', 240)->nullable();
            $t->string('bairro', 160)->nullable();
            $t->string('cep', 12)->nullable();
            $t->string('telefone', 40)->nullable();
            $t->string('email', 160)->nullable();
            $t->string('website', 240)->nullable();
            $t->string('responsavel', 200)->nullable();

            $t->string('comarca', 160)->nullable();
            $t->string('circunscricao', 240)->nullable();
            $t->string('codigo_ibge', 12)->nullable();
            $t->string('latitude', 30)->nullable();
            $t->string('longitude', 30)->nullable();
            $t->string('id_cnj', 20)->nullable();

            // desacentuado e minúsculo, pré-computado para o autocomplete
            $t->text('search_text');

            $t->string('fonte', 60)->nullable();
            $t->timestampTz('coletado_em')->nullable();

            $t->timestampTz('criado_em')->useCurrent();
            $t->timestampTz('atualizado_em')->useCurrent();

            $t->index(['uf', 'municipio']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared("ALTER TABLE {$tabela} ADD CONSTRAINT {$tabela}_cns_digitos CHECK (cns_digits ~ '^[0-9]{6}$')");

            // O trigrama acelera o "%termo%" do autocomplete. Sem permissão
            // para a extensão, o LIKE continua correto, só varre.
            try {
                DB::unprepared('CREATE EXTENSION IF NOT EXISTS pg_trgm');
                DB::unprepared("CREATE INDEX {$tabela}_busca ON {$tabela} USING gin (search_text gin_trgm_ops)");
            } catch (\Throwable) {
                // segue sem o índice
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists((string) config('serventias.tabela', 'serventias'));
    }
};
