<?php

declare(strict_types=1);

namespace KdbNetworks\Serventias\Tests;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use KdbNetworks\Serventias\Models\Serventia;

/**
 * Os campos que o serventias-br passou a publicar em 11/2026 (`tipo_cartorio`,
 * `substitutos`, `comarca_uf`) e o formato da API que os trouxe, sem `id` e sem
 * coordenadas.
 */
final class CamposDe2026_11Test extends TestCase
{
    private const CABECALHO_11_2026 = [...self::CABECALHO, 'tipo_cartorio', 'substitutos', 'comarca_uf'];

    private function itajai(): array
    {
        return [
            'cns' => '10.437-2', 'cns_digits' => '104372', 'denominacao' => '1º TABELIONATO DE NOTAS E PROTESTOS DE ITAJAÍ',
            'status' => 'ATIVADA', 'tipo_registro' => 'serventia', 'municipio' => 'Itajaí', 'uf' => 'SC',
            'atribuicoes' => 'notas|protesto', 'coletado_em' => '2026-11-25T03:00:00+00:00',
            'tipo_cartorio' => 'PRIVATIZADO', 'substitutos' => 'Gustavo Vanderlinde Medeiros|Ana Souza',
            'comarca' => 'Itajaí', 'comarca_uf' => 'SC',
        ];
    }

    public function test_o_csv_novo_grava_regime_substitutos_e_comarca(): void
    {
        $csv = $this->csv(5, self::CABECALHO_11_2026, 'novo.csv', [$this->itajai()]);

        $this->artisan('serventias:importar', ['arquivo' => $csv, '--todas' => true])
            ->doesntExpectOutputToContain('desconhecidas')
            ->assertSuccessful();

        $s = Serventia::findOrFail('104372');
        $this->assertSame('PRIVATIZADO', $s->tipo_cartorio);
        $this->assertSame(['Gustavo Vanderlinde Medeiros', 'Ana Souza'], $s->substitutosLista());
        $this->assertSame('Itajaí', $s->comarca);
        $this->assertSame('SC', $s->comarca_uf);
    }

    public function test_o_csv_antigo_continua_importando(): void
    {
        $this->artisan('serventias:importar', ['arquivo' => $this->csv(5)])->assertSuccessful();

        $s = Serventia::findOrFail('000001');
        $this->assertNull($s->tipo_cartorio);
        $this->assertSame([], $s->substitutosLista());
    }

    public function test_a_migration_completa_a_tabela_antiga_e_e_idempotente(): void
    {
        Schema::dropColumns('serventias', ['tipo_cartorio', 'substitutos', 'comarca_uf']);
        $this->assertFalse(Schema::hasColumn('serventias', 'tipo_cartorio'));

        $migration = require __DIR__.'/../database/migrations/2026_11_25_000001_colunas_tipo_cartorio_substitutos_comarca_uf.php';
        $migration->up();
        $migration->up();   // a segunda vez não faz nada

        foreach (['tipo_cartorio', 'substitutos', 'comarca_uf'] as $c) {
            $this->assertTrue(Schema::hasColumn('serventias', $c), $c);
        }
    }

    public function test_tabela_sem_as_colunas_novas_importa_o_csv_novo_igual(): void
    {
        Schema::dropColumns('serventias', ['tipo_cartorio', 'substitutos', 'comarca_uf']);

        $csv = $this->csv(5, self::CABECALHO_11_2026, 'novo.csv', [$this->itajai()]);
        $this->artisan('serventias:importar', ['arquivo' => $csv, '--todas' => true])->assertSuccessful();
        $this->assertSame('1º TABELIONATO DE NOTAS E PROTESTOS DE ITAJAÍ', Serventia::findOrFail('104372')->denominacao);
    }

    /** O registro como a API o mandou em 25/11/2026 (SC): os quatro novos, sem id e sem coordenadas. */
    private function registroDe2026_11(): array
    {
        return [
            'denominacao_padrao' => 'Notas, Protesto de Títulos', 'cns' => '104372',
            'denominacao_fantasia' => '1º TABELIONATO DE NOTAS E PROTESTOS DE ITAJAÍ', 'tipo_cartorio' => 'PRIVATIZADO',
            'telefone' => '4733442445', 'website' => null, 'email' => 'contato@exemplo.test', 'cep' => '88301420',
            'endereco' => 'Rua XV de Novembro', 'bairro' => 'Centro', 'numero' => '173',
            'natureza' => 'Notas | Protesto de Títulos', 'cidade' => 'Itajaí', 'uf' => 'SC',
            'cidade_comarca' => '', 'cidade_comarca_uf' => '',
            'situacao_juridica_cartorio' => ['descricao' => 'PROVIDO'], 'responsavel' => ['nome' => 'Fulana'],
            'substitutos' => 'Gustavo Vanderlinde Medeiros', 'status' => 'Ativo',
        ];
    }

    public function test_a_sonda_reconhece_o_formato_de_11_2026(): void
    {
        Http::fake(['justicaabertaapi.cnj.jus.br/*' => Http::response([
            'data' => [$this->registroDe2026_11()], 'meta' => ['total' => 689, 'last_page' => 689, 'per_page' => 1],
        ], 200, ['Content-Type' => 'application/json'])]);

        $this->artisan('serventias:sondar')
            ->expectsOutputToContain('Formato conhecido')
            ->doesntExpectOutputToContain('Chaves novas')
            ->doesntExpectOutputToContain('não vieram')
            ->assertSuccessful();
    }

    public function test_a_sonda_diz_a_chave_conhecida_que_some(): void
    {
        $registro = $this->registroDe2026_11();
        unset($registro['tipo_cartorio']);
        Http::fake(['justicaabertaapi.cnj.jus.br/*' => Http::response([
            'data' => [$registro], 'meta' => ['total' => 689],
        ], 200, ['Content-Type' => 'application/json'])]);

        $this->artisan('serventias:sondar')
            ->expectsOutputToContain('Chaves conhecidas que não vieram: tipo_cartorio')
            ->assertSuccessful();
    }
}
