<?php
declare(strict_types=1);

/**
 * Retorna o caminho absoluto para o banco de dados de clientes.
 */
function clientesDbPath(): string
{
    return __DIR__ . '/../../db/clientes.db';
}

/**
 * Abre (ou cria) o banco de dados local de clientes.
 *
 * @throws RuntimeException Quando não é possível criar o diretório ou o arquivo do banco.
 */
function getClientesDb(): SQLite3
{
    $path = clientesDbPath();
    $dir = dirname($path);

    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Não foi possível preparar o diretório do banco de dados de clientes.');
    }

    $db = new SQLite3($path, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
    $db->busyTimeout(5000);

    $db->exec('PRAGMA journal_mode=WAL;');
    $db->exec('PRAGMA foreign_keys = ON;');

    $db->exec('CREATE TABLE IF NOT EXISTS clientes (
        id TEXT PRIMARY KEY,
        nome TEXT NOT NULL,
        tipo TEXT,
        numero_documento TEXT,
        celular TEXT,
        telefone TEXT,
        codigo TEXT,
        rua TEXT,
        numero TEXT,
        complemento TEXT,
        bairro TEXT,
        cidade TEXT,
        estado TEXT,
        cep TEXT,
        atualizado_em TEXT NOT NULL
    )');

    try {
        $colunas = [];
        $resultado = $db->query('PRAGMA table_info(clientes)');
        if ($resultado instanceof SQLite3Result) {
            while ($linha = $resultado->fetchArray(SQLITE3_ASSOC)) {
                if (isset($linha['name'])) {
                    $colunas[] = strtolower((string) $linha['name']);
                }
            }
            $resultado->finalize();
        }

        $colunasExistentes = array_flip($colunas);
        if (!isset($colunasExistentes['numero'])) {
            $db->exec("ALTER TABLE clientes ADD COLUMN numero TEXT");
        }
        if (!isset($colunasExistentes['complemento'])) {
            $db->exec("ALTER TABLE clientes ADD COLUMN complemento TEXT");
        }
    } catch (Throwable $e) {
        error_log('[clientes-db] Falha ao atualizar estrutura da tabela clientes: ' . $e->getMessage());
    }

    $db->exec('CREATE INDEX IF NOT EXISTS idx_clientes_nome ON clientes(nome COLLATE NOCASE)');

    return $db;
}

/**
 * Realiza o upsert de um único cliente no banco de dados local.
 */
function upsertCliente(SQLite3 $db, array $cliente): void
{
    static $stmt = null;

    if (!$stmt instanceof SQLite3Stmt) {
        $stmt = $db->prepare('INSERT INTO clientes (
                id, nome, tipo, numero_documento, celular, telefone, codigo, rua, numero, complemento, bairro, cidade, estado, cep, atualizado_em
            ) VALUES (
                :id, :nome, :tipo, :numero_documento, :celular, :telefone, :codigo, :rua, :numero, :complemento, :bairro, :cidade, :estado, :cep, :atualizado_em
            )
            ON CONFLICT(id) DO UPDATE SET
                nome = excluded.nome,
                tipo = excluded.tipo,
                numero_documento = excluded.numero_documento,
                celular = excluded.celular,
                telefone = excluded.telefone,
                codigo = excluded.codigo,
                rua = excluded.rua,
                numero = excluded.numero,
                complemento = excluded.complemento,
                bairro = excluded.bairro,
                cidade = excluded.cidade,
                estado = excluded.estado,
                cep = excluded.cep,
                atualizado_em = excluded.atualizado_em');
        if (!$stmt instanceof SQLite3Stmt) {
            $mensagemErro = trim($db->lastErrorMsg() ?: '');
            $stmt = null;
            throw new RuntimeException('Não foi possível preparar a instrução de gravação de clientes' . ($mensagemErro !== '' ? ': ' . $mensagemErro : '.'));
        }
    }

    $dados = normalizarDadosCliente($cliente);

    bindValorOuNulo($stmt, ':id', $dados['id']);
    bindValorOuNulo($stmt, ':nome', $dados['nome']);
    bindValorOuNulo($stmt, ':tipo', $dados['tipo']);
    bindValorOuNulo($stmt, ':numero_documento', $dados['numero_documento']);
    bindValorOuNulo($stmt, ':celular', $dados['celular']);
    bindValorOuNulo($stmt, ':telefone', $dados['telefone']);
    bindValorOuNulo($stmt, ':codigo', $dados['codigo']);
    bindValorOuNulo($stmt, ':rua', $dados['rua']);
    bindValorOuNulo($stmt, ':numero', $dados['numero']);
    bindValorOuNulo($stmt, ':complemento', $dados['complemento']);
    bindValorOuNulo($stmt, ':bairro', $dados['bairro']);
    bindValorOuNulo($stmt, ':cidade', $dados['cidade']);
    bindValorOuNulo($stmt, ':estado', $dados['estado']);
    bindValorOuNulo($stmt, ':cep', $dados['cep']);
    bindValorOuNulo($stmt, ':atualizado_em', $dados['atualizado_em']);

    $resultado = $stmt->execute();
    if ($resultado === false) {
        throw new RuntimeException('Falha ao gravar cliente no banco local.');
    }
    $resultado->finalize();
    $stmt->reset();
    $stmt->clear();
}

/**
 * Realiza o upsert em lote de diversos clientes.
 */
function upsertClientes(SQLite3 $db, array $clientes): void
{
    foreach ($clientes as $cliente) {
        if (!is_array($cliente)) {
            continue;
        }

        try {
            upsertCliente($db, $cliente);
        } catch (Throwable $e) {
            error_log('[clientes-db] Falha ao gravar cliente localmente: ' . $e->getMessage());
        }
    }
}

/**
 * Remove do banco local os clientes que não estão presentes na lista fornecida.
 */
function removerClientesForaDaLista(SQLite3 $db, array $idsAtuais): void
{
    $idsNormalizados = [];

    foreach ($idsAtuais as $id) {
        if (!is_scalar($id)) {
            continue;
        }

        $idNormalizado = trim((string) $id);
        if ($idNormalizado === '') {
            continue;
        }

        $idsNormalizados[$idNormalizado] = true;
    }

    if (empty($idsNormalizados)) {
        $db->exec('DELETE FROM clientes');
        return;
    }

    $placeholders = implode(',', array_fill(0, count($idsNormalizados), '?'));
    $stmt = $db->prepare("DELETE FROM clientes WHERE id NOT IN ($placeholders)");
    if (!$stmt instanceof SQLite3Stmt) {
        $mensagemErro = trim($db->lastErrorMsg() ?: '');
        throw new RuntimeException('Não foi possível preparar a limpeza de clientes' . ($mensagemErro !== '' ? ': ' . $mensagemErro : '.'));
    }

    $indice = 1;
    foreach (array_keys($idsNormalizados) as $id) {
        $stmt->bindValue($indice, $id, SQLITE3_TEXT);
        $indice++;
    }

    $resultado = $stmt->execute();
    if ($resultado === false) {
        $stmt->close();
        throw new RuntimeException('Falha ao remover clientes ausentes da lista informada.');
    }

    $resultado->finalize();
    $stmt->clear();
    $stmt->close();
}

/**
 * Garante que os clientes presentes no cache JSON também estejam no banco local.
 */
function importarClientesCache(SQLite3 $db, string $cachePath): void
{
    if (!is_file($cachePath)) {
        return;
    }

    $conteudo = @file_get_contents($cachePath);
    if ($conteudo === false || $conteudo === '') {
        return;
    }

    $json = json_decode($conteudo, true);
    if (!is_array($json) || !isset($json['data']) || !is_array($json['data'])) {
        return;
    }

    upsertClientes($db, $json['data']);
}

/**
 * Retorna os clientes salvos no banco local no formato esperado pelo front-end.
 */
function buscarClientesLocalmente(SQLite3 $db): array
{
    $clientes = [];

    $resultado = $db->query('SELECT * FROM clientes ORDER BY nome COLLATE NOCASE');
    if ($resultado === false) {
        return $clientes;
    }

    while ($linha = $resultado->fetchArray(SQLITE3_ASSOC)) {
        if (!is_array($linha)) {
            continue;
        }

        $clientes[] = montarEstruturaCliente($linha);
    }

    $resultado->finalize();

    return $clientes;
}

/**
 * Busca um único cliente salvo localmente.
 */
function buscarClienteLocalPorId(SQLite3 $db, string $id): ?array
{
    $idNormalizado = trim($id);
    if ($idNormalizado === '') {
        return null;
    }

    $stmt = $db->prepare('SELECT * FROM clientes WHERE id = :id LIMIT 1');
    if (!$stmt instanceof SQLite3Stmt) {
        return null;
    }

    $stmt->bindValue(':id', $idNormalizado, SQLITE3_TEXT);
    $resultado = $stmt->execute();
    if ($resultado === false) {
        $stmt->close();
        return null;
    }

    $linha = $resultado->fetchArray(SQLITE3_ASSOC);
    $resultado->finalize();
    $stmt->close();

    if (!is_array($linha)) {
        return null;
    }

    return montarEstruturaCliente($linha);
}

/**
 * Extrai e normaliza o endereço principal de um cliente, independente do formato
 * utilizado na origem (API, cache JSON ou banco local).
 */
function extrairEnderecoPrincipal(array $cliente): array
{
    $candidatos = [];

    $fonteEndereco = $cliente['endereco'] ?? null;
    if (is_array($fonteEndereco)) {
        if (isset($fonteEndereco['geral']) && is_array($fonteEndereco['geral'])) {
            $candidato = $fonteEndereco['geral'];
            $candidato['__tipoPreferido'] = 'GERAL';
            $candidato['__padrao'] = true;
            $candidatos[] = $candidato;
        }

        $rotulosSecundarios = ['principal', 'cobranca', 'cobrança', 'entrega'];
        foreach ($rotulosSecundarios as $rotulo) {
            if (isset($fonteEndereco[$rotulo]) && is_array($fonteEndereco[$rotulo])) {
                $candidato = $fonteEndereco[$rotulo];
                $candidato['__tipoPreferido'] = strtoupper($rotulo);
                $candidato['__padrao'] = false;
                $candidatos[] = $candidato;
            }
        }

        if (array_values($fonteEndereco) !== $fonteEndereco) {
            $candidato = $fonteEndereco;
            $candidato['__tipoPreferido'] = 'GERAL';
            $candidato['__padrao'] = true;
            $candidatos[] = $candidato;
        }
    } elseif ($fonteEndereco !== null && $fonteEndereco !== '') {
        $candidatos[] = [
            'endereco' => $fonteEndereco,
            'numero' => $cliente['numero'] ?? ($cliente['numeroEndereco'] ?? null),
            'complemento' => $cliente['complemento'] ?? null,
            'bairro' => $cliente['bairro'] ?? null,
            'municipio' => $cliente['municipio'] ?? ($cliente['cidade'] ?? null),
            'uf' => $cliente['uf'] ?? ($cliente['estado'] ?? null),
            'cep' => $cliente['cep'] ?? null,
            '__tipoPreferido' => 'GERAL',
            '__padrao' => true,
        ];
    }

    if (isset($cliente['enderecos']) && is_array($cliente['enderecos'])) {
        foreach ($cliente['enderecos'] as $enderecoLista) {
            if (!is_array($enderecoLista)) {
                continue;
            }

            $tipo = $enderecoLista['tipo'] ?? ($enderecoLista['tipoEndereco'] ?? ($enderecoLista['descricao'] ?? ''));
            $padrao = false;
            if (isset($enderecoLista['padrao'])) {
                $valorPadrao = $enderecoLista['padrao'];
                if (is_bool($valorPadrao)) {
                    $padrao = $valorPadrao;
                } elseif (is_numeric($valorPadrao)) {
                    $padrao = (int) $valorPadrao !== 0;
                } elseif (is_string($valorPadrao)) {
                    $padrao = in_array(strtolower(trim($valorPadrao)), ['1', 'true', 't', 'sim', 's', 'y', 'yes'], true);
                }
            }

            $enderecoLista['__tipoPreferido'] = strtoupper(trim((string) $tipo));
            $enderecoLista['__padrao'] = $padrao;
            $candidatos[] = $enderecoLista;
        }
    }

    $topLevel = [
        'endereco' => null,
        'numero' => $cliente['numero'] ?? ($cliente['numeroEndereco'] ?? null),
        'complemento' => $cliente['complemento'] ?? null,
        'bairro' => $cliente['bairro'] ?? null,
        'municipio' => $cliente['municipio'] ?? ($cliente['cidade'] ?? null),
        'uf' => $cliente['uf'] ?? ($cliente['estado'] ?? null),
        'cep' => $cliente['cep'] ?? null,
    ];

    foreach (['logradouro', 'rua'] as $chave) {
        if (isset($cliente[$chave]) && is_string($cliente[$chave])) {
            $valor = trim($cliente[$chave]);
            if ($valor !== '') {
                $topLevel['endereco'] = $valor;
                break;
            }
        }
    }

    if ($topLevel['endereco'] === null && isset($cliente['endereco']) && !is_array($cliente['endereco'])) {
        $valor = trim((string) $cliente['endereco']);
        if ($valor !== '') {
            $topLevel['endereco'] = $valor;
        }
    }

    $possuiAlgumValor = false;
    foreach ($topLevel as $campo => $valor) {
        if ($valor !== null && $valor !== '') {
            $possuiAlgumValor = true;
            break;
        }
    }

    if ($possuiAlgumValor) {
        $topLevel['__tipoPreferido'] = 'GERAL';
        $topLevel['__padrao'] = true;
        $candidatos[] = $topLevel;
    }

    $melhorEndereco = [];
    $melhorPontuacao = -1;

    foreach ($candidatos as $candidato) {
        $tipoPreferido = strtoupper(trim((string) ($candidato['__tipoPreferido'] ?? '')));
        $padrao = !empty($candidato['__padrao']);
        unset($candidato['__tipoPreferido'], $candidato['__padrao']);

        $normalizado = normalizarEnderecoCandidato($candidato);
        if (empty($normalizado)) {
            continue;
        }

        $pontuacao = pontuarEnderecoCandidato($normalizado, $tipoPreferido, $padrao);
        if ($pontuacao > $melhorPontuacao) {
            $melhorPontuacao = $pontuacao;
            $melhorEndereco = $normalizado;
        }
    }

    return $melhorEndereco;
}

/**
 * Normaliza um endereço de diversas estruturas para chaves consistentes.
 */
function normalizarEnderecoCandidato(array $fonte): array
{
    $resultado = [];

    $mapa = [
        'endereco' => ['endereco', 'logradouro', 'rua'],
        'numero' => ['numero', 'numeroEndereco'],
        'complemento' => ['complemento'],
        'bairro' => ['bairro'],
        'municipio' => ['municipio', 'cidade'],
        'uf' => ['uf', 'estado'],
        'cep' => ['cep'],
    ];

    foreach ($mapa as $destino => $chaves) {
        foreach ($chaves as $chave) {
            if (!array_key_exists($chave, $fonte)) {
                continue;
            }

            $valor = $fonte[$chave];
            if ($valor === null) {
                continue;
            }

            $valor = trim((string) $valor);
            if ($valor === '') {
                continue;
            }

            if ($destino === 'uf') {
                $valor = strtoupper($valor);
            }

            if ($destino === 'cep') {
                $valor = preg_replace('/\D+/', '', $valor);
            }

            $resultado[$destino] = $valor;
            break;
        }
    }

    return $resultado;
}

/**
 * Atribui uma pontuação ao endereço candidato para escolher o mais completo e relevante.
 */
function pontuarEnderecoCandidato(array $endereco, string $tipoPreferido, bool $padrao): int
{
    $pontuacao = 0;

    $tipo = strtoupper(trim($tipoPreferido));
    switch ($tipo) {
        case 'GERAL':
        case 'PRINCIPAL':
            $pontuacao += 50;
            break;
        case 'COBRANCA':
        case 'COBRANÇA':
            $pontuacao += 40;
            break;
        case 'ENTREGA':
            $pontuacao += 30;
            break;
        default:
            if ($tipo !== '') {
                $pontuacao += 10;
            }
    }

    if ($padrao) {
        $pontuacao += 5;
    }

    foreach (['endereco', 'municipio', 'uf'] as $campo) {
        if (!empty($endereco[$campo])) {
            $pontuacao += 5;
        }
    }

    if (!empty($endereco['cep'])) {
        $pontuacao += 4;
    }
    if (!empty($endereco['numero'])) {
        $pontuacao += 2;
    }
    if (!empty($endereco['bairro'])) {
        $pontuacao += 2;
    }
    if (!empty($endereco['complemento'])) {
        $pontuacao += 1;
    }

    return $pontuacao;
}

/**
 * Normaliza e prepara os dados do cliente antes de gravar no banco local.
 */
function normalizarDadosCliente(array $cliente): array
{
    $id = isset($cliente['id']) ? (string) $cliente['id'] : null;
    $nome = isset($cliente['nome']) ? trim((string) $cliente['nome']) : '';
    $tipo = null;

    if (!empty($cliente['tipo'])) {
        $tipo = strtoupper(substr((string) $cliente['tipo'], 0, 1));
    } elseif (!empty($cliente['tipoPessoa'])) {
        $tipo = strtoupper(substr((string) $cliente['tipoPessoa'], 0, 1));
    }

    $numeroDocumento = null;
    if (!empty($cliente['numeroDocumento'])) {
        $numeroDocumento = preg_replace('/\D+/', '', (string) $cliente['numeroDocumento']);
    } elseif (!empty($cliente['documento'])) {
        $numeroDocumento = preg_replace('/\D+/', '', (string) $cliente['documento']);
    }

    $codigo = isset($cliente['codigo']) ? trim((string) $cliente['codigo']) : null;
    $celular = isset($cliente['celular']) ? trim((string) $cliente['celular']) : null;
    $telefone = isset($cliente['telefone']) ? trim((string) $cliente['telefone']) : null;

    $enderecoPrincipal = extrairEnderecoPrincipal($cliente);

    $rua = isset($enderecoPrincipal['endereco']) ? trim((string) $enderecoPrincipal['endereco']) : null;
    $numero = isset($enderecoPrincipal['numero']) ? trim((string) $enderecoPrincipal['numero']) : null;
    $complemento = isset($enderecoPrincipal['complemento']) ? trim((string) $enderecoPrincipal['complemento']) : null;
    $bairro = isset($enderecoPrincipal['bairro']) ? trim((string) $enderecoPrincipal['bairro']) : null;
    $cidade = isset($enderecoPrincipal['municipio']) ? trim((string) $enderecoPrincipal['municipio']) : null;
    $estado = isset($enderecoPrincipal['uf']) ? strtoupper(trim((string) $enderecoPrincipal['uf'])) : null;

    $cep = null;
    if (!empty($enderecoPrincipal['cep'])) {
        $cep = preg_replace('/\D+/', '', (string) $enderecoPrincipal['cep']);
    }

    return [
        'id' => $id,
        'nome' => $nome,
        'tipo' => $tipo,
        'numero_documento' => $numeroDocumento,
        'celular' => $celular,
        'telefone' => $telefone,
        'codigo' => $codigo,
        'rua' => $rua,
        'numero' => $numero,
        'complemento' => $complemento,
        'bairro' => $bairro,
        'cidade' => $cidade,
        'estado' => $estado,
        'cep' => $cep,
        'atualizado_em' => date('c'),
    ];
}

/**
 * Normaliza uma estrutura de cliente (vinda do Bling ou cache) para o formato
 * utilizado pelo front-end.
 */
function normalizarClienteParaResposta(array $cliente): ?array
{
    $id = isset($cliente['id']) ? trim((string) $cliente['id']) : '';
    if ($id === '') {
        return null;
    }

    $possiveisNomes = [
        $cliente['nome'] ?? null,
        $cliente['razaoSocial'] ?? null,
        $cliente['fantasia'] ?? null,
    ];

    $nome = '';
    foreach ($possiveisNomes as $valor) {
        if (is_string($valor)) {
            $valor = trim($valor);
            if ($valor !== '') {
                $nome = $valor;
                break;
            }
        }
    }

    if ($nome === '') {
        return null;
    }

    $tipo = null;
    if (!empty($cliente['tipo'])) {
        $tipo = strtoupper(substr((string) $cliente['tipo'], 0, 1));
    } elseif (!empty($cliente['tipoPessoa'])) {
        $tipo = strtoupper(substr((string) $cliente['tipoPessoa'], 0, 1));
    }

    $numeroDocumento = null;
    $documentoBruto = $cliente['numeroDocumento'] ?? ($cliente['documento'] ?? null);
    if ($documentoBruto !== null && $documentoBruto !== '') {
        $digitos = preg_replace('/\D+/', '', (string) $documentoBruto);
        if ($digitos !== '') {
            $numeroDocumento = formatarDocumentoSaida($digitos);
        }
    }

    $codigo = isset($cliente['codigo']) ? trim((string) $cliente['codigo']) : null;
    if ($codigo === '') {
        $codigo = null;
    }

    $celular = isset($cliente['celular']) ? trim((string) $cliente['celular']) : null;
    if ($celular === '') {
        $celular = null;
    }

    $telefone = isset($cliente['telefone']) ? trim((string) $cliente['telefone']) : null;
    if ($telefone === '') {
        $telefone = null;
    }

    $enderecoPrincipal = extrairEnderecoPrincipal($cliente);
    $enderecoNormalizado = [];

    if (!empty($enderecoPrincipal)) {
        if (!empty($enderecoPrincipal['endereco'])) {
            $enderecoNormalizado['endereco'] = trim((string) $enderecoPrincipal['endereco']);
        }

        if (!empty($enderecoPrincipal['numero'])) {
            $enderecoNormalizado['numero'] = trim((string) $enderecoPrincipal['numero']);
        }

        if (!empty($enderecoPrincipal['complemento'])) {
            $enderecoNormalizado['complemento'] = trim((string) $enderecoPrincipal['complemento']);
        }

        if (!empty($enderecoPrincipal['bairro'])) {
            $enderecoNormalizado['bairro'] = trim((string) $enderecoPrincipal['bairro']);
        }

        if (!empty($enderecoPrincipal['municipio'])) {
            $enderecoNormalizado['municipio'] = trim((string) $enderecoPrincipal['municipio']);
        }

        if (!empty($enderecoPrincipal['uf'])) {
            $enderecoNormalizado['uf'] = strtoupper(trim((string) $enderecoPrincipal['uf']));
        }

        if (!empty($enderecoPrincipal['cep'])) {
            $cepDigitos = preg_replace('/\D+/', '', (string) $enderecoPrincipal['cep']);
            if ($cepDigitos !== '') {
                $enderecoNormalizado['cep'] = formatarCepSaida($cepDigitos);
            }
        }
    }

    $clienteNormalizado = [
        'id' => $id,
        'nome' => $nome,
    ];

    if ($tipo !== null && $tipo !== '') {
        $clienteNormalizado['tipo'] = $tipo;
    }

    if ($numeroDocumento !== null) {
        $clienteNormalizado['numeroDocumento'] = $numeroDocumento;
    }

    if ($codigo !== null) {
        $clienteNormalizado['codigo'] = $codigo;
    }

    if ($celular !== null) {
        $clienteNormalizado['celular'] = $celular;
    }

    if ($telefone !== null) {
        $clienteNormalizado['telefone'] = $telefone;
    }

    if (!empty($enderecoNormalizado)) {
        $clienteNormalizado['endereco'] = ['geral' => $enderecoNormalizado];
    }

    return $clienteNormalizado;
}

/**
 * Converte uma linha da tabela de clientes para a estrutura utilizada no front-end.
 */
function montarEstruturaCliente(array $linha): array
{
    $numeroDocumento = null;
    if (!empty($linha['numero_documento'])) {
        $numeroDocumento = formatarDocumentoSaida($linha['numero_documento']);
    }

    $cep = null;
    if (!empty($linha['cep'])) {
        $cep = formatarCepSaida($linha['cep']);
    }

    $endereco = array_filter([
        'endereco' => $linha['rua'] ?? null,
        'numero' => $linha['numero'] ?? null,
        'complemento' => $linha['complemento'] ?? null,
        'bairro' => $linha['bairro'] ?? null,
        'municipio' => $linha['cidade'] ?? null,
        'uf' => $linha['estado'] ?? null,
        'cep' => $cep,
    ], static fn($valor) => $valor !== null && $valor !== '');

    $cliente = [
        'id' => $linha['id'],
        'nome' => $linha['nome'],
    ];

    if (!empty($linha['tipo'])) {
        $cliente['tipo'] = $linha['tipo'];
    }
    if ($numeroDocumento !== null) {
        $cliente['numeroDocumento'] = $numeroDocumento;
    }
    if (!empty($linha['codigo'])) {
        $cliente['codigo'] = $linha['codigo'];
    }
    if (!empty($linha['celular'])) {
        $cliente['celular'] = $linha['celular'];
    }
    if (!empty($linha['telefone'])) {
        $cliente['telefone'] = $linha['telefone'];
    }
    if (!empty($endereco)) {
        $cliente['endereco'] = ['geral' => $endereco];
    }

    return $cliente;
}

/**
 * Formata CPF ou CNPJ de saída, quando possível.
 */
function formatarDocumentoSaida(string $documento): string
{
    $digitos = preg_replace('/\D+/', '', $documento);
    if (strlen($digitos) === 11) {
        return substr($digitos, 0, 3) . '.' . substr($digitos, 3, 3) . '.' . substr($digitos, 6, 3) . '-' . substr($digitos, 9);
    }
    if (strlen($digitos) === 14) {
        return substr($digitos, 0, 2) . '.' . substr($digitos, 2, 3) . '.' . substr($digitos, 5, 3) . '/' . substr($digitos, 8, 4) . '-' . substr($digitos, 12);
    }
    return $documento;
}

/**
 * Formata CEP na saída.
 */
function formatarCepSaida(string $cep): string
{
    $digitos = preg_replace('/\D+/', '', $cep);
    if (strlen($digitos) === 8) {
        return substr($digitos, 0, 5) . '-' . substr($digitos, 5);
    }
    return $cep;
}

/**
 * Faz o bind de valores em uma declaração SQLite, considerando valores nulos.
 */
function bindValorOuNulo(SQLite3Stmt $stmt, string $parametro, $valor): void
{
    if ($valor === null || $valor === '') {
        $stmt->bindValue($parametro, null, SQLITE3_NULL);
    } else {
        $stmt->bindValue($parametro, (string) $valor, SQLITE3_TEXT);
    }
}
