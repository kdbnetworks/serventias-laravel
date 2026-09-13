<?php

declare(strict_types=1);

namespace KdbNetworks\Serventias;

use Illuminate\Support\Carbon;
use KdbNetworks\Serventias\Models\Importacao;

/** O que uma rodada de importação contou, e como terminou. */
final class Resultado
{
    public int $lidos = 0;

    public int $validos = 0;

    public int $semCns = 0;

    public int $malformados = 0;

    public int $filtrados = 0;

    public int $aGravar = 0;

    public int $gravados = 0;

    public int $tabelaAntes = 0;

    public int $tabelaDepois = 0;

    public ?string $sha256 = null;

    public ?Carbon $coletadoEm = null;

    /** ok · ensaio · abortado · falha */
    public string $resultado = Importacao::FALHA;

    public ?string $mensagem = null;

    public ?Importacao $importacao = null;

    public function __construct(public readonly string $arquivo) {}

    public function deuCerto(): bool
    {
        return in_array($this->resultado, [Importacao::OK, Importacao::ENSAIO], true);
    }
}
