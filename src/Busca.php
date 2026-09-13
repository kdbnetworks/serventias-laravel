<?php

declare(strict_types=1);

namespace KdbNetworks\Serventias;

use Illuminate\Database\Eloquent\Builder;
use KdbNetworks\Serventias\Models\Serventia;

/**
 * A busca do autocomplete.
 *
 * Trabalha sobre `search_text`, que é desacentuado e minúsculo e já vem
 * pré-computado da importação: assim "itajai" encontra "Itajaí" sem
 * `unaccent()` por linha a cada tecla. Inativas e extintas aparecem, porque
 * matrícula antiga cita a serventia da época; a ordenação só empurra as
 * ativas para cima.
 */
class Busca
{
    /**
     * @return list<array{cns_digits:string, cns:string, denominacao:string, contexto:string, ativa:bool, status:?string, municipio:?string, uf:?string}>
     */
    public function resultados(string $termo, ?int $limite = null, ?string $atribuicao = null): array
    {
        $consulta = $this->consulta($termo, $atribuicao);
        if ($consulta === null) {
            return [];
        }

        return $consulta
            ->limit($limite ?? (int) config('serventias.busca_limite', 8))
            ->get()
            ->map(fn (Serventia $s) => [
                'cns_digits' => $s->cns_digits,
                'cns' => $s->cns,
                'denominacao' => $s->denominacao,
                'contexto' => $s->contexto(),
                'ativa' => $s->ativa(),
                'status' => $s->status,
                'municipio' => $s->municipio,
                'uf' => $s->uf,
            ])->all();
    }

    /**
     * O query builder por trás dos resultados, para quem quer paginar ou
     * carregar colunas a mais. Null quando o termo é curto demais.
     *
     * @return Builder<Serventia>|null
     */
    public function consulta(string $termo, ?string $atribuicao = null): ?Builder
    {
        $termo = trim($termo);
        $min = (int) config('serventias.busca_min', 3);
        $digitos = preg_replace('/\D/', '', $termo) ?? '';

        // CNS parcial (4+ dígitos) vale como busca mesmo sendo curto.
        if (mb_strlen($termo) < $min && strlen($digitos) < 4) {
            return null;
        }

        // Cada palavra vira um LIKE próprio: "registro itajai" encontra
        // "1º Registro de Imóveis de Itajaí" mesmo com o "de" no meio.
        $palavras = preg_split('/\s+/', self::desacentuar($termo), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return Serventia::query()
            ->where(function (Builder $q) use ($palavras): void {
                foreach ($palavras as $p) {
                    $q->where('search_text', 'like', '%'.$p.'%');
                }
            })
            ->when($atribuicao, fn (Builder $q) => $q->where('atribuicoes', 'like', '%'.$atribuicao.'%'))
            ->orderByRaw("CASE WHEN status = 'ATIVADA' THEN 0 ELSE 1 END")
            ->orderBy('uf')
            ->orderBy('municipio')
            ->orderBy('denominacao');
    }

    /** O CNS digitado inteiro resolve a serventia do catálogo, ou null. */
    public function porCns(?string $bruto): ?Serventia
    {
        $d = preg_replace('/\D/', '', (string) $bruto) ?? '';

        return strlen($d) === 6 ? Serventia::find($d) : null;
    }

    /**
     * Minúsculo e sem acento, do mesmo jeito em qualquer sistema. O `iconv`
     * com TRANSLIT dá resultado diferente por plataforma (no Windows, "ó" vira
     * "'o"), e o texto de busca precisa ser idêntico entre quem importou e
     * quem digita.
     */
    public static function desacentuar(string $s): string
    {
        $t = mb_strtolower(trim($s));

        if (class_exists(\Normalizer::class)) {
            $nfkd = \Normalizer::normalize($t, \Normalizer::FORM_KD);
            if (is_string($nfkd)) {
                $t = preg_replace('/\p{Mn}+/u', '', $nfkd) ?? $nfkd;
            }
        }

        return strtr($t, self::TABELA_ACENTOS);
    }

    /** Fallback para PHP sem intl, e o que o NFKD não cobre. */
    private const TABELA_ACENTOS = [
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a', 'ª' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o', 'º' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n', 'ý' => 'y', 'ÿ' => 'y',
    ];
}
