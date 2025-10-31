<?php
declare(strict_types=1);

/**
 * Funções auxiliares relacionadas ao crediário (formas de pagamento e saldos).
 */

const FORMA_CREDIARIO_ID = 8126949;

/**
 * Normaliza um texto para facilitar a detecção de palavras-chave relacionadas ao crediário.
 */
function normalizarTextoCrediario(string $texto): string
{
    $texto = function_exists('mb_strtolower') ? mb_strtolower($texto, 'UTF-8') : strtolower($texto);
    $map = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a',
        'é' => 'e', 'ê' => 'e', 'è' => 'e',
        'í' => 'i', 'ï' => 'i', 'ì' => 'i', 'î' => 'i',
        'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ò' => 'o',
        'ú' => 'u', 'ü' => 'u', 'ù' => 'u',
        'ç' => 'c'
    ];
    return strtr($texto, $map);
}

/**
 * Extrai o identificador da forma de pagamento a partir de estruturas variadas.
 */
function extrairIdFormaPagamentoCrediario(array $dados): ?int
{
    $possiveis = ['id', 'forma_pagamento_id', 'formaPagamentoId'];
    foreach ($possiveis as $chave) {
        if (isset($dados[$chave]) && (int)$dados[$chave] > 0) {
            return (int) $dados[$chave];
        }
    }

    if (isset($dados['formaPagamento']) && is_array($dados['formaPagamento'])) {
        return extrairIdFormaPagamentoCrediario($dados['formaPagamento']);
    }

    return null;
}

/**
 * Extrai o nome/descrição de uma forma de pagamento a partir de diferentes chaves possíveis.
 */
function extrairNomeFormaPagamentoCrediario(array $dados): string
{
    $possiveis = [
        'nome', 'forma', 'descricao', 'descrição', 'label',
        'forma_pagamento_nome', 'formaPagamentoNome'
    ];
    foreach ($possiveis as $chave) {
        if (!empty($dados[$chave])) {
            return (string) $dados[$chave];
        }
    }

    if (isset($dados['formaPagamento']) && is_array($dados['formaPagamento'])) {
        return extrairNomeFormaPagamentoCrediario($dados['formaPagamento']);
    }

    return '';
}

/**
 * Extrai o valor associado a uma forma de pagamento considerando diferentes estruturas.
 */
function extrairValorFormaPagamentoCrediario(array $dados): float
{
    $chaves = ['valor', 'valorAplicado', 'valorInformado', 'valorParcela', 'valor_total', 'subtotal'];
    foreach ($chaves as $chave) {
        if (isset($dados[$chave]) && is_numeric($dados[$chave])) {
            $valor = (float) $dados[$chave];
            if ($valor > 0) {
                return $valor;
            }
        }
    }

    if (isset($dados['formaPagamento']) && is_array($dados['formaPagamento'])) {
        return extrairValorFormaPagamentoCrediario($dados['formaPagamento']);
    }

    return 0.0;
}

/**
 * Analisa uma lista de pagamentos/parcelas e identifica se há crediário, retornando o total associado.
 *
 * @return array{0: bool, 1: float} [possuiCrediario, valorTotalCrediario]
 */
function analisarPagamentosCrediario(array $entradas): array
{
    $possuiCrediario = false;
    $totalCrediario = 0.0;

    foreach ($entradas as $entrada) {
        if (!is_array($entrada)) {
            continue;
        }

        $idForma = extrairIdFormaPagamentoCrediario($entrada);
        $nomeForma = normalizarTextoCrediario(extrairNomeFormaPagamentoCrediario($entrada));

        $ehCrediario = false;
        if ($idForma === FORMA_CREDIARIO_ID) {
            $ehCrediario = true;
        }
        if (!$ehCrediario && $nomeForma !== '' && strpos($nomeForma, 'crediario') !== false) {
            $ehCrediario = true;
        }

        if (!$ehCrediario) {
            continue;
        }

        $possuiCrediario = true;
        $valor = extrairValorFormaPagamentoCrediario($entrada);
        if ($valor > 0) {
            $totalCrediario += $valor;
        }
    }

    return [$possuiCrediario, round($totalCrediario, 2)];
}

/**
 * Consulta o saldo atual do crediário do cliente utilizando os endpoints internos disponíveis.
 */
function consultarSaldoCrediarioCliente($clienteId, ?callable $logger = null): ?float
{
    if (!$clienteId) {
        return null;
    }

    $payload = ['clienteId' => (string) $clienteId];
    $scheme      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host        = $_SERVER['HTTP_HOST'] ?? '';
    $serverAddr  = $_SERVER['SERVER_ADDR'] ?? '';

    $scriptDir = '';
    if (!empty($_SERVER['SCRIPT_NAME'])) {
        $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
        if ($dir !== '/' && $dir !== '.') {
            $scriptDir = $dir;
        }
    }

    $paths = [];
    if ($scriptDir !== '') {
        $paths[] = rtrim($scriptDir, '/') . '/crediario/saldo.php';
    }
    $paths[] = '/pdv_carmania/api/crediario/saldo.php';
    $paths[] = '/api/crediario/saldo.php';
    $paths[] = '/crediario/saldo.php';
    $paths = array_values(array_unique(array_map(
        static fn(string $path): string => '/' . ltrim($path, '/'),
        array_filter($paths)
    )));

    $hosts = array_values(array_unique(array_filter([
        $host,
        $serverAddr,
        '127.0.0.1',
        'localhost',
        '::1',
    ])));

    $schemes = ['https', 'http'];
    if (!in_array($scheme, $schemes, true)) {
        array_unshift($schemes, $scheme);
    } else {
        $schemes = array_unique(array_merge([$scheme], $schemes));
    }

    $tentativasRealizadas = [];

    foreach ($paths as $path) {
        foreach ($hosts as $tryHost) {
            foreach ($schemes as $tryScheme) {
                if ($tryScheme === 'https' && preg_match('/^(127\.0\.0\.1|localhost|::1)$/', $tryHost)) {
                    continue;
                }

                $url = $tryScheme . '://' . $tryHost . $path;
                if (isset($tentativasRealizadas[$url])) {
                    continue;
                }
                $tentativasRealizadas[$url] = true;

                $ch = curl_init($url);
                $options = [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST           => true,
                    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
                    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
                    CURLOPT_TIMEOUT        => 15,
                ];

                if ($tryScheme === 'https' && $tryHost !== $host) {
                    $options[CURLOPT_SSL_VERIFYPEER] = false;
                    $options[CURLOPT_SSL_VERIFYHOST] = 0;
                }

                curl_setopt_array($ch, $options);
                $resp = curl_exec($ch);
                $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err  = curl_error($ch);
                curl_close($ch);

                if ($logger) {
                    $logger(sprintf('↪️ saldo.php: POST %s (HTTP %d)%s', $url, $http, $err ? " ERR={$err}" : ''));
                }

                if ($http === 200 && $resp) {
                    $jsonSaldo = json_decode($resp, true);
                    if (!empty($jsonSaldo['ok']) && isset($jsonSaldo['saldoAtual'])) {
                        $saldo = round((float) $jsonSaldo['saldoAtual'], 2);
                        if ($logger) {
                            $logger('✅ Saldo obtido via ' . $path . ' -> R$ ' . number_format($saldo, 2, ',', '.'));
                        }
                        return $saldo;
                    }
                    if ($logger) {
                        $logger('⚠️ Resposta inesperada de ' . $path . ' -> ' . $resp);
                    }
                } else {
                    if ($logger) {
                        $logger('⚠️ Falha ao consultar ' . $path . " (HTTP {$http}) -> " . (string) $resp);
                        if ($err) {
                            $logger('ℹ️ cURL: ' . $err);
                        }
                    }
                }
            }
        }
    }

    return null;
}
