<?php
declare(strict_types=1);

/**
 * Kondela → Prodboard price sync
 * PHP 7.4+ / 8.x
 */

// ===================== CONFIG =====================
const CONFIG = [
    'sourceUrl'        => 'https://b2b.kondela.sk/feed/kuchyna_jedalen.xml',
    //'sourceUrl'        => 'https://dev.prodboard.com/clients/kondela/test.xml',
    'cacheFile'        => __DIR__ . '/kondela_price_cache.json',
    'logFile'          => __DIR__ . '/kondela_price_sync.log',
    'company'          => '',
    'privateKey'       => '',
    'apiBase'          => 'https://api-v2.prodboard.com',
    'priceListCode'    => 'price',
    'batchLimit'       => 100,
    'batchPauseMs'     => 200,
    'timeoutSeconds'   => 30,
    'verifySSL'        => true,
    'dryRun'           => true,
    'timezone'         => 'Europe/Bratislava',
];
// ===================================================

date_default_timezone_set(CONFIG['timezone']);

/** ------------ Helpers ------------- */

function log_prepend(string $file, string $line): void {
    $date = date('c');
    $entry = "[$date] $line\n";
    if (!file_exists($file)) {
        file_put_contents($file, $entry);
        return;
    }
    $existing = file_get_contents($file);
    file_put_contents($file, $entry . $existing);
}

function load_env_file(string $file): void {
    if (!file_exists($file)) {
        return;
    }
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }
        $key = trim($parts[0]);
        $val = trim($parts[1]);
        $val = trim($val, "\"'");
        if ($key !== '') {
            putenv("$key=$val");
            $_ENV[$key] = $val;
        }
    }
}

function http_request_json(string $method, string $url, ?array $payload = null, array $headers = [], int $timeout = 30, bool $verifySSL = true): array {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => $verifySSL,
        CURLOPT_SSL_VERIFYHOST => $verifySSL ? 2 : 0,
    ];
    if ($payload !== null) {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $opts[CURLOPT_POSTFIELDS] = $body;
        $headers = array_merge($headers, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen((string)$body),
        ]);
        $opts[CURLOPT_HTTPHEADER] = $headers;
    }
    curl_setopt_array($ch, $opts);
    $respBody = curl_exec($ch);
    $err      = curl_error($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'ok'      => $err === '' && $code >= 200 && $code < 300,
        'status'  => $code,
        'error'   => $err,
        'raw'     => $respBody,
    ];
}

function fetch_xml(string $url, int $timeout = 30, bool $verifySSL = true): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => $verifySSL,
        CURLOPT_SSL_VERIFYHOST => $verifySSL ? 2 : 0,
        CURLOPT_HTTPGET        => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_HTTPHEADER     => ['accept: application/xml,text/xml,*/*'],
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err !== '' || $code < 200 || $code >= 300 || !$resp) {
        throw new RuntimeException("Fetch XML failed (HTTP $code): $err");
    }
    return $resp;
}

function parse_prices_from_xml(string $xmlText, string $priceListCode): array {
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlText);
    if ($xml === false) {
        $errs = array_map(fn($e) => trim($e->message), libxml_get_errors());
        throw new RuntimeException('XML parse error: ' . implode(' | ', $errs));
    }
    $result = [];
    $products = $xml->xpath('//PRODUKT');
    $priceNodeName = $priceListCode === 'price_ron' ? 'MOC_RO' : 'MOC_EUR';
    foreach ($products as $p) {
        $skuNode   = $p->KOD_TOVARU ?? null;
        $priceNode = $p->{$priceNodeName} ?? null;
        if ($skuNode === null || $priceNode === null) continue;
        $sku = trim((string)$skuNode);
        if ($sku === '') continue;
        $raw = trim(str_replace(',', '.', (string)$priceNode));
        $raw = preg_replace('/[^\d\.]+/', '', $raw ?? '');
        if ($raw === '' || !is_numeric($raw)) continue;
        $price = (float)$raw;
        $result[$sku] = $price;
    }
    return $result;
}

function load_cache(string $file): array {
    if (!file_exists($file)) return [];
    $txt = file_get_contents($file);
    if ($txt === false || trim($txt) === '') return [];
    $data = json_decode($txt, true);
    return is_array($data) ? $data : [];
}

function save_cache(string $file, array $data): void {
    // округляем все значения до 2 знаков
    $rounded = [];
    foreach ($data as $sku => $price) {
        $rounded[$sku] = round((float)$price, 2);
    }

    $json = json_encode(
        $rounded,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );
    file_put_contents($file, $json);
}

function chunk_array(array $items, int $size): array {
    return array_chunk($items, $size);
}

/** ------------ MAIN ------------- */

load_env_file(__DIR__ . '/.env');
$company = getenv('PRODBOARD_COMPANY') ?: CONFIG['company'];
$privateKey = getenv('PRODBOARD_PRIVATE_KEY') ?: CONFIG['privateKey'];
$sourceUrl = getenv('SYNC_SOURCE_URL') ?: CONFIG['sourceUrl'];
$cacheFile = getenv('SYNC_CACHE_FILE') ?: CONFIG['cacheFile'];
$logFile = getenv('SYNC_LOG_FILE') ?: CONFIG['logFile'];
$priceListCode = getenv('SYNC_PRICE_LIST_CODE') ?: CONFIG['priceListCode'];
$dryRunRaw = getenv('SYNC_DRY_RUN');
$dryRun = $dryRunRaw === false ? CONFIG['dryRun'] : filter_var($dryRunRaw, FILTER_VALIDATE_BOOLEAN);
$forceAllRaw = getenv('SYNC_FORCE_ALL');
$forceAll = $forceAllRaw !== false && filter_var($forceAllRaw, FILTER_VALIDATE_BOOLEAN);
$logContext = "priceList=$priceListCode | source=$sourceUrl";
$log = static function (string $line) use ($logFile, $logContext): void {
    log_prepend($logFile, "[$logContext] $line");
};
if ($company === '' || $privateKey === '') {
    $log('ERROR auth config: missing PRODBOARD_COMPANY or PRODBOARD_PRIVATE_KEY');
    exit(4);
}

// 1️⃣ Получаем XML
try {
    $xml = fetch_xml($sourceUrl, CONFIG['timeoutSeconds'], CONFIG['verifySSL']);
} catch (Throwable $e) {
    $log("ERROR fetch: " . $e->getMessage());
    exit(2);
}

// 2️⃣ Парсим XML
try {
    $source = parse_prices_from_xml($xml, $priceListCode);
} catch (Throwable $e) {
    $log("ERROR parse: " . $e->getMessage());
    exit(3);
}
$totalSkus = count($source);

// 3️⃣ Загружаем кэш
$cache = load_cache($cacheFile);

// 4️⃣ Авторизация
$authResp = http_request_json(
    'POST',
    CONFIG['apiBase'] . '/security/get-token',
    ['company' => $company, 'privateKey' => $privateKey],
    ['accept: */*'],
    CONFIG['timeoutSeconds'],
    CONFIG['verifySSL']
);
if (!$authResp['ok']) {
    $log("ERROR auth HTTP={$authResp['status']} RESP={$authResp['raw']}");
    exit(4);
}
$token = trim($authResp['raw'], "\" \n\r\t");
$log("OK auth | token length=" . strlen($token));

// 5️⃣ Получаем список всех существующих товаров
$existing = [];
$page = 0;
while (true) {
    $url = CONFIG['apiBase'] . '/products?pageIndex=' . $page;
    $resp = http_request_json(
        'GET',
        $url,
        null,
        ['accept: application/json', 'Authorization: Bearer ' . $token],
        CONFIG['timeoutSeconds'],
        CONFIG['verifySSL']
    );
    if (!$resp['ok']) {
        $log("ERROR products page#$page HTTP={$resp['status']}");
        break;
    }
    $data = json_decode($resp['raw'], true);
    if (!isset($data['items']) || empty($data['items'])) break;
    foreach ($data['items'] as $item) {
        if (!empty($item['code'])) {
            $existing[$item['code']] = true;
        }
    }
    $page++;
    if (count($data['items']) < 100) break;
}
$totalExisting = count($existing);
$log("OK fetched products | total existing=$totalExisting");

// 6️⃣ Сравниваем данные
$updates = [];
$missing = 0;

foreach ($source as $sku => $price) {
    if (!isset($existing[$sku])) {
        $missing++;
        continue;
    }
    $old = $cache[$sku] ?? null;
    if ($forceAll || $old === null || abs(((float)$old) - $price) > 0.00001) {
        $updates[] = [
            'product'   => (string)$sku,
            'unitPrice' => round($price, 2),
        ];
    }
}

$totalUpdated = count($updates);
if ($totalUpdated === 0) {
    $log("OK no changes | Total SKUs=$totalSkus | Existing=$totalExisting");
    $log("SK vysvetlenie: Beh je v poriadku, prihlasenie a nacitanie produktov prebehlo uspesne, ale nebola najdena ziadna zmena cien na odoslanie.");
    if (!file_exists($cacheFile)) save_cache($cacheFile, $source);
    exit(0);
}

// 7️⃣ Отправляем пакетами
$batches = chunk_array($updates, CONFIG['batchLimit']);
$sentCount = 0;
$failed = 0;

foreach ($batches as $idx => $batch) {
    if ($dryRun) {
        $sentCount += count($batch);
        continue;
    }

    foreach ($batch as &$line) $line['product'] = (string)$line['product'];
    unset($line);

    $resp = http_request_json(
        'POST',
        CONFIG['apiBase'] . '/pricing/price-lists/' . rawurlencode($priceListCode) . '/lines',
        $batch, // 👈 просто массив, без поля "lines"
        ['Authorization: Bearer ' . $token, 'accept: */*'],
        CONFIG['timeoutSeconds'],
        CONFIG['verifySSL']
    );


    if (!$resp['ok']) {
        $status = $resp['status'];
        $raw = trim($resp['raw']);
        if ($status === 422 && stripos($raw, 'entity-not-found') !== false) {
            $failed += count($batch);
            $log("WARN missing product: " . $batch[0]['product']);
            continue;
        }
        $log("ERROR push batch#" . ($idx + 1) . " HTTP=$status RESP=$raw");
        continue;
    }

    $sentCount += count($batch);
    usleep(CONFIG['batchPauseMs'] * 1000);
}

// 8️⃣ Обновляем кэш и лог
save_cache($cacheFile, $source);

$log("OK updated | Total SKUs=$totalSkus | Existing=$totalExisting | Total Updated=$totalUpdated | Sent=$sentCount | Missing=$missing | Failed=$failed");
if ($forceAll) {
    $log("SK vysvetlenie: Bezi force rezim, skript odosiela vsetky ceny z feedu bez porovnavania s cache.");
}
$log("SK vysvetlenie: Beh je v poriadku, ceny boli porovnane a zmenene polozky boli odoslane do cennika. Hodnota Sent znamena pocet uspesne odoslanych riadkov.");

exit(0);
