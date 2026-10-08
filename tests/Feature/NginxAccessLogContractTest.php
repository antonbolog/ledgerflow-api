<?php

declare(strict_types=1);

/** @return list<array{blocks: list<list<string>>, words: list<string>}> */
function nginxStatements(): array
{
    $path = base_path('docker/nginx/default.conf');

    expect($path)->toBeReadableFile();

    preg_match_all(
        '/"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|#[^\n]*|[{};]|[^\s{};"\'#]+/',
        (string) file_get_contents($path),
        $tokens,
    );

    $statements = [];
    $blocks = [];
    $words = [];

    foreach ($tokens[0] as $token) {
        if (str_starts_with($token, '#')) {
            continue;
        }

        if ($token === '{') {
            $statements[] = ['blocks' => $blocks, 'words' => $words];
            $blocks[] = $words;
            $words = [];
        } elseif ($token === '}') {
            array_pop($blocks);
        } elseif ($token === ';') {
            $statements[] = ['blocks' => $blocks, 'words' => $words];
            $words = [];
        } else {
            $words[] = str_starts_with($token, '"') || str_starts_with($token, "'") ? substr($token, 1, -1) : $token;
        }
    }

    return $statements;
}

/** @return list<array{blocks: list<list<string>>, words: list<string>}> */
function nginxDirectives(string $name): array
{
    return array_values(array_filter(nginxStatements(), fn (array $statement): bool => ($statement['words'][0] ?? null) === $name));
}

function nginxSelectedLogFormat(): string
{
    $serverLogs = array_values(array_filter(
        nginxDirectives('access_log'),
        fn (array $statement): bool => $statement['blocks'] === [['server']],
    ));

    expect($serverLogs)->toHaveCount(1);

    $formatName = $serverLogs[0]['words'][2] ?? null;
    $formats = array_values(array_filter(
        nginxDirectives('log_format'),
        fn (array $statement): bool => $statement['words'][1] === $formatName,
    ));

    expect($formats)->toHaveCount(1);

    return implode('', array_slice($formats[0]['words'], 2));
}

/** @return list<string> */
function nginxVariables(string $format): array
{
    // Whole names only, so rejecting $request never rejects $request_method.
    preg_match_all('/\$\{?([A-Za-z0-9_]+)\}?/', $format, $matches);

    return $matches[1];
}

it('selects one access log at server level so internal redirects cannot bypass it', function (): void {
    $logs = nginxDirectives('access_log');

    $serverLogs = array_filter($logs, fn (array $statement): bool => $statement['blocks'] === [['server']]);
    $otherLogs = array_filter($logs, fn (array $statement): bool => $statement['blocks'] !== [['server']]);

    expect($serverLogs)->toHaveCount(1)
        ->and(array_values($serverLogs)[0]['words'][1])->toBe('/var/log/nginx/access.log');

    foreach ($otherLogs as $statement) {
        expect($statement['blocks'])->not->toBe([])
            ->and($statement['words'])->toBe(['access_log', 'off']);
    }
});

it('mirrors the image main format with a query-free request target', function (): void {
    $format = nginxSelectedLogFormat();

    expect(nginxVariables($format))->toBe([
        'remote_addr',
        'remote_user',
        'time_local',
        'request_method',
        'request_path',
        'server_protocol',
        'status',
        'body_bytes_sent',
        'http_referer',
        'http_user_agent',
        'http_x_forwarded_for',
    ])
        ->and(nginxVariables($format))->not->toContain('request', 'request_uri', 'args', 'query_string', 'is_args')
        ->and($format)->toContain('"$request_method $request_path $server_protocol"')
        ->and($format)->not->toContain('escape=');
});

it('derives the logged path from the request URI without its query', function (): void {
    $maps = array_values(array_filter(
        nginxDirectives('map'),
        fn (array $statement): bool => ($statement['words'][2] ?? null) === '$request_path',
    ));

    expect($maps)->toHaveCount(1)
        ->and($maps[0]['words'])->toBe(['map', '$request_uri', '$request_path']);

    $entries = array_values(array_filter(
        nginxStatements(),
        fn (array $statement): bool => $statement['blocks'] === [$maps[0]['words']],
    ));

    expect($entries)->toHaveCount(1)
        ->and($entries[0]['words'][1])->toBe('$1')
        ->and($entries[0]['words'][0])->toStartWith('~');

    $pattern = '/'.str_replace('/', '\/', substr($entries[0]['words'][0], 1)).'/';

    $samples = [
        '/oauth/authorize?client_id=c&state=s&code_challenge=x&code_challenge_method=S256' => '/oauth/authorize',
        '/%6Fauth/authorize/?state=s' => '/%6Fauth/authorize/',
        '/api/payments/abc' => '/api/payments/abc',
        '/login?' => '/login',
    ];

    foreach ($samples as $requestUri => $loggedPath) {
        expect(preg_match($pattern, $requestUri, $captured))->toBe(1)
            ->and($captured[1])->toBe($loggedPath);
    }
});
