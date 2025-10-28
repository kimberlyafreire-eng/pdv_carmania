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
        celular_normalizado TEXT,
        telefone TEXT,
        codigo TEXT,
        rua TEXT,
        bairro TEXT,
        cidade TEXT,
        estado TEXT,
        cep TEXT,
        atualizado_em TEXT NOT NULL
    )');

    $db->exec('CREATE INDEX IF NOT EXISTS idx_clientes_nome ON clientes(nome COLLATE NOCASE)');

    garantirEstruturaClientes($db);

    return $db;
}

/**
 * Garante que a tabela de clientes possua as colunas e índices necessários.
 */
function garantirEstruturaClientes(SQLite3 $db): void
{
    $colunaCelularNormalizadoExiste = false;
    $resultado = $db->query('PRAGMA table_info(clientes)');
    if ($resultado instanceof SQLite3Result) {
        while ($linha = $resultado->fetchArray(SQLITE3_ASSOC)) {
            if (isset($linha['name']) && $linha['name'] === 'celular_normalizado') {
                $colunaCelularNormalizadoExiste = true;
                break;
            }
        }
        $resultado->finalize();
    }

    if (!$colunaCelularNormalizadoExiste) {
        $db->exec('ALTER TABLE clientes ADD COLUMN celular_normalizado TEXT');
    }

    $db->exec('CREATE INDEX IF NOT EXISTS idx_clientes_celular_normalizado ON clientes(celular_normalizado)');

    atualizarCelularesNormalizadosPendentes($db);
}

/**
 * Atualiza os registros que ainda não possuem o celular normalizado preenchido.
 */
function atualizarCelularesNormalizadosPendentes(SQLite3 $db): void
{
    $resultado = $db->query('SELECT id, celular, celular_normalizado FROM clientes WHERE celular IS NOT NULL AND celular <> ""');
    if ($resultado === false) {
        return;
    }

    while ($linha = $resultado->fetchArray(SQLITE3_ASSOC)) {
        if (!is_array($linha) || !isset($linha['id'])) {
            continue;
        }

        $celularNormalizado = normalizarTelefoneComparacao($linha['celular'] ?? null);
        if ($celularNormalizado === '') {
            $celularNormalizado = null;
        }

        $atual = $linha['celular_normalizado'] ?? null;
        if ($atual === '') {
            $atual = null;
        }

        if ($celularNormalizado === $atual) {
            continue;
        }

        $stmt = $db->prepare('UPDATE clientes SET celular_normalizado = :celular_normalizado WHERE id = :id');
        if (!$stmt instanceof SQLite3Stmt) {
            continue;
        }

        if ($celularNormalizado === null) {
            $stmt->bindValue(':celular_normalizado', null, SQLITE3_NULL);
        } else {
            $stmt->bindValue(':celular_normalizado', $celularNormalizado, SQLITE3_TEXT);
        }

        $stmt->bindValue(':id', (string) $linha['id'], SQLITE3_TEXT);

        $exec = $stmt->execute();
        if ($exec instanceof SQLite3Result) {
            $exec->finalize();
        }

        $stmt->close();
    }

    $resultado->finalize();
}

/**
 * Realiza o upsert de um único cliente no banco de dados local.

 */
function upsertCliente(SQLite3 $db, array $cliente): void
{
    static $stmt = null;

    if (!$stmt instanceof SQLite3Stmt) {
        $stmt = $db->prepare('INSERT INTO clientes (
                id, nome, tipo, numero_documento, celular, celular_normalizado, telefone, codigo, rua, bairro, cidade, estado, cep, atualizado_em
            ) VALUES (
                :id, :nome, :tipo, :numero_documento, :celular, :celular_normalizado, :telefone, :codigo, :rua, :bairro, :cidade, :estado, :cep, :atualizado_em
            )
            ON CONFLICT(id) DO UPDATE SET
                nome = excluded.nome,
                tipo = excluded.tipo,
                numero_documento = excluded.numero_documento,
                celular = excluded.celular,
                celular_normalizado = excluded.celular_normalizado,
                telefone = excluded.telefone,
                codigo = excluded.codigo,
                rua = excluded.rua,
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
    bindValorOuNulo($stmt, ':celular_normalizado', $dados['celular_normalizado']);
    bindValorOuNulo($stmt, ':telefone', $dados['telefone']);
    bindValorOuNulo($stmt, ':codigo', $dados['codigo']);
    bindValorOuNulo($stmt, ':rua', $dados['rua']);
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
 * Retorna a quantidade de clientes registrados no banco local.
 */
function contarClientes(SQLite3 $db): int
{
    $resultado = $db->query('SELECT COUNT(*) AS total FROM clientes');
    if ($resultado === false) {
        return 0;
    }

    $linha = $resultado->fetchArray(SQLITE3_ASSOC) ?: [];
    $resultado->finalize();

    if (!isset($linha['total'])) {
        return 0;
    }

    return (int) $linha['total'];
}

/**
 * Busca um cliente existente a partir do número de celular informado.
 */
function encontrarClientePorCelular(SQLite3 $db, string $celular, ?string $ignorarId = null): ?array
{
    $celularNormalizado = normalizarTelefoneComparacao($celular);
    if ($celularNormalizado === '') {
        return null;
    }

    $sql = 'SELECT * FROM clientes WHERE celular_normalizado = :celular';
    $ignorarIdNormalizado = null;
    if ($ignorarId !== null) {
        $ignorarIdNormalizado = trim((string) $ignorarId);
        if ($ignorarIdNormalizado === '') {
            $ignorarIdNormalizado = null;
        }
    }

    if ($ignorarIdNormalizado !== null) {
        $sql .= ' AND id <> :ignorar_id';
    }

    $sql .= ' LIMIT 1';

    $stmt = $db->prepare($sql);
    if (!$stmt instanceof SQLite3Stmt) {
        return null;
    }

    $stmt->bindValue(':celular', $celularNormalizado, SQLITE3_TEXT);
    if ($ignorarIdNormalizado !== null) {
        $stmt->bindValue(':ignorar_id', $ignorarIdNormalizado, SQLITE3_TEXT);
    }

    $resultado = $stmt->execute();
    if ($resultado === false) {
        $stmt->close();
        return null;
    }

    $linha = $resultado->fetchArray(SQLITE3_ASSOC) ?: null;
    $resultado->finalize();
    $stmt->close();

    return is_array($linha) ? $linha : null;
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
    if ($celular === '') {
        $celular = null;
    }

    $contatosCliente = extrairContatosDoCliente($cliente);

    if ($celular === null) {
        $contatoCelular = localizarContatoPreferencial($contatosCliente, ['CELULAR', 'WHATSAPP', 'MOBILE']);
        if ($contatoCelular !== null) {
            $celular = $contatoCelular['formatado'];
        }
    }

    $celularNormalizado = null;
    if ($celular !== null && $celular !== '') {
        $normalizado = normalizarTelefoneComparacao($celular);
        if ($normalizado !== '') {
            $celularNormalizado = $normalizado;
        }
    }

    $telefone = isset($cliente['telefone']) ? trim((string) $cliente['telefone']) : null;
    if ($telefone === '') {
        $telefone = null;
    }

    if ($telefone === null) {
        $contatoTelefone = localizarContatoPreferencial($contatosCliente, ['TELEFONE', 'FIXO'], $celularNormalizado !== null ? [$celularNormalizado] : []);
        if ($contatoTelefone !== null) {
            $telefone = $contatoTelefone['formatado'];
        }
    }

    $endereco = $cliente['endereco']['geral'] ?? ($cliente['endereco'] ?? []);
    $rua = isset($endereco['endereco']) ? trim((string) $endereco['endereco']) : null;
    $bairro = isset($endereco['bairro']) ? trim((string) $endereco['bairro']) : null;
    $cidade = isset($endereco['municipio']) ? trim((string) $endereco['municipio']) : null;
    $estado = isset($endereco['uf']) ? strtoupper(trim((string) $endereco['uf'])) : null;

    $cep = null;
    if (!empty($endereco['cep'])) {
        $cep = preg_replace('/\D+/', '', (string) $endereco['cep']);
    }

    return [
        'id' => $id,
        'nome' => $nome,
        'tipo' => $tipo,
        'numero_documento' => $numeroDocumento,
        'celular' => $celular,
        'celular_normalizado' => $celularNormalizado,
        'telefone' => $telefone,
        'codigo' => $codigo,
        'rua' => $rua,
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

    $contatosCliente = extrairContatosDoCliente($cliente);

    $celular = isset($cliente['celular']) ? trim((string) $cliente['celular']) : null;
    if ($celular === '') {
        $celular = null;
    }

    if ($celular === null) {
        $contatoCelular = localizarContatoPreferencial($contatosCliente, ['CELULAR', 'WHATSAPP', 'MOBILE']);
        if ($contatoCelular !== null) {
            $celular = $contatoCelular['formatado'];
        }
    }

    if ($celular !== null) {
        $celular = formatarTelefonePadrao($celular);
    }

    $telefone = isset($cliente['telefone']) ? trim((string) $cliente['telefone']) : null;
    if ($telefone === '') {
        $telefone = null;
    }

    if ($telefone === null) {
        $normalizadosIgnorados = [];
        if ($celular !== null) {
            $normalizadoCelular = normalizarTelefoneComparacao($celular);
            if ($normalizadoCelular !== '') {
                $normalizadosIgnorados[] = $normalizadoCelular;
            }
        }

        $contatoTelefone = localizarContatoPreferencial($contatosCliente, ['TELEFONE', 'FIXO'], $normalizadosIgnorados);
        if ($contatoTelefone !== null) {
            $telefone = $contatoTelefone['formatado'];
        }
    }

    if ($telefone !== null) {
        $telefone = formatarTelefonePadrao($telefone);
    }

    $enderecoFonte = $cliente['endereco']['geral'] ?? ($cliente['endereco'] ?? null);
    $enderecoNormalizado = [];

    if (is_array($enderecoFonte)) {
        $rua = isset($enderecoFonte['endereco']) ? trim((string) $enderecoFonte['endereco']) : '';
        if ($rua !== '') {
            $enderecoNormalizado['endereco'] = $rua;
        }

        $bairro = isset($enderecoFonte['bairro']) ? trim((string) $enderecoFonte['bairro']) : '';
        if ($bairro !== '') {
            $enderecoNormalizado['bairro'] = $bairro;
        }

        $municipio = isset($enderecoFonte['municipio']) ? trim((string) $enderecoFonte['municipio']) : '';
        if ($municipio !== '') {
            $enderecoNormalizado['municipio'] = $municipio;
        }

        $uf = isset($enderecoFonte['uf']) ? strtoupper(trim((string) $enderecoFonte['uf'])) : '';
        if ($uf !== '') {
            $enderecoNormalizado['uf'] = $uf;
        }

        $cepBruto = isset($enderecoFonte['cep']) ? (string) $enderecoFonte['cep'] : '';
        if ($cepBruto !== '') {
            $cepDigitos = preg_replace('/\D+/', '', $cepBruto);
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
    } elseif (!empty($linha['celular_normalizado'])) {
        $cliente['celular'] = formatarTelefonePadrao($linha['celular_normalizado']);
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
 * Formata números de telefone/celular para o padrão (DD) XXXX-XXXX/XXXXX-XXXX.
 */
function formatarTelefonePadrao(string $numero): string
{
    $numero = trim($numero);
    if ($numero === '') {
        return '';
    }

    $digitos = preg_replace('/\D+/', '', $numero);
    if ($digitos === '') {
        return $numero;
    }

    if (strlen($digitos) === 11) {
        return sprintf('(%s) %s-%s', substr($digitos, 0, 2), substr($digitos, 2, 5), substr($digitos, 7));
    }

    if (strlen($digitos) === 10) {
        return sprintf('(%s) %s-%s', substr($digitos, 0, 2), substr($digitos, 2, 4), substr($digitos, 6));
    }

    return $numero;
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

/**
 * Normaliza números de telefone/celular para comparação interna.
 */
function normalizarTelefoneComparacao($numero): string
{
    if (!is_scalar($numero)) {
        return '';
    }

    $digitos = preg_replace('/\D+/', '', (string) $numero);
    if ($digitos === null || $digitos === '') {
        return '';
    }

    $digitos = ltrim($digitos, '0');
    if ($digitos === '') {
        return '';
    }

    $tamanho = strlen($digitos);
    if ($tamanho > 11 && strncmp($digitos, '55', 2) === 0) {
        $restante = substr($digitos, 2);
        $tamanhoRestante = strlen($restante);
        if ($tamanhoRestante >= 10 && $tamanhoRestante <= 11) {
            $digitos = $restante;
            $tamanho = $tamanhoRestante;
        }
    }

    if ($tamanho > 11) {
        $digitos = substr($digitos, -11);
    }

    return $digitos;
}

/**
 * Extrai uma lista plana de contatos presentes na estrutura do cliente.
 */
function extrairContatosDoCliente($cliente): array
{
    if (!is_array($cliente)) {
        return [];
    }

    $fontesIniciais = [];
    foreach (['contatos', 'telefones', 'contato', 'formasContato'] as $chave) {
        if (isset($cliente[$chave])) {
            $fontesIniciais[] = $cliente[$chave];
        }
    }

    $pilha = $fontesIniciais;
    $contatos = [];

    while (!empty($pilha)) {
        $atual = array_pop($pilha);
        if (!is_array($atual)) {
            continue;
        }

        if (isset($atual['data']) && is_array($atual['data'])) {
            $pilha[] = $atual['data'];
            continue;
        }

        $temNumero = false;
        foreach (['contato', 'numero', 'valor', 'telefone'] as $campoNumero) {
            if (isset($atual[$campoNumero]) && trim((string)$atual[$campoNumero]) !== '') {
                $temNumero = true;
                break;
            }
        }

        if ($temNumero) {
            $contatos[] = $atual;
            continue;
        }

        foreach ($atual as $subValor) {
            if (is_array($subValor)) {
                $pilha[] = $subValor;
            }
        }
    }

    return $contatos;
}

/**
 * Localiza o primeiro contato que corresponda aos tipos desejados.
 */
function localizarContatoPreferencial(array $contatos, array $tiposPrioritarios = [], array $normalizadosIgnorados = []): ?array
{
    if (empty($contatos)) {
        return null;
    }

    $tiposPrioritariosNormalizados = [];
    foreach ($tiposPrioritarios as $tipo) {
        $tipo = mb_strtoupper(trim((string)$tipo));
        if ($tipo !== '') {
            $tiposPrioritariosNormalizados[$tipo] = true;
        }
    }

    $ignorar = [];
    foreach ($normalizadosIgnorados as $valor) {
        $normalizado = normalizarTelefoneComparacao($valor);
        if ($normalizado !== '') {
            $ignorar[$normalizado] = true;
        }
    }

    $candidatosPrioritarios = [];
    $candidatos = [];

    foreach ($contatos as $contato) {
        if (!is_array($contato)) {
            continue;
        }

        $numeroBruto = '';
        foreach (['contato', 'numero', 'valor', 'telefone'] as $campoNumero) {
            if (isset($contato[$campoNumero])) {
                $numeroBruto = trim((string)$contato[$campoNumero]);
                if ($numeroBruto !== '') {
                    break;
                }
            }
        }

        if ($numeroBruto === '') {
            continue;
        }

        $normalizado = normalizarTelefoneComparacao($numeroBruto);
        if ($normalizado === '' || isset($ignorar[$normalizado])) {
            continue;
        }

        $registro = [
            'bruto' => $numeroBruto,
            'normalizado' => $normalizado,
            'formatado' => formatarTelefonePadrao($numeroBruto),
        ];

        $tipoContato = '';
        foreach (['tipo', 'descricao', 'tipoContato'] as $campoTipo) {
            if (isset($contato[$campoTipo])) {
                $tipoContato = mb_strtoupper(trim((string)$contato[$campoTipo]));
                if ($tipoContato !== '') {
                    break;
                }
            }
        }

        if (!empty($tiposPrioritariosNormalizados) && $tipoContato !== '' && isset($tiposPrioritariosNormalizados[$tipoContato])) {
            if (!isset($candidatosPrioritarios[$normalizado])) {
                $candidatosPrioritarios[$normalizado] = $registro;
            }
            continue;
        }

        if (!isset($candidatos[$normalizado])) {
            $candidatos[$normalizado] = $registro;
        }
    }

    if (!empty($candidatosPrioritarios)) {
        return reset($candidatosPrioritarios);
    }

    if (!empty($candidatos)) {
        return reset($candidatos);
    }

    return null;
}
