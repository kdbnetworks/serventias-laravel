<?php

declare(strict_types=1);

namespace KdbNetworks\Serventias\Tests;

use Illuminate\Support\Facades\Http;
use KdbNetworks\Serventias\Models\Serventia;

final class BaixarESondarTest extends TestCase
{
    private const MANIFESTO = 'https://github.com/kdbnetworks/serventias-br/releases/latest/download/manifest.json';

    private const CSV = 'https://github.com/kdbnetworks/serventias-br/releases/download/v2026-09-01/serventias.csv.gz';

    public function test_baixar_confere_o_sha_e_guarda_em_storage(): void
    {
        $gz = (string) file_get_contents($this->csvGz(5));
        Http::fake([
            self::MANIFESTO => Http::response($this->manifesto('2026-09-01', hash('sha256', $gz), self::CSV)),
            self::CSV => Http::response($gz),
        ]);

        $this->artisan('serventias:baixar')->expectsOutputToContain('sha256 confere')->assertSuccessful();
        $this->assertFileExists($this->tmp.'/storage/serventias/serventias-2026-09-01.csv.gz');
        $this->assertFileExists($this->tmp.'/storage/serventias/manifest-2026-09-01.json');

        // a segunda vez não baixa de novo
        $this->artisan('serventias:baixar')->expectsOutputToContain('já está em')->assertSuccessful();
        Http::assertSentCount(3);   // 2 manifestos + 1 csv

        // e o importador prefere o que foi baixado
        $this->artisan('serventias:importar')->assertSuccessful();
        $this->assertSame(5, Serventia::count());
    }

    public function test_baixar_recusa_arquivo_com_sha_errado(): void
    {
        Http::fake([
            self::MANIFESTO => Http::response($this->manifesto('2026-09-01', 'deadbeef', self::CSV)),
            self::CSV => Http::response('conteudo qualquer'),
        ]);

        $this->artisan('serventias:baixar')->expectsOutputToContain('difere do manifesto')->assertFailed();
        $this->assertFileDoesNotExist($this->tmp.'/storage/serventias/serventias-2026-09-01.csv.gz');
    }

    public function test_importar_com_baixar_encadeia(): void
    {
        $gz = (string) file_get_contents($this->csvGz(6));
        Http::fake([
            self::MANIFESTO => Http::response($this->manifesto('2026-09-01', hash('sha256', $gz), self::CSV)),
            self::CSV => Http::response($gz),
        ]);

        $this->artisan('serventias:importar', ['--baixar' => true])->assertSuccessful();
        $this->assertSame(6, Serventia::count());
    }

    public function test_sondar_reconhece_o_formato_e_aponta_chave_nova(): void
    {
        Http::fake(['justicaabertaapi.cnj.jus.br/*' => Http::response([
            'data' => [['cns' => '10.449-7', 'status' => 'Ativo', 'denominacao_fantasia' => '2º RI', 'natureza' => 'Registro de Imóveis',
                'uf' => 'SC', 'cidade' => 'Itajaí', 'horario_novo' => '8h']],
            'meta' => ['total' => 429, 'last_page' => 429, 'per_page' => 1],
        ], 200, ['Content-Type' => 'application/json'])]);

        $this->artisan('serventias:sondar')
            ->expectsOutputToContain('total=429')
            ->expectsOutputToContain('horario_novo')
            ->assertSuccessful();

        Http::assertSent(fn ($req) => $req->method() === 'POST' && $req['uf'] === 'SC');
    }

    public function test_sondar_desconfia_de_html_com_200(): void
    {
        Http::fake(['justicaabertaapi.cnj.jus.br/*' => Http::response('<html>index</html>', 200, ['Content-Type' => 'text/html'])]);

        $this->artisan('serventias:sondar')->expectsOutputToContain('não é JSON')->assertFailed();
    }

    public function test_sondar_avisa_quando_chave_essencial_some(): void
    {
        Http::fake(['justicaabertaapi.cnj.jus.br/*' => Http::response([
            'data' => [['codigo' => '10.449-7', 'status' => 'Ativo', 'uf' => 'SC']],
            'meta' => ['total' => 1],
        ], 200, ['Content-Type' => 'application/json'])]);

        $this->artisan('serventias:sondar')->expectsOutputToContain('sumiram: cns')->assertFailed();
    }
}
