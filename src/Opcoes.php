<?php

declare(strict_types=1);

namespace KdbNetworks\Serventias;

/** As escolhas de uma rodada de importação. */
final class Opcoes
{
    public function __construct(
        public readonly ?string $arquivo = null,
        public readonly bool $todas = false,
        public readonly bool $comRuido = false,
        public readonly bool $dryRun = false,
        public readonly bool $force = false,
    ) {}
}
