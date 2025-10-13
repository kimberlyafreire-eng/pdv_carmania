<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/lib/token-helper.php'; // getAccessToken()/refreshAccessToken()

$logFile  = __DIR__ . '/../logs/atualizar-estoque.log';
$blingBase = 'https://bling.com.br/Api/v3';

if (!function_exists('logMsg')) {
  function logMsg($msg) {
    global $logFile;
    file_put_contents($logFile, "[".date('Y-m-d H:i:s')."] $msg\n", FILE_APPEND);
  }
}

try {
  $depositoId = isset($_GET['depositoId']) ? trim($_GET['depositoId']) : null;
  if (!$depositoId) {
    echo json_encode(['ok'=>false, 'erro'=>'Informe depositoId'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  logMsg("🚀 Iniciando atualização de estoque para depósito $depositoId");

  // DB
  $dbFile = __DIR__ . '/../db/produtos.db';
  if (!file_exists($dbFile)) {
    echo json_encode(['ok'=>false,'erro'=>'Banco não encontrado'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $db = new SQLite3($dbFile);

  // Tabela local de estoque (com unique p/ REPLACE funcionar)
  $db->exec("
    CREATE TABLE IF NOT EXISTS estoque_local (
      idDeposito TEXT NOT NULL,
      idProduto  INTEGER NOT NULL,
      saldo      REAL NOT NULL DEFAULT 0,
      PRIMARY KEY (idDeposito, idProduto)
    )
  ");

  // Carrega todos os IDs de produtos conhecidos
  $ids = [];
  $res = $db->query("SELECT id FROM produtos WHERE id IS NOT NULL");
  while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $ids[] = (int)$row['id'];
  }
  if (empty($ids)) {
    echo json_encode(['ok'=>false,'erro'=>'Sem produtos cadastrados'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  logMsg("🔄 Iniciando sincronização de estoque para $depositoId com " . count($ids) . " produtos.");

  // Função para chamar a API do Bling (com retry de 401/429)
  $callSaldos = function(array $lote, $retry = 0) use ($blingBase, $depositoId) {
    // Monta a query: idsProdutos[]=1&idsProdutos[]=2...
    $q = 'idsProdutos[]=' . implode('&idsProdutos[]=', array_map('intval', $lote));
    $url = rtrim($blingBase,'/') . '/estoques/saldos/' . rawurlencode($depositoId) . '?' . $q;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        'Authorization: Bearer ' . getAccessToken(),
      ],
      CURLOPT_TIMEOUT => 30
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($retry === 0) {
      // Loga só a 1a chamada do lote para referência
      logMsg("🔗 URL chamada: $url");
    }

    // Trata expiração do token
    if ($http === 401 && $retry < 1) {
      logMsg("⚠ Token expirado durante atualização — renovando...");
      refreshAccessToken();
      return $callSaldos($lote, $retry + 1);
    }

    // Trata rate limit
    if ($http === 429 && $retry < 3) {
      usleep(400000); // 400ms
      return $callSaldos($lote, $retry + 1);
    }

    return [$http, $resp];
  };

  // Prepare do UPSERT (INSERT OR REPLACE)
  $stmt = $db->prepare("INSERT OR REPLACE INTO estoque_local (idDeposito, idProduto, saldo) VALUES (:dep, :pid, :saldo)");

  $atualizados = 0;
  $chunkSize = 50; // recomendado
  $lotes = array_chunk($ids, $chunkSize);

  foreach ($lotes as $lote) {
    list($http, $resp) = $callSaldos($lote, 0);

    if ($http !== 200) {
      logMsg("❌ Erro HTTP $http ao buscar lote (".count($lote)."). Resp: $resp");
      continue;
    }

    $json = json_decode($resp, true);
    $data = $json['data'] ?? [];

    foreach ($data as $row) {
      $pid   = (int)($row['produto']['id'] ?? 0);
      // 👉 escolha o saldo que quer usar. Físico por padrão:
      $saldo = isset($row['saldoFisicoTotal']) ? (float)$row['saldoFisicoTotal'] : (float)($row['saldoVirtualTotal'] ?? 0);

      if ($pid <= 0) continue;

      $stmt->bindValue(':dep',   (string)$depositoId, SQLITE3_TEXT);
      $stmt->bindValue(':pid',   $pid,                SQLITE3_INTEGER);
      $stmt->bindValue(':saldo', $saldo,              SQLITE3_FLOAT);
      $stmt->execute();

      $atualizados++;
    }

    // respeito ao rate limit
    usleep(200000); // 200ms entre lotes
  }

  logMsg("🏁 Estoque atualizado — depósito $depositoId com $atualizados registros gravados.");

  echo json_encode(['ok'=>true,'depositoId'=>$depositoId,'total'=>$atualizados], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
  logMsg("💥 Erro: ".$e->getMessage());
  echo json_encode(['ok'=>false, 'erro'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
