<?php

declare(strict_types=1);

namespace KdbNetworks\Serventias\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use KdbNetworks\Serventias\Models\Importacao;
use KdbNetworks\Serventias\Models\Serventia;

/**
 * As travas do importador. O que se testa aqui é o CSV meio-gerado, que é
 * sintaticamente válido e entraria em silêncio; e o diário, que é o que o
 * farol lê.
 */
final class ImportadorTest extends TestCase
{
    public function test_importa_e_e_idempotente(): void
    {
        $csv = $this->csv(5);

        $this->artisan('serventias:importar', ['arquivo' => $csv])->assertSuccessful();
        $this->assertSame(5, Serventia::count());

        $s = Serventia::find('000003');
        $this->assertSame('00.000-3', $s->cns);
        $this->assertSame('3º Registro de Imóveis de Itajaí', $s->denominacao);
        $this->assertTrue($s->ativa());
        $this->assertSame(['registro_imoveis', 'notas'], $s->atribuicoesLista());
        $this->assertSame('-26.9', $s->latitude);
        $this->assertSame('1003', $s->id_cnj);
        $this->assertStringContainsString('registro de imoveis de itajai', $s->search_text);
        $this->assertSame('2026-08-06', $s->coletado_em->toDateString());

        $this->artisan('serventias:importar', ['arquivo' => $csv])->assertSuccessful();
        $this->assertSame(5, Serventia::count());
    }

    public function test_le_csv_gz_igual_ao_csv(): void
    {
        $this->artisan('serventias:importar', ['arquivo' => $this->csvGz(4)])->assertSuccessful();
        $this->assertSame(4, Serventia::count());
    }

    public function test_cada_rodada_deixa_uma_linha_no_diario(): void
    {
        $gz = $this->csvGz(5);
        $this->artisan('serventias:importar', ['arquivo' => $gz])->assertSuccessful();

        $i = Importacao::query()->sole();
        $this->assertSame(Importacao::OK, $i->resultado);
        $this->assertTrue($i->deuCerto());
        $this->assertSame(hash_file('sha256', $gz), $i->sha256);
        $this->assertSame('2026-08-06', $i->versao);
        $this->assertSame(5, $i->lidos);
        $this->assertSame(5, $i->gravados);
        $this->assertSame(5, $i->tabela_depois);
        $this->assertFalse($i->ensaio);
        $this->assertNotNull($i->concluido_em);
    }

    public function test_cabecalho_sem_coluna_exigida_aborta_sem_gravar(): void
    {
        $sem = array_values(array_diff(self::CABECALHO, ['atribuicoes']));
        $this->artisan('serventias:importar', ['arquivo' => $this->csv(5, $sem)])->assertFailed();

        $this->assertSame(0, Serventia::count());
        $this->assertSame(Importacao::ABORTADO, Importacao::query()->sole()->resultado);
        $this->assertStringContainsString('atribuicoes', Importacao::query()->sole()->mensagem);
    }

    public function test_coluna_nova_no_csv_nao_quebra(): void
    {
        $com = [...self::CABECALHO, 'coluna_do_futuro'];
        $this->artisan('serventias:importar', ['arquivo' => $this->csv(5, $com)])
            ->expectsOutputToContain('coluna_do_futuro')
            ->assertSuccessful();
        $this->assertSame(5, Serventia::count());
    }

    public function test_arquivo_pequeno_demais_aborta(): void
    {
        $this->artisan('serventias:importar', ['arquivo' => $this->csv(2)])->assertFailed();
        $this->assertSame(0, Serventia::count());
    }

    public function test_encolhimento_acima_de_vinte_por_cento_aborta(): void
    {
        $this->artisan('serventias:importar', ['arquivo' => $this->csv(10)])->assertSuccessful();
        $this->artisan('serventias:importar', ['arquivo' => $this->csv(5, null, 'menor.csv')])->assertFailed();
        $this->assertSame(10, Serventia::count());

        $ultima = Importacao::query()->orderByDesc('id')->first();
        $this->assertSame(Importacao::ABORTADO, $ultima->resultado);
        $this->assertStringContainsString('queda', $ultima->mensagem);
    }

    public function test_force_permite_o_encolhimento_real(): void
    {
        $this->artisan('serventias:importar', ['arquivo' => $this->csv(10)])->assertSuccessful();
        $this->artisan('serventias:importar', ['arquivo' => $this->csv(5, null, 'menor.csv'), '--force' => true])->assertSuccessful();
        // nada é apagado: upsert só atualiza
        $this->assertSame(10, Serventia::count());
        $this->assertTrue(Importacao::query()->orderByDesc('id')->first()->forcado);
    }

    public function test_dry_run_nao_grava_mas_registra_o_ensaio(): void
    {
        $this->artisan('serventias:importar', ['arquivo' => $this->csv(5), '--dry-run' => true])
            ->expectsOutputToContain('[ensaio] 5 registro(s) seriam gravados')
            ->assertSuccessful();
        $this->assertSame(0, Serventia::count());

        $i = Importacao::query()->sole();
        $this->assertSame(Importacao::ENSAIO, $i->resultado);
        $this->assertTrue($i->ensaio);
        $this->assertSame(0, $i->gravados);
    }

    public function test_baseline_do_piso_relativo_respeita_o_escopo(): void
    {
        $extras = [];
        for ($i = 1; $i <= 12; $i++) {
            $d = str_pad((string) (500 + $i), 6, '0', STR_PAD_LEFT);
            $extras[] = ['cns' => substr($d, 0, 2).'.'.substr($d, 2, 3).'-'.substr($d, 5, 1), 'cns_digits' => $d,
                'denominacao' => "Tabelionato {$i}", 'status' => 'ATIVADA', 'tipo_registro' => 'serventia',
                'municipio' => 'Blumenau', 'uf' => 'SC', 'atribuicoes' => 'notas', 'coletado_em' => '2026-08-06T02:17:51+00:00'];
        }
        // 4 RI + 12 notas, tudo com --todas
        $this->artisan('serventias:importar', ['arquivo' => $this->csv(4, null, 'todas.csv', $extras), '--todas' => true])->assertSuccessful();
        $this->assertSame(16, Serventia::count());

        // agora só RI: a baseline é 4, e 4 contra 4 não é queda
        $this->artisan('serventias:importar', ['arquivo' => $this->csv(4, null, 'ri.csv')])->assertSuccessful();
    }

    public function test_linha_malformada_e_ignorada_sem_derrubar_a_importacao(): void
    {
        $csv = $this->csv(5);
        file_put_contents($csv, "isto;nao;e;uma;linha\n", FILE_APPEND);
        $this->artisan('serventias:importar', ['arquivo' => $csv])->assertSuccessful();
        $this->assertSame(5, Serventia::count());
        $this->assertSame(1, Importacao::query()->sole()->malformados);
    }

    public function test_ruido_cadastral_do_cnj_fica_de_fora(): void
    {
        $extras = [[
            'cns' => '15.300-7', 'cns_digits' => '153007', 'denominacao' => 'CORREGEDORIA DO ESTADO',
            'status' => 'ATIVADA', 'tipo_registro' => 'corregedoria', 'municipio' => 'Rio Branco', 'uf' => 'AC',
            'atribuicoes' => 'registro_imoveis', 'coletado_em' => '2026-08-06T02:17:51+00:00',
        ]];
        $this->artisan('serventias:importar', ['arquivo' => $this->csv(5, null, 'ruido.csv', $extras)])->assertSuccessful();
        $this->assertNull(Serventia::find('153007'));

        $this->artisan('serventias:importar', ['arquivo' => $this->csv(5, null, 'ruido2.csv', $extras), '--com-ruido' => true])->assertSuccessful();
        $this->assertNotNull(Serventia::find('153007'));
    }

    public function test_tabela_antiga_sem_as_colunas_novas_importa_igual(): void
    {
        // Uma aplicação que tinha a tabela antes do pacote: sem latitude, sem id_cnj.
        Schema::dropColumns('serventias', ['comarca', 'circunscricao', 'codigo_ibge', 'latitude', 'longitude', 'id_cnj']);
        $this->assertFalse(Schema::hasColumn('serventias', 'latitude'));

        $this->artisan('serventias:importar', ['arquivo' => $this->csv(5)])->assertSuccessful();
        $this->assertSame(5, Serventia::count());
        $this->assertSame('5º Registro de Imóveis de Itajaí', Serventia::find('000005')->denominacao);
    }

    public function test_sem_fonte_nenhuma_falha_e_explica(): void
    {
        $this->artisan('serventias:importar')
            ->expectsOutputToContain('Nenhuma fonte de serventias encontrada')
            ->assertFailed();
        $this->assertSame(Importacao::FALHA, Importacao::query()->sole()->resultado);
    }

    public function test_snapshot_configurado_e_a_fonte_padrao(): void
    {
        config()->set('serventias.snapshot', $this->csvGz(5, 'snapshot.csv.gz'));
        $this->artisan('serventias:importar')->assertSuccessful();
        $this->assertSame(5, Serventia::count());
    }

    public function test_arquivo_baixado_ganha_do_snapshot(): void
    {
        config()->set('serventias.snapshot', $this->csvGz(5, 'snapshot.csv.gz'));
        mkdir($this->tmp.'/storage/serventias');
        copy($this->csvGz(7, 'novo.csv.gz'), $this->tmp.'/storage/serventias/serventias-2026-09-01.csv.gz');

        $this->artisan('serventias:importar')->assertSuccessful();
        $this->assertSame(7, Serventia::count());
    }

    public function test_migration_completa_colunas_de_uma_tabela_antiga(): void
    {
        Schema::dropColumns('serventias', ['latitude', 'id_cnj']);
        DB::table('migrations')->where('migration', 'like', '%completar_colunas%')->delete();

        $this->artisan('migrate')->assertSuccessful();

        $this->assertTrue(Schema::hasColumn('serventias', 'latitude'));
        $this->assertTrue(Schema::hasColumn('serventias', 'id_cnj'));
    }
}
