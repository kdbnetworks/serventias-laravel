<?php

declare(strict_types=1);

namespace KdbNetworks\Serventias;

/**
 * O Código Nacional da Serventia: 6 dígitos, exibido como NN.NNN-N.
 *
 * Só o formato é validado. O último dígito parece verificador, mas o
 * algoritmo não é público; um mod-11 inventado aqui rejeitaria cartório
 * legítimo. Para fé pública, a fonte é o CNJ.
 */
final class Cns
{
    /** Só os dígitos, ou null quando não dá para recuperar 6 deles. */
    public static function limpar(?string $bruto): ?string
    {
        $d = preg_replace('/\D/', '', (string) $bruto) ?? '';
        if ($d === '') {
            return null;
        }
        if (strlen($d) < 6) {
            $d = str_pad($d, 6, '0', STR_PAD_LEFT);   // fontes antigas cortam zero à esquerda
        }

        return strlen($d) === 6 ? $d : null;
    }

    /** '123456' vira '12.345-6'. */
    public static function formatar(string $digitos): string
    {
        $d = self::limpar($digitos);
        if ($d === null) {
            throw new \InvalidArgumentException("CNS inválido: {$digitos}");
        }

        return substr($d, 0, 2).'.'.substr($d, 2, 3).'-'.substr($d, 5, 1);
    }

    public static function valido(?string $bruto): bool
    {
        return self::limpar($bruto) !== null;
    }

    /**
     * Acha CNS em texto livre (cabeçalho de certidão, matrícula colada).
     *
     * @return list<string> os 6 dígitos de cada CNS, na ordem, sem repetição
     */
    public static function noTexto(?string $texto): array
    {
        preg_match_all('/\b(\d{2})\.?(\d{3})-?(\d)\b/', (string) $texto, $m, PREG_SET_ORDER);
        $vistos = [];
        foreach ($m as $x) {
            $vistos[$x[1].$x[2].$x[3]] = true;
        }

        return array_map('strval', array_keys($vistos));
    }
}
