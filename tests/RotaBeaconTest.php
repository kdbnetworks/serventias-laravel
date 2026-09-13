<?php

declare(strict_types=1);

namespace KdbNetworks\Serventias\Tests;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/** A rota JSON é opcional e nasce desligada; ligada, o status HTTP acompanha a cor. */
final class RotaBeaconTest extends TestCase
{
    private const URL = 'https://github.com/kdbnetworks/serventias-br/releases/latest/download/manifest.json';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('serventias.rota.ativa', true);
        $app['config']->set('serventias.rota.middleware', []);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_vermelho_responde_503_e_verde_200(): void
    {
        Http::fake([self::URL => Http::response($this->manifesto('2026-08-06', 'x'))]);
        $this->get('/serventias/beacon')->assertStatus(503)->assertJsonPath('nivel', 'vermelho');

        Carbon::setTestNow('2026-09-13 12:00:00');
        $gz = $this->csvGz(5);
        $this->artisan('serventias:importar', ['arquivo' => $gz])->assertSuccessful();
        Http::fake([self::URL => Http::response($this->manifesto('2026-08-06', hash_file('sha256', $gz)))]);

        $this->get('/serventias/beacon')
            ->assertOk()
            ->assertJsonPath('nivel', 'verde')
            ->assertJsonPath('tabela.total', 5)
            ->assertJsonPath('remoto.versao', '2026-08-06');
    }
}
