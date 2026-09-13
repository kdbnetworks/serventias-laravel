<?php

declare(strict_types=1);

namespace KdbNetworks\Serventias\Console;

use Illuminate\Console\Command;
use KdbNetworks\Serventias\Importador;
use KdbNetworks\Serventias\Opcoes;

/**
 *     php artisan serventias:importar
 *     php artisan serventias:importar --dry-run      # confere sem gravar
 *     php artisan serventias:importar --todas        # não só Registro de Imóveis
 *     php artisan serventias:importar --baixar       # traz a release mais nova antes
 *     php artisan serventias:importar caminho/para/serventias.csv.gz
 */
class Importar extends Command
{
    protected $signature = 'serventias:importar
                            {arquivo? : caminho de um .csv ou .csv.gz (padrão: o baixado mais novo, senão o snapshot)}
                            {--todas : importa todas as atribuições, não só Registro de Imóveis}
                            {--com-ruido : inclui corregedorias e registros de teste do cadastro do CNJ}
                            {--dry-run : percorre e relata sem gravar}
                            {--force : ignora as travas de sanidade}
                            {--baixar : baixa a release mais nova do serventias-br antes de importar}';

    protected $description = 'Importa o catálogo de serventias extrajudiciais (serventias-br / CNJ)';

    public function handle(Importador $importador): int
    {
        if ($this->option('baixar')) {
            $saida = $this->call('serventias:baixar');
            if ($saida !== self::SUCCESS) {
                return $saida;
            }
        }

        $r = $importador->importar(new Opcoes(
            arquivo: $this->argument('arquivo') ? (string) $this->argument('arquivo') : null,
            todas: (bool) $this->option('todas') || ! config('serventias.so_registro_imoveis', true),
            comRuido: (bool) $this->option('com-ruido'),
            dryRun: (bool) $this->option('dry-run'),
            force: (bool) $this->option('force'),
        ), function (string $nivel, string $msg): void {
            match ($nivel) {
                'aviso' => $this->warn($msg),
                'erro' => $this->error($msg),
                default => $this->line($msg),
            };
        });

        if (! $r->deuCerto()) {
            $this->error($r->mensagem ?? 'A importação não aconteceu.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
