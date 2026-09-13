<?php

declare(strict_types=1);

namespace KdbNetworks\Serventias\Http;

use Illuminate\Http\JsonResponse;
use KdbNetworks\Serventias\Beacon;
use KdbNetworks\Serventias\Sanidade;

/**
 * A sanidade em JSON, para um monitor externo. O status HTTP acompanha a cor:
 * 200 para verde e amarelo, 503 para vermelho, que é o que um healthcheck
 * entende sem ler o corpo.
 */
class BeaconController
{
    public function __invoke(Beacon $beacon): JsonResponse
    {
        $s = $beacon->sanidade();

        return new JsonResponse($s->toArray(), $s->nivel === Sanidade::VERMELHO ? 503 : 200);
    }
}
