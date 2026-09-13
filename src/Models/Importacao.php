<?php

declare(strict_types=1);

namespace KdbNetworks\Serventias\Models;

use Illuminate\Database\Eloquent\Model;

/** Uma rodada do `serventias:importar`, tenha ela gravado ou não. */
class Importacao extends Model
{
    public const OK = 'ok';

    public const ENSAIO = 'ensaio';

    public const ABORTADO = 'abortado';

    public const FALHA = 'falha';

    public $timestamps = false;

    protected $guarded = [];

    public function getTable(): string
    {
        return $this->table ?? (string) config('serventias.tabela', 'serventias').'_importacoes';
    }

    protected function casts(): array
    {
        return [
            'iniciado_em' => 'datetime',
            'concluido_em' => 'datetime',
            'coletado_em' => 'datetime',
            'todas' => 'bool',
            'com_ruido' => 'bool',
            'ensaio' => 'bool',
            'forcado' => 'bool',
        ];
    }

    public function deuCerto(): bool
    {
        return $this->resultado === self::OK;
    }
}
