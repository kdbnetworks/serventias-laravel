<?php

declare(strict_types=1);

namespace KdbNetworks\Serventias\Tests;

use KdbNetworks\Serventias\Busca;
use KdbNetworks\Serventias\Cns;
use KdbNetworks\Serventias\Models\Serventia;

final class BuscaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Serventia::insert([
            ['cns_digits' => '104497', 'cns' => '10.449-7', 'denominacao' => '2º REGISTRO DE IMOVEIS',
                'status' => 'ATIVADA', 'tipo_registro' => 'serventia', 'municipio' => 'Itajaí', 'uf' => 'SC',
                'atribuicoes' => 'registro_imoveis', 'search_text' => '104497 10.449-7 2o registro de imoveis itajai sc'],
            ['cns_digits' => '107441', 'cns' => '10.744-1', 'denominacao' => '1º Ofício de Registro de Imóveis de Itajaí',
                'status' => 'ATIVADA', 'tipo_registro' => 'serventia', 'municipio' => 'Itajaí', 'uf' => 'SC',
                'atribuicoes' => 'registro_imoveis', 'search_text' => '107441 10.744-1 1o oficio de registro de imoveis de itajai itajai sc'],
            ['cns_digits' => '999999', 'cns' => '99.999-9', 'denominacao' => 'REGISTRO DE IMOVEIS DE PORTO VELHO (extinto)',
                'status' => 'EXTINTA', 'tipo_registro' => 'serventia', 'municipio' => 'Porto Velho', 'uf' => 'RO',
                'atribuicoes' => 'registro_imoveis', 'search_text' => '999999 99.999-9 registro de imoveis de porto velho extinto porto velho ro'],
            ['cns_digits' => '111111', 'cns' => '11.111-1', 'denominacao' => 'Tabelionato de Notas de Itajaí',
                'status' => 'ATIVADA', 'tipo_registro' => 'serventia', 'municipio' => 'Itajaí', 'uf' => 'SC',
                'atribuicoes' => 'notas', 'search_text' => '111111 11.111-1 tabelionato de notas de itajai itajai sc'],
        ]);
    }

    public function test_busca_sem_acento_encontra_e_ordena_ativas_primeiro(): void
    {
        $r = app(Busca::class)->resultados('registro imoveis');
        $this->assertSame(['107441', '104497', '999999'], array_column($r, 'cns_digits'));
        $this->assertTrue($r[0]['ativa']);
        $this->assertFalse($r[2]['ativa']);
        $this->assertSame('Itajaí/SC · ATIVADA', $r[0]['contexto']);
    }

    public function test_termo_curto_nao_dispara_mas_cns_parcial_dispara(): void
    {
        $b = app(Busca::class);
        $this->assertSame([], $b->resultados('it'));
        $this->assertSame(['104497'], array_column($b->resultados('1044'), 'cns_digits'));
        $this->assertSame(['104497'], array_column($b->resultados('10.449-7'), 'cns_digits'));
    }

    public function test_filtro_por_atribuicao_e_limite(): void
    {
        $b = app(Busca::class);
        $this->assertSame(['111111'], array_column($b->resultados('itajai', null, 'notas'), 'cns_digits'));
        $this->assertCount(2, $b->resultados('itajai', 2));
    }

    public function test_por_cns_resolve_so_com_seis_digitos(): void
    {
        $b = app(Busca::class);
        $this->assertSame('2º REGISTRO DE IMOVEIS', $b->porCns('10.449-7')?->denominacao);
        $this->assertNull($b->porCns('1044'));
        $this->assertNull($b->porCns('000000'));
    }

    public function test_cns_helpers(): void
    {
        $this->assertSame('123456', Cns::limpar('12.345-6'));
        $this->assertSame('098761', Cns::limpar('9876-1'));
        $this->assertNull(Cns::limpar('1234567'));
        $this->assertNull(Cns::limpar(''));
        $this->assertSame('12.345-6', Cns::formatar('123456'));
        $this->assertTrue(Cns::valido(' 123456 '));
        $this->assertSame(['123456', '045678'], Cns::noTexto('Ofício de RI, CNS: 12.345-6, Livro 2. Ver CNS 04.567-8.'));
    }
}
