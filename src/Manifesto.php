<?php

declare(strict_types=1);

namespace KdbNetworks\Serventias;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Lê o manifesto do serventias-br, o farol que diz de quando é a última
 * coleta publicada e qual o hash de cada arquivo.
 *
 * O remoto vai para o cache: um painel que abre cem vezes por dia não precisa
 * bater cem vezes no GitHub. Quando a rede falha, devolve null e explica; o
 * farol trata isso como «não sei», nunca como «está em dia».
 */
class Manifesto
{
    public const ESQUEMA = 'serventias-br/manifesto@1';

    private ?string $ultimoErro = null;

    /** @return array<string,mixed>|null */
    public function remoto(bool $forcar = false): ?array
    {
        $url = (string) config('serventias.manifesto.url');
        if ($url === '') {
            $this->ultimoErro = 'Sem URL de manifesto configurada.';

            return null;
        }

        $chave = 'serventias.manifesto.'.md5($url);
        if ($forcar) {
            Cache::forget($chave);
        }

        $minutos = max(1, (int) config('serventias.manifesto.cache_minutos', 360));

        try {
            $lido = Cache::remember($chave, now()->addMinutes($minutos), function () use ($url): array {
                $resposta = Http::timeout((int) config('serventias.manifesto.timeout', 5))
                    ->acceptJson()
                    ->get($url);
                if (! $resposta->successful()) {
                    throw new \RuntimeException("HTTP {$resposta->status()} ao ler o manifesto.");
                }
                $dados = $resposta->json();
                if (! is_array($dados) || ($dados['esquema'] ?? null) !== self::ESQUEMA) {
                    throw new \RuntimeException('A resposta não é um manifesto do serventias-br.');
                }

                return $dados;
            });
            $this->ultimoErro = null;

            return $lido;
        } catch (\Throwable $e) {
            $this->ultimoErro = $e->getMessage();

            return null;
        }
    }

    public function ultimoErro(): ?string
    {
        return $this->ultimoErro;
    }

    /** @return array<string,mixed>|null */
    public function deArquivo(string $caminho): ?array
    {
        if (! is_file($caminho)) {
            return null;
        }
        $dados = json_decode((string) file_get_contents($caminho), true);

        return is_array($dados) && ($dados['esquema'] ?? null) === self::ESQUEMA ? $dados : null;
    }

    /** A versão (data da coleta) que um manifesto declara, ou null. */
    public static function versao(?array $manifesto): ?string
    {
        $v = $manifesto['versao'] ?? null;

        return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    }

    /** O sha256 do csv.gz que um manifesto declara, ou null. */
    public static function shaDoCsv(?array $manifesto): ?string
    {
        $s = $manifesto['arquivos']['serventias.csv.gz']['sha256'] ?? null;

        return is_string($s) ? strtolower($s) : null;
    }

    public static function urlDoCsv(?array $manifesto): ?string
    {
        $u = $manifesto['arquivos']['serventias.csv.gz']['url'] ?? null;

        return is_string($u) ? $u : null;
    }
}
