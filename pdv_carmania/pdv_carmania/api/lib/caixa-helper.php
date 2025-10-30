<?php

declare(strict_types=1);

function caixaDbPath(): string
{
    return __DIR__ . '/../../data/caixa.sqlite';
}

function getCaixaDb(): SQLite3
{
    static $db = null;
    if ($db instanceof SQLite3) {
        return $db;
    }

    $path = caixaDbPath();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException('Não foi possível criar o diretório do banco de caixa.');
        }
    }

    $db = new SQLite3($path);
    $db->busyTimeout(5000);
    ensureCaixaSchema($db);

    return $db;
}

function ensureCaixaSchema(SQLite3 $db): void
{
    $db->exec('PRAGMA foreign_keys = ON');

    $db->exec(
        'CREATE TABLE IF NOT EXISTS caixas (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            deposito_id TEXT NOT NULL UNIQUE,
            nome TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT "Fechado" CHECK(status IN ("Aberto", "Fechado")),
            saldo_atual REAL NOT NULL DEFAULT 0,
            atualizado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );

    $db->exec(
        'CREATE TABLE IF NOT EXISTS caixa_tipos_movimentacao (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slug TEXT NOT NULL UNIQUE,
            nome TEXT NOT NULL,
            natureza INTEGER NOT NULL CHECK(natureza IN (-1, 1))
        )'
    );

    $db->exec(
        'CREATE TABLE IF NOT EXISTS caixa_movimentacoes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            caixa_id INTEGER NOT NULL,
            tipo_id INTEGER NOT NULL,
            natureza INTEGER NOT NULL,
            usuario_id INTEGER,
            usuario_nome TEXT,
            valor REAL NOT NULL,
            observacao TEXT,
            data_hora TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            saldo_resultante REAL NOT NULL,
            FOREIGN KEY(caixa_id) REFERENCES caixas(id) ON DELETE CASCADE,
            FOREIGN KEY(tipo_id) REFERENCES caixa_tipos_movimentacao(id) ON DELETE RESTRICT
        )'
    );

    $db->exec('CREATE INDEX IF NOT EXISTS idx_caixa_mov_caixa ON caixa_movimentacoes(caixa_id, data_hora DESC, id DESC)');

    seedCaixaTipos($db);
}

function seedCaixaTipos(SQLite3 $db): void
{
    $tipos = [
        ['slug' => 'abertura', 'nome' => 'Abertura', 'natureza' => 1],
        ['slug' => 'fechamento', 'nome' => 'Fechamento', 'natureza' => -1],
        ['slug' => 'retirada', 'nome' => 'Retirada', 'natureza' => -1],
        ['slug' => 'abastecimento', 'nome' => 'Abastecimento', 'natureza' => 1],
        ['slug' => 'troco', 'nome' => 'Troco', 'natureza' => -1],
        ['slug' => 'venda', 'nome' => 'Venda', 'natureza' => 1],
        ['slug' => 'rcbto-crediario', 'nome' => 'Rcbto Crediário', 'natureza' => 1],
    ];

    $insert = $db->prepare('INSERT OR IGNORE INTO caixa_tipos_movimentacao (slug, nome, natureza) VALUES (?, ?, ?)');
    $update = $db->prepare('UPDATE caixa_tipos_movimentacao SET nome = ?, natureza = ? WHERE slug = ?');

    foreach ($tipos as $tipo) {
        $insert->bindValue(1, $tipo['slug'], SQLITE3_TEXT);
        $insert->bindValue(2, $tipo['nome'], SQLITE3_TEXT);
        $insert->bindValue(3, $tipo['natureza'], SQLITE3_INTEGER);
        $insert->execute();

        $update->bindValue(1, $tipo['nome'], SQLITE3_TEXT);
        $update->bindValue(2, $tipo['natureza'], SQLITE3_INTEGER);
        $update->bindValue(3, $tipo['slug'], SQLITE3_TEXT);
        $update->execute();
    }
}

function obterCaixaPorDeposito(SQLite3 $db, string $depositoId, string $depositoNome = ''): array
{
    $stmt = $db->prepare('SELECT * FROM caixas WHERE deposito_id = ? LIMIT 1');
    $stmt->bindValue(1, $depositoId, SQLITE3_TEXT);
    $res = $stmt->execute();
    $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : null;

    if (!$row) {
        $nome = $depositoNome !== '' ? $depositoNome : $depositoId;
        $agora = date('c');
        $insert = $db->prepare('INSERT INTO caixas (deposito_id, nome, status, saldo_atual, atualizado_em) VALUES (?, ?, "Fechado", 0, ?)');
        $insert->bindValue(1, $depositoId, SQLITE3_TEXT);
        $insert->bindValue(2, $nome, SQLITE3_TEXT);
        $insert->bindValue(3, $agora, SQLITE3_TEXT);
        $insert->execute();

        $row = [
            'id' => $db->lastInsertRowID(),
            'deposito_id' => $depositoId,
            'nome' => $nome,
            'status' => 'Fechado',
            'saldo_atual' => 0.0,
            'atualizado_em' => $agora,
        ];
    } elseif ($depositoNome !== '' && $depositoNome !== $row['nome']) {
        $update = $db->prepare('UPDATE caixas SET nome = ?, atualizado_em = ? WHERE id = ?');
        $update->bindValue(1, $depositoNome, SQLITE3_TEXT);
        $update->bindValue(2, date('c'), SQLITE3_TEXT);
        $update->bindValue(3, $row['id'], SQLITE3_INTEGER);
        $update->execute();

        $row['nome'] = $depositoNome;
        $row['atualizado_em'] = date('c');
    }

    $row['saldo_atual'] = (float) $row['saldo_atual'];
    return $row;
}

function calcularSaldoDesdeUltimaAbertura(SQLite3 $db, int $caixaId): float
{
    $stmtAbertura = $db->prepare(
        'SELECT m.id, m.data_hora
           FROM caixa_movimentacoes m
           JOIN caixa_tipos_movimentacao t ON m.tipo_id = t.id
          WHERE m.caixa_id = ? AND t.slug = "abertura"
          ORDER BY datetime(m.data_hora) DESC, m.id DESC
          LIMIT 1'
    );
    $stmtAbertura->bindValue(1, $caixaId, SQLITE3_INTEGER);
    $resAbertura = $stmtAbertura->execute();
    $ultimaAbertura = $resAbertura ? $resAbertura->fetchArray(SQLITE3_ASSOC) : null;

    $sqlSaldo =
        'SELECT COALESCE(SUM(CASE WHEN t.slug = "fechamento" THEN 0 ELSE m.valor * m.natureza END), 0) AS saldo
           FROM caixa_movimentacoes m
           JOIN caixa_tipos_movimentacao t ON m.tipo_id = t.id
          WHERE m.caixa_id = ?';

    if ($ultimaAbertura) {
        $sqlSaldo .=
            ' AND (datetime(m.data_hora) > datetime(?)
                OR (datetime(m.data_hora) = datetime(?) AND m.id >= ?))';
    }

    $stmtSaldo = $db->prepare($sqlSaldo);
    $stmtSaldo->bindValue(1, $caixaId, SQLITE3_INTEGER);

    if ($ultimaAbertura) {
        $stmtSaldo->bindValue(2, $ultimaAbertura['data_hora'], SQLITE3_TEXT);
        $stmtSaldo->bindValue(3, $ultimaAbertura['data_hora'], SQLITE3_TEXT);
        $stmtSaldo->bindValue(4, (int) $ultimaAbertura['id'], SQLITE3_INTEGER);
    }

    $resSaldo = $stmtSaldo->execute();
    $rowSaldo = $resSaldo ? $resSaldo->fetchArray(SQLITE3_ASSOC) : null;

    $saldo = $rowSaldo && isset($rowSaldo['saldo']) ? (float) $rowSaldo['saldo'] : 0.0;

    return round($saldo, 2);
}

function obterTipoPorSlug(SQLite3 $db, string $slug): ?array
{
    $stmt = $db->prepare('SELECT * FROM caixa_tipos_movimentacao WHERE slug = ? LIMIT 1');
    $stmt->bindValue(1, $slug, SQLITE3_TEXT);
    $res = $stmt->execute();
    $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : null;
    if (!$row) {
        return null;
    }
    $row['natureza'] = (int) $row['natureza'];
    return $row;
}

function obterUsuarioPorLogin(?string $login): ?array
{
    if (!$login) {
        return null;
    }
    $dbPath = __DIR__ . '/../../data/pdv_users.sqlite';
    if (!file_exists($dbPath)) {
        return null;
    }
    $db = new SQLite3($dbPath);
    $stmt = $db->prepare('SELECT id, nome, usuario FROM usuarios WHERE LOWER(TRIM(usuario)) = LOWER(TRIM(?)) LIMIT 1');
    $stmt->bindValue(1, $login, SQLITE3_TEXT);
    $res = $stmt->execute();
    $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : null;
    if (!$row) {
        return null;
    }
    return [
        'id' => isset($row['id']) ? (int) $row['id'] : null,
        'nome' => $row['nome'] ?? ($row['usuario'] ?? $login),
    ];
}

function validarOperacaoStatus(string $statusAtual, string $tipoSlug): array
{
    $statusAtual = $statusAtual === 'Aberto' ? 'Aberto' : 'Fechado';
    $tipoSlug = strtolower($tipoSlug);

    switch ($tipoSlug) {
        case 'abertura':
            return [
                'permitido' => $statusAtual === 'Fechado',
                'novo_status' => 'Aberto',
            ];
        case 'fechamento':
            return [
                'permitido' => $statusAtual === 'Aberto',
                'novo_status' => 'Fechado',
            ];
        case 'retirada':
        case 'abastecimento':
        case 'troco':
        case 'venda':
            return [
                'permitido' => $statusAtual === 'Aberto',
                'novo_status' => $statusAtual,
            ];
        case 'rcbto-crediario':
            return [
                'permitido' => true,
                'novo_status' => $statusAtual,
            ];
        default:
            return [
                'permitido' => false,
                'novo_status' => $statusAtual,
            ];
    }
}

function listarMovimentacoes(SQLite3 $db, int $caixaId, int $limite = 50): array
{
    $stmt = $db->prepare(
        'SELECT m.id, m.valor, m.observacao, m.data_hora, m.natureza, m.saldo_resultante,
                m.usuario_id, m.usuario_nome, t.nome AS tipo_nome, t.slug AS tipo_slug
         FROM caixa_movimentacoes m
         JOIN caixa_tipos_movimentacao t ON m.tipo_id = t.id
         WHERE m.caixa_id = ?
         ORDER BY datetime(m.data_hora) DESC, m.id DESC
         LIMIT ?'
    );
    $stmt->bindValue(1, $caixaId, SQLITE3_INTEGER);
    $stmt->bindValue(2, $limite, SQLITE3_INTEGER);
    $res = $stmt->execute();
    $movs = [];
    while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) {
        $movs[] = [
            'id' => (int) $row['id'],
            'valor' => (float) $row['valor'],
            'observacao' => $row['observacao'] ?? '',
            'dataHora' => $row['data_hora'],
            'natureza' => (int) $row['natureza'],
            'saldoResultante' => (float) $row['saldo_resultante'],
            'usuarioId' => isset($row['usuario_id']) ? (int) $row['usuario_id'] : null,
            'usuarioNome' => $row['usuario_nome'] ?? null,
            'tipoNome' => $row['tipo_nome'],
            'tipoSlug' => $row['tipo_slug'],
        ];
    }
    return $movs;
}

function obterResumoCaixa(SQLite3 $db, array $caixa): array
{
    return [
        'id' => (int) $caixa['id'],
        'depositoId' => $caixa['deposito_id'],
        'nome' => $caixa['nome'],
        'status' => $caixa['status'],
        'saldoAtual' => (float) $caixa['saldo_atual'],
        'atualizadoEm' => $caixa['atualizado_em'],
        'saldoCalculado' => calcularSaldoDesdeUltimaAbertura($db, (int) $caixa['id']),
    ];
}

function obterDadosCaixa(string $depositoId, string $depositoNome = ''): array
{
    $db = getCaixaDb();
    $caixa = obterCaixaPorDeposito($db, $depositoId, $depositoNome);
    return [
        'caixa' => obterResumoCaixa($db, $caixa),
        'movimentos' => listarMovimentacoes($db, (int) $caixa['id']),
    ];
}

function registrarMovimentacaoCaixa(
    string $depositoId,
    string $depositoNome,
    string $tipoSlug,
    float $valor,
    string $observacao,
    ?string $usuarioLogin
): array {
    if ($valor < 0) {
        throw new InvalidArgumentException('Informe um valor maior ou igual a zero.');
    }

    $db = getCaixaDb();
    $caixa = obterCaixaPorDeposito($db, $depositoId, $depositoNome);
    $tipo = obterTipoPorSlug($db, $tipoSlug);

    if (!$tipo) {
        throw new InvalidArgumentException('Tipo de movimentação inválido.');
    }

    if ($tipo['slug'] !== 'fechamento' && $valor <= 0) {
        throw new InvalidArgumentException('Informe um valor maior que zero.');
    }

    $validacao = validarOperacaoStatus($caixa['status'], $tipo['slug']);
    if (!$validacao['permitido']) {
        throw new LogicException('Operação não permitida para o status atual do caixa.');
    }

    $observacao = trim($observacao);

    $usuario = obterUsuarioPorLogin($usuarioLogin);
    $usuarioId = $usuario['id'] ?? null;
    $usuarioNome = $usuario['nome'] ?? $usuarioLogin;

    $valor = round($valor, 2);
    $saldoCalculadoFechamento = null;
    $saldoEsperadoFechamento = null;
    $diferencaFechamento = 0.0;
    $valorFechamentoConsiderado = $valor;
    $diferencaAbertura = 0.0;

    if ($tipo['slug'] === 'fechamento') {
        $saldoCalculadoFechamento = calcularSaldoDesdeUltimaAbertura($db, (int) $caixa['id']);
        $saldoEsperadoFechamento = round($saldoCalculadoFechamento, 2);
        $diferencaFechamento = round($valor - $saldoEsperadoFechamento, 2);
        if (abs($diferencaFechamento) >= 0.01) {
            $textoDiferenca = 'Diferença: ' . ($diferencaFechamento >= 0 ? '+' : '-') . 'R$ ' . number_format(abs($diferencaFechamento), 2, ',', '.');
            if ($observacao !== '') {
                $observacao .= ' | ';
            }
            $observacao .= $textoDiferenca;
        } else {
            $diferencaFechamento = 0.0;
            $valorFechamentoConsiderado = $saldoEsperadoFechamento !== null
                ? $saldoEsperadoFechamento
                : $valor;
        }
    } elseif ($tipo['slug'] === 'abertura') {
        $saldoAnterior = round((float) $caixa['saldo_atual'], 2);
        $diferencaAbertura = round($valor - $saldoAnterior, 2);
        if (abs($diferencaAbertura) >= 0.01) {
            $textoDiferenca = 'Diferença: ' . ($diferencaAbertura >= 0 ? '+' : '-')
                . 'R$ ' . number_format(abs($diferencaAbertura), 2, ',', '.');
            if ($observacao !== '') {
                $observacao .= ' | ';
            }
            $observacao .= $textoDiferenca;
        } else {
            $diferencaAbertura = 0.0;
        }
    }

    if (mb_strlen($observacao) > 140) {
        $observacao = mb_substr($observacao, 0, 140);
    }

    if ($tipo['slug'] === 'fechamento') {
        if ($saldoEsperadoFechamento !== null) {
            $novoSaldo = round($saldoEsperadoFechamento + $diferencaFechamento, 2);
        } else {
            $novoSaldo = round($valorFechamentoConsiderado, 2);
        }
    } elseif ($tipo['slug'] === 'abertura') {
        $novoSaldo = round($valor, 2);
    } else {
        $novoSaldo = round($caixa['saldo_atual'] + ($valor * $tipo['natureza']), 2);
    }
    $agora = date('c');

    $db->exec('BEGIN');
    try {
        $insert = $db->prepare(
            'INSERT INTO caixa_movimentacoes (caixa_id, tipo_id, natureza, usuario_id, usuario_nome, valor, observacao, data_hora, saldo_resultante)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->bindValue(1, $caixa['id'], SQLITE3_INTEGER);
        $insert->bindValue(2, $tipo['id'], SQLITE3_INTEGER);
        $insert->bindValue(3, $tipo['natureza'], SQLITE3_INTEGER);
        if ($usuarioId !== null) {
            $insert->bindValue(4, $usuarioId, SQLITE3_INTEGER);
        } else {
            $insert->bindValue(4, null, SQLITE3_NULL);
        }
        $insert->bindValue(5, $usuarioNome, SQLITE3_TEXT);
        $insert->bindValue(6, $valor, SQLITE3_FLOAT);
        if ($observacao === '') {
            $insert->bindValue(7, null, SQLITE3_NULL);
        } else {
            $insert->bindValue(7, $observacao, SQLITE3_TEXT);
        }
        $insert->bindValue(8, $agora, SQLITE3_TEXT);
        $insert->bindValue(9, $novoSaldo, SQLITE3_FLOAT);
        $insert->execute();

        $novoStatus = $validacao['novo_status'];
        $update = $db->prepare('UPDATE caixas SET status = ?, saldo_atual = ?, atualizado_em = ? WHERE id = ?');
        $update->bindValue(1, $novoStatus, SQLITE3_TEXT);
        $update->bindValue(2, $novoSaldo, SQLITE3_FLOAT);
        $update->bindValue(3, $agora, SQLITE3_TEXT);
        $update->bindValue(4, $caixa['id'], SQLITE3_INTEGER);
        $update->execute();

        $db->exec('COMMIT');
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }

    $caixaAtualizado = obterCaixaPorDeposito($db, $depositoId, $depositoNome);

    return [
        'caixa' => obterResumoCaixa($db, $caixaAtualizado),
        'movimentos' => listarMovimentacoes($db, (int) $caixaAtualizado['id']),
    ];
}

function listarTiposMovimentacao(SQLite3 $db): array
{
    $res = $db->query('SELECT id, slug, nome, natureza FROM caixa_tipos_movimentacao ORDER BY nome ASC');
    $tipos = [];
    while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) {
        $tipos[] = [
            'id' => (int) $row['id'],
            'slug' => $row['slug'],
            'nome' => $row['nome'],
            'natureza' => (int) $row['natureza'],
        ];
    }
    return $tipos;
}
