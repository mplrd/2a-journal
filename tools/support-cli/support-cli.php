<?php

declare(strict_types=1);

/**
 * support-cli — thin command-line client for the Trading Journal support API.
 *
 * Authenticates as an admin (credentials in tools/support-cli/.env), then calls
 * the admin support endpoints. Read commands are unrestricted; write commands
 * (reply / set-status / set-priority) are DRY-RUN by default and only hit the
 * API when --confirm is passed — because a write triggers a real e-mail to the
 * ticket author.
 *
 * Usage:
 *   php support-cli.php list [--status=OPEN,IN_PROGRESS] [--type=BUG] [--priority=HIGH] [--search=foo] [--page=1] [--per-page=20] [--json]
 *   php support-cli.php show <id> [--json]
 *   php support-cli.php reply <id> --body="..." [--confirm] [--json]
 *   php support-cli.php set-status <id> <STATUS> [--confirm] [--json]
 *   php support-cli.php set-priority <id> <PRIORITY> [--confirm] [--json]
 *
 * Exit codes: 0 ok, 1 usage error, 2 config error, 3 API/HTTP error.
 */

const STATUSES   = ['OPEN', 'IN_PROGRESS', 'WAITING_USER', 'RESOLVED', 'CLOSED'];
const PRIORITIES = ['LOW', 'NORMAL', 'HIGH'];
const TYPES      = ['SUPPORT', 'BUG', 'FEATURE'];

/* ── Pure helpers (unit-tested) ─────────────────────────────────────────── */

/**
 * Parse a dotenv-style string into a key=>value map. Ignores blank lines and
 * comments; strips surrounding single/double quotes from values.
 */
function parseEnvFile(string $contents): array
{
    $env = [];
    foreach (preg_split('/\r\n|\r|\n/', $contents) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }
        $key = trim(substr($line, 0, $eq));
        $val = trim(substr($line, $eq + 1));
        if (strlen($val) >= 2 && ($val[0] === '"' || $val[0] === "'") && $val[-1] === $val[0]) {
            $val = substr($val, 1, -1);
        }
        $env[$key] = $val;
    }

    return $env;
}

/**
 * Parse argv (after the command) into [positional[], options{}, flags{}].
 * --key=value -> options[key]=value ; --flag -> flags[flag]=true.
 */
function parseArgs(array $argv): array
{
    $positional = [];
    $options = [];
    $flags = [];
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--')) {
            $body = substr($arg, 2);
            $eq = strpos($body, '=');
            if ($eq === false) {
                $flags[$body] = true;
            } else {
                $options[substr($body, 0, $eq)] = substr($body, $eq + 1);
            }
        } else {
            $positional[] = $arg;
        }
    }

    return [$positional, $options, $flags];
}

/** Build a query string from admin list filters (drops empty values). */
function buildListQuery(array $options): string
{
    $map = [
        'type' => 'type',
        'status' => 'status',
        'priority' => 'priority',
        'search' => 'search',
        'page' => 'page',
        'per-page' => 'per_page',
    ];
    $query = [];
    foreach ($map as $opt => $param) {
        if (isset($options[$opt]) && $options[$opt] !== '') {
            $query[$param] = $options[$opt];
        }
    }

    return $query ? '?' . http_build_query($query) : '';
}

/** One-line summary of a ticket list row. */
function formatTicketRow(array $t): string
{
    return sprintf(
        '#%-4s  %-8s  %-12s  %-7s  %-22s  %s',
        $t['id'] ?? '?',
        $t['type'] ?? '?',
        $t['status'] ?? '?',
        $t['priority'] ?? '?',
        substr((string) ($t['user_email'] ?? '—'), 0, 22),
        (string) ($t['subject'] ?? '')
    );
}

/** Human-readable rendering of a full ticket detail (ticket + message thread). */
function formatTicketDetail(array $d): string
{
    $lines = [];
    $lines[] = sprintf('Ticket #%s — %s', $d['id'] ?? '?', $d['subject'] ?? '');
    $lines[] = sprintf(
        '  type=%s  status=%s  priority=%s  author=%s',
        $d['type'] ?? '?',
        $d['status'] ?? '?',
        $d['priority'] ?? '?',
        $d['user_email'] ?? '—'
    );
    if (!empty($d['details']) && is_array($d['details'])) {
        foreach ($d['details'] as $key => $value) {
            $lines[] = sprintf('  %s: %s', $key, $value);
        }
    }
    $lines[] = '  ── thread ──';
    foreach (($d['messages'] ?? []) as $m) {
        $who = !empty($m['author_is_admin']) ? 'ADMIN' : 'USER ';
        $att = !empty($m['attachments']) ? sprintf(' [%d attachment(s)]', count($m['attachments'])) : '';
        $lines[] = sprintf('  [%s] %s%s', $who, $m['created_at'] ?? '', $att);
        foreach (preg_split('/\r\n|\r|\n/', (string) ($m['body'] ?? '')) as $bodyLine) {
            $lines[] = '      ' . $bodyLine;
        }
    }

    return implode("\n", $lines);
}

/* ── Config & HTTP ──────────────────────────────────────────────────────── */

function loadConfig(string $dir): array
{
    $path = $dir . DIRECTORY_SEPARATOR . '.env';
    if (!is_file($path)) {
        fail(2, "Missing config: $path\nCopy .env.example to .env and fill in the admin credentials.");
    }
    $env = parseEnvFile((string) file_get_contents($path));

    $config = [
        'api_url'  => rtrim($env['JOURNAL_API_URL'] ?? '', '/'),
        'email'    => $env['JOURNAL_ADMIN_EMAIL'] ?? '',
        'password' => $env['JOURNAL_ADMIN_PASSWORD'] ?? '',
    ];
    foreach (['api_url' => 'JOURNAL_API_URL', 'email' => 'JOURNAL_ADMIN_EMAIL', 'password' => 'JOURNAL_ADMIN_PASSWORD'] as $key => $name) {
        if ($config[$key] === '') {
            fail(2, "Missing $name in $path");
        }
    }

    return $config;
}

/**
 * Extract the HTTP status code from a $http_response_header-style array
 * (e.g. "HTTP/1.1 200 OK"). Returns 0 if none is found.
 */
function parseStatusLine(array $responseHeaders): int
{
    foreach ($responseHeaders as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            $status = (int) $m[1]; // keep the last status line (handles redirects)
        }
    }

    return $status ?? 0;
}

/**
 * Perform an HTTP request. Uses curl when available, else native streams
 * (openssl + allow_url_fopen). Returns [statusCode, decodedJsonOrNull, rawBody].
 */
function httpRequest(string $method, string $url, array $headers, ?array $jsonBody = null): array
{
    $reqHeaders = $headers;
    $payload = null;
    if ($jsonBody !== null) {
        $payload = json_encode($jsonBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $reqHeaders[] = 'Content-Type: application/json';
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $reqHeaders,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_POSTFIELDS     => $payload,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            fail(3, "HTTP request failed: $err");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = json_decode((string) $raw, true);

        return [$status, is_array($json) ? $json : null, (string) $raw];
    }

    // Native streams fallback.
    $context = stream_context_create([
        'http' => [
            'method'        => $method,
            'header'        => implode("\r\n", $reqHeaders),
            'content'       => $payload,
            'timeout'       => 30,
            'ignore_errors' => true, // return 4xx/5xx bodies instead of false
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ]);
    $raw = @file_get_contents($url, false, $context);
    if ($raw === false) {
        $err = error_get_last()['message'] ?? 'connection failed';
        fail(3, "HTTP request failed: $err");
    }
    $status = parseStatusLine($http_response_header ?? []);
    $json = json_decode((string) $raw, true);

    return [$status, is_array($json) ? $json : null, (string) $raw];
}

/** Log in as admin and return a Bearer access token. Aborts if not an admin. */
function authenticate(array $config): string
{
    [$status, $json] = httpRequest('POST', $config['api_url'] . '/auth/login', [], [
        'email' => $config['email'],
        'password' => $config['password'],
    ]);
    if ($status !== 200 || empty($json['data']['access_token'])) {
        $key = $json['error']['message_key'] ?? 'unknown';
        fail(3, "Login failed (HTTP $status, $key). Check JOURNAL_ADMIN_EMAIL / JOURNAL_ADMIN_PASSWORD.");
    }
    $role = $json['data']['user']['role'] ?? null;
    if ($role !== 'ADMIN') {
        fail(2, "Account is not an admin (role=" . var_export($role, true) . "). Use an ADMIN account.");
    }

    return $json['data']['access_token'];
}

/** Authenticated admin API call. Aborts on non-2xx. Returns decoded JSON. */
function adminCall(array $config, string $token, string $method, string $path, ?array $body = null): array
{
    [$status, $json] = httpRequest($method, $config['api_url'] . $path, ["Authorization: Bearer $token"], $body);
    if ($status < 200 || $status >= 300) {
        $key = $json['error']['message_key'] ?? 'unknown';
        fail(3, "API error on $method $path (HTTP $status, $key).");
    }

    return $json ?? [];
}

/* ── Output ─────────────────────────────────────────────────────────────── */

function out(string $text): void
{
    fwrite(STDOUT, $text . "\n");
}

function fail(int $code, string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit($code);
}

function emitJson(array $data): void
{
    out((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/* ── Commands ───────────────────────────────────────────────────────────── */

function cmdList(array $config, array $options, array $flags): void
{
    $token = authenticate($config);
    $resp = adminCall($config, $token, 'GET', '/admin/support/tickets' . buildListQuery($options));
    if (!empty($flags['json'])) {
        emitJson($resp);
        return;
    }
    $rows = $resp['data'] ?? [];
    if (!$rows) {
        out('No tickets match.');
        return;
    }
    out(sprintf('%-5s %-8s %-12s %-7s %-22s %s', 'ID', 'TYPE', 'STATUS', 'PRIO', 'AUTHOR', 'SUBJECT'));
    foreach ($rows as $t) {
        out(formatTicketRow($t));
    }
    $meta = $resp['meta'] ?? [];
    if ($meta) {
        out(sprintf("\n%d shown · page %s/%s · %s total",
            count($rows), $meta['page'] ?? '?', $meta['total_pages'] ?? '?', $meta['total'] ?? '?'));
    }
}

function cmdShow(array $config, array $positional, array $flags): void
{
    $id = (int) ($positional[0] ?? 0);
    if ($id <= 0) {
        fail(1, 'Usage: show <id>');
    }
    $token = authenticate($config);
    $resp = adminCall($config, $token, 'GET', "/admin/support/tickets/$id");
    if (!empty($flags['json'])) {
        emitJson($resp);
        return;
    }
    out(formatTicketDetail($resp['data'] ?? []));
}

function cmdReply(array $config, array $positional, array $options, array $flags): void
{
    $id = (int) ($positional[0] ?? 0);
    $body = $options['body'] ?? '';
    if ($id <= 0 || trim($body) === '') {
        fail(1, 'Usage: reply <id> --body="..." [--confirm]');
    }
    if (empty($flags['confirm'])) {
        out("DRY-RUN — would POST a reply to ticket #$id (sends an e-mail to the author):");
        out('────────────────────────────────────────');
        out($body);
        out('────────────────────────────────────────');
        out('Re-run with --confirm to actually send.');
        return;
    }
    $token = authenticate($config);
    $resp = adminCall($config, $token, 'POST', "/admin/support/tickets/$id/messages", ['body' => $body]);
    out("Reply posted to ticket #$id (author notified by e-mail).");
    if (!empty($flags['json'])) {
        emitJson($resp);
    }
}

function cmdSetStatus(array $config, array $positional, array $flags): void
{
    $id = (int) ($positional[0] ?? 0);
    $status = strtoupper((string) ($positional[1] ?? ''));
    if ($id <= 0 || !in_array($status, STATUSES, true)) {
        fail(1, 'Usage: set-status <id> <' . implode('|', STATUSES) . '> [--confirm]');
    }
    if (empty($flags['confirm'])) {
        out("DRY-RUN — would set ticket #$id status to $status (notifies the author by e-mail). Re-run with --confirm.");
        return;
    }
    $token = authenticate($config);
    $resp = adminCall($config, $token, 'PATCH', "/admin/support/tickets/$id/status", ['status' => $status]);
    out("Ticket #$id status set to $status (author notified).");
    if (!empty($flags['json'])) {
        emitJson($resp);
    }
}

function cmdSetPriority(array $config, array $positional, array $flags): void
{
    $id = (int) ($positional[0] ?? 0);
    $priority = strtoupper((string) ($positional[1] ?? ''));
    if ($id <= 0 || !in_array($priority, PRIORITIES, true)) {
        fail(1, 'Usage: set-priority <id> <' . implode('|', PRIORITIES) . '> [--confirm]');
    }
    if (empty($flags['confirm'])) {
        out("DRY-RUN — would set ticket #$id priority to $priority (no e-mail). Re-run with --confirm.");
        return;
    }
    $token = authenticate($config);
    $resp = adminCall($config, $token, 'PATCH', "/admin/support/tickets/$id/priority", ['priority' => $priority]);
    out("Ticket #$id priority set to $priority.");
    if (!empty($flags['json'])) {
        emitJson($resp);
    }
}

function usage(): never
{
    out(<<<TXT
support-cli — Trading Journal support API client

Commands:
  list [--status=…] [--type=…] [--priority=…] [--search=…] [--page=N] [--per-page=N] [--json]
  show <id> [--json]
  reply <id> --body="…" [--confirm] [--json]
  set-status <id> <OPEN|IN_PROGRESS|WAITING_USER|RESOLVED|CLOSED> [--confirm] [--json]
  set-priority <id> <LOW|NORMAL|HIGH> [--confirm] [--json]

Write commands are DRY-RUN unless --confirm is given (writes trigger e-mails).
Config: tools/support-cli/.env (copy from .env.example).
TXT);
    exit(1);
}

/* ── Entry point ────────────────────────────────────────────────────────── */

// Guard so the file can be require'd by tests without running the CLI.
if (PHP_SAPI === 'cli' && isset($argv) && realpath($argv[0]) === realpath(__FILE__)) {
    $command = $argv[1] ?? '';
    [$positional, $options, $flags] = parseArgs(array_slice($argv, 2));
    $dir = __DIR__;

    switch ($command) {
        case 'list':
            cmdList(loadConfig($dir), $options, $flags);
            break;
        case 'show':
            cmdShow(loadConfig($dir), $positional, $flags);
            break;
        case 'reply':
            cmdReply(loadConfig($dir), $positional, $options, $flags);
            break;
        case 'set-status':
            cmdSetStatus(loadConfig($dir), $positional, $flags);
            break;
        case 'set-priority':
            cmdSetPriority(loadConfig($dir), $positional, $flags);
            break;
        default:
            usage();
    }
}
