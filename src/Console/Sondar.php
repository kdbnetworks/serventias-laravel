<?php

declare(strict_types=1);

namespace KdbNetworks\Serventias\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 *     php artisan serventias:sondar
 *
 * Uma única página, com um registro, na API pública do Justiça Aberta. Serve
 * para responder «a fonte ainda está lá, do jeito que o pipeline espera?»
 * sem varrer nada. É a única coisa neste pacote que fala com o CNJ, e só
 * acontece quando alguém digita o comando.
 *
 * Três coisas que já enganaram diagnósticos, e que a sonda confere:
 * a rota é POST (GET responde 404); o host da SPA devolve HTML com 200 para
 * qualquer caminho, então o Content-Type importa; e as chaves de cada
 * registro podem mudar de nome.
 */
class Sondar extends Command
{
    public const ENDPOINT = 'https://justicaabertaapi.cnj.jus.br/v1/api/serventias';

    /** As chaves que o pipeline do serventias-br sabe mapear hoje. */
    public const CHAVES_CONHECIDAS = [
        'cns', 'status', 'situacao_juridica_cartorio', 'denominacao_fantasia',
        'denominacao_padrao', 'responsavel', 'natureza', 'website', 'endereco',
        'numero', 'bairro', 'cidade', 'uf', 'telefone', 'email', 'id',
        'cidade_id', 'cep', 'latitude', 'longitude', 'complemento',
        // 11/2026: os quatro que a API passou a mandar
        'tipo_cartorio', 'substitutos', 'cidade_comarca', 'cidade_comarca_uf',
    ];

    /**
     * As que a API deixou de mandar em 11/2026. O serventias-br herda o valor
     * delas da coleta anterior; a sonda não as cobra, para não avisar o mesmo
     * sumiço toda vez.
     */
    public const CHAVES_APOSENTADAS = ['id', 'latitude', 'longitude', 'cidade_id', 'complemento'];

    protected $signature = 'serventias:sondar {--uf=SC : a UF usada na sonda}';

    protected $description = 'Confere se a API do Justiça Aberta ainda responde no formato que o pipeline espera';

    public function handle(): int
    {
        $uf = strtoupper((string) $this->option('uf'));

        try {
            $resposta = Http::timeout(30)
                ->withHeaders([
                    'Accept' => 'application/json, text/plain, */*',
                    'Origin' => 'https://justicaaberta.cnj.jus.br',
                    'Referer' => 'https://justicaaberta.cnj.jus.br/produtividade-e-localizacao-de-serventias-extrajudiciais',
                    'Authorization' => '',
                    'User-Agent' => 'serventias-laravel (sonda; +https://github.com/kdbnetworks/serventias-br)',
                ])
                ->withQueryParameters(['assignments' => '', 'page' => 1, 'perPage' => 1, 'search' => ''])
                ->post(self::ENDPOINT, ['cidade_id' => 0, 'uf' => $uf, 'cns' => null]);
        } catch (\Throwable $e) {
            $this->error('Sem resposta: '.$e->getMessage());

            return self::FAILURE;
        }

        $tipo = (string) $resposta->header('Content-Type');
        $this->line('HTTP '.$resposta->status().' · '.$tipo);

        if (! $resposta->successful()) {
            $this->error('A rota não respondeu 2xx. Se for 404, confira se ela ainda aceita POST (tools/sniff_spa.py no serventias-br).');

            return self::FAILURE;
        }
        if (! str_contains($tipo, 'json')) {
            $this->error('A resposta não é JSON. O host da SPA devolve index.html com 200 para rota desconhecida: o endpoint provavelmente mudou.');

            return self::FAILURE;
        }

        $corpo = $resposta->json();
        $registros = is_array($corpo['data'] ?? null) ? $corpo['data'] : [];
        $meta = is_array($corpo['meta'] ?? null) ? $corpo['meta'] : [];

        $this->line(sprintf('meta: total=%s · last_page=%s · per_page=%s',
            $meta['total'] ?? '?', $meta['last_page'] ?? '?', $meta['per_page'] ?? '?'));

        if ($registros === []) {
            $this->error("Nenhum registro veio para a UF {$uf}.");

            return self::FAILURE;
        }

        $chaves = array_keys($registros[0]);
        $novas = array_values(array_diff($chaves, self::CHAVES_CONHECIDAS));
        $sumidas = array_values(array_diff(['cns', 'status', 'denominacao_fantasia', 'natureza', 'uf'], $chaves));

        $this->line('1º registro: CNS '.($registros[0]['cns'] ?? '?').' · '.($registros[0]['denominacao_fantasia'] ?? $registros[0]['denominacao_padrao'] ?? '?'));

        if ($sumidas !== []) {
            $this->error('Chaves essenciais que sumiram: '.implode(', ', $sumidas));

            return self::FAILURE;
        }
        // Chave conhecida que some não derruba a sonda (só as essenciais acima), mas é dita:
        // o id e as coordenadas sumiram da API em 11/2026 sem que nada avisasse.
        $ausentes = array_values(array_diff(self::CHAVES_CONHECIDAS, self::CHAVES_APOSENTADAS, $chaves));
        if ($ausentes !== []) {
            $this->warn('Chaves conhecidas que não vieram: '.implode(', ', $ausentes));
        }
        if ($novas !== []) {
            $this->warn('Chaves novas, ainda não mapeadas pelo pipeline: '.implode(', ', $novas));
        } elseif ($ausentes === []) {
            $this->info('Formato conhecido; nenhuma chave nova.');
        }

        return self::SUCCESS;
    }
}
