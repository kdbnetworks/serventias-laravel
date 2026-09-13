<?php

declare(strict_types=1);

namespace KdbNetworks\Serventias\Tests;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use KdbNetworks\Serventias\Beacon;
use KdbNetworks\Serventias\Sanidade;

/** O farol responde com uma cor, e cada cor tem um porquê. */
final class BeaconTest extends TestCase
{
    private const URL = 'https://github.com/kdbnetworks/serventias-br/releases/latest/download/manifest.json';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function importar(string $gz): string
    {
        $this->artisan('serventias:importar', ['arquivo' => $gz])->assertSuccessful();

        return hash_file('sha256', $gz);
    }

    public function test_tabela_vazia_e_vermelho(): void
    {
        Http::fake([self::URL => Http::response($this->manifesto('2026-08-06', 'abc'))]);
        $s = app(Beacon::class)->sanidade();

        $this->assertSame(Sanidade::VERMELHO, $s->nivel);
        $this->assertSame(0, $s->total);
        $this->assertStringContainsString('vazia', $s->motivos[0]);
        $this->assertSame('2026-08-06', $s->versaoRemota);
    }

    public function test_importado_e_em_dia_e_verde(): void
    {
        Carbon::setTestNow('2026-09-13 12:00:00');
        $sha = $this->importar($this->csvGz(5));
        Http::fake([self::URL => Http::response($this->manifesto('2026-08-06', $sha))]);

        $s = app(Beacon::class)->sanidade();

        $this->assertSame(Sanidade::VERDE, $s->nivel);
        $this->assertSame([], $s->motivos);
        $this->assertSame(5, $s->total);
        $this->assertSame(5, $s->registroImoveis);
        $this->assertSame('2026-08-06', $s->versaoLocal);
        $this->assertSame(38, $s->defasagemDias);
        $this->assertFalse($s->releaseMaisNova);
        $this->assertTrue($s->ultimaImportacaoOk->deuCerto());
        $this->assertSame('verde', $s->toArray()['nivel']);
        $this->assertSame($sha, $s->toArray()['remoto']['sha256']);
    }

    public function test_release_mais_nova_e_amarelo(): void
    {
        Carbon::setTestNow('2026-09-13 12:00:00');
        $this->importar($this->csvGz(5));
        Http::fake([self::URL => Http::response($this->manifesto('2026-09-01', 'outro'))]);

        $s = app(Beacon::class)->sanidade();

        $this->assertSame(Sanidade::AMARELO, $s->nivel);
        $this->assertTrue($s->releaseMaisNova);
        $this->assertStringContainsString('2026-09-01', $s->motivos[0]);
    }

    public function test_manifesto_fora_do_ar_e_amarelo_e_explica(): void
    {
        Carbon::setTestNow('2026-09-13 12:00:00');
        $this->importar($this->csvGz(5));
        Http::fake([self::URL => Http::response('', 500)]);

        $s = app(Beacon::class)->sanidade();

        $this->assertSame(Sanidade::AMARELO, $s->nivel);
        $this->assertStringContainsString('HTTP 500', $s->erroRemoto);
        $this->assertNull($s->versaoRemota);
    }

    public function test_sem_remoto_nao_consulta_nada(): void
    {
        Carbon::setTestNow('2026-09-13 12:00:00');
        $this->importar($this->csvGz(5));
        Http::fake();

        $s = app(Beacon::class)->sanidade(semRemoto: true);

        $this->assertSame(Sanidade::VERDE, $s->nivel);
        Http::assertNothingSent();
    }

    public function test_coleta_velha_sobe_para_amarelo_e_depois_vermelho(): void
    {
        $sha = $this->importar($this->csvGz(5));
        Http::fake([self::URL => Http::response($this->manifesto('2026-08-06', $sha))]);

        Carbon::setTestNow('2026-10-20 12:00:00');   // 75 dias
        $this->assertSame(Sanidade::AMARELO, app(Beacon::class)->sanidade()->nivel);

        Carbon::setTestNow('2026-12-20 12:00:00');   // 136 dias
        $s = app(Beacon::class)->sanidade();
        $this->assertSame(Sanidade::VERMELHO, $s->nivel);
        $this->assertStringContainsString('acima do alerta', $s->motivos[0]);
    }

    public function test_importacao_abortada_depois_de_uma_boa_e_amarelo(): void
    {
        Carbon::setTestNow('2026-09-13 12:00:00');
        $sha = $this->importar($this->csvGz(10));
        Http::fake([self::URL => Http::response($this->manifesto('2026-08-06', $sha))]);

        $this->artisan('serventias:importar', ['arquivo' => $this->csvGz(5, 'menor.csv.gz')])->assertFailed();

        $s = app(Beacon::class)->sanidade();
        $this->assertSame(Sanidade::AMARELO, $s->nivel);
        $this->assertStringContainsString('abortado', $s->motivos[0]);
        $this->assertSame(10, $s->total);
    }

    public function test_manifesto_remoto_vai_para_o_cache(): void
    {
        Carbon::setTestNow('2026-09-13 12:00:00');
        $sha = $this->importar($this->csvGz(5));
        Http::fake([self::URL => Http::response($this->manifesto('2026-08-06', $sha))]);

        app(Beacon::class)->sanidade();
        app(Beacon::class)->sanidade();

        Http::assertSentCount(1);
    }

    public function test_status_no_terminal_e_o_codigo_de_saida(): void
    {
        Http::fake([self::URL => Http::response($this->manifesto('2026-08-06', 'x'))]);
        $this->artisan('serventias:status')->expectsOutputToContain('VERMELHO')->assertFailed();

        Carbon::setTestNow('2026-09-13 12:00:00');
        $sha = $this->importar($this->csvGz(5));
        Http::fake([self::URL => Http::response($this->manifesto('2026-08-06', $sha))]);
        $this->artisan('serventias:status')->expectsOutputToContain('VERDE')->assertSuccessful();
        $this->artisan('serventias:status', ['--json' => true])->expectsOutputToContain('"nivel": "verde"')->assertSuccessful();
    }

    public function test_componente_blade_renderiza_a_cor_e_os_numeros(): void
    {
        Carbon::setTestNow('2026-09-13 12:00:00');
        $sha = $this->importar($this->csvGz(5));
        Http::fake([self::URL => Http::response($this->manifesto('2026-08-06', $sha))]);

        $html = Blade::render('<x-serventias::beacon />');

        $this->assertStringContainsString('serventias-beacon--verde', $html);
        $this->assertStringContainsString('Em dia', $html);
        $this->assertStringContainsString('5 serventias', $html);
        $this->assertStringContainsString('06/08/2026', $html);
    }
}
