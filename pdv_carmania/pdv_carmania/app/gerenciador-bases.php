<?php
require_once __DIR__ . '/../session.php';

if (!isset($_SESSION['usuario'])) {
    header('Location: login.php');
    exit();
}

$basePath = realpath(__DIR__ . '/..');
$dbDirectories = [
    realpath(__DIR__ . '/../db'),
    realpath(__DIR__ . '/../data')
];
$dbFiles = [];

foreach ($dbDirectories as $dir) {
    if ($dir && is_dir($dir)) {
        $patterns = ['/*.db', '/*.sqlite', '/*.sqlite3'];
        foreach ($patterns as $pattern) {
            foreach (glob($dir . $pattern) as $file) {
                $real = realpath($file);
                if ($real && strpos($real, $basePath) === 0) {
                    $relativeKey = ltrim(str_replace($basePath, '', $real), '/\\');
                    $dbFiles[$relativeKey] = $real;
                }
            }
        }
    }
}
ksort($dbFiles);

$selectedDbKey = $_POST['db'] ?? $_GET['db'] ?? '';
$selectedTable = $_POST['table'] ?? $_GET['table'] ?? '';
$editRowId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$searchColumn = $_POST['search_column'] ?? $_GET['search_column'] ?? '';
$searchValue = $_POST['search_value'] ?? $_GET['search_value'] ?? '';
$limit = isset($_POST['limit']) ? (int) $_POST['limit'] : (isset($_GET['limit']) ? (int) $_GET['limit'] : 100);
if ($limit <= 0 || $limit > 500) {
    $limit = 100;
}
$offset = isset($_POST['offset']) ? (int) $_POST['offset'] : (isset($_GET['offset']) ? (int) $_GET['offset'] : 0);
if ($offset < 0) {
    $offset = 0;
}

$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if ($selectedDbKey && isset($dbFiles[$selectedDbKey])) {
        $dbPath = $dbFiles[$selectedDbKey];
        try {
            $db = new SQLite3($dbPath);
            $db->exec('PRAGMA foreign_keys = ON');
            $tabelasDisponiveis = listarTabelas($db);
            if (!in_array($selectedTable, $tabelasDisponiveis, true)) {
                $message = ['type' => 'danger', 'text' => 'Tabela selecionada inválida.'];
            } else {
                $rowId = isset($_POST['rowid']) ? (int) $_POST['rowid'] : 0;
                if ($rowId <= 0) {
                    $message = ['type' => 'danger', 'text' => 'Identificador de registro inválido.'];
                } else {
                    $sql = 'DELETE FROM "' . str_replace('"', '""', $selectedTable) . '" WHERE rowid = :rowid';
                    $stmt = $db->prepare($sql);
                    $stmt->bindValue(':rowid', $rowId, SQLITE3_INTEGER);
                    $resultado = $stmt->execute();
                    if ($resultado === false) {
                        $message = ['type' => 'danger', 'text' => 'Não foi possível excluir o registro.'];
                    } else {
                        if ($resultado instanceof SQLite3Result) {
                            $resultado->finalize();
                        }
                        $message = ['type' => 'success', 'text' => 'Registro excluído com sucesso.'];
                        if ($editRowId === $rowId) {
                            $editRowId = 0;
                        }
                    }
                }
            }
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Erro ao acessar o banco de dados: ' . htmlspecialchars($e->getMessage())];
        }
    } else {
        $message = ['type' => 'danger', 'text' => 'Banco de dados selecionado inválido.'];
    }
}

function listarTabelas(SQLite3 $db): array
{
    $tabelas = [];
    $resultado = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
    while ($row = $resultado->fetchArray(SQLITE3_ASSOC)) {
        $tabelas[] = $row['name'];
    }
    return $tabelas;
}

function obterColunas(SQLite3 $db, string $tabela): array
{
    $colunas = [];
    $resultado = $db->query('PRAGMA table_info("' . str_replace('"', '""', $tabela) . '")');
    while ($row = $resultado->fetchArray(SQLITE3_ASSOC)) {
        $colunas[] = $row;
    }
    return $colunas;
}

function detectarTipoBind(?string $tipo): int
{
    $tipo = strtoupper((string) $tipo);
    if (str_contains($tipo, 'INT')) {
        return SQLITE3_INTEGER;
    }
    if (str_contains($tipo, 'REAL') || str_contains($tipo, 'FLOA') || str_contains($tipo, 'DOUB')) {
        return SQLITE3_FLOAT;
    }
    if (str_contains($tipo, 'BLOB')) {
        return SQLITE3_BLOB;
    }
    return SQLITE3_TEXT;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    if ($selectedDbKey && isset($dbFiles[$selectedDbKey])) {
        $dbPath = $dbFiles[$selectedDbKey];
        try {
            $db = new SQLite3($dbPath);
            $tabelasDisponiveis = listarTabelas($db);
            if (!in_array($selectedTable, $tabelasDisponiveis, true)) {
                $message = ['type' => 'danger', 'text' => 'Tabela selecionada inválida.'];
            } else {
                $colunas = obterColunas($db, $selectedTable);
                $rowId = isset($_POST['rowid']) ? (int) $_POST['rowid'] : 0;
                if ($rowId <= 0) {
                    $message = ['type' => 'danger', 'text' => 'Identificador de registro inválido.'];
                } else {
                    $valoresColunas = $_POST['columns'] ?? [];
                    $colunasNulas = array_flip($_POST['null_columns'] ?? []);
                    $setParts = [];
                    $bindValues = [];
                    $index = 0;
                    foreach ($colunas as $coluna) {
                        $nomeColuna = $coluna['name'];
                        if ($nomeColuna === 'rowid') {
                            continue;
                        }
                        $placeholder = ':p' . $index;
                        $setParts[] = '"' . str_replace('"', '""', $nomeColuna) . '" = ' . $placeholder;
                        if (isset($colunasNulas[$nomeColuna])) {
                            $bindValues[] = [$placeholder, null, SQLITE3_NULL];
                        } else {
                            $valor = $valoresColunas[$nomeColuna] ?? '';
                            $tipoDado = detectarTipoBind($coluna['type']);
                            if ($tipoDado === SQLITE3_INTEGER) {
                                $valor = $valor === '' ? null : (int) $valor;
                            } elseif ($tipoDado === SQLITE3_FLOAT) {
                                $valor = $valor === '' ? null : (float) $valor;
                            } elseif ($tipoDado === SQLITE3_BLOB && $valor === '') {
                                $valor = null;
                            }
                            $bindValues[] = [$placeholder, $valor, $tipoDado];
                        }
                        $index++;
                    }
                    if ($setParts) {
                        $sql = 'UPDATE "' . str_replace('"', '""', $selectedTable) . '" SET ' . implode(', ', $setParts) . ' WHERE rowid = :rowid';
                        $stmt = $db->prepare($sql);
                        foreach ($bindValues as [$placeholder, $valor, $tipo]) {
                            if ($valor === null) {
                                $stmt->bindValue($placeholder, null, SQLITE3_NULL);
                            } else {
                                $stmt->bindValue($placeholder, $valor, $tipo);
                            }
                        }
                        $stmt->bindValue(':rowid', $rowId, SQLITE3_INTEGER);
                        $resultado = $stmt->execute();
                        if ($resultado === false) {
                            $message = ['type' => 'danger', 'text' => 'Não foi possível atualizar o registro.'];
                        } else {
                            $message = ['type' => 'success', 'text' => 'Registro atualizado com sucesso.'];
                            $editRowId = $rowId;
                        }
                    }
                }
            }
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Erro ao acessar o banco de dados: ' . htmlspecialchars($e->getMessage())];
        }
    } else {
        $message = ['type' => 'danger', 'text' => 'Banco de dados selecionado inválido.'];
    }
}

$tables = [];
$columns = [];
$rows = [];
$editRowData = null;

if ($selectedDbKey && isset($dbFiles[$selectedDbKey])) {
    $dbPath = $dbFiles[$selectedDbKey];
    try {
        $db = new SQLite3($dbPath);
        $tables = listarTabelas($db);
        if ($selectedTable && in_array($selectedTable, $tables, true)) {
            $columns = obterColunas($db, $selectedTable);
            $columnNames = array_column($columns, 'name');
            if ($searchColumn && !in_array($searchColumn, $columnNames, true)) {
                $searchColumn = '';
            }
            $safeTable = '"' . str_replace('"', '""', $selectedTable) . '"';
            $query = 'SELECT rowid AS _rowid_, * FROM ' . $safeTable;
            $stmt = null;
            $shouldBindSearch = $searchColumn !== '' && $searchValue !== '';
            if ($shouldBindSearch) {
                $safeColumn = '"' . str_replace('"', '""', $searchColumn) . '"';
                $query .= ' WHERE ' . $safeColumn . ' LIKE :search';
            }
            $query .= sprintf(' LIMIT %d OFFSET %d', $limit, $offset);
            $stmt = $db->prepare($query);
            if ($stmt === false) {
                throw new Exception('Não foi possível preparar a consulta.');
            }
            if ($shouldBindSearch) {
                $stmt->bindValue(':search', '%' . $searchValue . '%', SQLITE3_TEXT);
            }
            $result = $stmt->execute();
            if ($result === false) {
                throw new Exception('Não foi possível executar a consulta.');
            }
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $rows[] = $row;
                if ($editRowId > 0 && $row['_rowid_'] == $editRowId) {
                    $editRowData = $row;
                }
            }
            if ($editRowId > 0 && $editRowData === null) {
                $stmt = $db->prepare('SELECT rowid AS _rowid_, * FROM ' . $safeTable . ' WHERE rowid = :rowid');
                $stmt->bindValue(':rowid', $editRowId, SQLITE3_INTEGER);
                $res = $stmt->execute();
                if ($res) {
                    $editRowData = $res->fetchArray(SQLITE3_ASSOC);
                }
            }
        } else {
            $selectedTable = '';
        }
    } catch (Exception $e) {
        $message = ['type' => 'danger', 'text' => 'Erro ao carregar dados: ' . htmlspecialchars($e->getMessage())];
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciador de Bases Locais</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <style>
        body {
            background-color: #f8f9fa;
        }
        .table-scroll {
            max-height: 60vh;
            overflow: auto;
        }
        pre.value-preview {
            white-space: pre-wrap;
            word-break: break-word;
            font-size: 0.875rem;
        }
    </style>
</head>
<body>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Gerenciador de Bases Locais</h1>
        <a href="index.php" class="btn btn-outline-secondary">Voltar ao PDV</a>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <form class="row gy-3 align-items-end" method="get">
                <div class="col-12 col-md-4">
                    <label class="form-label" for="db-select">Banco de dados</label>
                    <select id="db-select" name="db" class="form-select" onchange="this.form.submit()">
                        <option value="">Selecione...</option>
                        <?php foreach ($dbFiles as $key => $path): ?>
                            <option value="<?= htmlspecialchars($key) ?>" <?= $key === $selectedDbKey ? 'selected' : '' ?>><?= htmlspecialchars($key) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label" for="table-select">Tabela</label>
                    <select id="table-select" name="table" class="form-select" <?= $selectedDbKey ? '' : 'disabled' ?> onchange="this.form.submit()">
                        <option value="">Selecione...</option>
                        <?php foreach ($tables as $table): ?>
                            <option value="<?= htmlspecialchars($table) ?>" <?= $table === $selectedTable ? 'selected' : '' ?>><?= htmlspecialchars($table) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label" for="limit-input">Limite</label>
                    <input type="number" min="1" max="500" id="limit-input" name="limit" class="form-control" value="<?= (int) $limit ?>">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label" for="offset-input">Offset</label>
                    <input type="number" min="0" id="offset-input" name="offset" class="form-control" value="<?= (int) $offset ?>">
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label" for="search-column">Coluna</label>
                    <select id="search-column" name="search_column" class="form-select" <?= $selectedTable ? '' : 'disabled' ?>>
                        <option value="">Todas</option>
                        <?php foreach ($columns as $coluna): ?>
                            <option value="<?= htmlspecialchars($coluna['name']) ?>" <?= $coluna['name'] === $searchColumn ? 'selected' : '' ?>><?= htmlspecialchars($coluna['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-5">
                    <label class="form-label" for="search-value">Valor da busca</label>
                    <input type="text" id="search-value" name="search_value" class="form-control" value="<?= htmlspecialchars($searchValue) ?>" <?= $selectedTable ? '' : 'disabled' ?>>
                </div>
                <div class="col-12">
                    <button class="btn btn-primary" type="submit">Atualizar lista</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?= htmlspecialchars($message['type']) ?>"><?= htmlspecialchars($message['text']) ?></div>
    <?php endif; ?>

    <?php if ($selectedDbKey && $selectedTable): ?>
        <div class="row">
            <div class="col-lg-7">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h2 class="h5 mb-0">Registros - <?= htmlspecialchars($selectedTable) ?></h2>
                        <small class="text-muted">Mostrando até <?= count($rows) ?> registros</small>
                    </div>
                    <div class="card-body table-scroll">
                        <table class="table table-sm table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col">Ações</th>
                                    <?php foreach ($columns as $coluna): ?>
                                        <th scope="col"><?= htmlspecialchars($coluna['name']) ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$rows): ?>
                                    <tr>
                                        <td colspan="<?= count($columns) + 1 ?>" class="text-center text-muted">Nenhum registro encontrado.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($rows as $linha): ?>
                                        <tr class="<?= ($editRowId > 0 && $linha['_rowid_'] == $editRowId) ? 'table-warning' : '' ?>">
                                            <td class="d-flex gap-2">
                                                <a class="btn btn-sm btn-outline-primary" href="?db=<?= urlencode($selectedDbKey) ?>&table=<?= urlencode($selectedTable) ?>&limit=<?= (int) $limit ?>&offset=<?= (int) $offset ?>&search_column=<?= urlencode($searchColumn) ?>&search_value=<?= urlencode($searchValue) ?>&edit=<?= (int) $linha['_rowid_'] ?>">Editar</a>
                                                <form method="post" class="d-inline" onsubmit="return confirm('Tem certeza que deseja excluir este registro? Esta ação não pode ser desfeita.');">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="db" value="<?= htmlspecialchars($selectedDbKey) ?>">
                                                    <input type="hidden" name="table" value="<?= htmlspecialchars($selectedTable) ?>">
                                                    <input type="hidden" name="rowid" value="<?= (int) $linha['_rowid_'] ?>">
                                                    <input type="hidden" name="limit" value="<?= (int) $limit ?>">
                                                    <input type="hidden" name="offset" value="<?= (int) $offset ?>">
                                                    <input type="hidden" name="search_column" value="<?= htmlspecialchars($searchColumn) ?>">
                                                    <input type="hidden" name="search_value" value="<?= htmlspecialchars($searchValue) ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">Excluir</button>
                                                </form>
                                            </td>
                                            <?php foreach ($columns as $coluna): ?>
                                                <?php $nomeColuna = $coluna['name']; ?>
                                                <td><pre class="mb-0 value-preview"><?= htmlspecialchars((string) ($linha[$nomeColuna] ?? '')) ?></pre></td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-lg-5 mt-4 mt-lg-0">
                <div class="card">
                    <div class="card-header">
                        <h2 class="h5 mb-0">Editar registro</h2>
                    </div>
                    <div class="card-body">
                        <?php if ($editRowData): ?>
                            <form method="post">
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="db" value="<?= htmlspecialchars($selectedDbKey) ?>">
                                <input type="hidden" name="table" value="<?= htmlspecialchars($selectedTable) ?>">
                                <input type="hidden" name="rowid" value="<?= (int) $editRowData['_rowid_'] ?>">
                                <input type="hidden" name="limit" value="<?= (int) $limit ?>">
                                <input type="hidden" name="offset" value="<?= (int) $offset ?>">
                                <input type="hidden" name="search_column" value="<?= htmlspecialchars($searchColumn) ?>">
                                <input type="hidden" name="search_value" value="<?= htmlspecialchars($searchValue) ?>">
                                <?php foreach ($columns as $coluna): ?>
                                    <?php $nomeColuna = $coluna['name']; ?>
                                    <div class="mb-3">
                                        <label class="form-label"><?= htmlspecialchars($nomeColuna) ?> <small class="text-muted"><?= $coluna['type'] ?></small></label>
                                        <textarea class="form-control" name="columns[<?= htmlspecialchars($nomeColuna) ?>]" rows="2"><?= htmlspecialchars((string) ($editRowData[$nomeColuna] ?? '')) ?></textarea>
                                        <div class="form-check mt-1">
                                            <input class="form-check-input" type="checkbox" name="null_columns[]" value="<?= htmlspecialchars($nomeColuna) ?>" id="null-<?= htmlspecialchars($nomeColuna) ?>">
                                            <label class="form-check-label" for="null-<?= htmlspecialchars($nomeColuna) ?>">Definir como NULL</label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <div class="d-flex gap-2">
                                    <button type="submit" class="btn btn-success">Salvar alterações</button>
                                    <a href="?db=<?= urlencode($selectedDbKey) ?>&table=<?= urlencode($selectedTable) ?>&limit=<?= (int) $limit ?>&offset=<?= (int) $offset ?>&search_column=<?= urlencode($searchColumn) ?>&search_value=<?= urlencode($searchValue) ?>" class="btn btn-outline-secondary">Cancelar</a>
                                </div>
                            </form>
                        <?php elseif ($selectedTable): ?>
                            <p class="text-muted mb-0">Selecione "Editar" em algum registro para carregá-lo aqui.</p>
                        <?php else: ?>
                            <p class="text-muted mb-0">Escolha um banco de dados e uma tabela para visualizar e editar registros.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php elseif ($selectedDbKey): ?>
        <div class="alert alert-info">Escolha uma tabela para visualizar os registros.</div>
    <?php else: ?>
        <div class="alert alert-info">Selecione um banco de dados para começar.</div>
    <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
