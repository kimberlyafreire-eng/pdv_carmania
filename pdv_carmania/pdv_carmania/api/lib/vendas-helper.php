<?php
declare(strict_types=1);

/**
 * Funções utilitárias para o banco local de vendas.
 */
function getVendasDb(): SQLite3
{
    static $db = null;
    if ($db instanceof SQLite3) {
        return $db;
    }

    $path = __DIR__ . '/../../db/vendas.sqlite';
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $db = new SQLite3($path);
    $db->exec('PRAGMA foreign_keys = ON');
    ensureVendasSchema($db);

    return $db;
}

function ensureVendasSchema(SQLite3 $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS vendas (
        id INTEGER PRIMARY KEY,
        data_hora TEXT NOT NULL,
        contato_id INTEGER,
        contato_nome TEXT,
        usuario_id INTEGER,
        usuario_login TEXT,
        usuario_nome TEXT,
        deposito_id INTEGER,
        deposito_nome TEXT,
        situacao_id INTEGER,
        valor_total REAL NOT NULL DEFAULT 0,
        valor_desconto REAL NOT NULL DEFAULT 0,
        criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        atualizado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS venda_pagamentos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        venda_id INTEGER NOT NULL,
        forma_pagamento_id INTEGER,
        forma_pagamento_nome TEXT,
        valor REAL NOT NULL DEFAULT 0,
        FOREIGN KEY (venda_id) REFERENCES vendas(id) ON DELETE CASCADE
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS venda_itens (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        venda_id INTEGER NOT NULL,
        produto_id INTEGER,
        produto_nome TEXT,
        valor_unitario REAL NOT NULL DEFAULT 0,
        quantidade INTEGER NOT NULL DEFAULT 0,
        FOREIGN KEY (venda_id) REFERENCES vendas(id) ON DELETE CASCADE
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS situacoes_pedido (
        id INTEGER PRIMARY KEY,
        nome TEXT NOT NULL
    )');

    $db->exec('CREATE INDEX IF NOT EXISTS idx_vendas_data ON vendas(data_hora)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_vendas_situacao ON vendas(situacao_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_pagamentos_venda ON venda_pagamentos(venda_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_itens_venda ON venda_itens(venda_id)');

    seedSituacoesPedido($db);
}

function seedSituacoesPedido(SQLite3 $db): void
{
    $situacoes = [
        9  => 'Atendido',
        12 => 'Cancelado',
        6  => 'Em aberto',
        15 => 'Em andamento',
        21 => 'Em digitação',
        18 => 'Venda Agenciada',
        24 => 'Verificado',
    ];

    $stmt = $db->prepare('INSERT OR REPLACE INTO situacoes_pedido (id, nome) VALUES (:id, :nome)');
    foreach ($situacoes as $id => $nome) {
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->bindValue(':nome', $nome, SQLITE3_TEXT);
        $stmt->execute();
    }
}

function registrarVendaLocal(array $dadosVenda, array $pagamentos, array $itens): void
{
    $db = getVendasDb();
    $db->exec('BEGIN');

    try {
        $vendaId = (int) ($dadosVenda['id'] ?? 0);
        if ($vendaId <= 0) {
            throw new RuntimeException('ID da venda inválido para persistência local.');
        }

        $dataHora = (string) ($dadosVenda['data_hora'] ?? date('Y-m-d H:i:s'));
        $contatoId = isset($dadosVenda['contato_id']) ? (int) $dadosVenda['contato_id'] : null;
        $contatoNome = (string) ($dadosVenda['contato_nome'] ?? '');
        $situacaoId = isset($dadosVenda['situacao_id']) ? (int) $dadosVenda['situacao_id'] : null;
        $valorTotal = (float) ($dadosVenda['valor_total'] ?? 0);
        $valorDesconto = (float) ($dadosVenda['valor_desconto'] ?? 0);
        $depositoId = isset($dadosVenda['deposito_id']) ? (int) $dadosVenda['deposito_id'] : null;
        $depositoNome = (string) ($dadosVenda['deposito_nome'] ?? '');
        $usuarioLogin = isset($dadosVenda['usuario_login']) ? trim((string) $dadosVenda['usuario_login']) : '';
        $usuarioNome = isset($dadosVenda['usuario_nome']) ? trim((string) $dadosVenda['usuario_nome']) : '';
        $usuarioId = isset($dadosVenda['usuario_id']) ? (int) $dadosVenda['usuario_id'] : null;

        if (($usuarioId === null || $usuarioId <= 0) && $usuarioLogin !== '') {
            $usuarioInfo = buscarUsuarioSistema($usuarioLogin);
            if ($usuarioInfo) {
                $usuarioId = (int) $usuarioInfo['id'];
                if ($usuarioNome === '' && !empty($usuarioInfo['nome'])) {
                    $usuarioNome = $usuarioInfo['nome'];
                }
            }
        }

        if ($usuarioNome === '' && $usuarioLogin !== '') {
            $usuarioNome = $usuarioLogin;
        }

        $sql = 'INSERT INTO vendas (
                    id, data_hora, contato_id, contato_nome, usuario_id, usuario_login, usuario_nome,
                    deposito_id, deposito_nome, situacao_id, valor_total, valor_desconto, atualizado_em
                ) VALUES (
                    :id, :data_hora, :contato_id, :contato_nome, :usuario_id, :usuario_login, :usuario_nome,
                    :deposito_id, :deposito_nome, :situacao_id, :valor_total, :valor_desconto, CURRENT_TIMESTAMP
                )
                ON CONFLICT(id) DO UPDATE SET
                    data_hora = excluded.data_hora,
                    contato_id = excluded.contato_id,
                    contato_nome = excluded.contato_nome,
                    usuario_id = excluded.usuario_id,
                    usuario_login = excluded.usuario_login,
                    usuario_nome = excluded.usuario_nome,
                    deposito_id = excluded.deposito_id,
                    deposito_nome = excluded.deposito_nome,
                    situacao_id = excluded.situacao_id,
                    valor_total = excluded.valor_total,
                    valor_desconto = excluded.valor_desconto,
                    atualizado_em = excluded.atualizado_em';

        $stmt = $db->prepare($sql);
        $stmt->bindValue(':id', $vendaId, SQLITE3_INTEGER);
        $stmt->bindValue(':data_hora', $dataHora, SQLITE3_TEXT);

        if ($contatoId !== null && $contatoId > 0) {
            $stmt->bindValue(':contato_id', $contatoId, SQLITE3_INTEGER);
        } else {
            $stmt->bindValue(':contato_id', null, SQLITE3_NULL);
        }
        $stmt->bindValue(':contato_nome', $contatoNome, SQLITE3_TEXT);

        if ($usuarioId !== null && $usuarioId > 0) {
            $stmt->bindValue(':usuario_id', $usuarioId, SQLITE3_INTEGER);
        } else {
            $stmt->bindValue(':usuario_id', null, SQLITE3_NULL);
        }
        $stmt->bindValue(':usuario_login', $usuarioLogin !== '' ? $usuarioLogin : null, $usuarioLogin !== '' ? SQLITE3_TEXT : SQLITE3_NULL);
        $stmt->bindValue(':usuario_nome', $usuarioNome !== '' ? $usuarioNome : null, $usuarioNome !== '' ? SQLITE3_TEXT : SQLITE3_NULL);

        if ($depositoId !== null && $depositoId > 0) {
            $stmt->bindValue(':deposito_id', $depositoId, SQLITE3_INTEGER);
        } else {
            $stmt->bindValue(':deposito_id', null, SQLITE3_NULL);
        }
        $stmt->bindValue(':deposito_nome', $depositoNome !== '' ? $depositoNome : null, $depositoNome !== '' ? SQLITE3_TEXT : SQLITE3_NULL);

        if ($situacaoId !== null && $situacaoId > 0) {
            $stmt->bindValue(':situacao_id', $situacaoId, SQLITE3_INTEGER);
        } else {
            $stmt->bindValue(':situacao_id', null, SQLITE3_NULL);
        }

        $stmt->bindValue(':valor_total', $valorTotal, SQLITE3_FLOAT);
        $stmt->bindValue(':valor_desconto', $valorDesconto, SQLITE3_FLOAT);
        $stmt->execute();

        $delPag = $db->prepare('DELETE FROM venda_pagamentos WHERE venda_id = :id');
        $delPag->bindValue(':id', $vendaId, SQLITE3_INTEGER);
        $delPag->execute();

        $delItens = $db->prepare('DELETE FROM venda_itens WHERE venda_id = :id');
        $delItens->bindValue(':id', $vendaId, SQLITE3_INTEGER);
        $delItens->execute();

        if (!empty($pagamentos)) {
            $insPag = $db->prepare('INSERT INTO venda_pagamentos (venda_id, forma_pagamento_id, forma_pagamento_nome, valor)
                                    VALUES (:venda_id, :forma_id, :forma_nome, :valor)');
            foreach ($pagamentos as $p) {
                if (!is_array($p)) {
                    continue;
                }
                $formaId = isset($p['id']) ? (int) $p['id'] : null;
                $formaNome = (string) ($p['forma'] ?? $p['nome'] ?? '');
                $valor = (float) ($p['valor'] ?? 0);

                if ($valor <= 0 && isset($p['valorAplicado'])) {
                    $valor = (float) $p['valorAplicado'];
                }
                if ($valor <= 0) {
                    continue;
                }

                $insPag->bindValue(':venda_id', $vendaId, SQLITE3_INTEGER);
                if ($formaId !== null && $formaId > 0) {
                    $insPag->bindValue(':forma_id', $formaId, SQLITE3_INTEGER);
                } else {
                    $insPag->bindValue(':forma_id', null, SQLITE3_NULL);
                }
                $insPag->bindValue(':forma_nome', $formaNome !== '' ? $formaNome : null, $formaNome !== '' ? SQLITE3_TEXT : SQLITE3_NULL);
                $insPag->bindValue(':valor', round($valor, 2), SQLITE3_FLOAT);
                $insPag->execute();
            }
        }

        if (!empty($itens)) {
            $insItem = $db->prepare('INSERT INTO venda_itens (venda_id, produto_id, produto_nome, valor_unitario, quantidade)
                                     VALUES (:venda_id, :produto_id, :produto_nome, :valor_unitario, :quantidade)');
            foreach ($itens as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $produtoId = isset($item['id']) ? (int) $item['id'] : null;
                $produtoNome = (string) ($item['nome'] ?? '');
                $valorUnitario = (float) ($item['preco'] ?? 0);
                $quantidade = (int) ($item['quantidade'] ?? 0);
                if ($quantidade <= 0) {
                    continue;
                }

                $insItem->bindValue(':venda_id', $vendaId, SQLITE3_INTEGER);
                if ($produtoId !== null && $produtoId > 0) {
                    $insItem->bindValue(':produto_id', $produtoId, SQLITE3_INTEGER);
                } else {
                    $insItem->bindValue(':produto_id', null, SQLITE3_NULL);
                }
                $insItem->bindValue(':produto_nome', $produtoNome !== '' ? $produtoNome : null, $produtoNome !== '' ? SQLITE3_TEXT : SQLITE3_NULL);
                $insItem->bindValue(':valor_unitario', round($valorUnitario, 4), SQLITE3_FLOAT);
                $insItem->bindValue(':quantidade', $quantidade, SQLITE3_INTEGER);
                $insItem->execute();
            }
        }

        $db->exec('COMMIT');
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
}

function buscarUsuarioSistema(string $login): ?array
{
    static $cache = [];
    $chave = strtolower(trim($login));
    if ($chave === '') {
        return null;
    }
    if (array_key_exists($chave, $cache)) {
        return $cache[$chave];
    }

    $path = __DIR__ . '/../../data/pdv_users.sqlite';
    if (!is_file($path)) {
        $cache[$chave] = null;
        return null;
    }

    $db = new SQLite3($path);
    $stmt = $db->prepare('SELECT id, nome FROM usuarios WHERE LOWER(TRIM(usuario)) = LOWER(TRIM(:usuario)) LIMIT 1');
    $stmt->bindValue(':usuario', $login, SQLITE3_TEXT);
    $res = $stmt->execute();
    $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : null;
    $db->close();

    if ($row) {
        $cache[$chave] = ['id' => $row['id'], 'nome' => $row['nome'] ?? ''];
    } else {
        $cache[$chave] = null;
    }

    return $cache[$chave];
}
