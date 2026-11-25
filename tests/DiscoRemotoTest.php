<?php

declare(strict_types=1);

namespace KdbNetworks\Serventias\Tests;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use KdbNetworks\Serventias\Models\Serventia;
use Mockery;

/**
 * O disco de `serventias.armazenamento` pode não ser local. No Matrize o disco
 * `local` é um bucket S3: o `serventias:baixar` guardava a coleta lá, e o
 * importador perguntava `is_file()` sobre a chave do bucket, não achava e caía
 * no snapshot do repositório em silêncio. O «Baixar coleta nova e importar»
 * baixava a coleta de 25/11/2026 e importava a de 15/09 (25/11/2026).
 */
final class DiscoRemotoTest extends TestCase
{
    public function test_a_coleta_baixada_num_disco_remoto_e_a_que_se_importa(): void
    {
        $baixada = $this->csvGz(7, 'serventias-2026-11-25.csv.gz');

        // um disco cujo path() devolve a chave do bucket, que não é arquivo no disco local
        $remoto = Mockery::mock(Filesystem::class);
        $remoto->shouldReceive('files')->with('serventias')->andReturn([
            'serventias/manifest-2026-11-25.json',
            'serventias/serventias-2026-09-15.csv.gz',
            'serventias/serventias-2026-11-25.csv.gz',
        ]);
        $remoto->shouldReceive('path')->andReturnUsing(fn (string $k) => 'private/'.$k);
        $remoto->shouldReceive('readStream')->with('serventias/serventias-2026-11-25.csv.gz')
            ->andReturnUsing(fn () => fopen($baixada, 'rb'));
        Storage::set('remoto', $remoto);
        config()->set('serventias.armazenamento.disco', 'remoto');

        // o snapshot do repositório existe e é menor: se o importador cair nele, o teste vê
        config()->set('serventias.snapshot', $this->csvGz(5, 'snapshot.csv.gz'));

        $this->artisan('serventias:importar')
            ->expectsOutputToContain('serventias-2026-11-25.csv.gz')
            ->assertSuccessful();
        $this->assertSame(7, Serventia::count());
    }

    public function test_disco_local_segue_lendo_no_lugar(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('serventias/serventias-2026-11-25.csv.gz', (string) file_get_contents($this->csvGz(6)));
        config()->set('serventias.snapshot', $this->csvGz(5, 'snapshot.csv.gz'));

        $this->assertSame(
            Storage::disk('local')->path('serventias/serventias-2026-11-25.csv.gz'),
            app(\KdbNetworks\Serventias\Importador::class)->ultimoBaixado(),
        );
        $this->artisan('serventias:importar')->assertSuccessful();
        $this->assertSame(6, Serventia::count());
    }
}
