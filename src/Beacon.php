<?php

declare(strict_types=1);

namespace KdbNetworks\Serventias;

use Illuminate\Support\Carbon;
use KdbNetworks\Serventias\Models\Importacao;
use KdbNetworks\Serventias\Models\Serventia;

/**
 * O farol: responde «o catálogo está em dia?» com uma cor e os motivos.
 *
 * Só lê. Nada aqui importa, baixa ou toca no CNJ; quem age é o comando no
 * terminal ou o botão que a sua aplicação decidir expor. Um painel de
 * diagnóstico com ação embutida é painel que se usa no susto.
 */
class Beacon
{
    public function __construct(private readonly Manifesto $manifesto) {}

    public function sanidade(bool $semRemoto = false): Sanidade
    {
        $agora = Carbon::now();
        $motivos = [];

        // ---- a tabela ----------------------------------------------------------
        $total = Serventia::query()->count();
        $ri = Serventia::query()->where('atribuicoes', 'like', '%registro_imoveis%')->count();
        $ativas = Serventia::query()->where('status', 'ATIVADA')->count();
        $coletaBruta = Serventia::query()->max('coletado_em');
        $coletadoEm = $coletaBruta ? Carbon::parse($coletaBruta) : null;
        $versaoLocal = $coletadoEm?->toDateString();
        $defasagem = $coletadoEm ? (int) $coletadoEm->diffInDays($agora) : null;

        // ---- o diário ----------------------------------------------------------
        $ultima = $ultimaOk = null;
        try {
            $ultima = Importacao::query()->orderByDesc('iniciado_em')->orderByDesc('id')->first();
            $ultimaOk = Importacao::query()->where('resultado', Importacao::OK)
                ->orderByDesc('iniciado_em')->orderByDesc('id')->first();
        } catch (\Throwable) {
            // sem a tabela do diário, o farol segue só com o que a tabela principal diz
        }

        // ---- o remoto ----------------------------------------------------------
        $remoto = $semRemoto ? null : $this->manifesto->remoto();
        $erroRemoto = $semRemoto ? null : $this->manifesto->ultimoErro();
        $versaoRemota = Manifesto::versao($remoto);
        $releaseMaisNova = $versaoRemota !== null && $versaoLocal !== null && $versaoRemota > $versaoLocal;

        // ---- o veredito --------------------------------------------------------
        $aviso = (int) config('serventias.manifesto.aviso_dias', 60);
        $alerta = (int) config('serventias.manifesto.alerta_dias', 120);
        $nivel = Sanidade::VERDE;
        $sobe = function (string $para) use (&$nivel): void {
            if ($para === Sanidade::VERMELHO || ($para === Sanidade::AMARELO && $nivel === Sanidade::VERDE)) {
                $nivel = $para;
            }
        };

        if ($total === 0) {
            $sobe(Sanidade::VERMELHO);
            $motivos[] = 'A tabela está vazia. Rode serventias:importar.';
        }

        if ($ultima !== null && ! $ultima->deuCerto() && ! $ultima->ensaio) {
            if ($ultimaOk === null) {
                $sobe(Sanidade::VERMELHO);
                $motivos[] = 'A última importação '.$ultima->resultado.' e nunca houve uma que desse certo: '.$ultima->mensagem;
            } else {
                $sobe(Sanidade::AMARELO);
                $motivos[] = 'A última importação '.$ultima->resultado.'; a tabela ainda é da rodada de '
                    .$ultimaOk->concluido_em?->format('d/m/Y').': '.$ultima->mensagem;
            }
        }

        if ($defasagem !== null && $defasagem >= $alerta) {
            $sobe(Sanidade::VERMELHO);
            $motivos[] = "A coleta tem {$defasagem} dias, acima do alerta de {$alerta}.";
        } elseif ($defasagem !== null && $defasagem >= $aviso) {
            $sobe(Sanidade::AMARELO);
            $motivos[] = "A coleta tem {$defasagem} dias, acima do aviso de {$aviso}.";
        }

        if ($releaseMaisNova) {
            $sobe(Sanidade::AMARELO);
            $motivos[] = "Há uma coleta mais nova publicada ({$versaoRemota}) do que a importada ({$versaoLocal}).";
        }

        if (! $semRemoto && $remoto === null) {
            $sobe(Sanidade::AMARELO);
            $motivos[] = 'Não consegui ler o manifesto remoto: '.($erroRemoto ?? 'motivo desconhecido');
        }

        return new Sanidade(
            nivel: $nivel,
            motivos: $motivos,
            total: $total,
            registroImoveis: $ri,
            ativas: $ativas,
            coletadoEm: $coletadoEm,
            versaoLocal: $versaoLocal,
            defasagemDias: $defasagem,
            ultimaImportacao: $ultima,
            ultimaImportacaoOk: $ultimaOk,
            remoto: $remoto,
            versaoRemota: $versaoRemota,
            erroRemoto: $erroRemoto,
            releaseMaisNova: $releaseMaisNova,
            avaliadoEm: $agora,
        );
    }
}
