<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Not found.\n");
}

/* A dependency-free black-box test runner. The source piplet is never mutated. */

if (($argv[1] ?? '') === '--worker') {
    worker_main($argv);
}

$root = dirname(__DIR__);
$source = $root . '/wiki-piplet.php';
$temporaryRoot = sys_get_temp_dir() . '/piplet-tests-' . bin2hex(random_bytes(6));
$copy = $temporaryRoot . '/index.php';
$sourceHashBefore = is_file($source) ? hash_file('sha256', $source) : false;
$runnerHashBefore = hash_file('sha256', __FILE__);
$assertions = 0;
$liveWorkers = [];
$successMessage = null;
$temporaryRootOwned = false;
$temporaryRootIdentity = null;
$defaultHttpHeaders = [];

function check(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class PipletShortWriteStream
{
    public mixed $context;
    public static string $written = '';

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        self::$written = '';
        return true;
    }

    public function stream_write(string $data): int
    {
        $chunk = substr($data, 0, 7);
        self::$written .= $chunk;
        return strlen($chunk);
    }

    public function stream_stat(): array
    {
        return [];
    }
}

function make_fixture(string $source, string $destination): bool
{
    $raw = @file_get_contents($source);
    $sourceMarker = "\nPIPLET-DATA/2\n";
    $marker = "\nPIPLET-DATA/1\n";
    $markerAt = is_string($raw) ? strrpos($raw, $sourceMarker) : false;
    if ($markerAt === false || !@copy($source, $destination)) return false;

    $document = [
        'format' => 1,
        'revision' => 1,
        'notes' => [
            'welcome' => [
                'id' => 'welcome',
                'title' => 'Hello, piplet',
                'body' => "This is a **piplet**: a single file php application.\n\n## markup\n\n- `#` makes a heading\n- `-` makes a list\n- `**words**` adds emphasis\n- `[[Hello, piplet|welcome]]` links one note to another",
                'tags' => ['welcome', 'simplicity'],
                'revision' => 1,
                'created' => '2026-08-15T05:30:00Z',
                'updated' => '2026-08-15T05:30:00Z',
            ],
        ],
    ];
    $json = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $fixture = substr($raw, 0, $markerAt) . $marker . $json . "\n";
    return file_put_contents($destination, $fixture) === strlen($fixture);
}

function fixture_copy(string $root, string $source, string $name, string $failure): string
{
    $destination = "$root/$name/index.php";
    check(mkdir(dirname($destination), 0700) && make_fixture($source, $destination), $failure);
    return $destination;
}

function replace_fixture_trailer(string $path, string $marker, string $json): bool
{
    $raw = file_get_contents($path);
    if (!is_string($raw)) return false;
    $positions = array_filter([
        strrpos($raw, "\nPIPLET-DATA/1\n"),
        strrpos($raw, "\nPIPLET-DATA/2\n"),
    ], static fn ($position): bool => $position !== false);
    if ($positions === []) return false;
    $snapshot = substr($raw, 0, max($positions)) . $marker . $json . "\n";
    return file_put_contents($path, $snapshot) === strlen($snapshot);
}

function fixture_document_object(string $path): stdClass
{
    $raw = file_get_contents($path);
    if (!is_string($raw)) throw new RuntimeException('Could not read fixture JSON.');
    $positions = array_filter([
        strrpos($raw, "\nPIPLET-DATA/1\n"),
        strrpos($raw, "\nPIPLET-DATA/2\n"),
    ], static fn ($position): bool => $position !== false);
    if ($positions === []) throw new RuntimeException('Fixture data marker is missing.');
    $markerAt = max($positions);
    $lineEnd = strpos($raw, "\n", $markerAt + 1);
    $json = $lineEnd === false ? '' : trim(substr($raw, $lineEnd + 1));
    $decoded = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
    if (!$decoded instanceof stdClass) throw new RuntimeException('Fixture data is not an object.');
    return $decoded;
}

function css_theme_tokens(string $source, string $selector): array
{
    $quoted = preg_quote($selector, '/');
    check(preg_match('/' . $quoted . '\s*\{([^}]+)\}/', $source, $block) === 1, "Missing CSS theme block: $selector");
    $tokens = [];
    preg_match_all('/--([a-z-]+)\s*:\s*(#[0-9a-f]{6})\s*;/i', $block[1], $matches, PREG_SET_ORDER);
    foreach ($matches as $match) $tokens['--' . $match[1]] = strtolower($match[2]);
    return $tokens;
}

function css_braced_block(string $source, string $needle): string
{
    $start = strpos($source, $needle);
    $open = $start === false ? false : strpos($source, '{', $start + strlen($needle));
    if ($open === false) throw new RuntimeException("Missing CSS block: $needle");
    $depth = 0;
    for ($cursor = $open; $cursor < strlen($source); $cursor++) {
        if ($source[$cursor] === '{') $depth++;
        elseif ($source[$cursor] === '}' && --$depth === 0) return substr($source, $open + 1, $cursor - $open - 1);
    }
    throw new RuntimeException("Unclosed CSS block: $needle");
}

function worker_main(array $arguments): never
{
    $target = $arguments[2] ?? '';
    $action = $arguments[3] ?? '';
    $encoded = $arguments[4] ?? '';
    $realTarget = realpath($target);
    $realSource = realpath(dirname(__DIR__) . '/wiki-piplet.php');
    $workerRoot = realpath((string) getenv('PIPLET_TEST_ROOT'));
    $targetStat = $realTarget === false ? false : lstat($realTarget);
    $allowedTarget = is_string($realTarget) && is_array($targetStat)
        && (((int) $targetStat['mode']) & 0170000) === 0100000
        && ($realTarget === $realSource ? $action === 'read' : is_string($workerRoot)
            && str_starts_with($realTarget, $workerRoot . DIRECTORY_SEPARATOR));
    if (!$allowedTarget) {
        fwrite(STDERR, "Worker target rejected.\n");
        exit(2);
    }
    define('PIPLET_LIBRARY_ONLY', true);
    require $realTarget;

    try {
        $input = $encoded === '' ? [] : json_decode(base64_decode($encoded, true), true, 16, JSON_THROW_ON_ERROR);
        if (in_array($action, ['save', 'delete', 'appearance'], true)) {
            $input = worker_api_input($action, $input);
        }
        $result = match ($action) {
            'read' => piplet_read(),
            'save' => piplet_save_note($input),
            'delete' => piplet_delete_note($input),
            'appearance' => piplet_save_appearance($input),
            'current-appearance' => piplet_current_appearance(piplet_read()),
            'prefix' => hash('sha256', substr(file_get_contents(piplet_path()), 0, piplet_code_offset())),
            'held-save' => worker_held_save($input),
            'large-save' => worker_large_save($input),
            'exact-file-save' => worker_exact_file_save(),
            'summary' => worker_summary(),
            'seed-notes' => worker_seed_notes($input),
            'large-output' => str_repeat('x', 1024 * 1024),
            'json-length' => [
                'projected' => piplet_json_encoded_length($input['value'] ?? null),
                'actual' => strlen(json_encode($input['value'] ?? null, PIPLET_JSON_FLAGS)),
            ],
            'temp-info' => worker_temp_info($input),
            'inject-appearance' => worker_inject_appearance($input),
            'duplicate-token' => worker_duplicate_token(),
            'numeric-id' => worker_numeric_id(),
            'cookie-path' => piplet_cookie_path((string) ($input['script'] ?? '/')),
            'authorization-password' => worker_authorization_password($input),
            'request-is-https' => worker_request_is_https($input),
            'short-write' => worker_short_write(),
            'import-data' => worker_import_data($input),
            default => throw new RuntimeException('Unknown worker action.'),
        };
        fwrite(STDOUT, json_encode(['ok' => true, 'value' => $result], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        exit(0);
    } catch (PipletConflict $error) {
        fwrite(STDOUT, json_encode(['ok' => false, 'conflict' => true, 'current' => $error->current], JSON_THROW_ON_ERROR));
        exit(3);
    } catch (Throwable $error) {
        fwrite(STDERR, "$action " . json_encode($input ?? []) . ': ' . $error::class . ': ' . $error->getMessage() . PHP_EOL);
        exit(2);
    }
}

function worker_api_input(string $action, array $input): array
{
    $document = piplet_read();
    $input['baseGeneration'] ??= $document['generation'];
    if ($action === 'appearance') {
        $appearance = piplet_current_appearance($document);
        $input['baseVersion'] ??= $appearance['version'];
        return $input;
    }
    $id = $input['id'] ?? null;
    $note = is_string($id) ? ($document['notes'][$id] ?? null) : null;
    $input['baseVersion'] ??= is_array($note) ? $note['version'] : null;
    if ($action === 'save') {
        $input['createToken'] ??= $id === null ? bin2hex(random_bytes(16)) : null;
    }
    return $input;
}

function worker_held_save(array $input): array
{
    $hold = max(0, min(3000000, (int) ($input['hold'] ?? 0)));
    $title = (string) ($input['title'] ?? 'Concurrent note');
    return piplet_mutate(function (array &$document) use ($hold, $title): array {
        if ($hold > 0) {
            usleep($hold);
        }
        $id = piplet_slug($title, $document['notes']);
        $now = piplet_now();
        $note = [
            'id' => $id,
            'title' => $title,
            'body' => 'Written by a concurrent worker.',
            'tags' => ['concurrency'],
            'revision' => $document['revision'] + 1,
            'version' => piplet_version(),
            'created' => $now,
            'updated' => $now,
        ];
        $document['notes'][$id] = $note;
        return $note;
    });
}

function worker_large_save(array $input): array
{
    $bytes = max(0, (int) ($input['bytes'] ?? 0));
    $id = isset($input['id']) && is_string($input['id']) ? $input['id'] : null;
    $saved = piplet_save_note(worker_api_input('save', [
        'id' => $id,
        'baseRevision' => (int) ($input['baseRevision'] ?? 0),
        'title' => (string) ($input['title'] ?? 'Large note'),
        'body' => str_repeat((string) ($input['character'] ?? 'x'), $bytes),
        'tags' => ['large'],
    ]));
    return [
        'id' => $saved['result']['id'],
        'noteRevision' => $saved['result']['revision'],
        'documentRevision' => $saved['document']['revision'],
        'peakBytes' => memory_get_peak_usage(true),
    ];
}

function worker_summary(): array
{
    $document = piplet_read();
    return [
        'revision' => $document['revision'],
        'notes' => count($document['notes']),
        'bytes' => filesize(piplet_path()),
    ];
}

function worker_exact_file_save(): array
{
    $saved = piplet_mutate(function (array &$document): array {
        $now = piplet_now();
        $document['notes']['welcome']['body'] = '';
        $document['notes']['welcome']['revision'] = $document['revision'] + 1;
        $document['notes']['welcome']['version'] = piplet_version();
        $document['notes']['welcome']['updated'] = $now;
        $projected = $document;
        $projected['revision']++;
        $padding = PIPLET_MAX_FILE_BYTES - piplet_code_offset() - strlen(PIPLET_DATA_HEADER) - 1
            - piplet_json_encoded_length($projected);
        if ($padding < 0) throw new RuntimeException('The exact-limit fixture has no room for padding.');
        $document['notes']['welcome']['body'] = str_repeat('x', $padding);
        return ['bodyBytes' => $padding];
    });
    return ['bytes' => $saved['bytes'], 'bodyBytes' => $saved['result']['bodyBytes']];
}

function worker_seed_notes(array $input): array
{
    $count = max(0, min(100, (int) ($input['count'] ?? 0)));
    return piplet_mutate(function (array &$document) use ($count): array {
        $now = piplet_now();
        for ($index = 0; $index < $count; $index++) {
            $id = "story-cap-$index";
            $document['notes'][$id] = [
                'id' => $id,
                'title' => "Story cap $index",
                'body' => "A bounded note $index.",
                'tags' => ['cap'],
                'revision' => $document['revision'] + 1,
                'version' => piplet_version(),
                'created' => $now,
                'updated' => $now,
            ];
        }
        return ['notes' => count($document['notes'])];
    });
}

function worker_temp_info(array $input): array
{
    $previous = umask((int) ($input['umask'] ?? 0));
    $temp = '';
    $handle = null;
    try {
        [$temp, $handle] = piplet_open_temp(piplet_path());
        $stat = fstat($handle);
        return [
            'mode' => ((int) $stat['mode']) & 0777,
            'directory' => dirname($temp),
            'basename' => basename($temp),
        ];
    } finally {
        if (is_resource($handle)) fclose($handle);
        if ($temp !== '') @unlink($temp);
        umask($previous);
    }
}

function worker_inject_appearance(array $input): array
{
    return piplet_mutate(function (array &$document) use ($input): array {
        $document['appearance'] = [
            'revision' => $document['revision'] + 1,
            'version' => piplet_version(),
            ...$input,
        ];
        return $document['appearance'];
    });
}

function worker_duplicate_token(): array
{
    return piplet_mutate(function (array &$document): array {
        $now = piplet_now();
        foreach (['duplicate-one', 'duplicate-two'] as $id) {
            $document['notes'][$id] = [
                'id' => $id, 'title' => $id, 'body' => '', 'tags' => [],
                'revision' => $document['revision'] + 1,
                'version' => piplet_version(),
                'created' => $now, 'updated' => $now,
                'createToken' => str_repeat('e', 32),
            ];
        }
        return [];
    });
}

function worker_numeric_id(): array
{
    return piplet_mutate(function (array &$document): array {
        $document['notes']['01'] = [
            'id' => '01', 'title' => 'Numeric identifier', 'body' => '', 'tags' => [],
            'revision' => $document['revision'] + 1,
            'version' => piplet_version(),
            'created' => piplet_now(), 'updated' => piplet_now(),
        ];
        return [];
    });
}

function worker_authorization_password(array $input): string
{
    unset($_SERVER['PHP_AUTH_PW'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    foreach (['PHP_AUTH_PW' => 'phpAuth', 'HTTP_AUTHORIZATION' => 'authorization',
        'REDIRECT_HTTP_AUTHORIZATION' => 'redirectAuthorization'] as $serverKey => $inputKey) {
        if (array_key_exists($inputKey, $input)) $_SERVER[$serverKey] = $input[$inputKey];
    }
    return piplet_provided_password();
}

function worker_request_is_https(array $input): bool
{
    unset($_SERVER['HTTPS']);
    if (array_key_exists('https', $input)) $_SERVER['HTTPS'] = $input['https'];
    putenv('PIPLET_PUBLIC_HTTPS=' . (($input['public'] ?? false) ? '1' : '0'));
    return piplet_request_is_https();
}

function worker_short_write(): bool
{
    $scheme = 'pipletshort';
    if (!stream_wrapper_register($scheme, PipletShortWriteStream::class)) {
        throw new RuntimeException('Could not register the short-write stream.');
    }
    $handle = null;
    try {
        $handle = fopen("$scheme://test", 'wb');
        if ($handle === false) throw new RuntimeException('Could not open the short-write stream.');
        $payload = str_repeat('short-write-', 10000);
        piplet_write_all($handle, $payload);
        return PipletShortWriteStream::$written === $payload;
    } finally {
        if (is_resource($handle)) fclose($handle);
        stream_wrapper_unregister($scheme);
    }
}

function worker_import_data(array $input): array
{
    $source = $input['source'] ?? null;
    if (!is_string($source)) throw new RuntimeException('Import source missing.');
    return piplet_replace_document(piplet_read_snapshot_data($source));
}

function worker_command(string $target, string $action, array $input = []): array|string|bool
{
    $decoded = finish_worker(start_worker($target, $action, $input));
    return $decoded['value'];
}

function worker_conflict(string $target, string $action, array $input): array
{
    return finish_worker(start_worker($target, $action, $input), 3);
}

function start_worker(string $target, string $action, array $input, ?array $environment = null): array
{
    global $liveWorkers, $temporaryRoot;
    if ($environment === null) {
        $environment = getenv();
        $environment = is_array($environment) ? $environment : [];
    }
    $environment['PIPLET_TEST_ROOT'] = $temporaryRoot;
    $command = [PHP_BINARY, '-d', 'memory_limit=128M', __FILE__, '--worker', $target, $action,
        base64_encode(json_encode($input, JSON_THROW_ON_ERROR))];
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start a concurrent worker.');
    }
    fclose($pipes[0]);
    $worker = [$process, $pipes, microtime(true) + 10, $action];
    $liveWorkers[get_resource_id($process)] = $worker;
    return $worker;
}

function finish_worker(array $worker, int $expectedStatus = 0): array
{
    global $liveWorkers;
    [$process, $pipes, $deadline, $action] = $worker;
    $resourceId = get_resource_id($process);
    $lastStatus = null;
    $stdout = '';
    $stderr = '';
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    do {
        foreach ([1, 2] as $index) {
            while (is_resource($pipes[$index]) && ($chunk = fread($pipes[$index], 8192)) !== false && $chunk !== '') {
                if ($index === 1) $stdout .= $chunk;
                else $stderr .= $chunk;
            }
        }
        $lastStatus = proc_get_status($process);
        if (!$lastStatus['running']) break;
        usleep(10000);
    } while (microtime(true) < $deadline);

    if ($lastStatus['running']) {
        $stopped = terminate_process($process, 1.0);
        foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        if ($stopped) proc_close($process);
        unset($liveWorkers[$resourceId]);
        throw new RuntimeException($stopped
            ? "Worker $action exceeded its 10 second deadline."
            : "Worker $action exceeded its deadline and could not be terminated.");
    }
    $stdout .= stream_get_contents($pipes[1]);
    $stderr .= stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closedStatus = proc_close($process);
    $status = $closedStatus === -1 ? $lastStatus['exitcode'] : $closedStatus;
    unset($liveWorkers[$resourceId]);
    if ($status !== $expectedStatus) {
        throw new RuntimeException("Concurrent worker failed ($status): $stderr\n$stdout");
    }
    if ($stdout === '') {
        return ['ok' => false, 'stderr' => $stderr];
    }
    try {
        return json_decode($stdout, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new RuntimeException("Worker $action returned invalid JSON: $stdout\n$stderr", 0, $error);
    }
}

function kill_worker(array $worker): void
{
    global $liveWorkers;
    [$process, $pipes, , $action] = $worker;
    $resourceId = get_resource_id($process);
    $status = proc_get_status($process);
    if (!$status['running'] || !@proc_terminate($process, 9)) {
        throw new RuntimeException("Worker $action was not running at its crash checkpoint.");
    }
    $deadline = microtime(true) + 2;
    do {
        usleep(10000);
        $status = proc_get_status($process);
    } while ($status['running'] && microtime(true) < $deadline);
    foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
    if ($status['running']) {
        throw new RuntimeException("Worker $action did not stop after SIGKILL.");
    }
    proc_close($process);
    unset($liveWorkers[$resourceId]);
}

function free_port(): int
{
    $errorCode = 0;
    $errorMessage = '';
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    if ($socket === false) {
        throw new RuntimeException("Could not allocate a test port: $errorMessage");
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    return (int) substr(strrchr($address, ':'), 1);
}

function http_request(string $url, string $method = 'GET', array $headers = [], string $body = ''): array
{
    global $defaultHttpHeaders;
    $headers = [...$defaultHttpHeaders, ...$headers];
    $options = ['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'content' => $body,
        'ignore_errors' => true,
        'timeout' => 5,
    ]];
    $responseBody = file_get_contents($url, false, stream_context_create($options));
    $responseHeaders = $http_response_header ?? [];
    $status = isset($responseHeaders[0]) && preg_match('/\s(\d{3})\s/', $responseHeaders[0], $match) ? (int) $match[1] : 0;
    return [$status, $responseHeaders, $responseBody === false ? '' : $responseBody];
}

function page_state(string $html): array
{
    check(preg_match('/<script type="application\/octet-stream" id="piplet-state"[^>]*>([A-Za-z0-9+\/=\s]+)<\/script>/s', $html, $match) === 1,
        'The base64 state block is missing.');
    $json = base64_decode(trim($match[1]), true);
    check(is_string($json) && !str_contains(trim($match[1]), '<'), 'The state block was not valid non-HTML base64.');
    return json_decode($json, true, 32, JSON_THROW_ON_ERROR);
}

function wait_for_server($process, int $port, string $log): void
{
    $deadline = microtime(true) + 2;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $status = proc_get_status($process);
        $output = @file_get_contents($log);
        if (!$status['running']) {
            throw new RuntimeException('The PHP test server exited before binding its port. ' . trim((string) $output));
        }
        if (is_string($output) && str_contains($output, 'Development Server') && str_contains($output, 'started')) {
            $socket = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, .1);
            if (is_resource($socket)) {
                fclose($socket);
                return;
            }
        }
        usleep(20000);
        if (microtime(true) >= $deadline) break;
    }
    throw new RuntimeException('The PHP test server did not start.');
}

function test_environment(array $set = []): array
{
    $environment = getenv();
    $environment = is_array($environment) ? $environment : [];
    unset($environment['PIPLET_PASSWORD'], $environment['PIPLET_ALLOW_PASSWORDLESS'], $environment['PIPLET_PUBLIC_HTTPS']);
    return [...$environment, ...$set];
}

function start_test_server(string $root, array $environment, string $failure, ?int $selectedPort = null): array
{
    $port = $selectedPort ?? free_port();
    $log = $root . '/.piplet-server-' . bin2hex(random_bytes(8)) . '.log';
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $root],
        [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', $log, 'a']],
        $pipes,
        $root,
        $environment
    );
    check(is_resource($process), $failure);
    fclose($pipes[0]);
    try {
        wait_for_server($process, $port, $log);
    } catch (Throwable $error) {
        if (terminate_process($process, 1.0)) proc_close($process);
        throw $error;
    }
    return [$process, $port];
}

function stop_test_server($process, string $name): void
{
    if (!terminate_process($process, 1.0)) throw new RuntimeException("The $name test server could not be terminated.");
    proc_close($process);
}

function chrome_binary(): ?string
{
    $configured = getenv('PIPLET_CHROME');
    if (is_string($configured) && is_executable($configured)) return $configured;
    $candidates = [
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        '/opt/google/chrome/chrome',
        '/usr/bin/google-chrome',
        '/usr/bin/google-chrome-stable',
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser',
        '/snap/bin/chromium',
    ];
    $path = getenv('PATH');
    if (is_string($path)) {
        foreach (array_filter(explode(PATH_SEPARATOR, $path), 'strlen') as $directory) {
            foreach (['google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser'] as $name) {
                $candidates[] = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
            }
        }
    }
    foreach (array_unique($candidates) as $candidate) {
        if (is_executable($candidate)) return $candidate;
    }
    return null;
}

function terminate_process($process, float $seconds = 1.0): bool
{
    if (!is_resource($process)) return true;
    $status = proc_get_status($process);
    if (!$status['running']) return true;
    @proc_terminate($process);
    $deadline = microtime(true) + $seconds;
    do {
        usleep(10000);
        $status = proc_get_status($process);
        if (!$status['running']) return true;
    } while (microtime(true) < $deadline);
    @proc_terminate($process, 9);
    $deadline = microtime(true) + $seconds;
    do {
        usleep(10000);
        $status = proc_get_status($process);
        if (!$status['running']) return true;
    } while (microtime(true) < $deadline);
    return false;
}

function run_bounded_command(array $command, float $seconds = 5.0, ?array $environment = null): array
{
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
        null, $environment);
    if (!is_resource($process)) throw new RuntimeException('Could not start a subprocess.');
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $drain = static function () use (&$pipes, &$stdout, &$stderr): void {
        foreach ([1, 2] as $index) {
            while (($chunk = fread($pipes[$index], 65536)) !== false && $chunk !== '') {
                if ($index === 1) $stdout .= $chunk;
                else $stderr .= $chunk;
            }
        }
    };
    $deadline = microtime(true) + $seconds;
    $lastStatus = null;
    do {
        $drain();
        $lastStatus = proc_get_status($process);
        if (!$lastStatus['running']) break;
        usleep(10000);
    } while (microtime(true) < $deadline);

    if ($lastStatus === null || $lastStatus['running']) {
        $stopped = terminate_process($process, 1.0);
        $drain();
        fclose($pipes[1]);
        fclose($pipes[2]);
        if ($stopped) proc_close($process);
        throw new RuntimeException($stopped ? 'A subprocess exceeded its deadline.' : 'A subprocess timed out and could not be terminated.');
    }

    $drain();
    $stdout .= stream_get_contents($pipes[1]);
    $stderr .= stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closedStatus = proc_close($process);
    $status = $closedStatus === -1 ? $lastStatus['exitcode'] : $closedStatus;
    return ['status' => $status, 'stdout' => $stdout, 'stderr' => $stderr];
}

function run_browser_scenario(
    string $chrome,
    string $url,
    string $profile,
    string $signalFile,
    string $signalNamespace,
    ?string $windowSize = null
): string
{
    if (!mkdir($profile, 0700)) {
        throw new RuntimeException('Could not create the browser profile.');
    }
    $command = [
        $chrome, '--headless=new', '--disable-background-networking', '--disable-component-update',
        '--disable-extensions', '--disable-sync', '--no-proxy-server', '--no-first-run',
        '--no-default-browser-check', '--disable-gpu', '--virtual-time-budget=20000',
    ];
    if ($windowSize !== null) {
        $command[] = '--force-device-scale-factor=1';
        $command[] = "--window-size=$windowSize";
    }
    array_push($command, "--user-data-dir=$profile", '--dump-dom', $url);
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the browser smoke test.');
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $deadline = microtime(true) + 45;
    $match = null;
    $signalPattern = '/^' . preg_quote($signalNamespace, '/') . ':result:([^\r\n]+)$/m';
    try {
        do {
            foreach ([1, 2] as $index) {
                while (($chunk = fread($pipes[$index], 65536)) !== false && $chunk !== '') {
                    if ($index === 1) $stdout .= $chunk;
                    else $stderr .= $chunk;
                }
            }
            if (preg_match('/<output id="piplet-browser-result">([^<]*)<\/output>/', $stdout, $found) === 1) {
                $match = html_entity_decode($found[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                break;
            }
            $signal = @file_get_contents($signalFile);
            if (is_string($signal) && preg_match_all($signalPattern, $signal, $signals) > 0) {
                $match = trim($signals[1][array_key_last($signals[1])]);
                break;
            }
            $status = proc_get_status($process);
            if (!$status['running']) break;
            usleep(20000);
        } while (microtime(true) < $deadline);
    } finally {
        $stopped = terminate_process($process, 1.0);
        foreach ([1, 2] as $index) {
            if ($index === 1) $stdout .= stream_get_contents($pipes[$index]);
            else $stderr .= stream_get_contents($pipes[$index]);
            fclose($pipes[$index]);
        }
        if ($stopped) proc_close($process);
        remove_tree($profile);
        if (!$stopped) throw new RuntimeException('The browser process could not be terminated.');
    }
    if ($match === null && preg_match('/<output id="piplet-browser-result">([^<]*)<\/output>/', $stdout, $found) === 1) {
        $match = html_entity_decode($found[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    if ($match === null) {
        $signal = @file_get_contents($signalFile);
        if (is_string($signal) && preg_match_all($signalPattern, $signal, $signals) > 0) {
            $match = trim($signals[1][array_key_last($signals[1])]);
        }
    }
    if ($match === null) {
        throw new RuntimeException('Browser smoke test produced no result: ' . substr(trim($stderr), -500));
    }
    return $match;
}

function header_value(array $headers, string $name): ?string
{
    foreach ($headers as $header) {
        if (str_starts_with(strtolower($header), strtolower($name) . ':')) {
            return trim(substr($header, strlen($name) + 1));
        }
    }
    return null;
}

function contrast_ratio(string $first, string $second): float
{
    $luminance = static function (string $color): float {
        $hex = ltrim($color, '#');
        $channels = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
        $linear = array_map(static function (int $channel): float {
            $value = $channel / 255;
            return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, $channels);
        return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
    };
    $a = $luminance($first);
    $b = $luminance($second);
    return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
}

function remove_tree(string $directory): void
{
    $stat = @lstat($directory);
    if ($stat === false || (((int) $stat['mode']) & 0170000) !== 0040000) return;
    @chmod($directory, 0700);
    foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $item) {
        if ($item->isDir() && !$item->isLink()) remove_tree($item->getPathname());
        else @unlink($item->getPathname());
    }
    @rmdir($directory);
}

function stop_live_workers(): bool
{
    global $liveWorkers;
    $stoppedAll = true;
    foreach ($liveWorkers as [$process, $pipes]) {
        $stopped = terminate_process($process, 1.0);
        foreach ($pipes as $pipe) if (is_resource($pipe)) @fclose($pipe);
        if ($stopped) @proc_close($process);
        else $stoppedAll = false;
    }
    $liveWorkers = [];
    return $stoppedAll;
}

$exitStatus = 0;
try {
    check(is_file($source), 'wiki-piplet.php is missing.');
    check(mkdir($temporaryRoot, 0700), 'Could not create the test directory.');
    $temporaryRootOwned = true;
    $temporaryRootIdentity = lstat($temporaryRoot);
    check(is_array($temporaryRootIdentity), 'Could not identify the test directory.');
    $liveDocument = worker_command($source, 'read');
    check(($liveDocument['format'] ?? null) === 2
        && preg_match('/^[a-f0-9]{32}$/D', $liveDocument['generation'] ?? '') === 1,
        'The live piplet data cannot be read as the current format.');
    check(make_fixture($source, $copy), 'Could not make an isolated test copy.');

    $initialLint = run_bounded_command([PHP_BINARY, '-l', $copy]);
    check($initialLint['status'] === 0, 'The initial piplet does not lint.');
    $timeoutObserved = false;
    try {
        run_bounded_command([PHP_BINARY, '-r', 'usleep(500000);'], .05);
    } catch (RuntimeException $error) {
        $timeoutObserved = str_contains($error->getMessage(), 'deadline');
    }
    check($timeoutObserved, 'The bounded subprocess runner did not enforce its deadline.');
    $foreignWorker = run_bounded_command([
        PHP_BINARY, __FILE__, '--worker', '/etc/passwd', 'read', base64_encode('[]'),
    ]);
    check($foreignWorker['status'] === 2 && trim($foreignWorker['stderr']) === 'Worker target rejected.'
        && $foreignWorker['stdout'] === '',
        'The internal worker accepted an arbitrary executable target.');

    $occupiedPortError = 0;
    $occupiedPortMessage = '';
    $occupiedPort = stream_socket_server('tcp://127.0.0.1:0', $occupiedPortError, $occupiedPortMessage);
    check(is_resource($occupiedPort), 'Could not reserve a port for the server-ownership test.');
    $occupiedAddress = stream_socket_get_name($occupiedPort, false);
    $occupiedNumber = (int) substr(strrchr((string) $occupiedAddress, ':'), 1);
    $occupiedRejected = false;
    try {
        start_test_server($temporaryRoot, test_environment(), 'Could not start the collision test server.', $occupiedNumber);
    } catch (RuntimeException $error) {
        $occupiedRejected = str_contains($error->getMessage(), 'exited before binding');
    } finally {
        fclose($occupiedPort);
    }
    check($occupiedRejected, 'Server readiness accepted a different process that already owned the selected port.');

    $prefixBefore = worker_command($copy, 'prefix');
    $initial = worker_command($copy, 'read');
    check($initial['format'] === 2 && isset($initial['generation']), 'Unexpected data format.');
    check($initial['revision'] === 1, 'Unexpected initial document revision.');
    check(isset($initial['notes']['welcome']), 'The welcome note is missing.');
    $legacyHash = hash_file('sha256', $copy);
    $legacyAgain = worker_command($copy, 'read');
    check($legacyAgain['generation'] === $initial['generation']
        && $legacyAgain['notes']['welcome']['version'] === $initial['notes']['welcome']['version']
        && hash_file('sha256', $copy) === $legacyHash,
        'Format-1 virtual security identities were not deterministic and read-only.');

    $rekeyCopy = fixture_copy($temporaryRoot, $source, 'rekey', 'Could not create the rekey fixture.');
    $rekeyBefore = worker_command($rekeyCopy, 'read');
    $rekeyPrefix = worker_command($rekeyCopy, 'prefix');
    $checkHash = hash_file('sha256', $rekeyCopy);
    $cliCheck = run_bounded_command([PHP_BINARY, $rekeyCopy, '--check']);
    check($cliCheck['status'] === 0 && str_contains($cliCheck['stdout'], 'format 2')
        && hash_file('sha256', $rekeyCopy) === $checkHash,
        'The CLI integrity check failed or rewrote its target.');
    $cliRekey = run_bounded_command([PHP_BINARY, $rekeyCopy, '--rekey']);
    $rekeyAfter = worker_command($rekeyCopy, 'read');
    check($cliRekey['status'] === 0
        && $rekeyAfter['revision'] === 1
        && $rekeyAfter['notes']['welcome']['revision'] === 1
        && $rekeyAfter['appearance']['revision'] === 0
        && $rekeyAfter['generation'] !== $rekeyBefore['generation']
        && $rekeyAfter['notes']['welcome']['version'] !== $rekeyBefore['notes']['welcome']['version']
        && $rekeyAfter['notes']['welcome']['title'] === $rekeyBefore['notes']['welcome']['title']
        && worker_command($rekeyCopy, 'prefix') === $rekeyPrefix
        && str_contains(file_get_contents($rekeyCopy), "\nPIPLET-DATA/2\n"),
        'CLI rekey did not rotate authority while preserving content and code.');

    $importSource = fixture_copy($temporaryRoot, $source, 'import-source', 'Could not create the import source.');
    $importRaw = file_get_contents($importSource);
    $importDeclaration = "declare(strict_types=1);\n";
    $markerComment = "/*\nPIPLET-DATA/2\n*/\n";
    check(is_string($importRaw) && substr_count($importRaw, $importDeclaration) === 1
        && file_put_contents($importSource,
            str_replace($importDeclaration, $importDeclaration . $markerComment, $importRaw)) !== false,
        'Could not add a harmless marker-looking source comment to the import fixture.');
    worker_command($importSource, 'save', [
        'id' => null, 'baseRevision' => 0, 'title' => 'Imported note', 'body' => 'trusted backup data', 'tags' => ['restore'],
    ]);
    $importDocument = worker_command($importSource, 'read');
    $importTarget = fixture_copy($temporaryRoot, $source, 'import-target', 'Could not create the import target.');
    $targetBefore = worker_command($importTarget, 'read');
    $targetPrefix = worker_command($importTarget, 'prefix');
    $importResult = run_bounded_command([
        PHP_BINARY, $importTarget, '--import-snapshot-data', $importSource, '--rekey',
    ]);
    $imported = worker_command($importTarget, 'read');
    check($importResult['status'] === 0
        && array_keys($imported['notes']) === array_keys($importDocument['notes'])
        && $imported['notes']['imported-note']['body'] === 'trusted backup data'
        && $imported['generation'] !== $importDocument['generation']
        && $imported['notes']['welcome']['version'] !== $importDocument['notes']['welcome']['version']
        && $imported['revision'] === 1
        && worker_command($importTarget, 'prefix') === $targetPrefix,
        'CLI data import did not preserve backup data, ignore a prefix marker string, and rotate all authority.');
    $staleAfterImport = worker_conflict($importTarget, 'save', [
        'id' => 'welcome',
        'baseGeneration' => $targetBefore['generation'],
        'baseRevision' => $targetBefore['notes']['welcome']['revision'],
        'baseVersion' => $targetBefore['notes']['welcome']['version'],
        'createToken' => null,
        'title' => 'Stale before import', 'body' => '', 'tags' => [],
    ]);
    check(($staleAfterImport['current']['id'] ?? null) === 'welcome',
        'A pre-import request crossed the import/rekey generation boundary.');
    $staleComposerAfterImport = worker_conflict($importTarget, 'save', [
        'id' => null,
        'baseGeneration' => $targetBefore['generation'],
        'baseRevision' => 0,
        'baseVersion' => null,
        'createToken' => str_repeat('7', 32),
        'title' => 'Stale unsent draft', 'body' => '', 'tags' => [],
    ]);
    check($staleComposerAfterImport['current'] === null,
        'A pre-import unsent draft crossed the import/rekey generation boundary.');
    $importLink = dirname($importTarget) . '/snapshot-link.php';
    check(@symlink($importSource, $importLink), 'Could not create the import symlink fixture.');
    $linkImport = run_bounded_command([
        PHP_BINARY, $importTarget, '--import-snapshot-data', $importLink, '--rekey',
    ]);
    check($linkImport['status'] === 1 && str_contains($linkImport['stderr'], 'non-symlink'),
        'CLI import followed a symbolic-link source.');
    check(unlink($importLink), 'Could not clean the import symlink fixture.');

    $importRaceTarget = fixture_copy($temporaryRoot, $source, 'import-race-target',
        'Could not create the import path-race target.');
    $importRaceSourceA = fixture_copy($temporaryRoot, $source, 'import-race-a',
        'Could not create import race source A.');
    $importRaceSourceB = fixture_copy($temporaryRoot, $source, 'import-race-b',
        'Could not create import race source B.');
    worker_command($importRaceSourceB, 'save', [
        'id' => null, 'baseRevision' => 0, 'title' => 'Swapped import source', 'body' => '', 'tags' => [],
    ]);
    $importRaceCode = file_get_contents($importRaceTarget);
    $importOpenNeedle = <<<'PHP'
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('The import source cannot be opened.');
    }
PHP;
    $importOpenBarrier = <<<'PHP'
    $testBarrier = getenv('PIPLET_TEST_IMPORT_BARRIER');
    if (is_string($testBarrier) && $testBarrier !== '') {
        file_put_contents($testBarrier . '.ready', '1', LOCK_EX);
        $testDeadline = microtime(true) + 5;
        while (!is_file($testBarrier . '.release')) {
            if (microtime(true) >= $testDeadline) throw new RuntimeException('Import test barrier timed out.');
            usleep(1000);
        }
    }
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('The import source cannot be opened.');
    }
PHP;
    check(is_string($importRaceCode) && substr_count($importRaceCode, $importOpenNeedle) === 1,
        'Could not locate the import lstat/open race point.');
    $importRaceCode = str_replace($importOpenNeedle, $importOpenBarrier, $importRaceCode);
    check(file_put_contents($importRaceTarget, $importRaceCode) === strlen($importRaceCode),
        'Could not instrument the import lstat/open race.');
    $importRaceHash = hash_file('sha256', $importRaceTarget);
    $importBarrier = dirname($importRaceTarget) . '/import-open';
    $importEnvironment = getenv();
    $importEnvironment = is_array($importEnvironment) ? $importEnvironment : [];
    $importEnvironment['PIPLET_TEST_IMPORT_BARRIER'] = $importBarrier;
    $importWorker = start_worker($importRaceTarget, 'import-data', ['source' => $importRaceSourceA], $importEnvironment);
    for ($attempt = 0; $attempt < 400 && !is_file($importBarrier . '.ready'); $attempt++) usleep(5000);
    check(is_file($importBarrier . '.ready'), 'The import worker did not reach its lstat/open checkpoint.');
    $importSourceAside = dirname($importRaceSourceA) . '/original.php';
    check(rename($importRaceSourceA, $importSourceAside) && rename($importRaceSourceB, $importRaceSourceA),
        'Could not swap the import source pathname.');
    touch($importBarrier . '.release');
    $importRaceFailure = finish_worker($importWorker, 2);
    check(str_contains($importRaceFailure['stderr'], 'import source changed')
        && hash_file('sha256', $importRaceTarget) === $importRaceHash,
        'Import accepted a different inode installed between lstat and fopen.');

    $corruptImportTarget = fixture_copy($temporaryRoot, $source, 'import-corrupt-target', 'Could not create the corrupt import target.');
    $corruptImportPrefix = worker_command($corruptImportTarget, 'prefix');
    check(replace_fixture_trailer($corruptImportTarget, "\nPIPLET-DATA/2\n", '{'),
        'Could not corrupt the import target trailer.');
    $corruptImport = run_bounded_command([
        PHP_BINARY, $corruptImportTarget, '--import-snapshot-data', $importSource, '--rekey',
    ]);
    $recoveredImport = worker_command($corruptImportTarget, 'read');
    check($corruptImport['status'] === 0
        && isset($recoveredImport['notes']['imported-note'])
        && $recoveredImport['revision'] === 1
        && worker_command($corruptImportTarget, 'prefix') === $corruptImportPrefix,
        'The trusted import path could not repair a corrupt live trailer.');

    $ceilingCopy = fixture_copy($temporaryRoot, $source, 'revision-ceiling', 'Could not create the revision-ceiling fixture.');
    $ceilingDocument = worker_command($ceilingCopy, 'read');
    $ceilingDocument['revision'] = 9007199254740991;
    foreach ($ceilingDocument['notes'] as &$ceilingNote) $ceilingNote['revision'] = 9007199254740991;
    unset($ceilingNote);
    $ceilingJson = json_encode($ceilingDocument, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    check(replace_fixture_trailer($ceilingCopy, "\nPIPLET-DATA/2\n", $ceilingJson),
        'Could not install the revision-ceiling fixture.');
    $ceilingRekey = run_bounded_command([PHP_BINARY, $ceilingCopy, '--rekey']);
    $ceilingAfter = worker_command($ceilingCopy, 'read');
    check($ceilingRekey['status'] === 0 && $ceilingAfter['revision'] === 1
        && $ceilingAfter['notes']['welcome']['revision'] === 1,
        'Rekey could not recover a document at the revision ceiling.');

    foreach ([
        null,
        ['plain' => 'value', 'slashes' => '</script>', 'unicode' => "snowman ☃\u{2028}", 'nul' => "a\0b"],
        ['list' => [1, 1.0, true, false, null, ['nested' => ['x', 'y']]]],
    ] as $jsonLengthValue) {
        $lengths = worker_command($copy, 'json-length', ['value' => $jsonLengthValue]);
        check($lengths['projected'] === $lengths['actual'], 'Projected JSON length diverged from the serializer.');
    }

    $denseStoredCopy = fixture_copy($temporaryRoot, $source, 'dense-stored', 'Could not create the dense stored-data fixture.');
    $denseBase = json_encode($initial, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $denseStoredJson = substr($denseBase, 0, -1) . ',"unknown":[' . str_repeat('[],', 50000) . '[]]}';
    check(replace_fixture_trailer($denseStoredCopy, "\nPIPLET-DATA/2\n", $denseStoredJson),
        'Could not write the dense stored-data fixture.');
    $denseStoredFailure = finish_worker(start_worker($denseStoredCopy, 'read', []), 2);
    check(str_contains($denseStoredFailure['stderr'], 'too structurally complex'),
        'Dense stored JSON reached decoding instead of the structural guard.');
    [$denseServer, $densePort] = start_test_server(
        dirname($denseStoredCopy),
        test_environment(['PIPLET_PASSWORD' => 'dense-test']),
        'Could not start the invalid-data recovery server.'
    );
    try {
        $denseAuthorization = ['Authorization: Basic ' . base64_encode('writer:dense-test')];
        [$densePageStatus, $densePageHeaders, $densePageBody] = http_request("http://127.0.0.1:$densePort/", 'GET', $denseAuthorization);
        [$denseDownloadStatus, , $denseDownloadBody] = http_request(
            "http://127.0.0.1:$densePort/?download=1", 'GET', $denseAuthorization
        );
        check($densePageStatus === 500 && !str_contains($densePageBody, $denseStoredJson)
            && str_contains((string) header_value($densePageHeaders, 'Content-Security-Policy'), "default-src 'none'")
            && strtolower((string) header_value($densePageHeaders, 'X-Content-Type-Options')) === 'nosniff'
            && $denseDownloadStatus === 200 && $denseDownloadBody === file_get_contents($denseStoredCopy),
            'Invalid stored JSON did not fail closed while preserving authenticated raw recovery.');
    } finally {
        stop_test_server($denseServer, 'invalid-data recovery');
    }

    $closureCopy = fixture_copy($temporaryRoot, $source, 'structure-closure', 'Could not create the structure-closure fixture.');
    $closureDocument = fixture_document_object($closureCopy);
    $closureDocument->padding = array_fill(0, 99948, 0);
    $closureJson = json_encode($closureDocument, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    check(replace_fixture_trailer($closureCopy, "\nPIPLET-DATA/1\n", $closureJson),
        'Could not install the near-structure-limit fixture.');
    worker_command($closureCopy, 'read');
    $closureHash = hash_file('sha256', $closureCopy);
    $closureFailure = finish_worker(start_worker($closureCopy, 'save', [
        'id' => null, 'baseRevision' => 0, 'title' => 'Must remain readable', 'body' => '', 'tags' => [],
    ]), 2);
    check(str_contains($closureFailure['stderr'], 'structurally complex')
        && hash_file('sha256', $closureCopy) === $closureHash
        && glob(dirname($closureCopy) . '/.piplet-tmp-*.php') === [],
        'A mutation wrote a snapshot outside the reader structural budget.');

    $noteLimitCopy = fixture_copy($temporaryRoot, $source, 'note-limit', 'Could not create the note-limit fixture.');
    $limitDocument = $initial;
    $limitDocument['notes'] = [];
    $limitTags = array_map(static fn (int $index): string => "t$index", range(0, 11));
    for ($index = 0; $index < 2000; $index++) {
        $id = "p$index";
        $limitDocument['notes'][$id] = [
            'id' => $id, 'title' => $id, 'body' => '', 'tags' => $limitTags,
            'revision' => 1, 'version' => substr(hash('sha256', $id), 0, 32),
            'created' => '2026-01-01T00:00:00Z', 'updated' => '2026-01-01T00:00:00Z',
        ];
    }
    $limitJson = json_encode($limitDocument, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    check(replace_fixture_trailer($noteLimitCopy, "\nPIPLET-DATA/2\n", $limitJson)
        && count(worker_command($noteLimitCopy, 'read')['notes']) === 2000,
        'The documented note/tag cardinality boundary was not accepted.');
    $limitDocument['notes']['overflow'] = [
        'id' => 'overflow', 'title' => 'overflow', 'body' => '', 'tags' => [],
        'revision' => 1, 'version' => str_repeat('f', 32),
        'created' => '2026-01-01T00:00:00Z', 'updated' => '2026-01-01T00:00:00Z',
    ];
    $overLimitJson = json_encode($limitDocument, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    check(replace_fixture_trailer($noteLimitCopy, "\nPIPLET-DATA/2\n", $overLimitJson),
        'Could not write the over-limit note fixture.');
    $noteLimitFailure = finish_worker(start_worker($noteLimitCopy, 'read', []), 2);
    check(str_contains($noteLimitFailure['stderr'], 'Invalid note collection'),
        'A stored document above the note limit passed validation.');
    unset($limitDocument, $limitJson, $overLimitJson, $denseStoredJson, $denseBase);
    $numericIdFailure = finish_worker(start_worker($copy, 'numeric-id', []), 2);
    check(str_contains($numericIdFailure['stderr'], 'Invalid note identifier'), 'A stored numeric-only note identifier passed validation.');
    check(worker_command($copy, 'cookie-path', ['script' => '/notes./wiki-piplet.php']) === '/notes./', 'A trailing dot was stripped from the CSRF cookie directory.');
    check(worker_command($copy, 'cookie-path', ['script' => '/wiki-piplet.php']) === '/', 'The root CSRF cookie path was malformed.');
    foreach ([null, '', '0', 'off', 'OFF'] as $httpsValue) {
        $httpsInput = $httpsValue === null ? [] : ['https' => $httpsValue];
        check(worker_command($copy, 'request-is-https', $httpsInput) === false,
            'A non-TLS HTTPS server value enabled Secure cookies.');
    }
    foreach (['on', '1'] as $httpsValue) {
        check(worker_command($copy, 'request-is-https', ['https' => $httpsValue]) === true,
            'A TLS HTTPS server value disabled Secure cookies.');
    }
    check(worker_command($copy, 'request-is-https', ['https' => 'off', 'public' => true]) === true,
        'The explicit public-HTTPS setting did not enable Secure cookies.');
    $basic = base64_encode('writer:fallback secret');
    check(worker_command($copy, 'authorization-password', ['authorization' => "basic  $basic"]) === 'fallback secret'
        && worker_command($copy, 'authorization-password', ['redirectAuthorization' => "Basic $basic"]) === 'fallback secret'
        && worker_command($copy, 'authorization-password', ['phpAuth' => 'primary', 'authorization' => "Basic $basic"]) === 'primary'
        && worker_command($copy, 'authorization-password', ['authorization' => 'Basic !!!']) === ''
        && worker_command($copy, 'authorization-password', ['authorization' => 'Basic ' . base64_encode('missing-colon')]) === '',
        'The raw Basic-authorization fallback did not parse or prioritize credentials safely.');
    $sourceText = file_get_contents($source);
    check(is_string($sourceText), 'Could not read the production CSS for contrast checks.');
    check(preg_match('/function piplet_mutate\(callable \$change\): array\s*\{\s*piplet_require_runtime\(\);/', $sourceText) === 1,
        'A direct mutation path bypasses the required 64-bit runtime guard.');
    check(
        preg_match('/font-size\s*:\s*var\(--title-size\)/', css_braced_block($sourceText, '.note-title')) === 1,
        'The note title stopped honoring the shared title-size token.'
    );
    $mobileCss = css_braced_block($sourceText, '@media (max-width: 760px)');
    preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $mobileCss, $mobileRules, PREG_SET_ORDER);
    $mobileNoteTitleValid = true;
    $mobileEditorTitleValid = false;
    foreach ($mobileRules as $rule) {
        if (str_contains($rule[1], '.note-title') && preg_match('/font-size\s*:\s*([^;]+)/', $rule[2], $size) === 1) {
            $mobileNoteTitleValid = trim($size[1]) === 'var(--title-size)';
        }
        if (str_contains($rule[1], '.field-title textarea') && preg_match('/font-size\s*:\s*var\(--title-size\)/', $rule[2]) === 1) {
            $mobileEditorTitleValid = true;
        }
    }
    check($mobileNoteTitleValid && $mobileEditorTitleValid, 'Mobile styles stopped honoring the shared title-size token.');
    foreach ([
        ':root',
        'html[data-theme="dark"]',
        'html[data-palette="ocean"]',
        'html[data-theme="dark"][data-palette="ocean"]',
        'html[data-palette="plum"]',
        'html[data-theme="dark"][data-palette="plum"]',
        'html[data-palette="mono"]',
        'html[data-theme="dark"][data-palette="mono"]',
    ] as $selector) {
        $tokens = css_theme_tokens($sourceText, $selector);
        check(
            isset($tokens['--faint'], $tokens['--canvas'], $tokens['--paper'], $tokens['--accent-wash'], $tokens['--line-strong'])
                && contrast_ratio($tokens['--faint'], $tokens['--canvas']) >= 4.5
                && contrast_ratio($tokens['--faint'], $tokens['--accent-wash']) >= 4.5
                && contrast_ratio($tokens['--line-strong'], $tokens['--canvas']) >= 3
                && contrast_ratio($tokens['--line-strong'], $tokens['--paper']) >= 3,
            "$selector text or control-boundary contrast is too low."
        );
    }
    $previousUmask = umask(0);
    $visibleTemp = tempnam(dirname($copy), '.piplet-visible-');
    umask($previousUmask);
    check(is_string($visibleTemp), 'The runtime could not create a temporary file for the first-visibility check.');
    $visiblePermissions = fileperms($visibleTemp);
    check($visiblePermissions !== false && ($visiblePermissions & 0777) === 0600, 'tempnam did not create mode 0600 at first visibility under umask 0000.');
    check(unlink($visibleTemp), 'Could not clean the first-visibility temporary file.');
    check(strlen(worker_command($copy, 'large-output')) === 1024 * 1024, 'The worker runner deadlocked or truncated a large response.');
    check(worker_command($copy, 'short-write') === true,
        'The persistence write loop did not complete positive short writes exactly.');
    foreach ([0, 0777] as $testUmask) {
        $tempInfo = worker_command($copy, 'temp-info', ['umask' => $testUmask]);
        check($tempInfo['mode'] === 0600, 'A temporary snapshot was not private from first use.');
        check(realpath($tempInfo['directory']) === realpath(dirname($copy)), 'A temporary snapshot was not created beside the piplet.');
        check(str_contains($tempInfo['basename'], '.piplet-tmp-') && str_ends_with($tempInfo['basename'], '.php'), 'A temporary snapshot lost its guarded PHP name.');
    }
    $noSyncHash = hash_file('sha256', $copy);
    $noSyncInput = base64_encode(json_encode([
        'id' => null, 'baseRevision' => 0, 'title' => 'No fsync', 'body' => '', 'tags' => [],
    ], JSON_THROW_ON_ERROR));
    $noSync = run_bounded_command([
        PHP_BINARY, '-d', 'disable_functions=fsync', __FILE__, '--worker', $copy, 'save', $noSyncInput,
    ], 5.0, [...test_environment(), 'PIPLET_TEST_ROOT' => $temporaryRoot]);
    check($noSync['status'] === 2 && str_contains($noSync['stderr'], 'file synchronization is disabled')
        && hash_file('sha256', $copy) === $noSyncHash,
        'Saving without fsync did not fail closed before mutation.');

    $defaultAppearanceValues = [
        'palette' => 'quiet',
        'font' => 'editorial',
        'scale' => 'comfortable',
        'measure' => 'balanced',
        'customCss' => '',
    ];
    $appearanceCopy = fixture_copy($temporaryRoot, $source, 'appearance', 'Could not create the appearance fixture.');
    $defaultAppearance = worker_command($appearanceCopy, 'current-appearance');
    check($defaultAppearance['revision'] === 0
        && preg_match('/^[a-f0-9]{32}$/D', $defaultAppearance['version']) === 1
        && array_diff_key($defaultAppearance, ['revision' => true, 'version' => true]) === $defaultAppearanceValues,
        'A document without appearance settings did not use the defaults.');
    $appearancePrefix = worker_command($appearanceCopy, 'prefix');
    $defaultHash = hash_file('sha256', $appearanceCopy);
    clearstatcache(true, $appearanceCopy);
    $defaultInode = fileinode($appearanceCopy);
    $defaultNoop = worker_command($appearanceCopy, 'appearance', ['baseRevision' => 0, 'appearance' => $defaultAppearanceValues]);
    clearstatcache(true, $appearanceCopy);
    check($defaultNoop['document']['revision'] === 1 && $defaultNoop['result'] === $defaultAppearance, 'Saving effective appearance defaults was not a no-op.');
    check(hash_file('sha256', $appearanceCopy) === $defaultHash && fileinode($appearanceCopy) === $defaultInode, 'A default appearance no-op rewrote the file.');
    $oceanAppearance = [
        'palette' => 'ocean',
        'font' => 'modern',
        'scale' => 'large',
        'measure' => 'wide',
        'customCss' => ".story { --test-label: 'ocean'; }",
    ];
    $savedAppearance = worker_command($appearanceCopy, 'appearance', ['baseRevision' => 0, 'appearance' => $oceanAppearance]);
    $expectedOcean = $savedAppearance['result'];
    check($expectedOcean['revision'] === 2
        && preg_match('/^[a-f0-9]{32}$/D', $expectedOcean['version']) === 1
        && array_diff_key($expectedOcean, ['revision' => true, 'version' => true]) === $oceanAppearance
        && $savedAppearance['document']['revision'] === 2, 'Appearance settings did not get a global commit revision.');
    check(worker_command($appearanceCopy, 'current-appearance') === $expectedOcean, 'Appearance settings did not persist across worker restarts.');
    check(worker_command($appearanceCopy, 'prefix') === $appearancePrefix, 'An appearance save changed the executable prefix.');
    $oceanHash = hash_file('sha256', $appearanceCopy);
    clearstatcache(true, $appearanceCopy);
    $oceanInode = fileinode($appearanceCopy);
    $oceanNoop = worker_command($appearanceCopy, 'appearance', ['baseRevision' => 2, 'appearance' => $oceanAppearance]);
    clearstatcache(true, $appearanceCopy);
    check($oceanNoop['document']['revision'] === 2 && $oceanNoop['result'] === $expectedOcean, 'An identical appearance retry was not a no-op.');
    check(hash_file('sha256', $appearanceCopy) === $oceanHash && fileinode($appearanceCopy) === $oceanInode, 'An identical appearance retry rewrote the file.');

    $unrelated = worker_command($appearanceCopy, 'save', [
        'id' => null, 'baseRevision' => 0, 'title' => 'Unrelated note', 'body' => '', 'tags' => [],
    ]);
    check($unrelated['result']['revision'] === 3, 'The unrelated note did not receive the next global revision.');
    $plumAppearance = [
        'palette' => 'plum',
        'font' => 'typewriter',
        'scale' => 'compact',
        'measure' => 'focused',
        'customCss' => '',
    ];
    $afterUnrelated = worker_command($appearanceCopy, 'appearance', ['baseRevision' => 2, 'appearance' => $plumAppearance]);
    check($afterUnrelated['result']['revision'] === 4
        && array_diff_key($afterUnrelated['result'], ['revision' => true, 'version' => true]) === $plumAppearance,
        'An unrelated note edit caused an appearance conflict.');
    check(isset($afterUnrelated['document']['notes']['unrelated-note']), 'An appearance save lost an unrelated note edit.');

    $appearanceAbaCopy = fixture_copy($temporaryRoot, $source, 'appearance-aba', 'Could not create the appearance revision fixture.');
    $firstAppearance = worker_command($appearanceAbaCopy, 'appearance', ['baseRevision' => 0, 'appearance' => $oceanAppearance]);
    $resetAppearance = worker_command($appearanceAbaCopy, 'appearance', ['baseRevision' => $firstAppearance['result']['revision'], 'appearance' => $defaultAppearanceValues]);
    $expectedReset = $resetAppearance['result'];
    check($expectedReset['revision'] === 3
        && array_diff_key($expectedReset, ['revision' => true, 'version' => true]) === $defaultAppearanceValues,
        'Restoring default appearance did not persist as a new revision.');
    $resetHash = hash_file('sha256', $appearanceAbaCopy);
    $staleAppearance = worker_conflict($appearanceAbaCopy, 'appearance', ['baseRevision' => 0, 'appearance' => $plumAppearance]);
    check($staleAppearance['current'] === $expectedReset, 'A stale appearance save crossed a customize/reset boundary.');
    check(hash_file('sha256', $appearanceAbaCopy) === $resetHash, 'A stale appearance save changed the file.');

    $invalidAppearanceCopy = fixture_copy($temporaryRoot, $source, 'appearance-invalid', 'Could not create the invalid appearance fixture.');
    $invalidAppearanceHash = hash_file('sha256', $invalidAppearanceCopy);
    $missingAppearance = $oceanAppearance;
    unset($missingAppearance['measure']);
    $missingCssAppearance = $oceanAppearance;
    unset($missingCssAppearance['customCss']);
    $extraAppearance = $oceanAppearance;
    $extraAppearance['css'] = 'body { display: none }';
    $injectedAppearance = $oceanAppearance;
    $injectedAppearance['palette'] = 'quiet"} body { display:none } /*';
    $nonTextCssAppearance = $oceanAppearance;
    $nonTextCssAppearance['customCss'] = ['body { display: none }'];
    $oversizeCssAppearance = $oceanAppearance;
    $oversizeCssAppearance['customCss'] = str_repeat('x', 32 * 1024 + 1);
    foreach ([
        ['baseRevision' => 0, 'appearance' => $missingAppearance],
        ['baseRevision' => 0, 'appearance' => $missingCssAppearance],
        ['baseRevision' => 0, 'appearance' => $extraAppearance],
        ['baseRevision' => 0, 'appearance' => $injectedAppearance],
        ['baseRevision' => 0, 'appearance' => $nonTextCssAppearance],
        ['baseRevision' => 0, 'appearance' => $oversizeCssAppearance],
        ['baseRevision' => 0, 'appearance' => 'ocean'],
    ] as $invalidAppearance) {
        $failure = finish_worker(start_worker($invalidAppearanceCopy, 'appearance', $invalidAppearance), 2);
        check(str_contains($failure['stderr'], 'PipletHttpError'), 'Invalid appearance input was not rejected as a request error.');
    }
    check(hash_file('sha256', $invalidAppearanceCopy) === $invalidAppearanceHash, 'Invalid appearance input changed the file.');
    $invalidDefaults = worker_command($invalidAppearanceCopy, 'current-appearance');
    check($invalidDefaults['revision'] === 0
        && array_diff_key($invalidDefaults, ['revision' => true, 'version' => true]) === $defaultAppearanceValues,
        'Invalid appearance input changed the effective defaults.');

    $exactCssCopy = fixture_copy($temporaryRoot, $source, 'appearance-exact-css',
        'Could not create the exact custom-CSS fixture.');
    $exactCssAppearance = $oceanAppearance;
    $exactCssAppearance['customCss'] = str_repeat('x', 32 * 1024);
    $exactCssSaved = worker_command($exactCssCopy, 'appearance', [
        'baseRevision' => 0, 'appearance' => $exactCssAppearance,
    ]);
    check(strlen($exactCssSaved['result']['customCss']) === 32 * 1024,
        'Custom CSS at the exact 32 KiB limit was rejected or changed.');

    $legacyAppearanceCopy = fixture_copy($temporaryRoot, $source, 'appearance-legacy', 'Could not create the legacy appearance fixture.');
    worker_command($legacyAppearanceCopy, 'inject-appearance', [
        'palette' => 'retired-choice',
        'font' => 'modern',
        'scale' => 'large',
        'futureSetting' => ['kept' => true],
        'tokens' => ['--story-width' => '60rem', '--accent' => 'url(https://invalid.example)', '--future-token' => '#ffffff'],
    ]);
    $legacyEffective = worker_command($legacyAppearanceCopy, 'current-appearance');
    check(array_diff_key($legacyEffective, ['version' => true]) === ['revision' => 2, 'palette' => 'quiet', 'font' => 'modern', 'scale' => 'large', 'measure' => 'balanced', 'customCss' => ":root {\n  --story-width: 60rem;\n}"], 'A sparse appearance record did not migrate its safe legacy CSS.');
    $legacySaved = worker_command($legacyAppearanceCopy, 'appearance', ['baseRevision' => 2, 'appearance' => $plumAppearance]);
    check($legacySaved['result']['revision'] === 3
        && array_diff_key($legacySaved['result'], ['revision' => true, 'version' => true]) === $plumAppearance,
        'A migrated appearance could not be saved.');
    $legacyRaw = worker_command($legacyAppearanceCopy, 'read');
    check(($legacyRaw['appearance']['futureSetting']['kept'] ?? false) === true, 'A future appearance field was erased by a known-setting save.');
    check(($legacyRaw['appearance']['tokens']['--future-token'] ?? null) === '#ffffff', 'A future design token was erased by a known-setting save.');

    $typedCopy = fixture_copy($temporaryRoot, $source, 'typed-extensions', 'Could not create the typed-extension fixture.');
    $typedDocument = worker_command($typedCopy, 'read');
    $typedDocument['futureEmpty'] = new stdClass();
    $typedDocument['futureNumeric'] = (object) ['0' => 'zero', '1' => 'one'];
    $typedDocument['futureFloat'] = 1.0;
    $typedDocument['futureFraction'] = 0.1;
    $typedDocument['appearance']['futureEmpty'] = new stdClass();
    $typedDocument['appearance'][5] = 'appearance-five';
    $typedDocument['notes']['welcome']['futureNumeric'] = (object) ['0' => 'note-zero'];
    $typedDocument['notes']['welcome'][7] = 'note-seven';
    $typedJson = json_encode($typedDocument, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    check(replace_fixture_trailer($typedCopy, "\nPIPLET-DATA/2\n", $typedJson),
        'Could not install typed extension data.');
    worker_command($typedCopy, 'save', [
        'id' => 'welcome', 'title' => $typedDocument['notes']['welcome']['title'],
        'body' => $typedDocument['notes']['welcome']['body'] . "\nTyped round trip",
        'tags' => $typedDocument['notes']['welcome']['tags'],
        'baseRevision' => $typedDocument['notes']['welcome']['revision'],
    ]);
    worker_command($typedCopy, 'appearance', [
        'baseRevision' => $typedDocument['appearance']['revision'],
        'appearance' => $oceanAppearance,
    ]);
    $typedStored = fixture_document_object($typedCopy);
    $typedAppearanceFields = get_object_vars($typedStored->appearance);
    $typedNoteFields = get_object_vars($typedStored->notes->welcome);
    check($typedStored->futureEmpty instanceof stdClass
        && get_object_vars($typedStored->futureEmpty) === []
        && $typedStored->futureNumeric instanceof stdClass
        && get_object_vars($typedStored->futureNumeric) === ['0' => 'zero', '1' => 'one']
        && is_float($typedStored->futureFloat) && $typedStored->futureFloat === 1.0
        && is_float($typedStored->futureFraction) && $typedStored->futureFraction === 0.1
        && $typedStored->appearance->futureEmpty instanceof stdClass
        && $typedStored->notes->welcome->futureNumeric instanceof stdClass
        && ($typedAppearanceFields[5] ?? null) === 'appearance-five'
        && ($typedNoteFields[7] ?? null) === 'note-seven',
        'A mutation changed the JSON type or value of an unknown extension.');

    $legacyNumericCopy = fixture_copy($temporaryRoot, $source, 'legacy-numeric-extension',
        'Could not create the legacy numeric-extension fixture.');
    $legacyNumericDocument = fixture_document_object($legacyNumericCopy);
    $legacyNumericDocument->appearance = (object) ['revision' => 1, 5 => 'legacy-five'];
    $legacyNumericJson = json_encode($legacyNumericDocument, JSON_THROW_ON_ERROR
        | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    check(replace_fixture_trailer($legacyNumericCopy, "\nPIPLET-DATA/1\n", $legacyNumericJson),
        'Could not install the legacy numeric-extension fixture.');
    worker_command($legacyNumericCopy, 'appearance', ['baseRevision' => 1, 'appearance' => $oceanAppearance]);
    $legacyNumericStored = fixture_document_object($legacyNumericCopy);
    check((get_object_vars($legacyNumericStored->appearance)[5] ?? null) === 'legacy-five',
        'Format-1 appearance migration renamed a numeric extension field.');

    foreach ([
        '9223372036854775808',
        '1e400',
        '9007199254740993.0',
        '0.10000000000000001',
        '1e-325',
    ] as $numberLiteral) {
        $numberCopy = fixture_copy($temporaryRoot, $source, 'number-' . md5($numberLiteral),
            'Could not create the unsafe-number fixture.');
        $numberDocument = worker_command($numberCopy, 'read');
        $numberDocument['futureNumber'] = 'PIPLET_NUMBER_SENTINEL';
        $numberJson = json_encode($numberDocument, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        $numberJson = str_replace('"PIPLET_NUMBER_SENTINEL"', $numberLiteral, $numberJson, $numberReplacements);
        check($numberReplacements === 1
            && replace_fixture_trailer($numberCopy, "\nPIPLET-DATA/2\n", $numberJson),
            'Could not install the unsafe-number fixture.');
        $numberHash = hash_file('sha256', $numberCopy);
        $numberFailure = finish_worker(start_worker($numberCopy, 'read', []), 2);
        check(str_contains($numberFailure['stderr'], 'cannot preserve exactly')
            && hash_file('sha256', $numberCopy) === $numberHash,
            'A JSON number that PHP cannot preserve was accepted or changed on failure.');
    }

    foreach ([
        '"futureDuplicate":{"x":1,"x":2}',
        '"futureDuplicate":{"x":1,"\\u0078":2}',
        '"futureDuplicate":{"nested":{"x":1,"\\u0078":2}}',
    ] as $duplicateMember) {
        $duplicateCopy = fixture_copy($temporaryRoot, $source, 'duplicate-' . md5($duplicateMember),
            'Could not create the duplicate-member fixture.');
        $duplicateDocument = worker_command($duplicateCopy, 'read');
        $duplicateJson = json_encode($duplicateDocument, JSON_THROW_ON_ERROR
            | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        $duplicateJson = substr($duplicateJson, 0, -1) . ',' . $duplicateMember . '}';
        check(replace_fixture_trailer($duplicateCopy, "\nPIPLET-DATA/2\n", $duplicateJson),
            'Could not install the duplicate-member fixture.');
        $duplicateHash = hash_file('sha256', $duplicateCopy);
        $duplicateFailure = finish_worker(start_worker($duplicateCopy, 'read', []), 2);
        check(str_contains($duplicateFailure['stderr'], 'duplicate object member names')
            && hash_file('sha256', $duplicateCopy) === $duplicateHash,
            'A duplicate or escape-equivalent JSON member was accepted or changed on failure.');
    }

    foreach ([
        '"bad' . "\xC0\xAF" . '"',
        '"\\ud800"',
    ] as $invalidTextLiteral) {
        $invalidTextCopy = fixture_copy($temporaryRoot, $source, 'invalid-text-' . md5($invalidTextLiteral),
            'Could not create the invalid-text fixture.');
        $invalidTextDocument = worker_command($invalidTextCopy, 'read');
        $invalidTextJson = json_encode($invalidTextDocument, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        $invalidTextJson = substr($invalidTextJson, 0, -1) . ',"futureText":' . $invalidTextLiteral . '}';
        check(replace_fixture_trailer($invalidTextCopy, "\nPIPLET-DATA/2\n", $invalidTextJson),
            'Could not install the invalid-text fixture.');
        $invalidTextHash = hash_file('sha256', $invalidTextCopy);
        $invalidTextFailure = finish_worker(start_worker($invalidTextCopy, 'read', []), 2);
        check(str_contains($invalidTextFailure['stderr'], 'not valid JSON')
            && hash_file('sha256', $invalidTextCopy) === $invalidTextHash,
            'Invalid UTF-8 or an unpaired JSON surrogate was accepted or changed on failure.');
    }

    $emptyNotesCopy = fixture_copy($temporaryRoot, $source, 'empty-note-map', 'Could not create the empty-note-map fixture.');
    worker_command($emptyNotesCopy, 'delete', ['id' => 'welcome', 'baseRevision' => 1]);
    $emptyNotesStored = fixture_document_object($emptyNotesCopy);
    check($emptyNotesStored->notes instanceof stdClass && get_object_vars($emptyNotesStored->notes) === [],
        'Deleting the final note serialized the note map as a JSON array.');

    $forkNoteA = fixture_copy($temporaryRoot, $source, 'fork-note-a', 'Could not create note fork A.');
    $forkNoteB = fixture_copy($temporaryRoot, $source, 'fork-note-b', 'Could not create note fork B.');
    $forkSavedA = worker_command($forkNoteA, 'save', [
        'id' => 'welcome', 'baseRevision' => 1, 'title' => 'Fork A', 'body' => 'branch A', 'tags' => [],
    ]);
    $forkSavedB = worker_command($forkNoteB, 'save', [
        'id' => 'welcome', 'baseRevision' => 1, 'title' => 'Fork B', 'body' => 'branch B', 'tags' => [],
    ]);
    $forkNoteHash = hash_file('sha256', $forkNoteB);
    $forkNoteConflict = worker_conflict($forkNoteB, 'save', [
        'id' => 'welcome',
        'baseGeneration' => $forkSavedA['document']['generation'],
        'baseRevision' => $forkSavedA['result']['revision'],
        'baseVersion' => $forkSavedA['result']['version'],
        'createToken' => null, 'title' => 'Crossed fork', 'body' => '', 'tags' => [],
    ]);
    check($forkSavedA['result']['revision'] === $forkSavedB['result']['revision']
        && $forkSavedA['result']['version'] !== $forkSavedB['result']['version']
        && ($forkNoteConflict['current']['title'] ?? null) === 'Fork B'
        && hash_file('sha256', $forkNoteB) === $forkNoteHash,
        'Equal numeric note revisions crossed a random-version fork.');

    $forkAppearanceA = fixture_copy($temporaryRoot, $source, 'fork-appearance-a', 'Could not create appearance fork A.');
    $forkAppearanceB = fixture_copy($temporaryRoot, $source, 'fork-appearance-b', 'Could not create appearance fork B.');
    $forkAppearanceSavedA = worker_command($forkAppearanceA, 'appearance', ['baseRevision' => 0, 'appearance' => $oceanAppearance]);
    $forkAppearanceSavedB = worker_command($forkAppearanceB, 'appearance', ['baseRevision' => 0, 'appearance' => $plumAppearance]);
    $forkAppearanceHash = hash_file('sha256', $forkAppearanceB);
    $forkAppearanceConflict = worker_conflict($forkAppearanceB, 'appearance', [
        'baseGeneration' => $forkAppearanceSavedA['document']['generation'],
        'baseRevision' => $forkAppearanceSavedA['result']['revision'],
        'baseVersion' => $forkAppearanceSavedA['result']['version'],
        'appearance' => $defaultAppearanceValues,
    ]);
    check($forkAppearanceSavedA['result']['revision'] === $forkAppearanceSavedB['result']['revision']
        && $forkAppearanceSavedA['result']['version'] !== $forkAppearanceSavedB['result']['version']
        && ($forkAppearanceConflict['current']['palette'] ?? null) === 'plum'
        && hash_file('sha256', $forkAppearanceB) === $forkAppearanceHash,
        'Equal numeric appearance revisions crossed a random-version fork.');

    $idempotentCopy = fixture_copy($temporaryRoot, $source, 'idempotent-create', 'Could not create the idempotent-create fixture.');
    $createPayload = [
        'id' => null, 'baseRevision' => 0, 'createToken' => str_repeat('a', 32),
        'title' => 'Only once', 'body' => 'A retry is the same mutation.', 'tags' => ['retry'],
    ];
    $createdOnce = worker_command($idempotentCopy, 'save', $createPayload);
    $onceHash = hash_file('sha256', $idempotentCopy);
    clearstatcache(true, $idempotentCopy);
    $onceInode = fileinode($idempotentCopy);
    $createdAgain = worker_command($idempotentCopy, 'save', $createPayload);
    clearstatcache(true, $idempotentCopy);
    check($createdAgain['result'] === $createdOnce['result'] && $createdAgain['document']['revision'] === 2, 'A repeated tokenized create did not return its original note.');
    check(hash_file('sha256', $idempotentCopy) === $onceHash && fileinode($idempotentCopy) === $onceInode, 'A repeated tokenized create rewrote the file.');
    $changedRetry = $createPayload;
    $changedRetry['body'] = 'Different content';
    $retryConflict = worker_conflict($idempotentCopy, 'save', $changedRetry);
    check($retryConflict['current']['id'] === $createdOnce['result']['id'], 'A changed retry did not conflict with its original create.');
    check(hash_file('sha256', $idempotentCopy) === $onceHash, 'A changed create retry modified the file.');
    $updatedTokenNote = worker_command($idempotentCopy, 'save', [
        'id' => $createdOnce['result']['id'], 'baseRevision' => 2, 'createToken' => null,
        'title' => 'Only once', 'body' => 'Updated', 'tags' => ['retry'],
    ]);
    check($updatedTokenNote['result']['createToken'] === str_repeat('a', 32), 'Updating a note replaced its stable creation token.');
    $badCreateToken = finish_worker(start_worker($idempotentCopy, 'save', [
        'id' => null, 'baseRevision' => 0, 'createToken' => 'not-a-token',
        'title' => 'Bad token', 'body' => '', 'tags' => [],
    ]), 2);
    check(str_contains($badCreateToken['stderr'], 'Invalid note creation token'), 'An invalid creation token was accepted.');

    $sameTokenCopy = fixture_copy($temporaryRoot, $source, 'same-token-race', 'Could not create the same-token concurrency fixture.');
    $sameTokenPayload = [
        'id' => null, 'baseRevision' => 0, 'createToken' => str_repeat('c', 32),
        'title' => 'Concurrent retry', 'body' => 'one logical create', 'tags' => [],
    ];
    $sameTokenWorkers = [start_worker($sameTokenCopy, 'save', $sameTokenPayload), start_worker($sameTokenCopy, 'save', $sameTokenPayload)];
    $sameTokenResults = array_map(fn(array $worker): array => finish_worker($worker), $sameTokenWorkers);
    check($sameTokenResults[0]['value']['result']['id'] === $sameTokenResults[1]['value']['result']['id'], 'Concurrent retries created different notes.');
    $sameTokenDocument = worker_command($sameTokenCopy, 'read');
    check($sameTokenDocument['revision'] === 2 && count($sameTokenDocument['notes']) === 2, 'Concurrent retries produced more than one commit or note.');
    $duplicateTokenHash = hash_file('sha256', $sameTokenCopy);
    $duplicateTokenFailure = finish_worker(start_worker($sameTokenCopy, 'duplicate-token', []), 2);
    check(str_contains($duplicateTokenFailure['stderr'], 'Invalid note creation token'), 'Duplicate stored creation tokens passed document validation.');
    check(hash_file('sha256', $sameTokenCopy) === $duplicateTokenHash, 'Duplicate-token rejection changed the canonical file.');

    $abaCopy = fixture_copy($temporaryRoot, $source, 'aba', 'Could not create the revision fixture.');
    $firstWelcomeEdit = worker_command($abaCopy, 'save', [
        'id' => 'welcome', 'baseRevision' => 1, 'title' => 'Hello, piplet', 'body' => 'first editor', 'tags' => [],
    ]);
    check($firstWelcomeEdit['result']['revision'] === 2, 'The first bundled-note edit did not advance its revision.');
    $staleWelcome = worker_conflict($abaCopy, 'save', [
        'id' => 'welcome', 'baseRevision' => 1, 'title' => 'Hello, piplet', 'body' => 'stale editor', 'tags' => [],
    ]);
    check($staleWelcome['current']['body'] === 'first editor', 'A stale bundled-note editor was not rejected.');
    worker_command($abaCopy, 'delete', ['id' => 'welcome', 'baseRevision' => 2]);
    $recreatedWelcome = worker_command($abaCopy, 'save', [
        'id' => null, 'baseRevision' => 0, 'title' => 'Welcome', 'body' => 'new generation', 'tags' => [],
    ]);
    check($recreatedWelcome['result']['id'] === 'welcome' && $recreatedWelcome['result']['revision'] === 4, 'Recreated slugs did not get a new generation revision.');
    $abaDelete = worker_conflict($abaCopy, 'delete', ['id' => 'welcome', 'baseRevision' => 2]);
    check($abaDelete['current']['body'] === 'new generation', 'A stale delete crossed a delete/recreate boundary.');

    $roundTripCopy = fixture_copy($temporaryRoot, $source, 'exact-editor-values', 'Could not create the exact-value fixture.');
    $exactTitle = "  title with spacing\nand a line  ";
    $exactTags = ['comma, tag', ' duplicate ', ' duplicate ', "line\nbreak", "nul\0tag"];
    $exactCreated = worker_command($roundTripCopy, 'save', [
        'id' => null, 'baseRevision' => 0, 'title' => $exactTitle, 'body' => 'first body', 'tags' => $exactTags,
    ]);
    $exactUpdated = worker_command($roundTripCopy, 'save', [
        'id' => $exactCreated['result']['id'],
        'baseRevision' => $exactCreated['result']['revision'],
        'title' => $exactTitle, 'body' => 'body-only edit', 'tags' => $exactTags,
    ]);
    check($exactUpdated['result']['title'] === $exactTitle
        && $exactUpdated['result']['tags'] === $exactTags
        && worker_command($roundTripCopy, 'read')['notes'][$exactCreated['result']['id']]['tags'] === $exactTags,
        'A body-only edit changed accepted title or tag values.');

    $hostileBody = "A null follows: \0\r\n__halt_compiler();\r\nPIPLET-DATA/1\r\n<?php echo 'not code'; ?>\r\n</script>\r\nSnowman: ☃";
    $created = worker_command($copy, 'save', [
        'id' => null,
        'baseRevision' => 0,
        'title' => 'Unicode & hostile text',
        'body' => $hostileBody,
        'tags' => ['testing', '日本語'],
    ]);
    $note = $created['result'];
    check($note['revision'] === 2, 'A note should carry its global commit revision.');
    check($created['document']['revision'] === 2, 'The document revision did not advance.');
    check($note['body'] === $hostileBody, 'Marker-like hostile text did not round-trip exactly.');
    check(worker_command($copy, 'read')['notes'][$note['id']]['body'] === $hostileBody, 'The persisted hostile body changed on reload.');

    $updated = worker_command($copy, 'save', [
        'id' => $note['id'],
        'baseRevision' => 2,
        'title' => $note['title'],
        'body' => 'Updated safely.',
        'tags' => $note['tags'],
    ]);
    check($updated['result']['revision'] === 3, 'The note revision did not advance.');

    $conflict = worker_conflict($copy, 'save', [
        'id' => $note['id'], 'baseRevision' => 2, 'title' => 'Stale', 'body' => 'Must not win', 'tags' => [],
    ]);
    check($conflict['conflict'] === true, 'A stale update was not rejected.');
    check($conflict['current']['body'] === 'Updated safely.', 'The conflict did not return the current note.');

    $deleted = worker_command($copy, 'delete', ['id' => $note['id'], 'baseRevision' => 3]);
    check($deleted['result']['id'] === $note['id'], 'Delete returned the wrong note.');
    check(!isset($deleted['document']['notes'][$note['id']]), 'The note was not deleted.');

    $numeric = worker_command($copy, 'save', ['id' => null, 'baseRevision' => 0, 'title' => '123', 'body' => '', 'tags' => []]);
    check($numeric['result']['id'] === 'note-123', 'A numeric title produced an invalid PHP array key.');
    check($numeric['result']['revision'] === 5, 'A recreated note version must use the global commit number.');
    worker_command($copy, 'delete', ['id' => 'note-123', 'baseRevision' => 5]);

    // Force waiters to queue on an inode that the first worker will replace.
    $workers = [];
    $workers[] = start_worker($copy, 'held-save', ['title' => 'Worker 00', 'hold' => 180000]);
    usleep(25000);
    for ($index = 1; $index < 24; $index++) {
        $workers[] = start_worker($copy, 'held-save', ['title' => sprintf('Worker %02d', $index), 'hold' => 0]);
    }
    foreach ($workers as $worker) {
        $result = finish_worker($worker);
        check($result['ok'] === true, 'A concurrent writer failed.');
    }
    $afterConcurrency = worker_command($copy, 'read');
    for ($index = 0; $index < 24; $index++) {
        check(isset($afterConcurrency['notes'][sprintf('worker-%02d', $index)]), "Concurrent note $index was lost.");
    }
    check($afterConcurrency['revision'] === 30, 'Concurrent document revisions were lost.');
    check($prefixBefore === worker_command($copy, 'prefix'), 'A save changed the executable prefix.');
    check(glob($temporaryRoot . '/.piplet-tmp-*.php') === [], 'A temporary snapshot was left behind.');

    // Deterministically exercise the stale-inode retry. Instrument only this
    // disposable copy: A opens inode 1 and pauses; B replaces it with inode 2;
    // A resumes, rejects its stale descriptor, retries, and preserves B's save.
    $raceCopy = fixture_copy($temporaryRoot, $source, 'race', 'Could not create the stale-inode fixture.');
    $raceRoot = dirname($raceCopy);
    $raceSource = file_get_contents($raceCopy);
    $needle = <<<'PHP'
        if ($handle === false) {
            throw new RuntimeException('Cannot open the piplet for saving.');
        }

        try {
PHP;
    $instrumented = <<<'PHP'
        if ($handle === false) {
            throw new RuntimeException('Cannot open the piplet for saving.');
        }

        $testBarrier = getenv('PIPLET_TEST_OPEN_BARRIER');
        if (is_string($testBarrier) && $testBarrier !== '' && !is_file($testBarrier . '.passed')) {
            file_put_contents($testBarrier . '.opened', '1');
            $testDeadline = microtime(true) + 5;
            while (!is_file($testBarrier . '.release')) {
                if (microtime(true) >= $testDeadline) {
                    throw new RuntimeException('Test barrier timed out.');
                }
                usleep(1000);
            }
            file_put_contents($testBarrier . '.passed', '1');
        }

        try {
PHP;
    check(is_string($raceSource) && substr_count($raceSource, $needle) === 1, 'Could not locate the lock instrumentation point.');
    check(file_put_contents($raceCopy, str_replace($needle, $instrumented, $raceSource)) !== false, 'Could not instrument the stale-inode copy.');
    $barrier = $raceRoot . '/barrier';
    $workerEnvironment = getenv();
    $workerEnvironment = is_array($workerEnvironment) ? $workerEnvironment : [];
    $workerEnvironment['PIPLET_TEST_OPEN_BARRIER'] = $barrier;
    clearstatcache(true, $raceCopy);
    $oldInode = fileinode($raceCopy);
    $staleWorker = start_worker($raceCopy, 'held-save', ['title' => 'Opened before rename', 'hold' => 0], $workerEnvironment);
    for ($attempt = 0; $attempt < 200 && !is_file($barrier . '.opened'); $attempt++) usleep(5000);
    check(is_file($barrier . '.opened'), 'The stale-inode worker did not reach its barrier.');
    worker_command($raceCopy, 'held-save', ['title' => 'Replacement writer', 'hold' => 0]);
    clearstatcache(true, $raceCopy);
    check(fileinode($raceCopy) !== $oldInode, 'The replacement writer did not swap the inode.');
    touch($barrier . '.release');
    $staleResult = finish_worker($staleWorker);
    check($staleResult['ok'] === true, 'The stale-inode worker failed to retry.');
    $raceDocument = worker_command($raceCopy, 'read');
    check(isset($raceDocument['notes']['replacement-writer']), 'The retry clobbered the replacement writer.');
    check(isset($raceDocument['notes']['opened-before-rename']), 'The retried mutation was lost.');
    check($raceDocument['revision'] === 3, 'The deterministic race lost a document revision.');

    // Pause after the complete temp snapshot is closed, then replace the live
    // pathname. The final identity check must protect that replacement.
    $finalRaceCopy = fixture_copy($temporaryRoot, $source, 'final-race',
        'Could not create the final-path-race fixture.');
    $finalRaceRoot = dirname($finalRaceCopy);
    $finalRaceSource = file_get_contents($finalRaceCopy);
    $finalRaceNeedle = <<<'PHP'
        $tempHandle = null;

        // Refuse to clobber an out-of-band replacement made while we prepared.
PHP;
    $finalRaceBarrier = <<<'PHP'
        $tempHandle = null;

        $testBarrier = getenv('PIPLET_TEST_FINAL_PATH_BARRIER');
        if (is_string($testBarrier) && $testBarrier !== '') {
            file_put_contents($testBarrier . '.ready', '1', LOCK_EX);
            $testDeadline = microtime(true) + 5;
            while (!is_file($testBarrier . '.release')) {
                if (microtime(true) >= $testDeadline) throw new RuntimeException('Final-path test barrier timed out.');
                usleep(1000);
            }
        }

        // Refuse to clobber an out-of-band replacement made while we prepared.
PHP;
    check(is_string($finalRaceSource) && substr_count($finalRaceSource, $finalRaceNeedle) === 1,
        'Could not locate the final pathname revalidation point.');
    $finalRaceSource = str_replace($finalRaceNeedle, $finalRaceBarrier, $finalRaceSource);
    check(file_put_contents($finalRaceCopy, $finalRaceSource) === strlen($finalRaceSource),
        'Could not instrument the final pathname race.');
    $replacement = $finalRaceRoot . '/replacement.php';
    check(copy($finalRaceCopy, $replacement), 'Could not create the final pathname replacement.');
    worker_command($replacement, 'save', [
        'id' => null, 'baseRevision' => 0, 'title' => 'Out of band replacement', 'body' => '', 'tags' => [],
    ]);
    $replacementHash = hash_file('sha256', $replacement);
    $finalBarrier = $finalRaceRoot . '/final-path';
    $finalEnvironment = getenv();
    $finalEnvironment = is_array($finalEnvironment) ? $finalEnvironment : [];
    $finalEnvironment['PIPLET_TEST_FINAL_PATH_BARRIER'] = $finalBarrier;
    $finalWorker = start_worker($finalRaceCopy, 'save', [
        'id' => null, 'baseRevision' => 0, 'title' => 'Must not clobber replacement', 'body' => '', 'tags' => [],
    ], $finalEnvironment);
    for ($attempt = 0; $attempt < 400 && !is_file($finalBarrier . '.ready'); $attempt++) usleep(5000);
    check(is_file($finalBarrier . '.ready'), 'The final-path worker did not reach its checkpoint.');
    check(rename($replacement, $finalRaceCopy), 'Could not install the out-of-band replacement.');
    touch($finalBarrier . '.release');
    $finalFailure = finish_worker($finalWorker, 2);
    $finalDocument = worker_command($finalRaceCopy, 'read');
    check(str_contains($finalFailure['stderr'], 'changed during the save')
        && hash_file('sha256', $finalRaceCopy) === $replacementHash
        && isset($finalDocument['notes']['out-of-band-replacement'])
        && !isset($finalDocument['notes']['must-not-clobber-replacement'])
        && glob($finalRaceRoot . '/.piplet-tmp-*.php') === [],
        'The last pre-rename identity check did not preserve an out-of-band replacement.');

    $faultCopy = fixture_copy($temporaryRoot, $source, 'fault', 'Could not create the fault-injection fixture.');
    $faultRoot = dirname($faultCopy);
    $faultSource = file_get_contents($faultCopy);
    $faultNeedle = <<<'PHP'
        $tempHandle = null;

        // Refuse to clobber an out-of-band replacement made while we prepared.
PHP;
    $faultReplacement = <<<'PHP'
        $tempHandle = null;

        if (getenv('PIPLET_TEST_FAIL_BEFORE_RENAME') === '1') {
            throw new RuntimeException('Injected failure before rename.');
        }

        // Refuse to clobber an out-of-band replacement made while we prepared.
PHP;
    check(is_string($faultSource) && substr_count($faultSource, $faultNeedle) === 1, 'Could not locate the persistence fault point.');
    check(file_put_contents($faultCopy, str_replace($faultNeedle, $faultReplacement, $faultSource)) !== false, 'Could not instrument the fault fixture.');
    $faultHash = hash_file('sha256', $faultCopy);
    $faultEnvironment = getenv();
    $faultEnvironment = is_array($faultEnvironment) ? $faultEnvironment : [];
    $faultEnvironment['PIPLET_TEST_FAIL_BEFORE_RENAME'] = '1';
    $faultFailure = finish_worker(start_worker($faultCopy, 'save', ['id' => null, 'baseRevision' => 0, 'title' => 'Faulted save', 'body' => '', 'tags' => []], $faultEnvironment), 2);
    check(str_contains($faultFailure['stderr'], 'Injected failure'), 'The post-temp persistence failure was not reached.');
    check(hash_file('sha256', $faultCopy) === $faultHash, 'A failed pre-rename commit changed the canonical file.');
    check(glob($faultRoot . '/.piplet-tmp-*.php') === [], 'A failed pre-rename commit left its temporary snapshot.');

    // Unlike the exception tests below, these workers are killed without
    // running finally blocks. Before rename the canonical file must remain the
    // old complete snapshot; after rename it must be the new complete one.
    $crashCopy = fixture_copy($temporaryRoot, $source, 'crash', 'Could not create the crash-consistency fixture.');
    $crashRoot = dirname($crashCopy);
    $crashSource = file_get_contents($crashCopy);
    $syncNeedle = <<<'PHP'
        if (!@fflush($tempHandle) || !@fsync($tempHandle)) {
            throw new RuntimeException('Cannot sync the new snapshot.');
        }
PHP;
    $syncBarrier = <<<'PHP'
        if (!@fflush($tempHandle) || !@fsync($tempHandle)) {
            throw new RuntimeException('Cannot sync the new snapshot.');
        }
        if (getenv('PIPLET_TEST_CRASH_STAGE') === 'synced') {
            $testSignal = (string) getenv('PIPLET_TEST_CRASH_SIGNAL');
            if ($testSignal === '' || file_put_contents($testSignal, basename($temp), LOCK_EX) === false) {
                throw new RuntimeException('Could not signal the synced crash checkpoint.');
            }
            while (true) usleep(10000);
        }
PHP;
    $renameNeedle = <<<'PHP'
        $committed = true;
        clearstatcache(true, $path);
PHP;
    $renameBarrier = <<<'PHP'
        $committed = true;
        if (getenv('PIPLET_TEST_CRASH_STAGE') === 'renamed') {
            $testSignal = (string) getenv('PIPLET_TEST_CRASH_SIGNAL');
            if ($testSignal === '' || file_put_contents($testSignal, 'renamed', LOCK_EX) === false) {
                throw new RuntimeException('Could not signal the renamed crash checkpoint.');
            }
            while (true) usleep(10000);
        }
        clearstatcache(true, $path);
PHP;
    check(is_string($crashSource) && substr_count($crashSource, $syncNeedle) === 1
        && substr_count($crashSource, $renameNeedle) === 1,
        'Could not locate the crash-consistency checkpoints.');
    $crashSource = str_replace([$syncNeedle, $renameNeedle], [$syncBarrier, $renameBarrier], $crashSource);
    check(file_put_contents($crashCopy, $crashSource) === strlen($crashSource),
        'Could not instrument the crash-consistency fixture.');
    $crashHash = hash_file('sha256', $crashCopy);
    $crashEnvironment = getenv();
    $crashEnvironment = is_array($crashEnvironment) ? $crashEnvironment : [];
    $crashEnvironment['PIPLET_TEST_CRASH_STAGE'] = 'synced';
    $crashSignal = $crashRoot . '/synced.signal';
    $crashEnvironment['PIPLET_TEST_CRASH_SIGNAL'] = $crashSignal;
    $crashWorker = start_worker($crashCopy, 'save', [
        'id' => null, 'baseRevision' => 0, 'title' => 'Killed before rename', 'body' => '', 'tags' => [],
    ], $crashEnvironment);
    for ($attempt = 0; $attempt < 400 && !is_file($crashSignal); $attempt++) usleep(5000);
    check(is_file($crashSignal), 'The pre-rename crash worker did not reach its checkpoint.');
    $orphanBasename = trim((string) file_get_contents($crashSignal));
    kill_worker($crashWorker);
    $orphan = $crashRoot . '/' . $orphanBasename;
    clearstatcache(true, $orphan);
    check(hash_file('sha256', $crashCopy) === $crashHash,
        'A hard kill before rename changed the canonical snapshot.');
    check(preg_match('/^\.piplet-tmp-[A-Za-z0-9._-]+\.php$/D', $orphanBasename) === 1
        && is_file($orphan) && (fileperms($orphan) & 0777) === 0600,
        'A hard kill before rename did not leave exactly the expected private snapshot.');
    $orphanExecution = run_bounded_command([PHP_BINARY, $orphan]);
    $orphanDocument = fixture_document_object($orphan);
    check($orphanExecution['status'] === 0 && trim($orphanExecution['stdout']) === 'Save in progress.'
        && isset($orphanDocument->notes->{'killed-before-rename'}) && @unlink($orphan),
        'The synced orphan executed as an app, was incomplete, or could not be removed after inspection.');

    @unlink($crashSignal);
    $crashEnvironment['PIPLET_TEST_CRASH_STAGE'] = 'renamed';
    $crashSignal = $crashRoot . '/renamed.signal';
    $crashEnvironment['PIPLET_TEST_CRASH_SIGNAL'] = $crashSignal;
    $crashWorker = start_worker($crashCopy, 'save', [
        'id' => null, 'baseRevision' => 0, 'title' => 'Killed after rename', 'body' => '', 'tags' => [],
    ], $crashEnvironment);
    for ($attempt = 0; $attempt < 400 && !is_file($crashSignal); $attempt++) usleep(5000);
    check(is_file($crashSignal), 'The post-rename crash worker did not reach its checkpoint.');
    kill_worker($crashWorker);
    $postCrashDocument = worker_command($crashCopy, 'read');
    $postCrashLint = run_bounded_command([PHP_BINARY, '-l', $crashCopy]);
    check(isset($postCrashDocument['notes']['killed-after-rename'])
        && $postCrashLint['status'] === 0 && glob($crashRoot . '/.piplet-tmp-*.php') === [],
        'A hard kill after rename did not leave one complete canonical snapshot.');

    $ioFaultCopy = fixture_copy($temporaryRoot, $source, 'io-faults', 'Could not create the I/O fault fixture.');
    $ioFaultRoot = dirname($ioFaultCopy);
    $ioFaultSource = file_get_contents($ioFaultCopy);
    $ioReplacements = [
        <<<'PHP'
        $handle = @fopen($temp, 'r+b');
        $stat = $handle === false ? false : fstat($handle);
        $pathStat = @lstat($temp);
PHP => <<<'PHP'
        $handle = @fopen($temp, 'r+b');
        $stat = getenv('PIPLET_TEST_IO_FAULT') === 'fstat' ? false : ($handle === false ? false : fstat($handle));
        $pathStat = @lstat($temp);
PHP,
        '$count = @fwrite($handle, $chunk);' => <<<'PHP'
$count = getenv('PIPLET_TEST_IO_FAULT') === 'write' ? 0 : @fwrite($handle, $chunk);
PHP,
        'if (!@fflush($tempHandle) || !@fsync($tempHandle)) {' => <<<'PHP'
if (getenv('PIPLET_TEST_IO_FAULT') === 'flush' || !@fflush($tempHandle)
            || getenv('PIPLET_TEST_IO_FAULT') === 'sync-content' || !@fsync($tempHandle)) {
PHP,
        'if (!@chmod($temp, $mode)) {' => <<<'PHP'
if (getenv('PIPLET_TEST_IO_FAULT') === 'chmod' || !@chmod($temp, $mode)) {
PHP,
        "if (!piplet_same_inode(\$tempStat, \$pathStat) || (\$pathStat['nlink'] ?? 0) !== 1\n            || !@fsync(\$tempHandle)) {" => <<<'PHP'
if (!piplet_same_inode($tempStat, $pathStat) || ($pathStat['nlink'] ?? 0) !== 1
            || getenv('PIPLET_TEST_IO_FAULT') === 'sync-mode' || !@fsync($tempHandle)) {
PHP,
        'if (!@fclose($tempHandle)) {' => <<<'PHP'
if (getenv('PIPLET_TEST_IO_FAULT') === 'close' || !@fclose($tempHandle)) {
PHP,
        'if (!@rename($temp, $path)) {' => <<<'PHP'
if (getenv('PIPLET_TEST_IO_FAULT') === 'rename' || !@rename($temp, $path)) {
PHP,
    ];
    check(is_string($ioFaultSource), 'Could not read the I/O fault fixture.');
    foreach ($ioReplacements as $ioNeedle => $ioReplacement) {
        check(substr_count($ioFaultSource, $ioNeedle) === 1, 'Could not locate an I/O persistence checkpoint.');
        $ioFaultSource = str_replace($ioNeedle, $ioReplacement, $ioFaultSource);
    }
    check(file_put_contents($ioFaultCopy, $ioFaultSource) === strlen($ioFaultSource),
        'Could not instrument the I/O persistence checkpoints.');
    $ioFaultHash = hash_file('sha256', $ioFaultCopy);
    foreach (array_keys([
        'fstat' => true, 'write' => true, 'flush' => true, 'sync-content' => true,
        'chmod' => true, 'sync-mode' => true, 'close' => true, 'rename' => true,
    ]) as $ioFault) {
        $ioEnvironment = getenv();
        $ioEnvironment = is_array($ioEnvironment) ? $ioEnvironment : [];
        $ioEnvironment['PIPLET_TEST_IO_FAULT'] = $ioFault;
        $ioFailure = finish_worker(start_worker($ioFaultCopy, 'save', [
            'id' => null, 'baseRevision' => 0, 'title' => "I/O fault $ioFault", 'body' => '', 'tags' => [],
        ], $ioEnvironment), 2);
        check($ioFailure['stderr'] !== ''
            && hash_file('sha256', $ioFaultCopy) === $ioFaultHash
            && glob($ioFaultRoot . '/.piplet-tmp-*.php') === [],
            "The $ioFault persistence failure changed the canonical file or leaked its temporary snapshot.");
    }

    $modeCopy = fixture_copy($temporaryRoot, $source, 'mode', 'Could not create the mode fixture.');
    check(chmod($modeCopy, 0440), 'Could not set the mode fixture permissions.');
    clearstatcache(true, $modeCopy);
    $readOnlyInode = fileinode($modeCopy);
    worker_command($modeCopy, 'save', ['id' => null, 'baseRevision' => 0, 'title' => 'Mode check', 'body' => '', 'tags' => []]);
    clearstatcache(true, $modeCopy);
    check(fileinode($modeCopy) !== $readOnlyInode && worker_command($modeCopy, 'summary')['notes'] === 2, 'A readable file in a writable directory could not be atomically replaced.');
    check((fileperms($modeCopy) & 0777) === 0440, 'Atomic replacement did not preserve read-only mode bits.');

    $hardCopy = fixture_copy($temporaryRoot, $source, 'hardlink', 'Could not create the hard-link fixture.');
    $hardRoot = dirname($hardCopy);
    check(link($hardCopy, $hardRoot . '/alias.php'), 'Could not create a hard-linked alias.');
    $hardHash = hash_file('sha256', $hardCopy);
    $hardFailure = finish_worker(start_worker($hardCopy, 'save', ['id' => null, 'baseRevision' => 0, 'title' => 'Must fail', 'body' => '', 'tags' => []]), 2);
    check(str_contains($hardFailure['stderr'], 'Hard-linked piplets'), 'A hard-linked deployment was not rejected clearly.');
    check(hash_file('sha256', $hardCopy) === $hardHash, 'Hard-link rejection changed the canonical file.');

    $largeCopy = fixture_copy($temporaryRoot, $source, 'large', 'Could not create the large-data fixture.');
    $largeRoot = dirname($largeCopy);
    $largeStart = microtime(true);
    $largePeak = 0;
    $largeNotes = [];
    for ($index = 1; $index <= 3; $index++) {
        $savedLarge = worker_command($largeCopy, 'large-save', ['title' => "Large $index", 'bytes' => 2450000]);
        $largeNotes[] = $savedLarge;
        $largePeak = max($largePeak, $savedLarge['peakBytes']);
    }
    $largeSummary = worker_command($largeCopy, 'summary');
    check($largeSummary['notes'] === 4 && $largeSummary['bytes'] > 7 * 1024 * 1024, 'The multi-megabyte snapshot did not persist across worker restarts.');
    $updatedLarge = worker_command($largeCopy, 'large-save', [
        'id' => $largeNotes[0]['id'], 'baseRevision' => $largeNotes[0]['noteRevision'], 'title' => 'Large 1', 'bytes' => 2450000, 'character' => 'y',
    ]);
    check($updatedLarge['noteRevision'] > $largeNotes[0]['noteRevision'], 'A multi-megabyte note could not be updated.');
    $beforeOversize = hash_file('sha256', $largeCopy);
    $oversize = finish_worker(start_worker($largeCopy, 'large-save', ['title' => 'Over the limit', 'bytes' => 1400000]), 2);
    check(
        str_contains($oversize['stderr'], 'PipletHttpError: This save would make the piplet larger than 8 MiB.'),
        'An over-limit snapshot did not reach the exact file-size guard.'
    );
    check(hash_file('sha256', $largeCopy) === $beforeOversize, 'An over-limit save changed the canonical file.');
    check(glob($largeRoot . '/.piplet-tmp-*.php') === [], 'An over-limit save left a temporary snapshot.');
    check($largePeak < 96 * 1024 * 1024, 'The 7+ MiB save cycle exceeded the 96 MiB worker memory envelope.');
    $largeElapsed = microtime(true) - $largeStart;

    $exactFileCopy = fixture_copy($temporaryRoot, $source, 'exact-file-limit',
        'Could not create the exact file-limit fixture.');
    $exactFile = worker_command($exactFileCopy, 'exact-file-save');
    check($exactFile['bytes'] === 8 * 1024 * 1024
        && filesize($exactFileCopy) === 8 * 1024 * 1024
        && $exactFile['bodyBytes'] > 7 * 1024 * 1024,
        'A snapshot at the exact 8 MiB file limit was rejected or misprojected.');

    $savedLint = run_bounded_command([PHP_BINARY, '-l', $copy]);
    check($savedLint['status'] === 0, 'The saved piplet no longer lints.');

    // Exercise the actual HTML and JSON API against another isolated copy.
    $httpCopy = fixture_copy($temporaryRoot, $source, 'http', 'Could not create the HTTP fixture.');
    $httpRoot = dirname($httpCopy);
    $browserCapability = bin2hex(random_bytes(32));
    $browserHarness = <<<'PHP'
    <script nonce="<?= piplet_h($nonce) ?>">
    (() => {
        const browserMode = new URLSearchParams(location.search).get('__browser');
        if (!['state', 'mobile', 'safe', 'long-path'].includes(browserMode)) return;
        const runtimeErrors = [];
        addEventListener('error', event => runtimeErrors.push(event.message || 'window error'));
        addEventListener('unhandledrejection', event => runtimeErrors.push(String(event.reason?.message || event.reason || 'unhandled rejection')));
        const result = document.createElement('output');
        result.id = 'piplet-browser-result';
        const wait = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));
        const until = async (predicate, message, milliseconds = 4000) => {
            const deadline = performance.now() + milliseconds;
            while (!predicate()) {
                if (performance.now() >= deadline) throw new Error(message);
                await wait(20);
            }
        };
        const assert = (condition, message) => { if (!condition) throw new Error(message); };
        const input = (control, value) => {
            control.value = value;
            control.dispatchEvent(new Event('input', {bubbles: true}));
        };
        const button = (root, label) => {
            const found = root ? [...root.querySelectorAll('button')].find(item => item.textContent.trim() === label) : null;
            if (!found) throw new Error(`button was missing: ${label}`);
            return found;
        };
        const click = (target, label) => {
            if (!target) throw new Error(`control was missing: ${label}`);
            target.click();
        };
        const browserFetch = window.fetch.bind(window);
        const nativeFetch = (resource, options = {}) => {
            const url = new URL(resource instanceof Request ? resource.url : String(resource), location.origin);
            const headers = new Headers(options.headers || (resource instanceof Request ? resource.headers : undefined));
            headers.set('Authorization', `Basic ${btoa('writer:browser-test')}`);
            headers.set('X-Piplet-Test-Capability', '__PIPLET_TEST_CAPABILITY__');
            return browserFetch(url, {...options, headers});
        };
        window.fetch = nativeFetch;
        const storedDrafts = () => Object.keys(sessionStorage).flatMap(key => {
            if (!key.includes(':draft:')) return [];
            try {
                const draft = JSON.parse(sessionStorage.getItem(key));
                return draft && typeof draft === 'object' ? [{key, draft}] : [];
            } catch (_) { return []; }
        });
        const progress = label => nativeFetch(`?__browser_progress=${encodeURIComponent(`${browserMode}:${label}`)}`, {method: 'POST'}).catch(() => null);
        if (browserMode === 'long-path') {
            const runLongPath = async () => {
                if (sessionStorage.getItem('piplet-long-path-phase') === 'reload') {
                    await until(() => document.getElementById('edit-title')?.value === 'Long-path recovery',
                        'a long-path recovery record was not rediscovered after reload');
                    assert(document.getElementById('edit-body').value === 'exact long-path draft'
                        && storedDrafts().some(item => item.draft.title === 'Long-path recovery'),
                        'the long-path recovery changed or disappeared');
                    sessionStorage.removeItem('piplet-long-path-phase');
                    return;
                }
                assert(location.pathname.length >= 208, 'the long-path browser scenario used a short route');
                document.getElementById('new-button').click();
                await until(() => document.querySelector('.editor'), 'the long-path composer did not open');
                input(document.getElementById('edit-title'), 'Long-path recovery');
                input(document.getElementById('edit-body'), 'exact long-path draft');
                await until(() => storedDrafts().some(item => item.draft.title === 'Long-path recovery'),
                    'the long-path recovery was not stored');
                const recovery = storedDrafts().find(item => item.draft.title === 'Long-path recovery');
                assert(recovery.key.length > 256 && /:draft:v2:[a-f0-9]{32}$/.test(recovery.key),
                    'the long-path test did not cross the former absolute key limit');
                sessionStorage.setItem('piplet-long-path-phase', 'reload');
                await progress('reload');
                location.reload();
                await new Promise(() => {});
            };
            runLongPath().then(async () => {
                result.textContent = 'PASS'; document.body.append(result);
                await progress('result:PASS');
            }).catch(async error => {
                const message = String(error.stack || error.message).replace(/\s+/g, ' ');
                result.textContent = `FAIL: ${message}`; document.body.append(result);
                await progress(`result:FAIL: ${message}`);
            });
            return;
        }
        if (browserMode === 'safe') {
            const runSafe = async () => {
                const encoded = document.getElementById('piplet-state').textContent.trim();
                const bytes = Uint8Array.from(atob(encoded), character => character.charCodeAt(0));
                const state = JSON.parse(new TextDecoder('utf-8', {fatal: true}).decode(bytes));
                assert(state.safeAppearance && state.appearance.customCss, 'safe mode lost the stored custom CSS');
                assert(document.getElementById('piplet-custom-style').textContent === '', 'safe mode applied stored custom CSS');
                assert(document.querySelector('.safe-appearance'), 'safe mode did not explain how to recover');
                document.getElementById('appearance-button').click();
                await until(() => document.getElementById('appearance-dialog').open, 'appearance did not open in safe mode');
                const editor = document.getElementById('appearance-css');
                assert(editor.value === state.appearance.customCss, 'safe mode did not keep custom CSS editable');
                input(editor, '* { display: none !important; }');
                assert(document.getElementById('piplet-custom-style').textContent === '', 'safe mode live-previewed custom CSS');
                input(editor, '');
                document.getElementById('appearance-form').requestSubmit();
                await until(() => !document.getElementById('appearance-dialog').open, 'safe mode could not clear custom CSS');
                assert(document.getElementById('piplet-custom-style').textContent === '', 'safe mode applied CSS while clearing it');
                const snapshot = await nativeFetch('?download=1').then(response => response.text());
                const marker = '\nPIPLET-DATA/2\n';
                const documentState = JSON.parse(snapshot.slice(snapshot.lastIndexOf(marker) + marker.length).trim());
                assert(documentState.appearance.customCss === '', 'safe mode did not persist the cleared CSS');
                if (runtimeErrors.length) throw new Error(`safe-mode page error: ${runtimeErrors.join('; ')}`);
            };
            runSafe().then(async () => {
                result.textContent = 'PASS'; document.body.append(result);
                await progress('result:PASS');
            }).catch(async error => {
                const message = String(error.stack || error.message).replace(/\s+/g, ' ');
                result.textContent = `FAIL: ${message}`; document.body.append(result);
                await progress(`result:FAIL: ${message}`);
            });
            return;
        }
        if (browserMode === 'mobile') {
            const runMobile = async () => {
                assert(matchMedia('(max-width: 760px)').matches && innerWidth <= 760,
                    'the mobile browser scenario did not enter the mobile layout');
                const menu = document.getElementById('menu-button');
                assert(getComputedStyle(menu).display !== 'none', 'the mobile menu control was not visible');
                menu.click();
                await until(() => document.body.dataset.drawer === 'open', 'the real mobile menu action did not open the note index');
                const library = document.getElementById('library');
                assert(library.getAttribute('role') === 'dialog'
                    && library.getAttribute('aria-modal') === 'true'
                    && !library.inert
                    && document.getElementById('main').inert
                    && document.querySelector('.bar-actions').inert,
                    'the open mobile note index did not isolate its modal surface');
                await until(() => document.activeElement === document.getElementById('search-input'), 'the mobile note index did not receive initial focus');
                const controls = [...library.querySelectorAll('button:not(:disabled), input, a[href]')];
                assert(controls.length > 1, 'the mobile note index had no keyboard surface');
                controls.at(-1).focus();
                const forwardTab = new KeyboardEvent('keydown', {key: 'Tab', bubbles: true, cancelable: true});
                controls.at(-1).dispatchEvent(forwardTab);
                assert(forwardTab.defaultPrevented && document.activeElement === controls[0]
                    && document.getElementById('drawer-shade').tabIndex === -1
                    && document.getElementById('drawer-shade').getAttribute('aria-hidden') === 'true',
                    'the real mobile focus trap escaped onto its outside backdrop');
                const reverseTab = new KeyboardEvent('keydown', {key: 'Tab', shiftKey: true, bubbles: true, cancelable: true});
                controls[0].dispatchEvent(reverseTab);
                assert(reverseTab.defaultPrevented && document.activeElement === controls.at(-1),
                    'the real mobile focus trap did not wrap backward');
                document.activeElement.dispatchEvent(new KeyboardEvent('keydown', {key: 'Escape', bubbles: true, cancelable: true}));
                await until(() => document.body.dataset.drawer !== 'open' && document.activeElement === menu,
                    'Escape did not close the mobile note index and restore focus');
                assert(menu.getAttribute('aria-expanded') === 'false'
                    && !library.hasAttribute('role')
                    && library.inert
                    && !document.getElementById('main').inert
                    && !document.querySelector('.bar-actions').inert,
                    'closing the mobile note index did not restore page access');
                menu.click();
                await until(() => document.body.dataset.drawer === 'open',
                    'the mobile note index did not reopen for its pointer-close check');
                document.getElementById('drawer-shade').click();
                await until(() => document.body.dataset.drawer !== 'open' && document.activeElement === menu,
                    'the mobile backdrop did not close the note index and restore focus');
                if (runtimeErrors.length) throw new Error(`mobile page error: ${runtimeErrors.join('; ')}`);
            };
            runMobile().then(async () => {
                result.textContent = 'PASS'; document.body.append(result);
                await progress('result:PASS');
            }).catch(async error => {
                const message = String(error.stack || error.message).replace(/\s+/g, ' ');
                result.textContent = `FAIL: ${message}`; document.body.append(result);
                await progress(`result:FAIL: ${message}`);
            });
            return;
        }
        const run = async () => {
            const csrf = document.querySelector('meta[name="piplet-csrf"]').content;
            const downloadDocument = async () => {
                const snapshot = await nativeFetch('?download=1').then(response => response.text());
                const marker = '\nPIPLET-DATA/2\n';
                return JSON.parse(snapshot.slice(snapshot.lastIndexOf(marker) + marker.length).trim());
            };
            const api = async (action, payload) => {
                const document = await downloadDocument();
                const prepared = {...payload, baseGeneration: document.generation};
                if (action === 'appearance') {
                    prepared.baseVersion = document.appearance.version;
                } else if (payload.id === null) {
                    prepared.baseVersion = null;
                    prepared.createToken ||= 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';
                } else if (document.notes[payload.id]) {
                    prepared.baseVersion = document.notes[payload.id].version;
                    if (action === 'save') prepared.createToken = null;
                }
                return nativeFetch(`?api=${action}`, {
                    method: 'POST', credentials: 'same-origin',
                    headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
                    body: JSON.stringify(prepared)
                });
            };
            const findLibraryItem = async title => {
                input(document.getElementById('search-input'), title);
                await until(() => [...document.querySelectorAll('.library-item')].some(node => node.querySelector('.library-title')?.textContent === title), `library search did not find ${title}`);
                return [...document.querySelectorAll('.library-item')].find(node => node.querySelector('.library-title')?.textContent === title);
            };
            await progress(`start:${sessionStorage.getItem('piplet-browser-phase') || 'main'}`);
            assert(document.getElementById('global-status').textContent === ''
                && document.getElementById('global-status').getAttribute('role') === 'status',
                'the header started with stale status copy or lost its live-region semantics');
            assert(!document.getElementById('file-size'), 'the idle header displayed file metadata');
            if (sessionStorage.getItem('piplet-browser-phase') === 'writable-setup') {
                await progress('writable-setup:entered');
                const welcome = await findLibraryItem('Hello, piplet');
                welcome.click();
                await until(() => document.getElementById('piplet-note-welcome'),
                    'welcome did not open for writable recovery setup');
                click(document.querySelector('#piplet-note-welcome button[title="Edit note"]'),
                    'edit welcome for writable reload recovery');
                await until(() => document.querySelector('#piplet-note-welcome.editor'),
                    'welcome editor did not open for writable recovery setup');
                input(document.getElementById('edit-title'), 'Writable reload recovery');
                input(document.getElementById('edit-body'), 'exact writable recovery body');
                input(document.getElementById('edit-tags'), '["reload","exact"]');
                window.dispatchEvent(new Event('pagehide'));
                const recovery = storedDrafts().find(item => item.draft.title === 'Writable reload recovery');
                assert(recovery && /:draft:v2:[a-f0-9]{32}$/.test(recovery.key),
                    'the existing-note recovery was not flushed before reload');
                const beforeRemote = await downloadDocument();
                const current = beforeRemote.notes.welcome;
                const remote = await api('save', {
                    id: 'welcome', baseRevision: current.revision, title: current.title,
                    body: 'saved remotely before writable recovery reload', tags: current.tags
                });
                assert(remote.ok, 'the competing writable-reload save failed');
                sessionStorage.setItem('piplet-browser-phase', 'writable-existing');
                await progress('writable-setup:reload');
                location.reload();
                await new Promise(() => {});
            }
            if (sessionStorage.getItem('piplet-browser-phase') === 'writable-existing') {
                await progress('writable-existing:entered');
                await until(() => document.querySelector('#piplet-note-welcome.editor .conflict-panel'),
                    'an existing-note recovery was not surfaced automatically after reload');
                assert(document.getElementById('edit-title').value === 'Writable reload recovery'
                    && document.getElementById('edit-body').value === 'exact writable recovery body'
                    && document.getElementById('edit-tags').value === '["reload","exact"]',
                    'writable startup changed or hid the existing-note recovery');
                const recovered = storedDrafts().find(item => item.draft.title === 'Writable reload recovery');
                assert(recovered && /:draft:v2:[a-f0-9]{32}$/.test(recovered.key),
                    'the writable reload did not retain its random recovery record');
                button(document.querySelector('.conflict-panel'), 'Use saved version').click();
                await until(() => !document.querySelector('.editor'),
                    'the writable existing-note recovery did not resolve explicitly');
                assert(sessionStorage.getItem(recovered.key) === null,
                    'resolving the writable existing-note recovery left its record behind');

                const lineageWelcome = await findLibraryItem('Hello, piplet');
                lineageWelcome.click();
                await until(() => document.getElementById('piplet-note-welcome'),
                    'welcome did not open for the restored-lineage reload test');
                click(document.querySelector('#piplet-note-welcome button[title="Edit note"]'),
                    'edit welcome for restored-lineage reload recovery');
                await until(() => document.querySelector('#piplet-note-welcome.editor'),
                    'the restored-lineage reload editor did not open');
                input(document.getElementById('edit-title'), 'Writable lineage recovery');
                input(document.getElementById('edit-body'), 'draft from the pre-rekey browser lineage');
                input(document.getElementById('edit-tags'), '["lineage","reload"]');
                window.dispatchEvent(new Event('pagehide'));
                const lineageRecovery = storedDrafts().find(item => item.draft.title === 'Writable lineage recovery');
                assert(lineageRecovery, 'the pre-rekey browser draft was not stored');
                const rekey = await nativeFetch('?__browser_rekey=1', {method: 'POST'});
                assert(rekey.status === 204, 'the browser fixture could not rekey its saved document');
                sessionStorage.setItem('piplet-browser-phase', 'writable-lineage');
                await progress('writable-existing:reload-lineage');
                location.reload();
                await new Promise(() => {});
            }
            if (sessionStorage.getItem('piplet-browser-phase') === 'writable-lineage') {
                await progress('writable-lineage:entered');
                await until(() => document.querySelector('.conflict-panel')?.textContent.includes('earlier restored copy'),
                    'a recovered old-generation draft was downgraded to an ordinary conflict after reload');
                const choices = [...document.querySelectorAll('.conflict-panel button')].map(item => item.textContent.trim());
                assert(document.getElementById('edit-title').value === 'Writable lineage recovery'
                    && document.getElementById('edit-body').value === 'draft from the pre-rekey browser lineage'
                    && choices.includes('Save as new in this piplet') && !choices.includes('Replace saved version'),
                    'the restored-lineage recovery could overwrite restored content after reload');
                const recovery = storedDrafts().find(item => item.draft.title === 'Writable lineage recovery');
                assert(recovery, 'the restored-lineage recovery record disappeared before resolution');
                button(document.querySelector('.conflict-panel'), 'Save as new in this piplet').click();
                await until(() => !document.querySelector('.conflict-panel'),
                    'the restored-lineage recovery did not rebase as a new note');
                document.querySelector('.editor form').requestSubmit();
                await until(() => !document.querySelector('.editor'),
                    'the restored-lineage recovery did not save as a new note');
                const saved = await downloadDocument();
                const adopted = Object.values(saved.notes).find(note => note.title === 'Writable lineage recovery');
                assert(saved.notes.welcome.body === 'saved remotely before writable recovery reload'
                    && adopted?.body === 'draft from the pre-rekey browser lineage'
                    && JSON.stringify(adopted?.tags) === '["lineage","reload"]'
                    && sessionStorage.getItem(recovery.key) === null,
                    'saving the restored-lineage recovery changed restored content or left its draft behind');
                sessionStorage.setItem('piplet-browser-phase', 'flat-read-only');
                await progress('writable-lineage:reload-flat-read-only');
                location.reload();
                await new Promise(() => {});
            }
            if (sessionStorage.getItem('piplet-browser-phase') === 'flat-read-only-final') {
                await progress('flat-read-only-final:entered');
                await until(() => document.querySelector('.plain-note[aria-label="Recovered draft text"]'),
                    'read-only mode did not expose the immutable recovery record');
                const recoveredValues = {
                    title: document.querySelector('[aria-label="Recovered draft title"]')?.value,
                    body: document.querySelector('.plain-note').value,
                    tags: document.querySelector('[aria-label="Recovered draft tags JSON"]')?.value
                };
                assert(recoveredValues.title === '  Exact recovery title  '
                    && recoveredValues.body === 'exact recovery body\nwith a second line'
                    && recoveredValues.tags === '["comma, tag"," spaced ","line\\nbreak"]',
                    `read-only recovery changed title, body, or tags: ${JSON.stringify(recoveredValues)}`);
                assert(![...document.querySelectorAll('button')].some(item => item.textContent.trim() === 'Save note'),
                    'read-only recovery exposed a server write action');
                const recovery = storedDrafts().find(item => item.draft.title === '  Exact recovery title  ');
                assert(recovery && /:draft:v2:[a-f0-9]{32}$/.test(recovery.key),
                    'recovery was not stored under a random v2 key');
                const removeItem = Storage.prototype.removeItem;
                const setItem = Storage.prototype.setItem;
                Storage.prototype.removeItem = function (key) {
                    if (key === recovery.key) throw new DOMException('blocked', 'SecurityError');
                    return removeItem.call(this, key);
                };
                Storage.prototype.setItem = function (key, value) {
                    if (key === recovery.key) throw new DOMException('blocked', 'SecurityError');
                    return setItem.call(this, key, value);
                };
                button(document.querySelector('.editor'), 'Dismiss recovery').click();
                assert(sessionStorage.getItem(recovery.key) !== null && document.querySelector('.plain-note'),
                    'a failed compare-and-delete discarded the only recovery copy');
                Storage.prototype.removeItem = removeItem;
                Storage.prototype.setItem = setItem;
                button(document.querySelector('.editor'), 'Dismiss recovery').click();
                await until(() => !document.querySelector('.plain-note[aria-label="Recovered draft text"]'),
                    'read-only recovery did not dismiss after storage recovered');
                assert(sessionStorage.getItem(recovery.key) === null,
                    'dismissal left its exact immutable record behind');
                assert(sessionStorage.getItem('piplet-browser-foreign') === 'keep me',
                    'draft metadata deleted an unrelated session-storage key');
                const malformed = Object.keys(sessionStorage).find(key => key.endsWith(':draft:malformed'));
                assert(malformed && sessionStorage.getItem(malformed) === '{',
                    'a malformed sibling record was treated as recovery authority');
                sessionStorage.removeItem(malformed);
                sessionStorage.removeItem('piplet-browser-foreign');
                sessionStorage.removeItem('piplet-browser-phase');
                await progress('flat-read-only-final:done');
                return;
            }
            if (sessionStorage.getItem('piplet-browser-phase') === 'flat-read-only') {
                await progress('flat-read-only:entered');
                assert(document.querySelectorAll('.library-item').length === 40,
                    'the library DOM exceeded its bounded window');
                for (const {key} of storedDrafts()) sessionStorage.removeItem(key);
                const welcomeItem = await findLibraryItem('Hello, piplet');
                welcomeItem.click();
                await until(() => document.getElementById('piplet-note-welcome'), 'welcome did not open for recovery setup');
                click(document.querySelector('#piplet-note-welcome button[title="Edit note"]'), 'edit welcome for recovery setup');
                await until(() => document.querySelector('#piplet-note-welcome.editor'), 'recovery editor did not open');
                input(document.getElementById('edit-title'), '  Exact recovery title  ');
                input(document.getElementById('edit-body'), 'exact recovery body\nwith a second line');
                input(document.getElementById('edit-tags'), '["comma, tag"," spaced ","line\\nbreak"]');
                window.dispatchEvent(new Event('pagehide'));
                const recovery = storedDrafts().find(item => item.draft.title === '  Exact recovery title  ');
                assert(recovery && recovery.draft.tagsText === '["comma, tag"," spaced ","line\\nbreak"]',
                    'the browser recovery record did not preserve exact editable values');
                recovery.draft.previousDraftKeys = ['piplet-browser-foreign'];
                sessionStorage.setItem(recovery.key, JSON.stringify(recovery.draft));
                const scope = recovery.key.slice(0, recovery.key.indexOf('draft:'));
                sessionStorage.setItem(`${scope}draft:malformed`, '{');
                sessionStorage.setItem('piplet-browser-foreign', 'keep me');
                const madeReadOnly = await nativeFetch('?__browser_readonly=1', {method: 'POST'});
                assert(madeReadOnly.ok, 'test fixture could not become read-only');
                sessionStorage.setItem('piplet-browser-phase', 'flat-read-only-final');
                await progress('flat-read-only:reload');
                location.reload();
                await new Promise(() => {});
            }
            await progress('main:entered');
            assert(document.querySelectorAll('.library-item').length === 40
                && document.querySelector('.library-empty')?.textContent.includes('Refine the search'),
                'the live library exceeded its bounded window or hid older-note search guidance');
            for (let index = 0; index < 21; index++) {
                const item = await findLibraryItem(`Story cap ${index}`);
                assert(item, `story-cap note ${index} was missing`);
                item.click();
            }
            assert(document.querySelectorAll('#story > article').length <= 20,
                'the live story rendered more than 20 open notes');
            input(document.getElementById('search-input'), '');
            const hostileItem = await findLibraryItem('HTTP note');
            assert(hostileItem, 'hostile-content note was missing from the browser fixture');
            hostileItem.click();
            const hostileNote = document.getElementById('piplet-note-http-note');
            assert(!document.getElementById('css-pwn') && !document.body.dataset.pwned, 'stored custom CSS became executable markup');
            assert(hostileNote?.querySelector('.prose h3')?.textContent === 'Safe heading', 'note headings did not preserve the document outline');
            assert(hostileNote?.querySelector('.prose h4')?.textContent === 'Subheading' && hostileNote?.querySelector('.prose h5')?.textContent === 'Detail', 'nested note headings lost their semantic levels');
            const headingSizes = ['h3', 'h4', 'h5'].map(selector => parseFloat(getComputedStyle(hostileNote.querySelector(selector)).fontSize));
            assert(headingSizes[0] > headingSizes[1] && headingSizes[1] > headingSizes[2]
                && ['h3', 'h4', 'h5'].every(selector => getComputedStyle(hostileNote.querySelector(selector)).marginBottom === '0px'),
                'nested note heading styles regressed');
            const hostileMarkup = '<im' + 'g src=x onerror=alert(1)>';
            assert(!hostileNote.querySelector('img') && hostileNote.textContent.includes(hostileMarkup), 'stored HTML became executable DOM');

            const mobileDrawer = matchMedia('(max-width: 760px)').matches;
            if (mobileDrawer) document.getElementById('menu-button').click();
            else document.body.dataset.drawer = 'open';
            const drawerControls = [...document.getElementById('library').querySelectorAll('button:not(:disabled), input, a[href]')];
            assert(drawerControls.length > 1, 'the mobile note index had no keyboard surface');
            drawerControls.at(-1).focus();
            drawerControls.at(-1).dispatchEvent(new KeyboardEvent('keydown', {key: 'Tab', bubbles: true, cancelable: true}));
            assert(document.activeElement === drawerControls[0]
                && document.getElementById('drawer-shade').tabIndex === -1
                && document.getElementById('drawer-shade').getAttribute('aria-hidden') === 'true',
                'the mobile modal drawer moved keyboard focus onto its outside backdrop');
            if (mobileDrawer) document.getElementById('drawer-shade').click();
            else document.body.dataset.drawer = '';

            let appearanceCalls = 0;
            window.fetch = (resource, options) => {
                if (String(resource).includes('api=appearance')) appearanceCalls++;
                return nativeFetch(resource, options);
            };
            const beforeTheme = await nativeFetch('?download=1').then(response => response.text());
            document.getElementById('appearance-button').click();
            const appearanceDialog = document.getElementById('appearance-dialog');
            await until(() => appearanceDialog.open, 'appearance did not open');
            const dark = document.querySelector('input[name="appearance-theme"][value="dark"]');
            dark.checked = true;
            dark.dispatchEvent(new Event('input', {bubbles: true}));
            document.getElementById('appearance-form').requestSubmit();
            await until(() => !appearanceDialog.open, 'theme-only save did not finish');
            const afterTheme = await nativeFetch('?download=1').then(response => response.text());
            assert(beforeTheme === afterTheme, 'theme-only save rewrote the piplet');
            assert(appearanceCalls === 0, 'theme-only save called the shared appearance API');

            document.getElementById('appearance-button').click();
            await until(() => appearanceDialog.open, 'appearance did not open for storage failure');
            const light = document.querySelector('input[name="appearance-theme"][value="light"]');
            light.checked = true;
            light.dispatchEvent(new Event('input', {bubbles: true}));
            const originalAppearanceSetItem = Storage.prototype.setItem;
            Storage.prototype.setItem = function (key, value) {
                if (String(key).endsWith(':theme')) throw new DOMException('blocked', 'QuotaExceededError');
                return originalAppearanceSetItem.call(this, key, value);
            };
            document.getElementById('appearance-form').requestSubmit();
            await until(() => document.getElementById('appearance-status').textContent.includes('could not store'), 'theme storage failure was not reported');
            assert(appearanceDialog.open, 'theme storage failure falsely completed the save');
            Storage.prototype.setItem = originalAppearanceSetItem;
            document.getElementById('appearance-cancel').click();
            await until(() => !appearanceDialog.open, 'appearance cancel did not close after storage failure');
            assert(appearanceCalls === 0, 'failed device-only theme save called the shared appearance API');
            window.fetch = nativeFetch;

            document.getElementById('appearance-button').click();
            await until(() => appearanceDialog.open, 'appearance did not reopen');
            document.querySelector('.appearance-custom').open = true;
            const cssEditor = document.getElementById('appearance-css');
            input(cssEditor, 'x'.repeat(32 * 1024 + 1));
            assert(document.getElementById('appearance-status').dataset.kind === 'error', 'oversized custom CSS was not rejected in preview');
            const plum = document.querySelector('input[name="appearance-palette"][value="plum"]');
            plum.checked = true;
            plum.dispatchEvent(new Event('input', {bubbles: true}));
            input(cssEditor, ':root { --story-width: 60rem; }\n.note-title { letter-spacing: 0; }\n</sty' + 'le><script id="css-pwn">1</scr' + 'ipt>');
            assert(document.getElementById('piplet-custom-style').textContent === cssEditor.value, 'custom CSS preview was not applied as text');
            assert(!document.getElementById('css-pwn'), 'custom CSS escaped its style element');
            assert(getComputedStyle(document.documentElement).getPropertyValue('--story-width').trim() === '60rem', 'custom CSS lost cascade precedence');
            document.getElementById('appearance-form').requestSubmit();
            await until(() => !appearanceDialog.open, 'custom CSS save did not finish');

            let saveCalls = 0;
            let releaseSave = null;
            window.fetch = (resource, options) => {
                if (String(resource).includes('api=save')) {
                    saveCalls++;
                    if (saveCalls === 1) {
                        return new Promise((resolve, reject) => {
                            releaseSave = () => nativeFetch(resource, options).then(resolve, reject);
                        });
                    }
                }
                return nativeFetch(resource, options);
            };
            document.getElementById('new-button').click();
            await until(() => document.querySelector('.editor'), 'new-note editor did not open');
            assert(document.querySelector('.editor-preview > h2.preview-label')?.textContent === 'Live preview',
                'the editor preview did not preserve a level-two heading above rendered note headings');
            input(document.getElementById('edit-title'), '😀'.repeat(61));
            input(document.getElementById('edit-body'), 'typed before the request');
            const firstForm = document.querySelector('.editor form');
            firstForm.requestSubmit();
            assert(saveCalls === 0
                && document.querySelector('.save-status')?.textContent.includes('240 UTF-8 bytes'),
                'the editor sent a multibyte title that exceeds the server byte limit');
            input(document.getElementById('edit-title'), 'One browser save');
            firstForm.requestSubmit();
            firstForm.requestSubmit();
            document.dispatchEvent(new KeyboardEvent('keydown', {key: 's', ctrlKey: true, bubbles: true}));
            await until(() => releaseSave !== null, 'save was not intercepted');
            assert(saveCalls === 1, 'duplicate submissions escaped the in-flight guard');
            assert(document.getElementById('edit-body').disabled, 'editor remained writable during save');
            document.getElementById('new-button').click();
            assert(document.getElementById('edit-title').value === 'One browser save', 'navigation replaced the in-flight editor');
            const nativeStatusTimeout = window.setTimeout;
            let savedStatusExpiry = null;
            window.setTimeout = function (callback, delay, ...args) {
                if (delay === 4000) {
                    savedStatusExpiry = () => callback(...args);
                    return 2147483646;
                }
                return nativeStatusTimeout.call(this, callback, delay, ...args);
            };
            releaseSave();
            await until(() => [...document.querySelectorAll('.note-title')].some(node => node.textContent === 'One browser save')
                || document.querySelector('.save-status')?.dataset.kind === 'error',
                'saved note did not render or report its failure');
            assert([...document.querySelectorAll('.note-title')].some(node => node.textContent === 'One browser save'),
                `saved note did not render: ${document.querySelector('.save-status')?.textContent || 'no editor status'}`);
            window.setTimeout = nativeStatusTimeout;
            assert(saveCalls === 1, 'one user save created multiple requests');
            const savedStatus = document.getElementById('global-status').textContent;
            assert(/saved/i.test(savedStatus) && savedStatus.includes('One browser save'), 'the save notice did not identify its note');
            assert(savedStatusExpiry, 'the save notice did not schedule its expiry');
            savedStatusExpiry();
            assert(document.getElementById('global-status').textContent === '', 'the expired save notice left stale header context');
            window.fetch = nativeFetch;

            const legacyGeneration = (await downloadDocument()).generation;
            const legacyRecoveryKey = `piplet:${location.pathname}:draft:legacy-security-audit`;
            const malformedUtf16Body = `migrate [[broken|${String.fromCharCode(0xd800)}]] exactly`;
            const repairedUtf16Body = 'migrate [[broken|�]] exactly';
            sessionStorage.setItem(legacyRecoveryKey, JSON.stringify({
                id: null, baseGeneration: legacyGeneration, baseRevision: 7,
                baseVersion: 'cccccccccccccccccccccccccccccccc',
                createToken: 'abababababababababababababababab', title: 'Legacy recovery migration',
                body: malformedUtf16Body, tags: ['legacy'], tagsText: '["legacy"]'
            }));
            document.getElementById('new-button').click();
            await until(() => document.getElementById('edit-title')?.value === 'Legacy recovery migration',
                'a legacy recovery record did not open');
            assert(document.getElementById('edit-body').value === repairedUtf16Body,
                'malformed UTF-16 was not repaired into savable Unicode');
            await until(() => storedDrafts().some(item => item.draft.title === 'Legacy recovery migration'
                && item.draft.recoveryFormat === 2), 'a legacy recovery record did not migrate');
            const migratedRecovery = storedDrafts().find(item => item.draft.title === 'Legacy recovery migration'
                && item.draft.recoveryFormat === 2);
            assert(migratedRecovery.key !== legacyRecoveryKey
                && /:draft:v2:[a-f0-9]{32}$/.test(migratedRecovery.key)
                && /^[a-f0-9]{32}$/.test(migratedRecovery.draft.draftId)
                && /^[a-f0-9]{32}$/.test(migratedRecovery.draft.storageVersion)
                && migratedRecovery.draft.baseRevision === 0
                && migratedRecovery.draft.baseVersion === null
                && migratedRecovery.draft.body === repairedUtf16Body
                && sessionStorage.getItem(legacyRecoveryKey) === null,
                'legacy recovery migration did not normalize an immutable, savable record');
            document.querySelector('.editor form').requestSubmit();
            await until(() => [...document.querySelectorAll('.note-title')]
                .some(node => node.textContent === 'Legacy recovery migration'),
                'the normalized recovery record could not be saved');
            const repairedDocument = await downloadDocument();
            const repairedNote = Object.values(repairedDocument.notes)
                .find(note => note.title === 'Legacy recovery migration');
            assert(repairedNote?.body === repairedUtf16Body,
                'saving the normalized recovery changed its repaired text');
            assert(sessionStorage.getItem(migratedRecovery.key) === null,
                'saving the migrated recovery left its random record behind');

            document.getElementById('new-button').click();
            await until(() => document.getElementById('piplet-composer'), 'the backlink composer did not open');
            input(document.getElementById('edit-title'), 'Backlink source');
            input(document.getElementById('edit-body'),
                'points at [[Hello, piplet|welcome]], quotes `[[One browser save|one-browser-save]]`, and names [[missing|nowhere]] plus [[Self|backlink-source]]');
            document.querySelector('.editor form').requestSubmit();
            await until(() => document.getElementById('piplet-note-backlink-source'),
                'the backlink source note did not save');
            assert(!document.getElementById('piplet-note-backlink-source').querySelector('.note-backlinks'),
                'a self-link produced a backlinks row');
            (await findLibraryItem('One browser save')).click();
            await until(() => document.getElementById('piplet-note-one-browser-save'),
                'the code-quoted note did not open for the backlink check');
            assert(!document.getElementById('piplet-note-one-browser-save').querySelector('.note-backlinks'),
                'a wiki link inside inline code produced a backlink');
            (await findLibraryItem('Hello, piplet')).click();
            await until(() => document.querySelector('#piplet-note-welcome .note-backlinks'),
                'welcome did not show its backlinks row');
            const backlinkAnchors = [...document.querySelectorAll('#piplet-note-welcome .note-backlinks a')];
            assert(backlinkAnchors.length === 1 && backlinkAnchors[0].textContent === 'Backlink source',
                'the backlinks row did not list exactly the one linking note');
            backlinkAnchors[0].click();
            await until(() => document.querySelector('#story > article')?.id === 'piplet-note-backlink-source',
                'a backlink click did not open its linking note');
            input(document.getElementById('search-input'), '');

            document.getElementById('new-button').click();
            await until(() => document.getElementById('piplet-composer'), 'the slug-collision note composer did not open');
            input(document.getElementById('edit-title'), 'new');
            input(document.getElementById('edit-body'), 'saved body for the real new slug');
            document.querySelector('.editor form').requestSubmit();
            await until(() => document.getElementById('piplet-note-new') && !document.querySelector('.editor'),
                'a note whose slug is new did not save');
            click(document.querySelector('#piplet-note-new button[title="Edit note"]'), 'edit the real new-slug note');
            await until(() => document.querySelector('#piplet-note-new.editor'), 'the real new-slug editor did not open');
            input(document.getElementById('edit-body'), 'unsaved body for the real new slug');
            document.getElementById('new-button').click();
            await until(() => document.getElementById('piplet-composer'), 'a separate composer did not open');
            const savedNoteDraft = storedDrafts().find(item => item.draft.body === 'unsaved body for the real new slug');
            assert(savedNoteDraft && savedNoteDraft.draft.id === 'new'
                && /:draft:v2:[a-f0-9]{32}$/.test(savedNoteDraft.key),
                'the saved-note recovery was not an independent random record');
            input(document.getElementById('edit-title'), 'Separate composer');
            input(document.getElementById('edit-body'), 'separate null-ID draft');
            await until(() => storedDrafts().some(item => item.draft.body === 'separate null-ID draft'),
                'the composer recovery was not stored');
            const composerDraft = storedDrafts().find(item => item.draft.body === 'separate null-ID draft');
            assert(composerDraft.draft.id === null && composerDraft.key !== savedNoteDraft.key,
                'two editors shared or overwrote a recovery record');
            button(document.querySelector('.editor-actions'), 'Cancel').click();
            await until(() => !document.querySelector('.editor'), 'the separate composer did not cancel');
            assert(sessionStorage.getItem(composerDraft.key) === null
                && JSON.parse(sessionStorage.getItem(savedNoteDraft.key))?.body === 'unsaved body for the real new slug',
                'cancel removed the wrong immutable recovery record');
            click(document.querySelector('#piplet-note-new button[title="Edit note"]'), 'reopen the real new-slug note');
            await until(() => document.querySelector('#piplet-note-new.editor'), 'the saved-note recovery did not reopen');
            assert(document.getElementById('edit-body').value === 'unsaved body for the real new slug',
                'the saved-note recovery did not round-trip');
            button(document.querySelector('.editor-actions'), 'Cancel').click();
            await until(() => !document.querySelector('.editor'), 'the saved-note recovery did not discard');

            document.getElementById('new-button').click();
            await until(() => document.getElementById('piplet-composer'), 'the fixed-ID collision composer did not open');
            input(document.getElementById('edit-title'), 'count');
            input(document.getElementById('edit-body'), 'saved without colliding with note-count');
            document.querySelector('.editor form').requestSubmit();
            await until(() => document.getElementById('piplet-note-count') && !document.querySelector('.editor'), 'a note whose slug is count did not save');
            assert(document.querySelectorAll('#note-count').length === 1
                && document.getElementById('note-count').classList.contains('note-count'),
                'a note slug collided with the fixed note-count element');
            click(document.querySelector('#piplet-note-count button[title="Edit note"]'), 'edit the disposable count note');
            await until(() => document.querySelector('#piplet-note-count.editor'), 'the disposable count editor did not open');
            button(document.querySelector('.editor-actions'), 'Delete').click();
            await until(() => document.querySelector('#piplet-note-count .delete-row'), 'the disposable count delete confirmation did not open');
            const nativeDeleteTimeout = window.setTimeout;
            let deleteStatusExpiry = null;
            window.setTimeout = function (callback, delay, ...args) {
                if (delay === 4000) {
                    deleteStatusExpiry = () => callback(...args);
                    return 2147483645;
                }
                return nativeDeleteTimeout.call(this, callback, delay, ...args);
            };
            let releaseDelete = null;
            window.fetch = (resource, options) => {
                if (String(resource).includes('api=delete')) {
                    return new Promise((resolve, reject) => {
                        releaseDelete = () => nativeFetch(resource, options).then(resolve, reject);
                    });
                }
                return nativeFetch(resource, options);
            };
            button(document.querySelector('#piplet-note-count .delete-row'), 'Delete note').click();
            await until(() => releaseDelete !== null, 'the disposable delete was not held');
            const deletingStatus = document.getElementById('global-status').textContent;
            assert(/deleting/i.test(deletingStatus) && deletingStatus.includes('count'), 'the pending delete did not identify its note');
            document.body.click();
            document.dispatchEvent(new KeyboardEvent('keydown', {key: 'Tab', bubbles: true}));
            assert(document.getElementById('global-status').textContent === deletingStatus,
                'ordinary interaction cleared a pending operation status');
            releaseDelete();
            await until(() => !document.getElementById('piplet-note-count'), 'the disposable count note was not deleted');
            window.fetch = nativeFetch;
            window.setTimeout = nativeDeleteTimeout;
            const deletedStatus = document.getElementById('global-status').textContent;
            assert(/deleted/i.test(deletedStatus) && deletedStatus.includes('count'), 'the delete notice did not identify its note');
            assert(deleteStatusExpiry, 'the delete notice did not schedule its expiry');
            deleteStatusExpiry();
            assert(document.getElementById('global-status').textContent === '', 'the expired delete notice left stale header context');


            let loseCreateResponse = true;
            window.fetch = (resource, options) => {
                if (loseCreateResponse && String(resource).includes('api=save')) {
                    loseCreateResponse = false;
                    return nativeFetch(resource, options).then(() => { throw new TypeError('simulated lost response'); });
                }
                return nativeFetch(resource, options);
            };
            document.getElementById('new-button').click();
            await until(() => document.querySelector('.editor'), 'lost-response editor did not open');
            input(document.getElementById('edit-title'), 'Lost response create');
            input(document.getElementById('edit-body'), 'committed body');
            document.querySelector('.editor form').requestSubmit();
            await until(() => document.querySelector('.save-status')?.textContent.includes('Could not reach'),
                'lost create response was not reported');
            const originalRecovery = storedDrafts().find(item => item.draft.title === 'Lost response create');
            assert(originalRecovery && /^[a-f0-9]{32}$/.test(originalRecovery.draft.createToken),
                'lost create was not recoverable with a stable token');
            input(document.getElementById('edit-body'), 'changed after lost response');
            window.fetch = nativeFetch;
            document.querySelector('.editor form').requestSubmit();
            await until(() => document.querySelector('.conflict-panel'),
                'changed lost-response retry did not show its conflict');
            assert(document.getElementById('edit-body').value === 'changed after lost response'
                && storedDrafts().some(item => item.draft.body === 'changed after lost response'),
                'create conflict lost its live or persisted draft');
            await until(() => document.querySelector('.conflict-panel')?.contains(document.activeElement),
                'create conflict did not receive keyboard focus');
            button(document.querySelector('.conflict-panel'), 'Replace saved version').click();
            await until(() => !document.querySelector('.conflict-panel'), 'the lost create did not rebase onto its saved note');
            document.querySelector('.editor form').requestSubmit();
            await until(() => !document.querySelector('.editor'), 'replacing the committed create did not save');
            const replacedLost = await downloadDocument();
            assert(Object.values(replacedLost.notes).some(note => note.title === 'Lost response create'
                && note.body === 'changed after lost response'),
                'the rebased lost create retained a create-only token or lost its changed body');
            assert(sessionStorage.getItem(originalRecovery.key) === null,
                'resolving the lost create left its immutable recovery record behind');

            document.getElementById('new-button').click();
            await until(() => document.querySelector('.editor'), 'lineage-conflict editor did not open');
            input(document.getElementById('edit-title'), 'Recovered across rekey');
            input(document.getElementById('edit-body'), 'keep this unsent draft');
            const lineageDocument = await downloadDocument();
            let injectLineageConflict = true;
            window.fetch = (resource, options) => {
                if (injectLineageConflict && String(resource).includes('api=save')) {
                    injectLineageConflict = false;
                    return Promise.resolve(new Response(JSON.stringify({
                        ok: false, error: 'This piplet changed lineage; reload before saving.',
                        current: null, generation: lineageDocument.generation
                    }), {status: 409, headers: {'Content-Type': 'application/json'}}));
                }
                return nativeFetch(resource, options);
            };
            document.querySelector('.editor form').requestSubmit();
            await until(() => document.querySelector('.conflict-panel'), 'a stale composer did not show a lineage conflict');
            assert(document.getElementById('edit-body').value === 'keep this unsent draft',
                'the lineage conflict lost its unsent draft');
            button(document.querySelector('.conflict-panel'), 'Save as new in this piplet').click();
            await until(() => !document.querySelector('.conflict-panel'), 'the lineage adoption action did not rebase the draft');
            window.fetch = nativeFetch;
            document.querySelector('.editor form').requestSubmit();
            await until(() => !document.querySelector('.editor'), 'the explicitly rebased lineage draft did not save');
            const lineageSaved = await downloadDocument();
            assert(Object.values(lineageSaved.notes).some(note => note.title === 'Recovered across rekey'
                && note.body === 'keep this unsent draft'),
                'the explicitly rebased lineage draft did not round-trip');

            const welcomeForConflict = await findLibraryItem('Hello, piplet');
            assert(welcomeForConflict, 'welcome was missing from the conflict library');
            welcomeForConflict.click();
            await until(() => document.getElementById('piplet-note-welcome'), 'welcome did not open for conflict testing');
            click(document.querySelector('#piplet-note-welcome button[title="Edit note"]'), 'conflict welcome edit');
            await until(() => document.querySelector('#piplet-note-welcome.editor'), 'welcome editor did not open');
            input(document.getElementById('edit-body'), 'my conflicted draft');
            const remote = await api('save', {id: 'welcome', baseRevision: 1, title: 'Hello, piplet', body: 'saved in another tab', tags: ['welcome']});
            assert(remote.ok, 'competing save failed');
            const conflictSetItem = Storage.prototype.setItem;
            Storage.prototype.setItem = function (key, value) {
                if (String(key).includes('draft:')) throw new DOMException('blocked', 'QuotaExceededError');
                return conflictSetItem.call(this, key, value);
            };
            document.querySelector('.editor form').requestSubmit();
            await until(() => document.querySelector('.conflict-panel'), 'stale save did not show conflict choices');
            assert(document.getElementById('edit-body').value === 'my conflicted draft', '409 discarded the local draft');
            assert(document.querySelector('.save-status').textContent.includes('could not store'), '409 storage failure was mislabeled as safely recovered');
            await until(() => document.querySelector('.conflict-panel')?.contains(document.activeElement), 'saved-version conflict did not receive keyboard focus');
            Storage.prototype.setItem = conflictSetItem;
            button(document.querySelector('.editor-actions'), 'Cancel').click();
            await until(() => !document.querySelector('.editor'), 'conflict cancel did not close the editor');
            click(document.querySelector('#piplet-note-welcome button[title="Edit note"]'), 'reopen conflicted welcome');
            await until(() => document.querySelector('.conflict-panel'), 'kept conflict draft was not recovered');
            assert(document.getElementById('edit-body').value === 'my conflicted draft', 'reopened conflict lost local text');
            button(document.querySelector('.conflict-panel'), 'Use saved version').click();
            await until(() => !document.querySelector('.editor'), 'using the saved version did not close the draft');
            assert(document.querySelector('#piplet-note-welcome .prose').textContent.includes('saved in another tab'), 'saved version was not restored');

            click(document.querySelector('#piplet-note-welcome button[title="Edit note"]'), 'pristine welcome edit');
            await until(() => document.querySelector('#piplet-note-welcome.editor'), 'pristine editor did not open');
            window.dispatchEvent(new Event('pagehide'));
            assert(!storedDrafts().some(item => item.draft.id === 'welcome'), 'pagehide created a recovery draft for an untouched editor');
            document.getElementById('new-button').click();
            await until(() => document.getElementById('piplet-composer'), 'switching away from an untouched editor failed');
            assert(!storedDrafts().some(item => item.draft.id === 'welcome'), 'switching notes created a false recovery draft');
            button(document.querySelector('.editor-actions'), 'Cancel').click();
            await until(() => !document.querySelector('.editor'), 'untouched new editor did not cancel');

            click(document.querySelector('#piplet-note-welcome button[title="Edit note"]'), 'ordinary cancel welcome edit');
            await until(() => document.querySelector('#piplet-note-welcome.editor'), 'ordinary-cancel editor did not open');
            input(document.getElementById('edit-body'), 'ordinary draft to discard');
            button(document.querySelector('.editor-actions'), 'Cancel').click();
            await until(() => !document.querySelector('.editor'), 'ordinary cancel did not close the editor');
            assert(!document.getElementById('global-status').textContent.includes('Draft kept'), 'ordinary cancel falsely claimed to keep its draft');
            click(document.querySelector('#piplet-note-welcome button[title="Edit note"]'), 'ordinary cancel welcome reopen');
            await until(() => document.querySelector('#piplet-note-welcome.editor'), 'ordinary-cancel note did not reopen');
            assert(document.getElementById('edit-body').value === 'saved in another tab', 'ordinary cancel retained discarded text');
            input(document.getElementById('edit-body'), 'draft whose getItem fails');
            await until(() => storedDrafts().some(item => item.draft.body === 'draft whose getItem fails'),
                'getItem-failure draft was not stored first');
            const originalGetItem = Storage.prototype.getItem;
            Storage.prototype.getItem = function (key) {
                if (String(key).includes('draft:')) throw new DOMException('blocked', 'SecurityError');
                return originalGetItem.call(this, key);
            };
            button(document.querySelector('.editor-actions'), 'Cancel').click();
            assert(document.querySelector('#piplet-note-welcome.editor')
                && document.querySelector('.save-status').textContent.includes('could not discard'),
                'a browser-storage read failure was mistaken for successful draft removal');
            Storage.prototype.getItem = originalGetItem;
            button(document.querySelector('.editor-actions'), 'Cancel').click();
            await until(() => !document.querySelector('.editor'), 'getItem-failure editor did not close after storage recovered');
            click(document.querySelector('#piplet-note-welcome button[title="Edit note"]'), 'getItem welcome reopen');
            await until(() => document.querySelector('#piplet-note-welcome.editor'), 'getItem-failure note did not reopen cleanly');
            assert(document.getElementById('edit-body').value === 'saved in another tab', 'getItem failure resurrected discarded text');
            input(document.getElementById('edit-body'), 'draft whose removeItem fails');
            const originalRemoveItem = Storage.prototype.removeItem;
            Storage.prototype.removeItem = function (key) {
                if (String(key).includes('draft:')) throw new DOMException('blocked', 'SecurityError');
                return originalRemoveItem.call(this, key);
            };
            button(document.querySelector('.editor-actions'), 'Cancel').click();
            await until(() => !document.querySelector('.editor'), 'second ordinary cancel did not close');
            Storage.prototype.removeItem = originalRemoveItem;
            click(document.querySelector('#piplet-note-welcome button[title="Edit note"]'), 'removeItem welcome reopen');
            await until(() => document.querySelector('#piplet-note-welcome.editor'), 'removeItem-failure note did not reopen');
            assert(document.getElementById('edit-body').value === 'saved in another tab', 'a failed removeItem resurrected discarded text');
            button(document.querySelector('.editor-actions'), 'Cancel').click();
            await until(() => !document.querySelector('.editor'), 'removeItem-failure editor did not close again');

            click(document.querySelector('#piplet-note-welcome button[title="Edit note"]'), 'storage-failure welcome edit');
            await until(() => document.querySelector('#piplet-note-welcome.editor'), 'storage-failure editor did not open');
            const originalSetItem = Storage.prototype.setItem;
            Storage.prototype.setItem = function (key, value) {
                if (String(key).includes('draft:')) throw new DOMException('blocked', 'QuotaExceededError');
                return originalSetItem.call(this, key, value);
            };
            input(document.getElementById('edit-body'), 'must stay visible');
            const blockedUnload = new Event('beforeunload', {cancelable: true});
            window.dispatchEvent(blockedUnload);
            assert(blockedUnload.defaultPrevented,
                'beforeunload did not protect a dirty draft when recovery storage failed');
            document.getElementById('new-button').click();
            assert(document.getElementById('edit-body')?.value === 'must stay visible', 'storage failure allowed an editor switch');
            assert(document.querySelector('.save-status').dataset.kind === 'error', 'storage failure was not reported');
            const staleTokenFetch = window.fetch;
            window.fetch = (resource, options) => String(resource).includes('api=save')
                ? Promise.resolve(new Response(JSON.stringify({ok: false, error: 'Refresh the page before saving again.'}), {
                    status: 403, headers: {'Content-Type': 'application/json'}
                }))
                : staleTokenFetch(resource, options);
            document.querySelector('.editor form').requestSubmit();
            await until(() => document.querySelector('.save-status')?.textContent.includes('not in recovery storage'),
                'a stale security token advised refresh after browser recovery had failed');
            assert(document.getElementById('edit-body')?.value === 'must stay visible',
                'a stale security token hid the only live copy of an unstored draft');
            window.fetch = staleTokenFetch;
            Storage.prototype.setItem = originalSetItem;

            input(document.getElementById('edit-body'), 'flushed on pagehide');
            window.dispatchEvent(new Event('pagehide'));
            const draftStorageKey = storedDrafts().find(item => item.draft.body === 'flushed on pagehide')?.key;
            assert(draftStorageKey && JSON.parse(sessionStorage.getItem(draftStorageKey)).body === 'flushed on pagehide', 'pagehide did not synchronously flush the draft');

            input(document.getElementById('edit-body'), 'x'.repeat(513 * 1024));
            document.getElementById('new-button').click();
            assert(document.getElementById('edit-body')?.value.length === 513 * 1024, 'oversized draft was lost on editor switch');
            assert(JSON.parse(sessionStorage.getItem(draftStorageKey)).body === 'flushed on pagehide', 'an oversized draft erased the last recoverable copy');
            const unmatchedStarted = performance.now();
            input(document.getElementById('edit-body'), '['.repeat(100000));
            await until(() => document.querySelector('.editor-preview .prose')?.textContent.length === 100000, 'unmatched inline markup did not render promptly');
            assert(performance.now() - unmatchedStarted < 2500, 'unmatched inline markup blocked rendering for too long');
            await until(() => document.querySelector('.save-status').dataset.kind !== 'error', 'a smaller draft did not clear the stale recovery warning');
            const structured = Array.from({length: 2101}, (_, index) => `line ${index}`).join('\n');
            input(document.getElementById('edit-body'), structured);
            await until(() => document.querySelector('.editor-preview .render-notice'), 'node-heavy preview was not bounded');
            document.querySelector('.editor form').requestSubmit();
            await until(() => !document.querySelector('.editor'), 'bounded note save did not finish');
            const welcome = document.getElementById('piplet-note-welcome');
            assert(welcome.querySelector('.render-notice'), 'stored node-heavy note was not bounded');
            assert(welcome.querySelector('.plain-note').value === structured, 'bounded renderer did not retain complete text');
            assert(welcome.querySelectorAll('*').length < 250, 'bounded renderer created too many DOM nodes');


            const deletedConflictItem = await findLibraryItem('One browser save');
            deletedConflictItem.click();
            await until(() => [...document.querySelectorAll('.note-title')].some(node => node.textContent === 'One browser save'),
                'deleted-conflict note did not open');
            const deletedConflictArticle = [...document.querySelectorAll('.note')]
                .find(node => node.querySelector('.note-title')?.textContent === 'One browser save');
            click(deletedConflictArticle?.querySelector('button[title="Edit note"]'), 'deleted-conflict edit');
            input(document.getElementById('edit-body'), 'keep after remote deletion');
            window.dispatchEvent(new Event('pagehide'));
            const deletedRecovery = storedDrafts().find(item => item.draft.body === 'keep after remote deletion');
            assert(deletedRecovery, 'deleted-note draft did not persist before its conflict');
            const conflictSnapshot = await nativeFetch('?download=1').then(response => response.text());
            const conflictMarker = '\nPIPLET-DATA/2\n';
            const conflictDocument = JSON.parse(conflictSnapshot.slice(conflictSnapshot.lastIndexOf(conflictMarker) + conflictMarker.length).trim());
            const deletedConflictNote = Object.values(conflictDocument.notes).find(note => note.title === 'One browser save');
            assert((await api('delete', {id: deletedConflictNote.id, baseRevision: deletedConflictNote.revision})).ok,
                'deleted-conflict remote delete failed');
            document.querySelector('.editor form').requestSubmit();
            await until(() => document.querySelector('.conflict-panel')?.textContent.includes('deleted elsewhere'),
                'deleted-note save did not show its conflict');
            assert(document.getElementById('edit-body').value === 'keep after remote deletion'
                && JSON.parse(sessionStorage.getItem(deletedRecovery.key))?.body === 'keep after remote deletion',
                'deleted-note conflict lost its immutable recovery');
            const deletedOpenKey = Object.keys(sessionStorage).find(key => key.endsWith(':open'));
            assert(!JSON.parse(sessionStorage.getItem(deletedOpenKey) || '[]').includes(deletedConflictNote.id)
                && location.hash !== `#${encodeURIComponent(deletedConflictNote.id)}`,
                'deleted-note conflict retained dead navigation state');
            button(document.querySelector('.conflict-panel'), 'Discard draft').click();
            await until(() => !document.querySelector('.editor'), 'deleted-note conflict did not discard');
            assert(sessionStorage.getItem(deletedRecovery.key) === null,
                'discarding a deleted-note conflict left its recovery record behind');

            const pristineItem = await findLibraryItem('Hello, piplet');
            pristineItem.click();
            await until(() => document.getElementById('piplet-note-welcome'),
                'welcome did not open for the pristine conflict test');
            click(document.querySelector('#piplet-note-welcome button[title="Edit note"]'),
                'open pristine editor before a competing save');
            await until(() => document.querySelector('#piplet-note-welcome.editor'),
                'pristine conflict editor did not open');
            const pristineBody = document.getElementById('edit-body').value;
            const pristineSnapshot = await downloadDocument();
            const pristineCurrent = pristineSnapshot.notes.welcome;
            assert((await api('save', {
                id: 'welcome', baseRevision: pristineCurrent.revision, title: pristineCurrent.title,
                body: 'saved remotely after pristine editor opened', tags: pristineCurrent.tags
            })).ok, 'the pristine competing save failed');
            document.querySelector('.editor form').requestSubmit();
            await until(() => document.querySelector('.conflict-panel'),
                'an untouched stale editor did not show a conflict');
            const pristineRecovery = storedDrafts().find(item => item.draft.id === 'welcome'
                && item.draft.body === pristineBody);
            assert(pristineRecovery, 'an untouched stale editor was not forced into browser recovery');
            button(document.querySelector('.editor-actions'), 'Cancel').click();
            await until(() => !document.querySelector('.editor'), 'the pristine conflict did not close while retaining recovery');
            assert(sessionStorage.getItem(pristineRecovery.key) !== null,
                'closing the pristine conflict lost its only old copy');
            click(document.querySelector('#piplet-note-welcome button[title="Edit note"]'),
                'reopen the pristine conflict recovery');
            await until(() => document.querySelector('.conflict-panel'),
                'the pristine conflict recovery did not reopen');
            button(document.querySelector('.conflict-panel'), 'Use saved version').click();
            await until(() => !document.querySelector('.editor'),
                'the pristine conflict recovery did not resolve explicitly');

            click(document.querySelector('#piplet-note-welcome button[title="Edit note"]'),
                'open an existing note for restored-lineage conflict testing');
            await until(() => document.querySelector('#piplet-note-welcome.editor'),
                'restored-lineage editor did not open');
            input(document.getElementById('edit-body'), 'draft from the earlier lineage');
            const lineageSnapshot = await downloadDocument();
            const restoredCurrent = lineageSnapshot.notes.welcome;
            const fakeGeneration = lineageSnapshot.generation.startsWith('f')
                ? `e${lineageSnapshot.generation.slice(1)}` : `f${lineageSnapshot.generation.slice(1)}`;
            let injectExistingLineage = true;
            window.fetch = (resource, options) => {
                if (injectExistingLineage && String(resource).includes('api=save')) {
                    injectExistingLineage = false;
                    return Promise.resolve(new Response(JSON.stringify({
                        ok: false, error: 'This piplet changed lineage; reload before saving.',
                        current: restoredCurrent, generation: fakeGeneration
                    }), {status: 409, headers: {'Content-Type': 'application/json'}}));
                }
                return nativeFetch(resource, options);
            };
            document.querySelector('.editor form').requestSubmit();
            await until(() => document.querySelector('.conflict-panel')?.textContent.includes('earlier restored copy'),
                'an existing-note generation mismatch was downgraded to an ordinary edit conflict');
            assert(document.getElementById('edit-body').value === 'draft from the earlier lineage'
                && [...document.querySelectorAll('.conflict-panel button')].some(item => item.textContent === 'Save as new in this piplet')
                && ![...document.querySelectorAll('.conflict-panel button')].some(item => item.textContent === 'Replace saved version'),
                'the restored-lineage conflict lost its draft or offered to overwrite restored content');
            button(document.querySelector('.conflict-panel'), 'Discard draft').click();
            await until(() => !document.querySelector('.editor'), 'the restored-lineage conflict did not discard');
            window.fetch = nativeFetch;

            assert(runtimeErrors.length === 0, `page error before writable recovery reload: ${runtimeErrors.join('; ')}`);
            sessionStorage.setItem('piplet-browser-phase', 'writable-setup');
            await progress('main:reload-writable-setup');
            location.reload();
            await new Promise(() => {});
        };
        run().then(async () => {
            if (runtimeErrors.length) throw new Error(`page error: ${runtimeErrors.join('; ')}`);
            result.textContent = 'PASS'; document.body.append(result);
            await progress('result:PASS');
        })
            .catch(async error => {
                const message = String(error.stack || error.message).replace(/\s+/g, ' ');
                result.textContent = `FAIL: ${message}`; document.body.append(result);
                await progress(`result:FAIL: ${message}`);
            });
    })();
    </script>
PHP;
    $browserNeedle = "    </script>\n</body>";
    $httpSource = file_get_contents($httpCopy);
    check(is_string($httpSource) && substr_count($httpSource, $browserNeedle) === 1, 'Could not locate the browser harness insertion point.');
    $testPrelude = <<<'PHP'
if (isset($_GET['__browser_readonly']) || isset($_GET['__browser_rekey']) || isset($_GET['__browser_progress'])) {
    $testCapability = $_SERVER['HTTP_X_PIPLET_TEST_CAPABILITY'] ?? '';
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
        || !is_string($testCapability)
        || !hash_equals('__PIPLET_TEST_CAPABILITY__', $testCapability)) {
        http_response_code(404);
        exit("Not found.\n");
    }
    if (($_GET['__browser_rekey'] ?? null) === '1') {
        define('PIPLET_TEST_REKEY', true);
    } elseif (($_GET['__browser_readonly'] ?? null) === '1') {
        if (!chmod(__DIR__, 0555)) { http_response_code(500); }
        exit;
    } else {
        $progress = (string) ($_GET['__browser_progress'] ?? '');
        if ($progress === '' || strlen($progress) > 1000 || strpbrk($progress, "\r\n") !== false) {
            http_response_code(400);
            exit;
        }
        file_put_contents(__DIR__ . '/browser-progress.log', $progress . "\n", FILE_APPEND | LOCK_EX);
        exit;
    }
}
PHP;
    $browserHarness = str_replace('__PIPLET_TEST_CAPABILITY__', $browserCapability, $browserHarness);
    $testPrelude = str_replace('__PIPLET_TEST_CAPABILITY__', $browserCapability, $testPrelude);
    $declareNeedle = "declare(strict_types=1);\n";
    check(substr_count($httpSource, $declareNeedle) === 1, 'Could not locate the browser fixture prelude point.');
    $httpSource = str_replace($declareNeedle, $declareNeedle . $testPrelude . "\n", $httpSource);
    $dispatchNeedle = "if (!defined('PIPLET_LIBRARY_ONLY')) {\n";
    $testTail = <<<'PHP'
if (defined('PIPLET_TEST_REKEY')) {
    piplet_mutate(function (array &$document): array {
        piplet_rekey_document($document);
        return [];
    });
    http_response_code(204);
    exit;
}
PHP;
    check(substr_count($httpSource, $dispatchNeedle) === 1, 'Could not locate the browser fixture dispatch point.');
    $httpSource = str_replace($dispatchNeedle, $testTail . "\n" . $dispatchNeedle, $httpSource);
    check(file_put_contents($httpCopy, str_replace($browserNeedle, "    </script>\n$browserHarness</body>", $httpSource)) !== false, 'Could not instrument the browser fixture.');
    $browserSignal = $httpRoot . '/browser-progress.log';
    check(file_put_contents($browserSignal, '') === 0, 'Could not initialize the browser completion signal.');
    $longPathSegment = str_repeat('p', 220);
    $longPathRoot = $httpRoot . '/' . $longPathSegment;
    $longPathCopy = $longPathRoot . '/index.php';
    $longPathSignal = $longPathRoot . '/browser-progress.log';
    check(mkdir($longPathRoot, 0700) && copy($httpCopy, $longPathCopy)
        && file_put_contents($longPathSignal, '') === 0,
        'Could not create the long-path browser fixture.');
    [$server, $port] = start_test_server(
        $httpRoot,
        test_environment(['PIPLET_PASSWORD' => 'browser-test']),
        'Could not start the PHP test server.'
    );
    $defaultHttpHeaders = ['Authorization: Basic ' . base64_encode('writer:browser-test')];
    $browserBase = "http://writer:browser-test@127.0.0.1:$port";
    try {
        [$anonymousProgress] = http_request("http://127.0.0.1:$port/?__browser_progress=state%3Aresult%3APASS");
        [$anonymousReadOnly] = http_request("http://127.0.0.1:$port/?__browser_readonly=1");
        [$anonymousRekey] = http_request("http://127.0.0.1:$port/?__browser_rekey=1");
        [$malformedProgress] = http_request(
            "http://127.0.0.1:$port/?__browser_progress=state%3Aresult%3APASS%0Aforged",
            'POST', ["X-Piplet-Test-Capability: $browserCapability"]
        );
        clearstatcache(true, $httpRoot);
        check($anonymousProgress === 404 && $anonymousReadOnly === 404 && $anonymousRekey === 404
            && $malformedProgress === 400
            && file_get_contents($browserSignal) === '' && (fileperms($httpRoot) & 0777) === 0700,
            'Unauthenticated or malformed browser-test hooks forged progress or changed fixture permissions.');
        [$getStatus, $getHeaders, $page] = http_request("http://127.0.0.1:$port/");
        check($getStatus === 200, 'The app page did not return 200.');
        check(!str_contains($page, '>one file<') && !str_contains($page, '> · </span>') && !str_contains($page, 'id="file-size"'),
            'The header retained its old one-file status, separator, or file size.');
        $csp = (string) header_value($getHeaders, 'Content-Security-Policy');
        check(
            preg_match("~^default-src 'none'; style-src 'nonce-([A-Za-z0-9+/]{24})'; "
                . "script-src 'nonce-\\1'; connect-src 'self'; img-src data:; base-uri 'none'; "
                . "form-action 'self'; frame-ancestors 'none'$~D", $csp) === 1,
            'The restrictive CSP contract changed.'
        );
        check(strtolower((string) header_value($getHeaders, 'Cache-Control')) === 'no-store'
            && strtolower((string) header_value($getHeaders, 'X-Content-Type-Options')) === 'nosniff'
            && strtolower((string) header_value($getHeaders, 'Referrer-Policy')) === 'no-referrer'
            && strtoupper((string) header_value($getHeaders, 'X-Frame-Options')) === 'DENY',
            'The authenticated page lost its no-cache, anti-sniffing, referrer, or framing policy.');
        check(preg_match('/name="piplet-csrf" content="([a-f0-9]{64})"/', $page, $tokenMatch) === 1, 'The CSRF token is missing.');
        $setCookie = header_value($getHeaders, 'Set-Cookie');
        check($setCookie !== null && preg_match('/^([^=]+)=([^;]+)/', $setCookie, $cookieMatch) === 1, 'The CSRF cookie is missing.');
        check(preg_match('/(?:^|;)\s*HttpOnly(?:;|$)/i', $setCookie) === 1
            && preg_match('/(?:^|;)\s*SameSite=Lax(?:;|$)/i', $setCookie) === 1
            && preg_match('/(?:^|;)\s*path=\/(?:;|$)/i', $setCookie) === 1
            && preg_match('/(?:^|;)\s*Secure(?:;|$)/i', $setCookie) !== 1,
            'The local-HTTP CSRF cookie lost its path, HttpOnly, SameSite, or non-Secure policy.');
        $token = $tokenMatch[1];
        $cookie = $cookieMatch[1] . '=' . $cookieMatch[2];
        $authorizedJsonHeaders = ["Cookie: $cookie", 'Content-Type: application/json', "X-CSRF-Token: $token"];
        [$forwardedStatus, $forwardedHeaders] = http_request(
            "http://127.0.0.1:$port/",
            'GET',
            ['X-Forwarded-Proto: https', 'Forwarded: proto=https']
        );
        $forwardedCookie = (string) header_value($forwardedHeaders, 'Set-Cookie');
        check($forwardedStatus === 200 && !preg_match('/(?:^|;)\s*Secure(?:;|$)/i', $forwardedCookie),
            'Untrusted forwarding headers changed the CSRF cookie security policy.');
        $httpDocument = worker_command($httpCopy, 'read');
        $httpAppearance = worker_command($httpCopy, 'current-appearance');
        $payload = json_encode([
            'id' => null,
            'baseGeneration' => $httpDocument['generation'],
            'baseRevision' => 0,
            'baseVersion' => null,
            'createToken' => str_repeat('d', 32),
            'title' => 'HTTP note',
            'body' => "# Safe heading\n\n## Subheading\n\n### Detail\n\n<img src=x onerror=alert(1)>\n<!--<script>boom</script>\u{2028}nul:\0",
            'tags' => ['web'],
        ], JSON_THROW_ON_ERROR);
        $hostileCss = ":root { --story-width: 60rem; --radius: 10px; }\n.note-title { letter-spacing: 0; }\n</style><script id=\"css-pwn\">document.body.dataset.pwned=1</script>";
        $httpAppearanceValues = [
            'palette' => 'ocean',
            'font' => 'modern',
            'scale' => 'large',
            'measure' => 'wide',
            'customCss' => $hostileCss,
        ];
        $appearancePayload = json_encode([
            'baseGeneration' => $httpDocument['generation'],
            'baseRevision' => $httpAppearance['revision'],
            'baseVersion' => $httpAppearance['version'],
            'appearance' => $httpAppearanceValues,
        ], JSON_THROW_ON_ERROR);

        [$rebindStatus] = http_request("http://127.0.0.1:$port/", 'GET', ['Host: notes.attacker.example']);
        check($rebindStatus === 200, 'Authenticated access unexpectedly depended on a trusted Host header.');
        [$crossSiteStatus] = http_request("http://127.0.0.1:$port/?download=1", 'GET', [
            'Sec-Fetch-Site: cross-site', 'Sec-Fetch-Mode: no-cors', 'Sec-Fetch-Dest: empty',
        ]);
        [$sameSiteStatus] = http_request("http://127.0.0.1:$port/", 'GET', [
            'Sec-Fetch-Site: same-site', 'Sec-Fetch-Mode: no-cors', 'Sec-Fetch-Dest: image',
        ]);
        [$externalNavigationStatus] = http_request("http://127.0.0.1:$port/", 'GET', [
            'Sec-Fetch-Site: cross-site', 'Sec-Fetch-Mode: navigate', 'Sec-Fetch-Dest: document',
        ]);
        check($crossSiteStatus === 403 && $sameSiteStatus === 403 && $externalNavigationStatus === 200,
            'Fetch Metadata did not reject browser subresources while allowing top-level navigation.');
        $crossSiteMutationHash = hash_file('sha256', $httpCopy);
        [$crossSiteMutationStatus] = http_request(
            "http://127.0.0.1:$port/?api=save",
            'POST',
            [...$authorizedJsonHeaders, 'Sec-Fetch-Site: cross-site', 'Sec-Fetch-Mode: cors', 'Sec-Fetch-Dest: empty'],
            $payload
        );
        check($crossSiteMutationStatus === 403 && hash_file('sha256', $httpCopy) === $crossSiteMutationHash,
            'A cross-origin browser mutation reached authentication, CSRF, or mutation work.');
        $rebindHash = hash_file('sha256', $httpCopy);
        [$rebindMutationStatus] = http_request("http://127.0.0.1:$port/?api=unknown", 'POST', ['Host: notes.attacker.example', "Cookie: $cookie", 'Content-Type: application/json', "X-CSRF-Token: $token"], $payload);
        check($rebindMutationStatus === 404 && hash_file('sha256', $httpCopy) === $rebindHash, 'Unknown authenticated action with an alternate Host reached mutation logic.');
        [$methodStatus, $methodHeaders] = http_request("http://127.0.0.1:$port/?api=save");
        check($methodStatus === 405 && header_value($methodHeaders, 'Allow') === 'POST', 'The API did not reject GET with Allow: POST.');
        [$appearanceMethodStatus, $appearanceMethodHeaders] = http_request("http://127.0.0.1:$port/?api=appearance");
        check($appearanceMethodStatus === 405 && header_value($appearanceMethodHeaders, 'Allow') === 'POST', 'The appearance API did not reject GET with Allow: POST.');
        [$pagePostStatus, $pagePostHeaders] = http_request("http://127.0.0.1:$port/", 'POST');
        check($pagePostStatus === 405 && header_value($pagePostHeaders, 'Allow') === 'GET, HEAD',
            'The page route accepted a non-reading method.');
        [$downloadPostStatus, $downloadPostHeaders] = http_request("http://127.0.0.1:$port/?download=1", 'POST');
        check($downloadPostStatus === 405 && header_value($downloadPostHeaders, 'Allow') === 'GET, HEAD',
            'The download route accepted a non-reading method.');
        [$ambiguousStatus] = http_request("http://127.0.0.1:$port/?api=save&download=1");
        check($ambiguousStatus === 400, 'An ambiguous API/download route was accepted.');

        [$missingStatus] = http_request("http://127.0.0.1:$port/?api=save", 'POST', ['Content-Type: application/json'], $payload);
        check($missingStatus === 403, 'A mutation without CSRF protection was accepted.');
        [$appearanceMissingStatus] = http_request("http://127.0.0.1:$port/?api=appearance", 'POST', ['Content-Type: application/json'], $appearancePayload);
        check($appearanceMissingStatus === 403, 'An appearance mutation without CSRF protection was accepted.');

        [$typeStatus] = http_request("http://127.0.0.1:$port/?api=save", 'POST', ["Cookie: $cookie", 'Content-Type: text/plain', "X-CSRF-Token: $token"], '{}');
        check($typeStatus === 415, 'The API accepted a non-JSON content type.');
        [$malformedStatus] = http_request("http://127.0.0.1:$port/?api=save", 'POST', $authorizedJsonHeaders, '{');
        check($malformedStatus === 400, 'The API accepted malformed JSON.');
        foreach (['"Bad' . "\xC0\xAF" . '"', '"\\ud800"'] as $invalidRequestTitle) {
            $invalidRequestPayload = str_replace('"HTTP note"', $invalidRequestTitle, $payload, $invalidRequestReplacements);
            $invalidRequestHash = hash_file('sha256', $httpCopy);
            [$invalidRequestStatus] = http_request(
                "http://127.0.0.1:$port/?api=save", 'POST', $authorizedJsonHeaders, $invalidRequestPayload
            );
            check($invalidRequestReplacements === 1 && $invalidRequestStatus === 400
                && hash_file('sha256', $httpCopy) === $invalidRequestHash,
                'The API accepted invalid UTF-8 or an unpaired JSON surrogate.');
        }
        $duplicatePayload = str_replace(
            '"title":"HTTP note"',
            '"title":"Earlier","t\\u0069tle":"HTTP note"',
            $payload,
            $duplicateRequestFields
        );
        $duplicateRequestHash = hash_file('sha256', $httpCopy);
        [$duplicateRequestStatus] = http_request(
            "http://127.0.0.1:$port/?api=save", 'POST', $authorizedJsonHeaders, $duplicatePayload
        );
        check($duplicateRequestFields === 1 && $duplicateRequestStatus === 400
            && hash_file('sha256', $httpCopy) === $duplicateRequestHash,
            'The API accepted escape-equivalent duplicate request fields.');
        $missingVersionPayload = json_decode($payload, true, 16, JSON_THROW_ON_ERROR);
        unset($missingVersionPayload['baseVersion']);
        $beforeMissingVersion = hash_file('sha256', $httpCopy);
        [$missingVersionStatus] = http_request(
            "http://127.0.0.1:$port/?api=save",
            'POST',
            $authorizedJsonHeaders,
            json_encode($missingVersionPayload, JSON_THROW_ON_ERROR)
        );
        check($missingVersionStatus === 428 && hash_file('sha256', $httpCopy) === $beforeMissingVersion,
            'A request missing its version precondition reached mutation logic.');
        $denseRequest = '{"dense":[' . implode(',', array_fill(0, 140, '0')) . ']}';
        [$denseStatus, $denseHeaders] = http_request(
            "http://127.0.0.1:$port/?api=save", 'POST', $authorizedJsonHeaders, $denseRequest
        );
        check($denseStatus === 413
            && str_contains((string) header_value($denseHeaders, 'Content-Type'), 'application/json')
            && strtolower((string) header_value($denseHeaders, 'X-Content-Type-Options')) === 'nosniff'
            && str_contains(strtolower((string) header_value($denseHeaders, 'Cache-Control')), 'no-store'),
            'A structurally dense request was not rejected with hardened JSON headers.');
        $deepRequest = str_repeat('{"x":', 18) . '0' . str_repeat('}', 18);
        [$deepStatus] = http_request(
            "http://127.0.0.1:$port/?api=save", 'POST', $authorizedJsonHeaders, $deepRequest
        );
        check($deepStatus === 413, 'An over-depth request reached JSON decoding or mutation.');
        [$listRootStatus] = http_request("http://127.0.0.1:$port/?api=save", 'POST', $authorizedJsonHeaders, '[]');
        check($listRootStatus === 400, 'The API accepted a top-level JSON list as an object.');
        $objectTags = json_encode([
            'id' => null, 'baseGeneration' => $httpDocument['generation'], 'baseRevision' => 0,
            'baseVersion' => null, 'createToken' => str_repeat('e', 32),
            'title' => 'Bad tags', 'body' => '', 'tags' => ['name' => 'not-a-list'],
        ], JSON_THROW_ON_ERROR);
        [$tagStatus] = http_request("http://127.0.0.1:$port/?api=save", 'POST', $authorizedJsonHeaders, $objectTags);
        check($tagStatus === 422, 'The API accepted associative tags.');
        $numericObjectTags = '{"id":null,"baseGeneration":"' . $httpDocument['generation']
            . '","baseRevision":0,"baseVersion":null,"createToken":"' . str_repeat('f', 32)
            . '","title":"Bad numeric tags","body":"","tags":{"0":"not-a-list"}}';
        [$numericTagStatus] = http_request("http://127.0.0.1:$port/?api=save", 'POST', $authorizedJsonHeaders, $numericObjectTags);
        check($numericTagStatus === 422, 'The API confused a numeric-key JSON object with a tag list.');

        $overRequestPayload = json_encode([
            'id' => null, 'baseGeneration' => $httpDocument['generation'], 'baseRevision' => 0,
            'baseVersion' => null, 'createToken' => str_repeat('7', 32),
            'title' => 'Over request limit', 'body' => str_repeat('x', 5 * 1024 * 1024), 'tags' => [],
        ], JSON_THROW_ON_ERROR);
        $overRequestHash = hash_file('sha256', $httpCopy);
        [$overRequestStatus] = http_request(
            "http://127.0.0.1:$port/?api=save", 'POST', $authorizedJsonHeaders, $overRequestPayload
        );
        check(strlen($overRequestPayload) > 5 * 1024 * 1024 && $overRequestStatus === 413
            && hash_file('sha256', $httpCopy) === $overRequestHash,
            'A request above the exact 5 MiB application limit reached mutation logic.');
        unset($overRequestPayload);

        $nearRequestPayload = json_encode([
            'id' => null, 'baseGeneration' => $httpDocument['generation'], 'baseRevision' => 0,
            'baseVersion' => null, 'createToken' => str_repeat('8', 32),
            'title' => 'Near request limit', 'body' => str_repeat('x', 4 * 1024 * 1024), 'tags' => [],
        ], JSON_THROW_ON_ERROR);
        [$nearRequestStatus, , $nearRequestBody] = http_request(
            "http://127.0.0.1:$port/?api=save", 'POST', $authorizedJsonHeaders, $nearRequestPayload
        );
        $nearRequestResult = json_decode($nearRequestBody, true, 16, JSON_THROW_ON_ERROR);
        check($nearRequestStatus === 200 && strlen($nearRequestResult['result']['body'] ?? '') === 4 * 1024 * 1024,
            'A large request inside the configured byte/structure envelope failed.');
        worker_command($httpCopy, 'delete', [
            'id' => $nearRequestResult['result']['id'],
            'baseRevision' => $nearRequestResult['result']['revision'],
        ]);
        unset($nearRequestPayload, $nearRequestBody, $nearRequestResult);

        $exactRequestPayload = json_encode([
            'id' => null, 'baseGeneration' => $httpDocument['generation'], 'baseRevision' => 0,
            'baseVersion' => null, 'createToken' => str_repeat('6', 32),
            'title' => 'Exact request limit', 'body' => '', 'tags' => [],
        ], JSON_THROW_ON_ERROR);
        $exactRequestPadding = 5 * 1024 * 1024 - strlen($exactRequestPayload);
        $exactRequestPayload = str_replace('"body":""', '"body":"' . str_repeat('x', $exactRequestPadding) . '"',
            $exactRequestPayload, $exactRequestReplacements);
        [$exactRequestStatus, , $exactRequestBody] = http_request(
            "http://127.0.0.1:$port/?api=save", 'POST', $authorizedJsonHeaders, $exactRequestPayload
        );
        $exactRequestResult = json_decode($exactRequestBody, true, 16, JSON_THROW_ON_ERROR);
        check($exactRequestReplacements === 1 && strlen($exactRequestPayload) === 5 * 1024 * 1024
            && $exactRequestStatus === 200
            && strlen($exactRequestResult['result']['body'] ?? '') === $exactRequestPadding,
            'A request at the exact 5 MiB application limit was rejected or changed.');
        worker_command($httpCopy, 'delete', [
            'id' => $exactRequestResult['result']['id'],
            'baseRevision' => $exactRequestResult['result']['revision'],
        ]);
        unset($exactRequestPayload, $exactRequestBody, $exactRequestResult);

        $lockHolder = start_worker($httpCopy, 'held-save', ['hold' => 2700000, 'title' => 'Lock holder']);
        usleep(150000);
        $busyPayload = json_encode([
            'id' => null, 'baseGeneration' => $httpDocument['generation'], 'baseRevision' => 0,
            'baseVersion' => null, 'createToken' => str_repeat('9', 32),
            'title' => 'Must time out', 'body' => '', 'tags' => [],
        ], JSON_THROW_ON_ERROR);
        $busyStarted = microtime(true);
        [$busyStatus, $busyHeaders] = http_request(
            "http://127.0.0.1:$port/?api=save", 'POST', $authorizedJsonHeaders, $busyPayload
        );
        $busyElapsed = microtime(true) - $busyStarted;
        check($busyStatus === 503 && header_value($busyHeaders, 'Retry-After') === '1'
            && $busyElapsed >= 1.7 && $busyElapsed < 3.5
            && glob($httpRoot . '/.piplet-tmp-*.php') === [],
            'Lock contention did not terminate at the shared deadline with a retryable response.');
        $heldResult = finish_worker($lockHolder)['value'];
        worker_command($httpCopy, 'delete', [
            'id' => $heldResult['result']['id'], 'baseRevision' => $heldResult['result']['revision'],
        ]);

        $beforeHttpAppearanceRevision = worker_command($httpCopy, 'read')['revision'];
        [$appearanceStatus, , $appearanceBody] = http_request("http://127.0.0.1:$port/?api=appearance", 'POST', $authorizedJsonHeaders, $appearancePayload);
        $appearanceResponse = json_decode($appearanceBody, true, 16, JSON_THROW_ON_ERROR);
        $expectedHttpAppearance = $appearanceResponse['result'] ?? [];
        check($appearanceStatus === 200
            && ($expectedHttpAppearance['revision'] ?? null) === $beforeHttpAppearanceRevision + 1
            && array_diff_key($expectedHttpAppearance, ['revision' => true, 'version' => true]) === $httpAppearanceValues
            && $appearanceResponse['documentRevision'] === $beforeHttpAppearanceRevision + 1,
            'A valid HTTP appearance save failed.');

        [$appearanceGetStatus, , $appearancePage] = http_request("http://127.0.0.1:$port/", 'GET', ["Cookie: $cookie"]);
        check($appearanceGetStatus === 200, 'The app failed after an HTTP appearance save.');
        check(preg_match('/<html\b[^>]*>/i', $appearancePage, $appearanceHtmlMatch) === 1, 'The saved page is missing its html element.');
        $appearanceHtml = $appearanceHtmlMatch[0];
        foreach (array_diff_key($httpAppearanceValues, ['customCss' => true]) as $name => $value) {
            check(str_contains($appearanceHtml, 'data-' . $name . '="' . $value . '"'), "The saved $name appearance was not rendered on reload.");
        }
        check(str_contains($appearancePage, '<style nonce=') && str_contains($appearancePage, 'id="piplet-custom-style"></style>'), 'The custom CSS module is not structurally empty.');
        check(!str_contains($appearancePage, '</style><script id="css-pwn">'), 'Custom CSS was interpolated into live HTML.');
        $appearanceState = page_state($appearancePage);
        check($appearanceState['appearance']['customCss'] === $hostileCss && $appearanceState['safeAppearance'] === false, 'Custom CSS did not round-trip through the inert state block.');
        [$safeStatus, , $safePage] = http_request("http://127.0.0.1:$port/?safe=1", 'GET', ["Cookie: $cookie"]);
        check($safeStatus === 200 && str_contains($safePage, 'Custom CSS is off for this page.'), 'Safe appearance mode is unavailable.');
        $safeState = page_state($safePage);
        check($safeState['safeAppearance'] === true && $safeState['appearance']['customCss'] === $hostileCss, 'Safe mode erased the editable CSS instead of only disabling it.');
        $appearanceHash = hash_file('sha256', $httpCopy);
        [$appearanceStaleStatus, , $appearanceStaleBody] = http_request("http://127.0.0.1:$port/?api=appearance", 'POST', $authorizedJsonHeaders, $appearancePayload);
        $appearanceStaleResponse = json_decode($appearanceStaleBody, true, 16, JSON_THROW_ON_ERROR);
        check($appearanceStaleStatus === 409 && $appearanceStaleResponse['current'] === $expectedHttpAppearance, 'The appearance API did not return the current record for a stale save.');
        check(hash_file('sha256', $httpCopy) === $appearanceHash, 'A stale HTTP appearance save changed the file.');

        [$postStatus, $postHeaders, $postBody] = http_request("http://127.0.0.1:$port/?api=save", 'POST', $authorizedJsonHeaders, $payload);
        check($postStatus === 200, 'A valid HTTP save failed.');
        check(str_contains((string) header_value($postHeaders, 'Content-Type'), 'application/json')
            && strtolower((string) header_value($postHeaders, 'X-Content-Type-Options')) === 'nosniff'
            && str_contains(strtolower((string) header_value($postHeaders, 'Cache-Control')), 'no-store'),
            'Successful API JSON lost its no-sniff or no-store boundary.');
        $post = json_decode($postBody, true, 16, JSON_THROW_ON_ERROR);
        check($post['result']['title'] === 'HTTP note', 'The HTTP response returned the wrong note.');
        $postHash = hash_file('sha256', $httpCopy);
        clearstatcache(true, $httpCopy);
        $postInode = fileinode($httpCopy);
        [$repeatStatus, , $repeatBody] = http_request("http://127.0.0.1:$port/?api=save", 'POST', $authorizedJsonHeaders, $payload);
        $repeat = json_decode($repeatBody, true, 16, JSON_THROW_ON_ERROR);
        clearstatcache(true, $httpCopy);
        check($repeatStatus === 200 && $repeat['result']['id'] === $post['result']['id'], 'A lost-response create retry produced another HTTP note.');
        check(hash_file('sha256', $httpCopy) === $postHash && fileinode($httpCopy) === $postInode, 'A lost-response create retry rewrote the HTTP snapshot.');

        [$secondGetStatus, , $secondPage] = http_request("http://127.0.0.1:$port/", 'GET', ["Cookie: $cookie"]);
        check($secondGetStatus === 200, 'The app failed after an HTTP save.');
        check(!str_contains($secondPage, '<img src=x onerror=alert(1)>'), 'Stored markup was embedded as live HTML.');
        $secondState = page_state($secondPage);
        check(str_contains($secondState['document']['notes'][$post['result']['id']]['body'], '<img src=x onerror=alert(1)>'),
            'Stored markup did not round-trip through base64 boot data.');

        [$downloadStatus, $downloadHeaders, $downloadBody] = http_request("http://127.0.0.1:$port/?download=1", 'GET', ["Cookie: $cookie"]);
        check($downloadStatus === 200 && str_starts_with($downloadBody, '<?php'), 'Snapshot download failed.');
        check(header_value($downloadHeaders, 'Content-Type') === 'application/octet-stream'
            && strtolower((string) header_value($downloadHeaders, 'X-Content-Type-Options')) === 'nosniff'
            && strtolower((string) header_value($downloadHeaders, 'Cache-Control')) === 'no-store'
            && header_value($downloadHeaders, 'Content-Disposition') === 'attachment; filename="wiki-piplet-snapshot.php"',
            'Snapshot download headers permit sniffing or an ambiguous filename.');
        check((int) header_value($downloadHeaders, 'Content-Length') === strlen($downloadBody), 'Snapshot Content-Length did not match its inode.');
        check($downloadBody === file_get_contents($httpCopy), 'The downloaded snapshot was not an exact restorable copy.');
        [$headDownloadStatus, $headDownloadHeaders, $headDownloadBody] = http_request(
            "http://127.0.0.1:$port/?download=1", 'HEAD', ["Cookie: $cookie"]
        );
        check($headDownloadStatus === 200 && $headDownloadBody === ''
            && (int) header_value($headDownloadHeaders, 'Content-Length') === strlen($downloadBody),
            'HEAD download did not return exact snapshot headers without a body.');
        $downloadCopy = $httpRoot . '/downloaded.php';
        check(file_put_contents($downloadCopy, $downloadBody) === strlen($downloadBody), 'Could not materialize the downloaded snapshot test.');
        $downloadLint = run_bounded_command([PHP_BINARY, '-l', $downloadCopy]);
        check($downloadLint['status'] === 0 && worker_command($downloadCopy, 'summary')['notes'] === 2, 'The downloaded snapshot was not runnable and decodable.');

        $seeded = worker_command($httpCopy, 'seed-notes', ['count' => 41]);
        check($seeded['result']['notes'] === 43, 'Could not seed the bounded library/story browser fixture.');

        $chrome = chrome_binary();
        if ($chrome !== null) {
            try {
                try {
                    $browserResult = run_browser_scenario(
                        $chrome,
                        "$browserBase/?__browser=state",
                        $temporaryRoot . '/chrome-profile',
                        $browserSignal,
                        'state'
                    );
                } catch (Throwable $error) {
                    $progress = @file_get_contents($httpRoot . '/browser-progress.log') ?: 'no progress';
                    throw new RuntimeException($error->getMessage() . "\nProgress:\n$progress", 0, $error);
                }
            } finally {
                @chmod($httpRoot, 0700);
            }
            $browserProgress = @file_get_contents($httpRoot . '/browser-progress.log') ?: 'no progress';
            check($browserResult === 'PASS', "Browser regression failed: $browserResult\nProgress:\n$browserProgress");
            $longPathResult = run_browser_scenario(
                $chrome,
                "$browserBase/$longPathSegment/index.php?__browser=long-path",
                $temporaryRoot . '/chrome-long-path-profile',
                $longPathSignal,
                'long-path'
            );
            $longPathProgress = @file_get_contents($longPathSignal) ?: 'no progress';
            check($longPathResult === 'PASS',
                "Long-path browser regression failed: $longPathResult\nProgress:\n$longPathProgress");
            $safeAppearance = worker_command($httpCopy, 'current-appearance');
            $safeAppearanceValues = array_diff_key($safeAppearance, ['revision' => true, 'version' => true]);
            $safeAppearanceValues['customCss'] = 'html { display: none !important; }';
            worker_command($httpCopy, 'appearance', ['baseRevision' => $safeAppearance['revision'], 'appearance' => $safeAppearanceValues]);
            check(file_put_contents($browserSignal, '') === 0, 'Could not reset the safe-mode browser completion signal.');
            $safeResult = run_browser_scenario(
                $chrome,
                "$browserBase/?safe=1&__browser=safe",
                $temporaryRoot . '/chrome-safe-profile',
                $browserSignal,
                'safe'
            );
            $safeProgress = @file_get_contents($httpRoot . '/browser-progress.log') ?: 'no progress';
            check($safeResult === 'PASS', "Safe-mode browser regression failed: $safeResult\nProgress:\n$safeProgress");
            check(worker_command($httpCopy, 'current-appearance')['customCss'] === '', 'Safe mode did not clear the broken stylesheet.');
            check(file_put_contents($browserSignal, '') === 0, 'Could not reset the mobile browser completion signal.');
            try {
                $mobileResult = run_browser_scenario(
                    $chrome,
                    "$browserBase/?__browser=mobile",
                    $temporaryRoot . '/chrome-mobile-profile',
                    $browserSignal,
                    'mobile',
                    '720,900'
                );
            } catch (Throwable $error) {
                $progress = @file_get_contents($httpRoot . '/browser-progress.log') ?: 'no progress';
                throw new RuntimeException($error->getMessage() . "\nMobile progress:\n$progress", 0, $error);
            }
            $mobileProgress = @file_get_contents($httpRoot . '/browser-progress.log') ?: 'no progress';
            check($mobileResult === 'PASS', "Mobile browser regression failed: $mobileResult\nProgress:\n$mobileProgress");
        } else {
            check(getenv('PIPLET_REQUIRE_CHROME') !== '1',
                'Chrome is required for this run, but no Chrome or Chromium executable was found.');
            check(str_contains($httpSource, 'if (noteSaving || editing !== editor) return;'), 'The browser save-flight guard is missing.');
            check(substr_count($httpSource, "location.pathname.replace(/^\\/+/, '/')") >= 2
                && !str_contains($httpSource, '`${location.pathname}${location.search}'),
                'History updates can reinterpret a double-leading-slash path as another origin.');
            check(str_contains($httpSource, 'return renderPlainBody(body, preview);'), 'The bounded-renderer fallback is missing.');
            check(str_contains($httpSource, 'recoverReadOnlyDraft'), 'The read-only draft recovery guard is missing.');
            check(str_contains($httpSource, 'const maxOpenNotes = 20;') && str_contains($httpSource, 'const maxLibraryNotes = 40;'), 'The aggregate rendering guards are missing.');
            check(str_contains($httpSource, "element('nav', 'note-backlinks')")
                && str_contains($httpSource, "'Linked from'")
                && str_contains($httpSource, 'id !== source.id'),
                'The backlinks row or its self-link guard is missing.');
            check(str_contains($httpSource, '`${draftPrefix}v2:${source.draftId}`')
                && str_contains($httpSource, "typeof expectedRaw === 'string' && raw !== expectedRaw")
                && str_contains($httpSource, 'stored?.draftId !== draftId')
                && str_contains($httpSource, 'removeStoredDraft(source.recoveryKey, source.recoveryRaw, source.draftId)')
                && !str_contains($httpSource, 'previousDraftKeys(source)'),
                'The immutable random-key recovery boundary is missing.');
            check(str_contains($httpSource, 'article.id = editor.id === null ? \'piplet-composer\' : `piplet-note-${editor.id}`;'), 'Saved notes and the composer no longer have separate DOM namespaces.');
            check(str_contains($httpSource, "els['drawer-shade'].tabIndex = -1;") && !str_contains($httpSource, ", els['drawer-shade']];"), 'The modal drawer focus guard includes its outside backdrop.');
            check(str_contains($httpSource, "boot.safeAppearance ? '' : values.customCss"), 'The safe-mode custom CSS guard is missing.');
            fwrite(STDOUT, "skip — Chrome unavailable; dynamic browser regressions were not run\n");
        }

        $beforeDeleteDocument = worker_command($httpCopy, 'read');
        $beforeDeleteNote = $beforeDeleteDocument['notes'][$post['result']['id']] ?? null;
        check(is_array($beforeDeleteNote), 'The browser scenarios lost the HTTP note used for delete testing.');
        $deletePayload = json_encode([
            'id' => $beforeDeleteNote['id'],
            'baseGeneration' => $beforeDeleteDocument['generation'],
            'baseRevision' => $beforeDeleteNote['revision'],
            'baseVersion' => $beforeDeleteNote['version'],
        ], JSON_THROW_ON_ERROR);
        [$deleteStatus] = http_request("http://127.0.0.1:$port/?api=delete", 'POST', $authorizedJsonHeaders, $deletePayload);
        check($deleteStatus === 200, 'A current HTTP delete failed.');
        $hashAfterDelete = hash_file('sha256', $httpCopy);
        $stalePayload = json_encode([
            'id' => $beforeDeleteNote['id'],
            'baseGeneration' => $beforeDeleteDocument['generation'],
            'baseRevision' => $beforeDeleteNote['revision'],
            'baseVersion' => $beforeDeleteNote['version'],
            'createToken' => null,
            'title' => 'Stale after delete',
            'body' => 'draft',
            'tags' => [],
        ], JSON_THROW_ON_ERROR);
        [$staleStatus, , $staleBody] = http_request("http://127.0.0.1:$port/?api=save", 'POST', $authorizedJsonHeaders, $stalePayload);
        $staleResponse = json_decode($staleBody, true, 16, JSON_THROW_ON_ERROR);
        check($staleStatus === 409 && array_key_exists('current', $staleResponse) && $staleResponse['current'] === null, 'A stale edit after deletion did not return 409/current:null.');
        check(hash_file('sha256', $httpCopy) === $hashAfterDelete, 'A stale edit after deletion changed the file.');
    } finally {
        $defaultHttpHeaders = [];
        @chmod($httpRoot, 0700);
        stop_test_server($server, 'HTTP');
    }

    $closedCopy = fixture_copy($temporaryRoot, $source, 'closed-local', 'Could not create the deny-by-default fixture.');
    $closedRoot = dirname($closedCopy);
    [$closedServer, $closedPort] = start_test_server($closedRoot, test_environment(), 'Could not start the deny-by-default server.');
    try {
        [$closedStatus] = http_request("http://127.0.0.1:$closedPort/", 'GET', ['Host: localhost', 'X-Forwarded-For: 127.0.0.1']);
        check($closedStatus === 403, 'HTTP access was enabled without a configured password.');
    } finally {
        stop_test_server($closedServer, 'deny-by-default');
    }

    $authCopy = fixture_copy($temporaryRoot, $source, 'auth', 'Could not create the authentication fixture.');
    $authRoot = dirname($authCopy);
    [$authServer, $authPort] = start_test_server(
        $authRoot,
        test_environment(['PIPLET_PASSWORD' => 'correct horse battery staple', 'PIPLET_PUBLIC_HTTPS' => '1']),
        'Could not start the authenticated test server.'
    );
    try {
        $authHash = hash_file('sha256', $authRoot . '/index.php');
        [$anonymousStatus, $anonymousHeaders] = http_request("http://127.0.0.1:$authPort/");
        check($anonymousStatus === 401 && header_value($anonymousHeaders, 'WWW-Authenticate') !== null, 'Password mode did not challenge an anonymous request.');
        [$anonymousDownloadStatus] = http_request("http://127.0.0.1:$authPort/?download=1");
        [$anonymousApiStatus] = http_request("http://127.0.0.1:$authPort/?api=save", 'POST', ['Content-Type: application/json'], '{}');
        check($anonymousDownloadStatus === 401 && $anonymousApiStatus === 401, 'Authentication did not protect download and mutation routes.');
        [$wrongStatus] = http_request("http://127.0.0.1:$authPort/", 'GET', ['Authorization: Basic ' . base64_encode('writer:wrong')]);
        check($wrongStatus === 401, 'Password mode accepted a wrong password.');
        [$wrongApiStatus] = http_request("http://127.0.0.1:$authPort/?api=delete", 'POST', ['Authorization: Basic ' . base64_encode('writer:wrong'), 'Content-Type: application/json'], '{}');
        check($wrongApiStatus === 401 && hash_file('sha256', $authRoot . '/index.php') === $authHash, 'Wrong-password API access changed the protected piplet.');
        [$correctStatus, $correctHeaders] = http_request("http://127.0.0.1:$authPort/", 'GET', ['Authorization: Basic ' . base64_encode('writer:correct horse battery staple')]);
        $secureCookie = (string) header_value($correctHeaders, 'Set-Cookie');
        check($correctStatus === 200 && preg_match('/(?:^|;)\s*Secure(?:;|$)/i', $secureCookie) === 1,
            'Password mode rejected the configured password or failed to mark a public-HTTPS cookie Secure.');
        check(preg_match('/^([^=]+)=([^;]+)/', $secureCookie, $secureCookieParts) === 1,
            'Could not parse the public-HTTPS CSRF cookie.');
        [, $reissuedHeaders] = http_request("http://127.0.0.1:$authPort/", 'GET', [
            'Authorization: Basic ' . base64_encode('writer:correct horse battery staple'),
            'Cookie: ' . $secureCookieParts[1] . '=' . $secureCookieParts[2],
        ]);
        check(preg_match('/(?:^|;)\s*Secure(?:;|$)/i', (string) header_value($reissuedHeaders, 'Set-Cookie')) === 1,
            'An existing CSRF cookie was not reissued with the public-HTTPS policy.');
        [$lowercaseStatus] = http_request("http://127.0.0.1:$authPort/", 'GET', ['Authorization: basic ' . base64_encode('writer:correct horse battery staple')]);
        check($lowercaseStatus === 200, 'The fallback parser treated the Basic authentication scheme as case-sensitive.');
        [$spacedStatus] = http_request("http://127.0.0.1:$authPort/", 'GET', ['Authorization: Basic  ' . base64_encode('writer:correct horse battery staple')]);
        check($spacedStatus === 200, 'The fallback parser rejected valid repeated authentication whitespace.');
    } finally {
        stop_test_server($authServer, 'authenticated');
    }

    $runnerWebRoot = $temporaryRoot . '/runner-web';
    check(mkdir($runnerWebRoot, 0700) && copy(__FILE__, $runnerWebRoot . '/run.php'),
        'Could not create the isolated runner-exposure fixture.');
    [$runnerServer, $runnerPort] = start_test_server(
        $runnerWebRoot,
        test_environment(),
        'Could not start the runner-exposure test server.'
    );
    try {
        [$runnerStatus, , $runnerBody] = http_request(
            "http://127.0.0.1:$runnerPort/run.php?--worker=../wiki-piplet.php"
        );
        check($runnerStatus === 404 && trim($runnerBody) === 'Not found.'
            && hash_file('sha256', __FILE__) === $runnerHashBefore
            && hash_file('sha256', $source) === $sourceHashBefore,
            'The CLI test runner exposed its worker surface over HTTP.');
    } finally {
        stop_test_server($runnerServer, 'runner-exposure');
    }

    check(hash_file('sha256', $source) === $sourceHashBefore, 'The test runner changed the source piplet.');
    $successMessage = sprintf("ok — %d assertions; source file untouched; 7+ MiB cycle %.2fs (worker peak %.1f MiB)\n", $assertions, $largeElapsed, $largePeak / 1024 / 1024);
} catch (Throwable $error) {
    fwrite(STDERR, "not ok — {$error->getMessage()} ({$error->getFile()}:{$error->getLine()})\n");
    $exitStatus = 1;
} finally {
    if (!stop_live_workers()) {
        fwrite(STDERR, "not ok — a worker process could not be terminated\n");
        $exitStatus = 1;
    }
    if ($temporaryRootOwned) {
        $currentRoot = @lstat($temporaryRoot);
        $sameRoot = $currentRoot === false || (
            is_array($temporaryRootIdentity)
            && $currentRoot['dev'] === $temporaryRootIdentity['dev']
            && $currentRoot['ino'] === $temporaryRootIdentity['ino']
            && (((int) $currentRoot['mode']) & 0170000) === 0040000
        );
        if ($sameRoot && $currentRoot !== false) remove_tree($temporaryRoot);
        if (!$sameRoot || @lstat($temporaryRoot) !== false) {
            fwrite(STDERR, "not ok — owned test fixtures could not be safely removed\n");
            $exitStatus = 1;
        }
    }
    if ($sourceHashBefore !== false && hash_file('sha256', $source) !== $sourceHashBefore) {
        fwrite(STDERR, "not ok — source piplet changed during tests\n");
        $exitStatus = 1;
    }
}
if ($exitStatus === 0 && is_string($successMessage)) fwrite(STDOUT, $successMessage);
exit($exitStatus);
