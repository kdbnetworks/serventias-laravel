<?php

declare(strict_types=1);

namespace KdbNetworks\Serventias;

use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use KdbNetworks\Serventias\Models\Importacao;
use KdbNetworks\Serventias\Models\Serventia;

/**
 * Importa o `serventias.csv.gz` do serventias-br para a tabela local.
 *
 * ## O CSV é a fronteira, e é de propósito
 *
 * Esta aplicação nunca fala com o CNJ. Quando o Justiça Aberta muda de rota,
 * de verbo ou de nome de campo, quem quebra é o pipeline do serventias-br;
 * aqui o arquivo anterior continua servindo dado velho em vez de dado nenhum.
 *
 * Por isso a leitura é por cabeçalho, nunca por posição, e cada campo sai com
 * `?? null`: coluna nova é ignorada (e reportada), coluna reordenada é
 * indiferente, coluna removida vira null. Só COLUNAS_EXIGIDAS aborta.
 *
 * ## As três travas
 *
 * Um CSV meio-gerado é sintaticamente válido e pequeno. Sem trava, uma
 * extração que morreu no meio entraria em silêncio e a tela ficaria com 40
 * serventias. Então:
 *
 *  1. Cabeçalho: falta coluna exigida, aborta antes de tocar no banco.
 *  2. Piso absoluto: menos que `serventias.minimo_esperado` linhas válidas, aborta.
 *  3. Piso relativo: encolhimento maior que 20% do que já está na tabela, aborta.
 *
 * `force` passa por cima das três. `dryRun` faz o percurso inteiro e não grava.
 *
 * ## Idempotência
 *
 * Upsert por `cns_digits`: rodar a cada deploy é seguro e barato. Nada é
 * apagado. Se um CNS sair da fonte, a linha permanece, porque matrícula antiga
 * cita a serventia que existia à época.
 *
 * Cada rodada, gravando ou não, deixa uma linha em `serventias_importacoes`.
 */
class Importador
{
    private const LOTE = 500;

    /** Sem estas, o registro não serve para o localizador. */
    public const COLUNAS_EXIGIDAS = ['cns_digits', 'denominacao', 'atribuicoes', 'tipo_registro'];

    /** Tudo que sabemos aproveitar. O resto do CSV é ignorado, e reportado. */
    public const COLUNAS_CONHECIDAS = [
        'cns', 'cns_digits', 'denominacao', 'status', 'status_raw',
        'situacao_juridica', 'situacao_juridica_raw', 'tipo_registro',
        'municipio', 'uf', 'atribuicoes', 'atribuicoes_raw', 'endereco',
        'bairro', 'cep', 'telefone', 'email', 'website', 'responsavel',
        'comarca', 'circunscricao', 'codigo_ibge', 'latitude', 'longitude',
        'id_cnj', 'fonte', 'coletado_em',
        // 11/2026: o regime, os substitutos e a UF da comarca
        'tipo_cartorio', 'substitutos', 'comarca_uf',
    ];

    /** @var list<string> as colunas que a tabela realmente tem; o importador só grava nessas */
    private array $colunasDaTabela = [];

    /**
     * @param  Closure(string $nivel, string $mensagem): void|null  $relator  recebe 'info', 'aviso' ou 'erro'
     */
    public function importar(Opcoes $o, ?Closure $relator = null): Resultado
    {
        $diga = $relator ?? static fn (string $n, string $m): null => null;
        $inicio = Carbon::now();

        $caminho = $this->resolverArquivo($o->arquivo);
        if ($caminho === null) {
            return $this->registrar(Importacao::FALHA, $o, $inicio, '(nenhum)', null,
                'Nenhuma fonte de serventias encontrada. Aponte serventias.snapshot ou rode serventias:baixar.');
        }

        $fh = $this->abrir($caminho);
        if ($fh === null) {
            return $this->registrar(Importacao::FALHA, $o, $inicio, $caminho, null, "Não consegui abrir {$caminho}");
        }
        $diga('info', "Fonte: {$caminho}");

        // ---- trava 1: cabeçalho --------------------------------------------
        $cabecalho = fgetcsv($fh, 0, ';', '"', '');
        if ($cabecalho === false) {
            fclose($fh);

            return $this->registrar(Importacao::ABORTADO, $o, $inicio, $caminho, null, 'CSV vazio.');
        }
        $cabecalho = array_map(fn ($c) => trim((string) $c, " \t\n\r\0\x0B\xEF\xBB\xBF"), $cabecalho);

        $faltando = array_diff(self::COLUNAS_EXIGIDAS, $cabecalho);
        if ($faltando !== []) {
            fclose($fh);

            return $this->registrar(Importacao::ABORTADO, $o, $inicio, $caminho, null,
                'Cabeçalho incompatível, colunas exigidas ausentes: '.implode(', ', $faltando)
                .'. Encontrado: '.implode(', ', $cabecalho));
        }

        $desconhecidas = array_diff($cabecalho, self::COLUNAS_CONHECIDAS);
        if ($desconhecidas !== []) {
            $diga('aviso', 'Colunas novas no CSV, ignoradas por este importador: '.implode(', ', $desconhecidas));
        }

        // ---- leitura ----------------------------------------------------------
        $soRi = ! $o->todas;
        $linhaNum = 1;
        $r = new Resultado(arquivo: $caminho);
        $pendentes = [];
        $coletas = [];

        while (($linha = fgetcsv($fh, 0, ';', '"', '')) !== false) {
            $linhaNum++;
            if ($linha === [null] || $linha === []) {
                continue;
            }
            $r->lidos++;

            if (count($linha) !== count($cabecalho)) {
                $r->malformados++;
                if ($r->malformados <= 3) {
                    $diga('aviso', "  linha {$linhaNum}: ".count($linha).' campos, esperado '.count($cabecalho).', ignorada');
                }

                continue;
            }
            $row = array_combine($cabecalho, $linha);

            $cnsDigits = preg_replace('/\D/', '', (string) ($row['cns_digits'] ?? '')) ?? '';
            if (strlen($cnsDigits) !== 6) {
                $r->semCns++;

                continue;
            }
            $r->validos++;

            $tipo = (string) ($row['tipo_registro'] ?? 'serventia');
            if (! $o->comRuido && $tipo !== 'serventia') {
                $r->filtrados++;

                continue;
            }

            $atribuicoes = (string) ($row['atribuicoes'] ?? '');
            if ($soRi && ! in_array('registro_imoveis', array_filter(explode('|', $atribuicoes)), true)) {
                $r->filtrados++;

                continue;
            }

            if (($row['coletado_em'] ?? '') !== '') {
                $coletas[] = (string) $row['coletado_em'];
            }
            $pendentes[] = $this->montar($cnsDigits, $tipo, $atribuicoes, $row);
        }
        fclose($fh);

        $r->coletadoEm = $coletas === [] ? null : Carbon::parse(max($coletas));
        $r->sha256 = hash_file('sha256', $caminho) ?: null;

        // ---- travas 2 e 3: volume ---------------------------------------------
        // A baseline é do MESMO escopo desta importação. Comparar 3.012 RI
        // contra os 12.012 de um `--todas` anterior acusaria uma queda de 75%
        // que nunca existiu.
        $r->aGravar = count($pendentes);
        $r->tabelaAntes = $this->baselineDoEscopo($soRi, $o->comRuido);

        if (! $o->force) {
            $erro = $this->violacaoDeSanidade($r->aGravar, $r->tabelaAntes);
            if ($erro !== null) {
                return $this->registrar(Importacao::ABORTADO, $o, $inicio, $caminho, $r,
                    $erro.' Se o encolhimento for real, repita com --force.');
            }
        }

        if ($r->malformados > 3) {
            $diga('aviso', '  … e mais '.($r->malformados - 3).' linha(s) malformada(s).');
        }

        // ---- gravação ---------------------------------------------------------
        if (! $o->dryRun) {
            $this->colunasDaTabela = Schema::getColumnListing((new Serventia)->getTable());
            foreach (array_chunk($pendentes, self::LOTE) as $chunk) {
                $r->gravados += $this->gravar($chunk);
            }
        }
        $r->tabelaDepois = Serventia::query()->count();

        $diga('info', sprintf('Lidas %d linha(s): %d válida(s), %d sem CNS, %d malformada(s).',
            $r->lidos, $r->validos, $r->semCns, $r->malformados));
        $diga('info', sprintf('Filtradas %d por atribuição/tipo.', $r->filtrados));

        if ($o->dryRun) {
            $diga('info', "[ensaio] {$r->aGravar} registro(s) seriam gravados (tabela tem {$r->tabelaAntes}).");

            return $this->registrar(Importacao::ENSAIO, $o, $inicio, $caminho, $r);
        }

        $diga('info', "Gravados {$r->gravados} · tabela: {$r->tabelaDepois} registros");
        if ($soRi) {
            $diga('info', 'Filtro: só Registro de Imóveis (use --todas para o catálogo completo).');
        }

        return $this->registrar(Importacao::OK, $o, $inicio, $caminho, $r);
    }

    /**
     * Argumento explícito, depois SERVENTIAS_CSV, depois o arquivo baixado
     * mais novo, depois o snapshot do repositório. O snapshot é o único que
     * sempre existe num deploy limpo.
     */
    public function resolverArquivo(?string $explicito = null): ?string
    {
        $candidatos = array_filter([
            $explicito,
            (string) config('serventias.csv'),
            $this->ultimoBaixado(),
            (string) config('serventias.snapshot'),
        ]);

        foreach ($candidatos as $c) {
            if (is_file($c)) {
                return $c;
            }
        }

        return null;
    }

    /**
     * O `.csv.gz` mais recente trazido por `serventias:baixar`, pelo nome (a versão é a data).
     *
     * O disco pode não ser local (no Matrize, o disco `local` é um bucket S3): aí
     * `path()` devolve a chave do bucket, que não é arquivo nenhum, e o
     * `resolverArquivo()` caía no snapshot em silêncio. Nesse caso o arquivo vem
     * para um temporário local, com o mesmo nome, e é esse que se importa.
     */
    public function ultimoBaixado(): ?string
    {
        try {
            $disco = Storage::disk((string) config('serventias.armazenamento.disco', 'local'));
            $pasta = (string) config('serventias.armazenamento.pasta', 'serventias');
            $arquivos = array_values(array_filter($disco->files($pasta), fn ($f) => str_ends_with($f, '.csv.gz')));
            if ($arquivos === []) {
                return null;
            }
            rsort($arquivos);

            $caminho = $disco->path($arquivos[0]);
            if (is_file($caminho)) {
                return $caminho;
            }

            return $this->trazerParaLocal($disco, $arquivos[0]);
        } catch (\Throwable) {
            return null;
        }
    }

    private function trazerParaLocal(\Illuminate\Contracts\Filesystem\Filesystem $disco, string $chave): ?string
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'serventias-laravel';
        if (! is_dir($dir) && ! @mkdir($dir, 0o700, true) && ! is_dir($dir)) {
            return null;
        }
        $local = $dir.DIRECTORY_SEPARATOR.basename($chave);

        $origem = $disco->readStream($chave);
        if (! is_resource($origem)) {
            return null;
        }
        $destino = @fopen($local, 'wb');
        if ($destino === false) {
            fclose($origem);

            return null;
        }
        stream_copy_to_stream($origem, $destino);
        fclose($origem);
        fclose($destino);

        return $local;
    }

    private function registrar(string $resultado, Opcoes $o, Carbon $inicio, string $arquivo, ?Resultado $r, ?string $mensagem = null): Resultado
    {
        $r ??= new Resultado(arquivo: $arquivo);
        $r->resultado = $resultado;
        $r->mensagem = $mensagem;

        try {
            $r->importacao = Importacao::create([
                'iniciado_em' => $inicio,
                'concluido_em' => Carbon::now(),
                'arquivo' => mb_substr($arquivo, 0, 400),
                'sha256' => $r->sha256,
                'coletado_em' => $r->coletadoEm,
                'versao' => $r->coletadoEm?->toDateString(),
                'todas' => $o->todas,
                'com_ruido' => $o->comRuido,
                'ensaio' => $o->dryRun,
                'forcado' => $o->force,
                'lidos' => $r->lidos,
                'validos' => $r->validos,
                'malformados' => $r->malformados,
                'filtrados' => $r->filtrados,
                'gravados' => $r->gravados,
                'tabela_depois' => $r->tabelaDepois,
                'resultado' => $resultado,
                'mensagem' => $mensagem,
            ]);
        } catch (\Throwable) {
            // Uma aplicação sem a tabela do diário (migration ainda não rodou)
            // continua importando; o diário é conforto, e não pré-condição.
        }

        return $r;
    }

    /** Quantos registros já existentes caem no mesmo recorte desta importação. */
    private function baselineDoEscopo(bool $soRi, bool $comRuido): int
    {
        return Serventia::query()
            ->when($soRi, fn ($q) => $q->where('atribuicoes', 'like', '%registro_imoveis%'))
            ->when(! $comRuido, fn ($q) => $q->where('tipo_registro', 'serventia'))
            ->count();
    }

    private function violacaoDeSanidade(int $aGravar, int $atual): ?string
    {
        $minimo = (int) config('serventias.minimo_esperado', 2500);

        if ($aGravar < $minimo) {
            return "Só {$aGravar} registro(s) aproveitáveis, abaixo do mínimo de {$minimo}. "
                .'A extração provavelmente falhou no meio; nada foi gravado.';
        }

        if ($atual > 0 && $aGravar < (int) ($atual * 0.8)) {
            return "O arquivo traz {$aGravar} registro(s) contra {$atual} já na tabela "
                .'(queda acima de 20%). Nada foi gravado.';
        }

        return null;
    }

    /** @return resource|null */
    private function abrir(string $caminho)
    {
        // compress.zlib:// dá um stream comum: fgetcsv funciona igual em .gz.
        $stream = str_ends_with(strtolower($caminho), '.gz') ? 'compress.zlib://'.$caminho : $caminho;
        $fh = @fopen($stream, 'r');

        return $fh === false ? null : $fh;
    }

    /** @return array<string,mixed> */
    private function montar(string $cnsDigits, string $tipo, string $atribuicoes, array $r): array
    {
        return [
            'cns_digits' => $cnsDigits,
            'cns' => Cns::formatar($cnsDigits),
            'denominacao' => $this->corta($r['denominacao'] ?? '', 240) ?: '(sem denominação)',
            'status' => $this->corta($r['status'] ?? null, 20),
            'status_raw' => $this->corta($r['status_raw'] ?? null, 40),
            'situacao_juridica' => $this->corta($r['situacao_juridica'] ?? null, 40),
            'situacao_juridica_raw' => $this->corta($r['situacao_juridica_raw'] ?? null, 80),
            'tipo_cartorio' => $this->corta($r['tipo_cartorio'] ?? null, 20),
            'tipo_registro' => $this->corta($tipo, 24),
            'municipio' => $this->corta($r['municipio'] ?? null, 160),
            'uf' => $this->corta($r['uf'] ?? null, 2),
            'atribuicoes' => $this->corta($atribuicoes, 240),
            'atribuicoes_raw' => $this->corta($r['atribuicoes_raw'] ?? null, 400),
            'endereco' => $this->corta($r['endereco'] ?? null, 240),
            'bairro' => $this->corta($r['bairro'] ?? null, 160),
            'cep' => $this->corta($r['cep'] ?? null, 12),
            'telefone' => $this->corta($r['telefone'] ?? null, 40),
            'email' => $this->corta($r['email'] ?? null, 160),
            'website' => $this->corta($r['website'] ?? null, 240),
            'responsavel' => $this->corta($r['responsavel'] ?? null, 200),
            'substitutos' => $this->corta($r['substitutos'] ?? null, 4000),
            'comarca' => $this->corta($r['comarca'] ?? null, 160),
            'comarca_uf' => $this->corta($r['comarca_uf'] ?? null, 2),
            'circunscricao' => $this->corta($r['circunscricao'] ?? null, 240),
            'codigo_ibge' => $this->corta($r['codigo_ibge'] ?? null, 12),
            'latitude' => $this->corta($r['latitude'] ?? null, 30),
            'longitude' => $this->corta($r['longitude'] ?? null, 30),
            'id_cnj' => $this->corta($r['id_cnj'] ?? null, 20),
            'search_text' => $this->buscavel($cnsDigits, $r),
            'fonte' => $this->corta($r['fonte'] ?? 'justica_aberta_api', 60),
            'coletado_em' => ($r['coletado_em'] ?? '') ?: null,
            'criado_em' => Carbon::now(),
            'atualizado_em' => Carbon::now(),
        ];
    }

    /** @param array<int,array<string,mixed>> $lote */
    private function gravar(array $lote): int
    {
        // Só as colunas que a tabela tem: uma aplicação com a tabela antiga
        // (sem latitude, sem id_cnj) importa igual.
        $lote = array_map(
            fn (array $linha) => array_intersect_key($linha, array_flip($this->colunasDaTabela)),
            $lote,
        );
        $atualizar = array_values(array_diff(array_keys($lote[0]), ['cns_digits', 'criado_em']));

        DB::table((new Serventia)->getTable())->upsert($lote, ['cns_digits'], $atualizar);

        return count($lote);
    }

    /**
     * Texto de busca: desacentuado e minúsculo, com o CNS nos dois formatos
     * para que digitar "12.345-6" ou "123456" encontre a mesma serventia.
     */
    private function buscavel(string $cnsDigits, array $r): string
    {
        $partes = [
            $cnsDigits,
            Cns::formatar($cnsDigits),
            (string) ($r['denominacao'] ?? ''),
            (string) ($r['municipio'] ?? ''),
            (string) ($r['uf'] ?? ''),
            (string) ($r['bairro'] ?? ''),
        ];

        $texto = Busca::desacentuar(implode(' ', array_filter($partes)));

        return preg_replace('/\s+/', ' ', $texto) ?? $texto;
    }

    private function corta(mixed $v, int $max): ?string
    {
        $s = trim((string) ($v ?? ''));

        return $s === '' ? null : mb_substr($s, 0, $max);
    }
}
