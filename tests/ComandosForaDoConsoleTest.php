<?php

declare(strict_types=1);

namespace KdbNetworks\Serventias\Tests;

use Illuminate\Support\Facades\Artisan;

/**
 * Os comandos existem também num pedido web. Um painel que chama
 * `Artisan::call('serventias:importar')` no clique (o do Matrize, ADR-067) recebia
 * «The command does not exist», porque o provider só os registrava sob
 * `runningInConsole()`. O PHPUnit roda em console; por isso este teste finge o
 * pedido web pela mesma variável que o Laravel consulta.
 */
final class ComandosForaDoConsoleTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('APP_RUNNING_IN_CONSOLE=false');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        putenv('APP_RUNNING_IN_CONSOLE');
    }

    public function test_os_quatro_comandos_existem_fora_do_console(): void
    {
        $this->assertFalse($this->app->runningInConsole(), 'o teste tem de se ver fora do console');

        $comandos = array_keys(Artisan::all());
        foreach (['serventias:importar', 'serventias:status', 'serventias:baixar', 'serventias:sondar'] as $c) {
            $this->assertContains($c, $comandos, "{$c} some fora do console");
        }
    }
}
