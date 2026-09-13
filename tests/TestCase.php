<?php

declare(strict_types=1);

namespace KdbNetworks\Serventias\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use KdbNetworks\Serventias\ServentiasServiceProvider;
use Orchestra\Testbench\TestCase as Testbench;

abstract class TestCase extends Testbench
{
    use RefreshDatabase;

    protected string $tmp;

    protected function getPackageProviders($app): array
    {
        return [ServentiasServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('filesystems.disks.local.root', $this->tmp.'/storage');
        $app['config']->set('serventias.minimo_esperado', 3);
        $app['config']->set('serventias.snapshot', null);
        $app['config']->set('serventias.csv', null);
    }

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/serventias-laravel-'.uniqid();
        mkdir($this->tmp);
        mkdir($this->tmp.'/storage');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->apagar($this->tmp);
    }

    private function apagar(string $dir): void
    {
        foreach (glob($dir.'/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (basename($f) === '.' || basename($f) === '..') {
                continue;
            }
            is_dir($f) ? $this->apagar($f) : @unlink($f);
        }
        @rmdir($dir);
    }

    public const CABECALHO = ['cns', 'cns_digits', 'denominacao', 'status', 'status_raw',
        'situacao_juridica', 'situacao_juridica_raw', 'tipo_registro', 'municipio', 'uf',
        'atribuicoes', 'atribuicoes_raw', 'endereco', 'bairro', 'cep', 'telefone',
        'email', 'website', 'responsavel', 'comarca', 'circunscricao', 'codigo_ibge',
        'latitude', 'longitude', 'id_cnj', 'fonte', 'coletado_em'];

    /**
     * Gera um CSV com N serventias de Registro de Imóveis, mais as linhas extras que vierem.
     *
     * @param  list<array<string,string>>  $extras
     */
    protected function csv(int $quantas, ?array $cabecalho = null, string $nome = 'in.csv', array $extras = [], string $coleta = '2026-08-06T02:17:51+00:00'): string
    {
        $caminho = $this->tmp.'/'.$nome;
        $fh = fopen($caminho, 'w');
        fputcsv($fh, $cabecalho ?? self::CABECALHO, ';', '"', '');

        $linha = function (array $r) use ($fh, $cabecalho): void {
            fputcsv($fh, array_map(fn ($c) => $r[$c] ?? '', $cabecalho ?? self::CABECALHO), ';', '"', '');
        };

        for ($i = 1; $i <= $quantas; $i++) {
            $d = str_pad((string) $i, 6, '0', STR_PAD_LEFT);
            $linha([
                'cns' => substr($d, 0, 2).'.'.substr($d, 2, 3).'-'.substr($d, 5, 1),
                'cns_digits' => $d,
                'denominacao' => "{$i}º Registro de Imóveis de Itajaí",
                'status' => 'ATIVADA', 'status_raw' => 'Ativo',
                'situacao_juridica' => 'PROVIDA', 'situacao_juridica_raw' => 'Provido',
                'tipo_registro' => 'serventia', 'municipio' => 'Itajaí', 'uf' => 'SC',
                'atribuicoes' => 'registro_imoveis|notas', 'atribuicoes_raw' => 'Registro de Imóveis e Notas',
                'endereco' => 'Rua X, 1', 'bairro' => 'Centro', 'cep' => '88300-000',
                'telefone' => '4733000000', 'email' => "ri{$i}@exemplo.test",
                'responsavel' => 'Fulana', 'latitude' => '-26.9', 'longitude' => '-48.66', 'id_cnj' => (string) (1000 + $i),
                'fonte' => 'justica_aberta_api', 'coletado_em' => $coleta,
            ]);
        }
        foreach ($extras as $r) {
            $linha($r);
        }
        fclose($fh);

        return $caminho;
    }

    protected function csvGz(int $quantas, string $nome = 'in.csv.gz'): string
    {
        $csv = $this->csv($quantas, null, 'tmp-'.$nome.'.csv');
        $gz = $this->tmp.'/'.$nome;
        file_put_contents($gz, gzencode((string) file_get_contents($csv), 9));

        return $gz;
    }

    /** @return array<string,mixed> */
    protected function manifesto(string $versao, string $shaCsv, string $urlCsv = 'https://exemplo.test/serventias.csv.gz'): array
    {
        return [
            'esquema' => 'serventias-br/manifesto@1',
            'dataset' => 'serventias-br',
            'versao' => $versao,
            'gerado_em' => $versao.'T03:00:00+00:00',
            'coletado_em' => $versao.'T02:17:51+00:00',
            'arquivos' => ['serventias.csv.gz' => ['bytes' => 1, 'sha256' => $shaCsv, 'url' => $urlCsv]],
            'contagens' => ['serventias_unicas' => 12430],
            'defasagem' => ['aviso_dias' => 60, 'alerta_dias' => 120],
        ];
    }
}
