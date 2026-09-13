{{-- O farol num card. Cole em qualquer painel: <x-serventias::beacon />
     Passe :sanidade="$s" se já tiver a fotografia em mãos; senão o componente pede ao Beacon.
     O estilo é mínimo e vem embutido, para o card ficar legível em qualquer stack de CSS. --}}
@props(['sanidade' => null, 'titulo' => 'Catálogo de serventias'])

@php
    $s = $sanidade ?? app(\KdbNetworks\Serventias\Beacon::class)->sanidade();
    $rotulo = ['verde' => 'Em dia', 'amarelo' => 'Vale olhar', 'vermelho' => 'Precisa de ação'][$s->nivel] ?? $s->nivel;
    $num = fn (int $n) => number_format($n, 0, ',', '.');
@endphp

<style>
    .serventias-beacon{border:1px solid;border-radius:12px;padding:16px 20px;font:14px/1.5 system-ui,sans-serif;max-width:640px}
    .serventias-beacon--verde{border-color:#86efac;background:#f0fdf4;color:#14532d}
    .serventias-beacon--amarelo{border-color:#fcd34d;background:#fffbeb;color:#78350f}
    .serventias-beacon--vermelho{border-color:#fca5a5;background:#fef2f2;color:#7f1d1d}
    .serventias-beacon__topo{display:flex;flex-wrap:wrap;align-items:baseline;gap:4px 12px;margin-bottom:10px}
    .serventias-beacon__titulo{font-size:16px;font-weight:600;margin:0}
    .serventias-beacon__nivel{font-size:12px;letter-spacing:.04em;text-transform:uppercase;font-weight:600}
    .serventias-beacon table{width:100%;border-collapse:collapse}
    .serventias-beacon td{padding:3px 0;vertical-align:top}
    .serventias-beacon td:first-child{opacity:.7;padding-right:12px;white-space:nowrap}
    .serventias-beacon td:last-child{text-align:right}
    .serventias-beacon__motivos{margin:10px 0 0;padding-left:18px}
    .serventias-beacon__rodape{margin:10px 0 0;font-size:12px;opacity:.7}
</style>

<div {{ $attributes->merge(['class' => 'serventias-beacon serventias-beacon--'.$s->nivel]) }} data-nivel="{{ $s->nivel }}">
    <div class="serventias-beacon__topo">
        <p class="serventias-beacon__titulo">{{ $titulo }}</p>
        <span class="serventias-beacon__nivel">{{ $rotulo }}</span>
    </div>

    <table>
        <tr><td>Na tabela</td><td>{{ $num($s->total) }} serventias · {{ $num($s->registroImoveis) }} de Registro de Imóveis · {{ $num($s->ativas) }} ativadas</td></tr>
        <tr>
            <td>Coleta importada</td>
            <td>
                @if ($s->versaoLocal)
                    {{ $s->coletadoEm?->format('d/m/Y') }} · há {{ $s->defasagemDias }} dia(s)
                @else
                    —
                @endif
            </td>
        </tr>
        <tr>
            <td>Última importação</td>
            <td>
                @if ($s->ultimaImportacao)
                    {{ $s->ultimaImportacao->concluido_em?->format('d/m/Y H:i') }} · {{ $s->ultimaImportacao->resultado }}
                    @if ($s->ultimaImportacao->deuCerto()) · {{ $num($s->ultimaImportacao->gravados) }} gravado(s) @endif
                @else
                    nenhuma registrada
                @endif
            </td>
        </tr>
        <tr>
            <td>Coleta publicada</td>
            <td>
                @if ($s->versaoRemota)
                    {{ \Illuminate\Support\Carbon::parse($s->versaoRemota)->format('d/m/Y') }}
                    @if ($s->releaseMaisNova) · mais nova que a importada @else · a mesma @endif
                @elseif ($s->erroRemoto)
                    indisponível
                @else
                    não consultada
                @endif
            </td>
        </tr>
    </table>

    @if ($s->motivos !== [])
        <ul class="serventias-beacon__motivos">
            @foreach ($s->motivos as $m)
                <li>{{ $m }}</li>
            @endforeach
        </ul>
    @endif

    <p class="serventias-beacon__rodape">
        Fonte: Justiça Aberta / CNJ, via serventias-br. Avaliado em {{ $s->avaliadoEm->format('d/m/Y H:i') }}.
    </p>
</div>
