<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

date_default_timezone_set('America/Chicago');

$jsonFile = __DIR__ . '/following.json';
$logFile = __DIR__ . '/actions.jsonl';

$allowedTypes = ['tech', 'news', 'porn', 'girls', 'fake', 'personal', 'other'];
$statusCommands = ['keep', 'unfollow', 'moved'];

function origin_allowed(string $origin): bool
{
    if ($origin === 'http://localhost:8000') {
        return true;
    }
    return (bool) preg_match('#\Achrome-extension://[A-Za-z0-9_-]{1,128}\z#', $origin);
}

function send_cors(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (is_string($origin) && $origin !== '' && origin_allowed($origin)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function fail(string $message, int $code = 500): void
{
    http_response_code($code);
    echo json_encode(['error' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function utf8(string $value): string
{
    if (preg_match('//u', $value) === 1) {
        return $value;
    }
    $clean = iconv('UTF-8', 'UTF-8//IGNORE', $value);
    return $clean === false ? '' : $clean;
}

function clean_text(string $value, int $max): string
{
    $value = utf8($value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $max);
    }
    return substr($value, 0, $max);
}

function clean_username(string $username): string
{
    $username = trim($username);
    if (str_starts_with($username, '@')) {
        $username = substr($username, 1);
    }
    if (!preg_match('/\A[A-Za-z0-9_]{1,15}\z/', $username)) {
        return '';
    }
    return $username;
}

function is_numeric_id(string $id): bool
{
    return preg_match('/\A\d{4,30}\z/', $id) === 1;
}

function clean_id(mixed $id, string $username): string
{
    if (is_int($id)) {
        $id = (string) $id;
    } elseif (!is_string($id)) {
        $id = '';
    }
    $id = trim($id);
    if ($id !== '' && (is_numeric_id($id) || preg_match('/\A[A-Za-z0-9_]{1,15}\z/', $id) === 1)) {
        return $id;
    }
    return $username;
}

function clean_url(string $url, string $username): string
{
    $url = trim($url);
    if (preg_match('#\Ahttps://(www\.)?(x|twitter)\.com/[A-Za-z0-9_]{1,15}/?\z#i', $url) === 1) {
        return rtrim($url, '/');
    }
    if ($username !== '') {
        return 'https://x.com/' . $username;
    }
    return '';
}

function norm_status(mixed $status): string
{
    $status = strtolower(trim((string) $status));
    if ($status === '' || $status === 'pending') {
        return 'pending';
    }
    return $status;
}

function read_data(): array
{
    global $jsonFile;
    if (!is_file($jsonFile)) {
        return ['accounts' => []];
    }
    $raw = file_get_contents($jsonFile);
    if ($raw === false) {
        fail('Failed to read following.json');
    }
    if (trim($raw) === '') {
        return ['accounts' => []];
    }
    $data = json_decode($raw, true, 512, JSON_BIGINT_AS_STRING);
    if (!is_array($data) || !isset($data['accounts']) || !is_array($data['accounts'])) {
        fail('Invalid JSON in following.json');
    }
    return $data;
}

function save_data(array $data): void
{
    global $jsonFile;
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        fail('Failed to encode JSON');
    }
    $json .= "\n";
    $tmp = $jsonFile . '.' . getmypid() . '.' . uniqid('', true) . '.tmp';
    if (file_put_contents($tmp, $json) === false) {
        fail('Failed to write temp file');
    }
    if (!rename($tmp, $jsonFile)) {
        @unlink($tmp);
        fail('Failed to save following.json');
    }
}

function log_line(array $fields): void
{
    global $logFile;
    $ordered = [
        'at' => date('c'),
        'id' => (string) ($fields['id'] ?? ''),
        'username' => (string) ($fields['username'] ?? ''),
        'action' => (string) ($fields['action'] ?? ''),
        'type' => (string) ($fields['type'] ?? ''),
        'status' => (string) ($fields['status'] ?? ''),
        'note' => (string) ($fields['note'] ?? ''),
    ];
    if ($ordered['action'] === 'import') {
        $ordered['added'] = (int) ($fields['added'] ?? 0);
        $ordered['updated'] = (int) ($fields['updated'] ?? 0);
        $ordered['total'] = (int) ($fields['total'] ?? 0);
    }
    $line = json_encode($ordered, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($line === false) {
        fail('Failed to encode log');
    }
    $fh = fopen($logFile, 'ab');
    if ($fh === false) {
        fail('Failed to open actions.jsonl');
    }
    $ok = fwrite($fh, $line . "\n");
    fflush($fh);
    fclose($fh);
    if ($ok === false) {
        fail('Failed to append actions.jsonl');
    }
}

function with_lock(callable $fn): void
{
    global $jsonFile;
    $lockPath = $jsonFile . '.lock';
    $lock = fopen($lockPath, 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        if ($lock !== false) {
            fclose($lock);
        }
        fail('Failed to lock following.json');
    }
    try {
        $fn();
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function public_account(array $account): array
{
    $username = clean_username((string) ($account['username'] ?? ''));
    $status = norm_status($account['status'] ?? '');
    if (!in_array($status, ['pending', 'keep', 'unfollow', 'moved'], true)) {
        $status = 'pending';
    }
    return [
        'id' => (string) ($account['id'] ?? $username),
        'username' => $username,
        'name' => clean_text((string) ($account['name'] ?? ''), 80),
        'bio' => clean_text((string) ($account['bio'] ?? ''), 500),
        'url' => clean_url((string) ($account['url'] ?? ''), $username),
        'status' => $status,
        'type' => (string) ($account['type'] ?? ''),
        'note' => clean_text((string) ($account['note'] ?? ''), 2000),
    ];
}

function find_by_id(array $accounts, string $id): int
{
    foreach ($accounts as $i => $account) {
        if ((string) ($account['id'] ?? '') === $id) {
            return $i;
        }
    }
    return -1;
}

function find_for_import(array $accounts, string $id, string $username): int
{
    if ($id !== '') {
        $exact = find_by_id($accounts, $id);
        if ($exact !== -1) {
            return $exact;
        }
    }
    if ($username === '') {
        return -1;
    }
    $needle = strtolower($username);
    foreach ($accounts as $i => $account) {
        $existingName = strtolower((string) ($account['username'] ?? ''));
        $existingId = (string) ($account['id'] ?? '');
        if ($existingName !== $needle && strtolower($existingId) !== $needle) {
            continue;
        }
        $existingNum = is_numeric_id($existingId);
        $incomingNum = is_numeric_id($id);
        if ($existingNum && $incomingNum && $existingId !== $id) {
            continue;
        }
        return $i;
    }
    return -1;
}

function empty_type_counts(): array
{
    return ['pending' => 0, 'keep' => 0, 'unfollow' => 0, 'moved' => 0];
}

function stats_payload(array $accounts): array
{
    global $allowedTypes;
    $counts = ['pending' => 0, 'keep' => 0, 'unfollow' => 0, 'moved' => 0];
    $byType = [];
    foreach ($allowedTypes as $type) {
        $byType[$type] = empty_type_counts();
    }
    $byType[''] = empty_type_counts();
    $current = null;
    $total = 0;
    foreach ($accounts as $account) {
        if (!is_array($account)) {
            continue;
        }
        $total++;
        $status = norm_status($account['status'] ?? '');
        if (!isset($counts[$status])) {
            $status = 'pending';
        }
        $counts[$status]++;
        $type = (string) ($account['type'] ?? '');
        if (!isset($byType[$type])) {
            $byType[$type] = empty_type_counts();
        }
        $byType[$type][$status]++;
        if ($current === null && $status === 'pending') {
            $current = public_account($account);
        }
    }
    $pending = $counts['pending'];
    $done = $counts['keep'] + $counts['unfollow'] + $counts['moved'];
    $percentage = $total > 0 ? round(($done / $total) * 100, 2) : 0;
    return [
        'total' => $total,
        'pending' => $pending,
        'done' => $done,
        'percentage' => $percentage,
        'by_status' => $counts,
        'by_type' => $byType,
        'current' => $current,
    ];
}

function account_log(array $account, string $action): array
{
    $pub = public_account($account);
    return [
        'id' => $pub['id'],
        'username' => $pub['username'],
        'action' => $action,
        'type' => $pub['type'],
        'status' => $pub['status'],
        'note' => $pub['note'],
    ];
}

send_cors();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    if (!is_file($jsonFile)) {
        echo json_encode(stats_payload([]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    $data = read_data();
    echo json_encode(stats_payload($data['accounts']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method !== 'POST') {
    fail('Invalid request', 405);
}

$rawBody = file_get_contents('php://input');
$input = json_decode($rawBody === false ? '' : $rawBody, true, 512, JSON_BIGINT_AS_STRING);
if (!is_array($input)) {
    fail('Invalid JSON body', 400);
}

$command = strtolower(trim((string) ($input['command'] ?? '')));
if ($command === '') {
    fail('Missing command', 400);
}

if ($command === 'import') {
    if (!isset($input['accounts']) || !is_array($input['accounts'])) {
        fail('Missing accounts', 400);
    }
    with_lock(function () use ($input): void {
        $data = read_data();
        $accounts = $data['accounts'];
        $added = 0;
        $updated = 0;
        $valid = 0;
        foreach ($input['accounts'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $username = clean_username((string) ($row['username'] ?? ''));
            if ($username === '' && isset($row['id']) && is_string($row['id'])) {
                $username = clean_username($row['id']);
            }
            if ($username === '') {
                continue;
            }
            $valid++;
            $id = clean_id($row['id'] ?? '', $username);
            $name = clean_text((string) ($row['name'] ?? ''), 80);
            $bio = clean_text((string) ($row['bio'] ?? ''), 500);
            $url = clean_url((string) ($row['url'] ?? ''), $username);
            $index = find_for_import($accounts, $id, $username);
            if ($index === -1) {
                $accounts[] = [
                    'id' => $id,
                    'username' => $username,
                    'name' => $name !== '' ? $name : $username,
                    'bio' => $bio,
                    'url' => $url,
                    'status' => 'pending',
                    'type' => '',
                    'note' => '',
                ];
                $added++;
                continue;
            }
            $existingId = (string) ($accounts[$index]['id'] ?? '');
            if (!is_numeric_id($existingId) && is_numeric_id($id)) {
                $accounts[$index]['id'] = $id;
            }
            $accounts[$index]['username'] = $username;
            if ($name !== '') {
                $accounts[$index]['name'] = $name;
            }
            if ($bio !== '') {
                $accounts[$index]['bio'] = $bio;
            }
            if ($url !== '') {
                $accounts[$index]['url'] = $url;
            }
            if (!isset($accounts[$index]['status']) || trim((string) $accounts[$index]['status']) === '') {
                $accounts[$index]['status'] = 'pending';
            }
            if (!isset($accounts[$index]['type'])) {
                $accounts[$index]['type'] = '';
            }
            if (!isset($accounts[$index]['note'])) {
                $accounts[$index]['note'] = '';
            }
            $updated++;
        }
        if ($valid === 0 && count($input['accounts']) > 0) {
            fail('No valid accounts', 400);
        }
        $data['accounts'] = $accounts;
        save_data($data);
        log_line([
            'action' => 'import',
            'added' => $added,
            'updated' => $updated,
            'total' => count($accounts),
        ]);
        echo json_encode([
            'success' => true,
            'command' => 'import',
            'added' => $added,
            'updated' => $updated,
            'total' => count($accounts),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    });
    exit;
}

if (!in_array($command, array_merge($statusCommands, ['type', 'note']), true)) {
    fail('Invalid command', 400);
}

$id = clean_id($input['id'] ?? '', '');
if ($id === '') {
    fail('Missing id', 400);
}

with_lock(function () use ($command, $id, $input, $statusCommands, $allowedTypes): void {
    $data = read_data();
    $accounts = $data['accounts'];
    $index = find_by_id($accounts, $id);
    if ($index === -1) {
        fail('Account not found', 404);
    }
    if (in_array($command, $statusCommands, true)) {
        $accounts[$index]['status'] = $command;
        $data['accounts'] = $accounts;
        save_data($data);
        log_line(account_log($accounts[$index], $command));
        echo json_encode([
            'success' => true,
            'command' => $command,
            'id' => (string) $accounts[$index]['id'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return;
    }
    if ($command === 'type') {
        $type = strtolower(trim((string) ($input['type'] ?? '')));
        if (!in_array($type, $allowedTypes, true)) {
            fail('Invalid type', 400);
        }
        $accounts[$index]['type'] = $type;
        $data['accounts'] = $accounts;
        save_data($data);
        log_line(account_log($accounts[$index], 'classify'));
        echo json_encode([
            'success' => true,
            'command' => 'type',
            'id' => (string) $accounts[$index]['id'],
            'type' => $type,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return;
    }
    if (!isset($input['note']) || !is_string($input['note'])) {
        fail('Missing note', 400);
    }
    $accounts[$index]['note'] = clean_text($input['note'], 2000);
    $data['accounts'] = $accounts;
    save_data($data);
    log_line(account_log($accounts[$index], 'note'));
    echo json_encode([
        'success' => true,
        'command' => 'note',
        'id' => (string) $accounts[$index]['id'],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
});
