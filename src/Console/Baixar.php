<?php

declare(strict_types=1);

namespace KdbNetworks\Serventias\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use KdbNetworks\Serventias\Manifesto;

/**
 *     php artisan serventias:baixar
 *
 * Lê o manifesto da última release do serventias-br, baixa o csv.gz que ele
 * aponta, confere o sha256 e guarda em storage. O importador passa a preferir
 * esse arquivo ao snapshot do repositório. Se a release baixada já está em
 * disco, não baixa de novo.
 */
class Baixar extends Command
{
    protected $signature = 'serventias:baixar {--force : baixa mesmo que a versão já esteja em disco}';

    protected $description = 'Baixa a release mais nova do catálogo de serventias (serventias-br)';

    public function handle(Manifesto $manifesto): int
    {
        $m = $manifesto->remoto(forcar: true);
        if ($m === null) {
            $this->error('Não consegui ler o manifesto: '.($manifesto->ultimoErro() ?? 'motivo desconhecido'));

            return self::FAILURE;
        }

        $versao = Manifesto::versao($m);
        $url = Manifesto::urlDoCsv($m);
        $shaEsperado = Manifesto::shaDoCsv($m);
        if ($versao === null || $url === null || $shaEsperado === null) {
            $this->error('O manifesto não descreve o serventias.csv.gz como eu esperava.');

            return self::FAILURE;
        }

        $disco = Storage::disk((string) config('serventias.armazenamento.disco', 'local'));
        $pasta = trim((string) config('serventias.armazenamento.pasta', 'serventias'), '/');
        $destino = "{$pasta}/serventias-{$versao}.csv.gz";

        if (! $this->option('force') && $disco->exists($destino)
            && hash('sha256', (string) $disco->get($destino)) === $shaEsperado) {
            $this->info("A coleta de {$versao} já está em ".$disco->path($destino));

            return self::SUCCESS;
        }

        $this->line("Baixando a coleta de {$versao}…");
        $resposta = Http::timeout(120)->get($url);
        if (! $resposta->successful()) {
            $this->error("HTTP {$resposta->status()} ao baixar {$url}");

            return self::FAILURE;
        }

        $corpo = $resposta->body();
        $sha = hash('sha256', $corpo);
        if ($sha !== $shaEsperado) {
            $this->error("O sha256 do arquivo baixado ({$sha}) difere do manifesto ({$shaEsperado}). Nada foi guardado.");

            return self::FAILURE;
        }

        $disco->put($destino, $corpo);
        $disco->put("{$pasta}/manifest-{$versao}.json", (string) json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->info("Guardado em ".$disco->path($destino).' · '.number_format(strlen($corpo) / 1024, 0, ',', '.').' KB · sha256 confere');
        $this->line('Agora rode: php artisan serventias:importar');

        return self::SUCCESS;
    }
}
