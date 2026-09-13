<?php

declare(strict_types=1);

namespace KdbNetworks\Serventias;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Carbon;
use KdbNetworks\Serventias\Models\Importacao;

/**
 * A fotografia que o farol devolve: o que a tabela tem, quando foi importado,
 * de quando é a coleta, o que a release mais nova diz, e um nível de cor.
 *
 *   verde     em dia, última importação ok
 *   amarelo   vale olhar: coleta velha, release mais nova, manifesto fora do ar
 *   vermelho  vale agir: tabela vazia, importação abortada sem sucesso anterior, coleta muito velha
 *
 * @implements Arrayable<string,mixed>
 */
final class Sanidade implements Arrayable
{
    public const VERDE = 'verde';

    public const AMARELO = 'amarelo';

    public const VERMELHO = 'vermelho';

    /**
     * @param  list<string>  $motivos
     * @param  array<string,mixed>|null  $remoto
     */
    public function __construct(
        public readonly string $nivel,
        public readonly array $motivos,
        public readonly int $total,
        public readonly int $registroImoveis,
        public readonly int $ativas,
        public readonly ?Carbon $coletadoEm,
        public readonly ?string $versaoLocal,
        public readonly ?int $defasagemDias,
        public readonly ?Importacao $ultimaImportacao,
        public readonly ?Importacao $ultimaImportacaoOk,
        public readonly ?array $remoto,
        public readonly ?string $versaoRemota,
        public readonly ?string $erroRemoto,
        public readonly bool $releaseMaisNova,
        public readonly Carbon $avaliadoEm,
    ) {}

    public function emDia(): bool
    {
        return $this->nivel === self::VERDE;
    }

    public function toArray(): array
    {
        return [
            'nivel' => $this->nivel,
            'motivos' => $this->motivos,
            'tabela' => [
                'total' => $this->total,
                'registro_imoveis' => $this->registroImoveis,
                'ativas' => $this->ativas,
                'coletado_em' => $this->coletadoEm?->toIso8601String(),
                'versao' => $this->versaoLocal,
                'defasagem_dias' => $this->defasagemDias,
            ],
            'importacao' => [
                'ultima' => $this->resumo($this->ultimaImportacao),
                'ultima_ok' => $this->resumo($this->ultimaImportacaoOk),
            ],
            'remoto' => [
                'versao' => $this->versaoRemota,
                'coletado_em' => $this->remoto['coletado_em'] ?? null,
                'sha256' => Manifesto::shaDoCsv($this->remoto),
                'release_mais_nova' => $this->releaseMaisNova,
                'erro' => $this->erroRemoto,
            ],
            'avaliado_em' => $this->avaliadoEm->toIso8601String(),
        ];
    }

    /** @return array<string,mixed>|null */
    private function resumo(?Importacao $i): ?array
    {
        if ($i === null) {
            return null;
        }

        return [
            'quando' => $i->concluido_em?->toIso8601String() ?? $i->iniciado_em?->toIso8601String(),
            'resultado' => $i->resultado,
            'versao' => $i->versao,
            'gravados' => $i->gravados,
            'tabela_depois' => $i->tabela_depois,
            'mensagem' => $i->mensagem,
        ];
    }
}
