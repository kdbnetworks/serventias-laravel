<?php

declare(strict_types=1);

namespace KdbNetworks\Serventias\Console;

use Illuminate\Console\Command;
use KdbNetworks\Serventias\Beacon;
use KdbNetworks\Serventias\Sanidade;

/**
 *     php artisan serventias:status            # a fotografia, em texto
 *     php artisan serventias:status --json     # a mesma, para um monitor
 *     php artisan serventias:status --sem-remoto
 *
 * O código de saída acompanha a cor: 0 para verde e amarelo, 1 para vermelho.
 * Dá para pendurar num cron ou num healthcheck.
 */
class Status extends Command
{
    protected $signature = 'serventias:status
                            {--json : imprime a sanidade em JSON}
                            {--sem-remoto : não consulta o manifesto publicado}';

    protected $description = 'Mostra a sanidade do catálogo de serventias: volume, defasagem, última importação';

    public function handle(Beacon $beacon): int
    {
        $s = $beacon->sanidade(semRemoto: (bool) $this->option('sem-remoto'));

        if ($this->option('json')) {
            $this->line((string) json_encode($s->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->relatar($s);
        }

        return $s->nivel === Sanidade::VERMELHO ? self::FAILURE : self::SUCCESS;
    }

    private function relatar(Sanidade $s): void
    {
        $cor = match ($s->nivel) {
            Sanidade::VERDE => 'info',
            Sanidade::AMARELO => 'comment',
            default => 'error',
        };
        $this->line("<{$cor}>Catálogo de serventias: ".strtoupper($s->nivel).'</>');
        $this->newLine();

        $this->table(['', ''], [
            ['Na tabela', number_format($s->total, 0, ',', '.')],
            ['Registro de Imóveis', number_format($s->registroImoveis, 0, ',', '.')],
            ['Ativadas', number_format($s->ativas, 0, ',', '.')],
            ['Coleta importada', $s->versaoLocal ? "{$s->versaoLocal} ({$s->defasagemDias} dias)" : '—'],
            ['Última importação', $s->ultimaImportacao
                ? ($s->ultimaImportacao->concluido_em?->format('d/m/Y H:i').' · '.$s->ultimaImportacao->resultado)
                : 'nenhuma registrada'],
            ['Coleta publicada', $s->versaoRemota ?? ($s->erroRemoto ? 'indisponível' : 'não consultada')],
        ]);

        foreach ($s->motivos as $m) {
            $this->line('  · '.$m);
        }
    }
}
