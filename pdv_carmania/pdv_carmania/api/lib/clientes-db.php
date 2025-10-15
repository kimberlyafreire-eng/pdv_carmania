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
        bairro TEXT,
        cidade TEXT,
        estado TEXT,
        cep TEXT,
        atualizado_em TEXT NOT NULL
    )');

    $db->exec('CREATE INDEX IF NOT EXISTS idx_clientes_nome ON clientes(nome COLLATE NOCASE)');

    return $db;
}

/**
 * Realiza o upsert de um único cliente no banco de dados local.
 */
function upsertCliente(SQLite3 $db, array $cliente): void
{
    static $stmt = null;

    if ($stmt === null) {
        $stmt = $db->prepare('INSERT INTO clientes (
                id, nome, tipo, numero_documento, celular, telefone, codigo, rua, bairro, cidade, estado, cep, atualizado_em
            ) VALUES (
                :id, :nome, :tipo, :numero_documento, :celular, :telefone, :codigo, :rua, :bairro, :cidade, :estado, :cep, :atualizado_em
            )
            ON CONFLICT(id) DO UPDATE SET
                nome = excluded.nome,
                tipo = excluded.tipo,
                numero_documento = excluded.numero_documento,
                celular = excluded.celular,
                telefone = excluded.telefone,
                codigo = excluded.codigo,
                rua = excluded.rua,
                bairro = excluded.bairro,
                cidade = excluded.cidade,
                estado = excluded.estado,
                cep = excluded.cep,
                atualizado_em = excluded.atualizado_em');
        if (!$stmt) {
            throw new RuntimeException('Não foi possível preparar a instrução de gravação de clientes.');
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
