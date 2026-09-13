<?php

declare(strict_types=1);

namespace KdbNetworks\Serventias\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Uma serventia extrajudicial do catálogo nacional (Justiça Aberta/CNJ).
 *
 * Chave primária é o CNS em 6 dígitos, string, não incremental. Sem chave de
 * tenant: a lista de cartórios do Brasil é a mesma para todos.
 *
 * Quem cita uma serventia (um imóvel, um confrontante) deve guardar o CNS,
 * e nunca uma FK para cá: se uma reimportação mudar a linha, a citação antiga
 * continua verdadeira.
 */
class Serventia extends Model
{
    protected $primaryKey = 'cns_digits';

    protected $keyType = 'string';

    public $incrementing = false;

    const CREATED_AT = 'criado_em';

    const UPDATED_AT = 'atualizado_em';

    protected $guarded = [];

    public function getTable(): string
    {
        return $this->table ?? (string) config('serventias.tabela', 'serventias');
    }

    protected function casts(): array
    {
        return [
            'coletado_em' => 'datetime',
        ];
    }

    /** As flags de atribuição como lista: 'registro_imoveis|notas' vira ['registro_imoveis', 'notas']. */
    public function atribuicoesLista(): array
    {
        return array_values(array_filter(explode('|', (string) $this->atribuicoes)));
    }

    public function temAtribuicao(string $flag): bool
    {
        return in_array($flag, $this->atribuicoesLista(), true);
    }

    public function ativa(): bool
    {
        return $this->status === 'ATIVADA';
    }

    /** Rótulo curto para um dropdown: "Itajaí/SC · ATIVADA". */
    public function contexto(): string
    {
        $local = trim((string) $this->municipio.($this->uf ? '/'.$this->uf : ''), '/');

        return trim($local.' · '.($this->status ?: '—'), ' ·');
    }
}
