<?php
declare(strict_types=1);

/*
 * piplet — a tiny, self-contained wiki.
 *
 * Everything before __halt_compiler() is the application. Everything after it
 * is the data. A save copies the application bytes unchanged, appends fresh
 * JSON, and atomically replaces this file.
 *
 * Runtime: PHP 8.1+, a local POSIX filesystem, and write access to this file's
 * directory. Every HTTP deployment must set PIPLET_PASSWORD.
 */

const PIPLET_DATA_HEADER_V1 = "\nPIPLET-DATA/1\n";
const PIPLET_DATA_HEADER = "\nPIPLET-DATA/2\n";
const PIPLET_FORMAT = 2;
const PIPLET_MAX_FILE_BYTES = 8 * 1024 * 1024;
const PIPLET_MAX_REQUEST_BYTES = 5 * 1024 * 1024;
const PIPLET_MAX_REQUEST_STRUCTURE = 128;
const PIPLET_MAX_REQUEST_CONTAINERS = 32;
const PIPLET_MAX_REQUEST_JSON_DEPTH = 16;
const PIPLET_MAX_STORED_STRUCTURE = 100000;
const PIPLET_MAX_STORED_CONTAINERS = 8192;
const PIPLET_MAX_STORED_JSON_DEPTH = 32;
const PIPLET_MAX_NOTES = 2000;
const PIPLET_MAX_TOTAL_TAGS = 24000;
const PIPLET_MAX_REVISION = 9007199254740991;
const PIPLET_WRITE_CHUNK_BYTES = 65536;
const PIPLET_LOCK_TIMEOUT_NS = 2000000000;
const PIPLET_MAX_BOOT_JSON_BYTES = 9 * 1024 * 1024;
const PIPLET_MAX_BOOT_BASE64_BYTES = 12 * 1024 * 1024;
const PIPLET_MAX_TITLE_BYTES = 240;
const PIPLET_MAX_TAG_BYTES = 48;
const PIPLET_MAX_TAGS = 12;
const PIPLET_MAX_CUSTOM_CSS_BYTES = 32 * 1024;
const PIPLET_JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
    | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;
const PIPLET_APPEARANCE_DEFAULTS = [
    'palette' => 'quiet',
    'font' => 'editorial',
    'scale' => 'comfortable',
    'measure' => 'balanced',
];
const PIPLET_APPEARANCE_OPTIONS = [
    'palette' => ['quiet', 'ocean', 'plum', 'mono'],
    'font' => ['editorial', 'modern', 'typewriter'],
    'scale' => ['compact', 'comfortable', 'large'],
    'measure' => ['focused', 'balanced', 'wide'],
];
const PIPLET_LEGACY_TOKEN_RULES = [
    '--story-width' => ['rem', 32, 80],
    '--measure' => ['ch', 42, 90],
    '--sidebar' => ['rem', 12, 28],
    '--radius' => ['px', 0, 24],
    '--radius-sm' => ['px', 0, 16],
    '--copy-size' => ['rem', 0.8, 1.6],
    '--title-size' => ['rem', 1.5, 4],
];

// A half-written temporary copy must never behave as the live application.
if (str_contains(basename(__FILE__), '.piplet-tmp-')) {
    http_response_code(503);
    exit('Save in progress.');
}

final class PipletConflict extends RuntimeException
{
    public function __construct(
        public readonly ?array $current,
        string $message = 'This note changed after you opened it.',
        public readonly ?string $generation = null
    )
    {
        parent::__construct($message);
    }
}

final class PipletHttpError extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}

final class PipletUnchanged
{
    public function __construct(public readonly array $result)
    {
    }
}

function piplet_path(): string
{
    static $path;
    if ($path === null) {
        $path = realpath(__FILE__);
        if ($path === false) {
            throw new RuntimeException('Cannot resolve the application file.');
        }
    }
    return $path;
}

function piplet_require_runtime(): void
{
    if (PHP_INT_SIZE < 8) {
        throw new RuntimeException('piplet requires a 64-bit PHP build.');
    }
}

function piplet_code_offset(): int
{
    return __COMPILER_HALT_OFFSET__;
}

function piplet_same_inode(array|false $first, array|false $second): bool
{
    return $first !== false && $second !== false
        && $first['dev'] === $second['dev'] && $first['ino'] === $second['ino'];
}

function piplet_is_record(mixed $value): bool
{
    return is_array($value) && !array_is_list($value);
}

function piplet_is_note_id(mixed $value): bool
{
    return is_string($value) && !ctype_digit($value)
        && preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D', $value) === 1;
}

function piplet_is_version(mixed $value): bool
{
    return is_string($value) && preg_match('/^[a-f0-9]{32}$/D', $value) === 1;
}

function piplet_version(): string
{
    return bin2hex(random_bytes(16));
}

/** Cheap allocation guard; json_decode remains the JSON grammar authority. */
function piplet_json_within_budget(string $json, int $maxStructure, int $maxContainers, int $maxDepth): bool
{
    $structure = 0;
    $containers = 0;
    $depth = 0;
    $inString = false;
    $escaped = false;
    $length = strlen($json);

    for ($index = 0; $index < $length; $index++) {
        $character = $json[$index];
        if ($inString) {
            if ($escaped) {
                $escaped = false;
            } elseif ($character === '\\') {
                $escaped = true;
            } elseif ($character === '"') {
                $inString = false;
            }
            continue;
        }
        if ($character === '"') {
            $inString = true;
            continue;
        }
        if (!str_contains('{}[],:', $character)) {
            continue;
        }
        if (++$structure > $maxStructure) {
            return false;
        }
        if ($character === '{' || $character === '[') {
            if (++$containers > $maxContainers || ++$depth > $maxDepth) {
                return false;
            }
        } elseif ($character === '}' || $character === ']') {
            $depth--;
        }
    }
    return true;
}

/** Reject object members that JSON decoding would silently collapse. */
function piplet_json_members_are_unique(string $json): bool
{
    $stack = [];
    $length = strlen($json);
    for ($index = 0; $index < $length; $index++) {
        $character = $json[$index];
        if ($character === '{') {
            $stack[] = [];
            continue;
        }
        if ($character === '[') {
            $stack[] = null;
            continue;
        }
        if ($character === '}' || $character === ']') {
            if ($stack !== []) array_pop($stack);
            continue;
        }
        if ($character !== '"') continue;

        $end = $index + 1;
        while ($end < $length) {
            if ($json[$end] === '\\') {
                $end += 2;
                continue;
            }
            if ($json[$end] === '"') break;
            $end++;
        }
        if ($end >= $length) return true; // The complete decoder reports malformed JSON.
        $after = $end + 1;
        while ($after < $length && str_contains(" \t\r\n", $json[$after])) $after++;
        $slot = array_key_last($stack);
        if ($after < $length && $json[$after] === ':' && $slot !== null && is_array($stack[$slot])) {
            try {
                $member = json_decode(substr($json, $index, $end - $index + 1), false, 2, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return true; // The complete decoder reports malformed strings.
            }
            $lookup = "\0" . $member; // Prevent numeric-string conversion by PHP arrays.
            if (isset($stack[$slot][$lookup])) return false;
            $stack[$slot][$lookup] = true;
        }
        $index = $end;
    }
    return true;
}

/** Reject JSON numbers PHP would change when it encodes the next snapshot. */
function piplet_json_numbers_are_lossless(string $json): bool
{
    $length = strlen($json);
    $inString = false;
    $escaped = false;
    for ($index = 0; $index < $length; $index++) {
        $character = $json[$index];
        if ($inString) {
            if ($escaped) $escaped = false;
            elseif ($character === '\\') $escaped = true;
            elseif ($character === '"') $inString = false;
            continue;
        }
        if ($character === '"') {
            $inString = true;
            continue;
        }
        if ($character !== '-' && ($character < '0' || $character > '9')) continue;

        $end = $index + 1;
        while ($end < $length && str_contains('0123456789+-.eE', $json[$end])) $end++;
        $number = substr($json, $index, $end - $index);
        try {
            $decoded = json_decode($number, false, 2, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $index = $end - 1;
            continue; // json_decode() of the complete document reports the grammar error.
        }
        if (is_int($decoded) || is_float($decoded)) {
            try {
                if (json_encode($decoded, PIPLET_JSON_FLAGS) !== $number) return false;
            } catch (JsonException) {
                return false;
            }
        }
        $index = $end - 1;
    }
    return true;
}

/** Convert only the schema-owned object envelopes; unknown JSON stays typed. */
function piplet_document_from_json(stdClass $decoded): array
{
    $document = get_object_vars($decoded);
    if (($document['appearance'] ?? null) instanceof stdClass) {
        $document['appearance'] = get_object_vars($document['appearance']);
    }
    if (($document['notes'] ?? null) instanceof stdClass) {
        $document['notes'] = get_object_vars($document['notes']);
    }
    if (is_array($document['notes'] ?? null)) {
        foreach ($document['notes'] as $id => $note) {
            if ($note instanceof stdClass) $document['notes'][$id] = get_object_vars($note);
        }
    }
    return $document;
}

function piplet_read_stream($handle): string
{
    if (!rewind($handle)) {
        throw new RuntimeException('Cannot rewind the application file.');
    }

    $raw = stream_get_contents($handle, PIPLET_MAX_FILE_BYTES + 1);
    if ($raw === false) {
        throw new RuntimeException('Cannot read the application file.');
    }
    if (strlen($raw) > PIPLET_MAX_FILE_BYTES) {
        throw new RuntimeException('The piplet is larger than its configured limit.');
    }
    return $raw;
}

function piplet_decode_at(string $raw, int $offset): array
{
    $length = strlen($raw);
    if ($length < $offset + strlen(PIPLET_DATA_HEADER_V1)) {
        throw new RuntimeException('The embedded data section is missing.');
    }

    if (substr_compare($raw, PIPLET_DATA_HEADER, $offset, strlen(PIPLET_DATA_HEADER)) === 0) {
        $format = 2;
        $jsonStart = $offset + strlen(PIPLET_DATA_HEADER);
    } elseif (substr_compare($raw, PIPLET_DATA_HEADER_V1, $offset, strlen(PIPLET_DATA_HEADER_V1)) === 0) {
        $format = 1;
        $jsonStart = $offset + strlen(PIPLET_DATA_HEADER_V1);
    } else {
        throw new RuntimeException('The embedded data marker is invalid.');
    }

    $jsonEnd = $length;
    while ($jsonEnd > $jsonStart && ($raw[$jsonEnd - 1] === "\n" || $raw[$jsonEnd - 1] === "\r")) {
        $jsonEnd--;
    }
    $json = substr($raw, $jsonStart, $jsonEnd - $jsonStart);
    if ($json === '') {
        throw new RuntimeException('The embedded data section is empty.');
    }
    if (!piplet_json_within_budget(
        $json,
        PIPLET_MAX_STORED_STRUCTURE,
        PIPLET_MAX_STORED_CONTAINERS,
        PIPLET_MAX_STORED_JSON_DEPTH
    )) {
        throw new RuntimeException('The embedded data is too structurally complex.');
    }
    if (!piplet_json_numbers_are_lossless($json)) {
        throw new RuntimeException('The embedded data contains a number PHP cannot preserve exactly.');
    }
    if (!piplet_json_members_are_unique($json)) {
        throw new RuntimeException('The embedded data contains duplicate object member names.');
    }

    try {
        $decoded = json_decode($json, false, PIPLET_MAX_STORED_JSON_DEPTH + 1, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new RuntimeException('The embedded data is not valid JSON.', 0, $error);
    }

    if (!$decoded instanceof stdClass) {
        throw new RuntimeException('The embedded data must be a JSON object.');
    }
    $document = piplet_document_from_json($decoded);
    if (($document['format'] ?? null) !== $format) {
        throw new RuntimeException('The embedded marker and format disagree.');
    }
    if ($format === 1) {
        piplet_validate_legacy_document($document);
        $document = piplet_upgrade_legacy_document($document, $json);
    }
    piplet_validate_document($document);
    return $document;
}

function piplet_decode(string $raw): array
{
    return piplet_decode_at($raw, __COMPILER_HALT_OFFSET__);
}

function piplet_read(): array
{
    piplet_require_runtime();
    $path = piplet_path();
    $handle = @fopen($path, 'rb');
    $stat = $handle === false ? false : fstat($handle);
    if ($handle === false || $stat === false || $stat['size'] > PIPLET_MAX_FILE_BYTES) {
        if (is_resource($handle)) fclose($handle);
        throw new RuntimeException('The piplet cannot be read or is too large.');
    }
    try {
        return piplet_decode(piplet_read_stream($handle));
    } finally {
        fclose($handle);
    }
}

function piplet_read_snapshot_data(string $path): array
{
    piplet_require_runtime();
    $linkStat = @lstat($path);
    if ($linkStat === false || (((int) ($linkStat['mode'] ?? 0)) & 0170000) !== 0100000) {
        throw new RuntimeException('The import source must be a regular, non-symlink file.');
    }
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('The import source cannot be opened.');
    }
    try {
        $stat = fstat($handle);
        if (!piplet_same_inode($linkStat, $stat)
            || (((int) ($stat['mode'] ?? 0)) & 0170000) !== 0100000
            || ($stat['size'] ?? PIPLET_MAX_FILE_BYTES + 1) > PIPLET_MAX_FILE_BYTES) {
            throw new RuntimeException('The import source changed or is too large.');
        }
        $raw = piplet_read_stream($handle);
    } finally {
        fclose($handle);
    }

    $offset = false;
    foreach ([PIPLET_DATA_HEADER_V1, PIPLET_DATA_HEADER] as $header) {
        $found = strrpos($raw, $header);
        if ($found !== false && ($offset === false || $found > $offset)) $offset = $found;
    }
    if ($offset === false) {
        throw new RuntimeException('The import source does not contain a supported data marker.');
    }
    return piplet_decode_at($raw, $offset);
}

function piplet_validate_revision(mixed $revision, int $minimum = 0): bool
{
    return is_int($revision) && $revision >= $minimum && $revision <= PIPLET_MAX_REVISION;
}

function piplet_validate_note_collection(array $document, bool $versions): void
{
    if (!isset($document['notes']) || !is_array($document['notes']) || count($document['notes']) > PIPLET_MAX_NOTES) {
        throw new RuntimeException('Invalid note collection.');
    }

    $createTokens = [];
    $totalTags = 0;
    foreach ($document['notes'] as $id => $note) {
        if (!piplet_is_note_id($id)) {
            throw new RuntimeException('Invalid note identifier.');
        }
        if (!piplet_is_record($note) || ($note['id'] ?? null) !== $id) {
            throw new RuntimeException('Invalid note record.');
        }
        foreach (['title', 'body', 'created', 'updated'] as $field) {
            if (!isset($note[$field]) || !is_string($note[$field]) || preg_match('//u', $note[$field]) !== 1) {
                throw new RuntimeException('Invalid note text.');
            }
        }
        if ($note['title'] === '' || strlen($note['title']) > PIPLET_MAX_TITLE_BYTES) {
            throw new RuntimeException('Invalid note title.');
        }
        if (!piplet_validate_revision($note['revision'] ?? null, 1)
            || $note['revision'] > $document['revision']) {
            throw new RuntimeException('Invalid note revision.');
        }
        if ($versions && !piplet_is_version($note['version'] ?? null)) {
            throw new RuntimeException('Invalid note version.');
        }
        if (!isset($note['tags']) || !is_array($note['tags']) || !array_is_list($note['tags'])
            || count($note['tags']) > PIPLET_MAX_TAGS) {
            throw new RuntimeException('Invalid note tags.');
        }
        $totalTags += count($note['tags']);
        if ($totalTags > PIPLET_MAX_TOTAL_TAGS) {
            throw new RuntimeException('The note collection has too many tag references.');
        }
        foreach ($note['tags'] as $tag) {
            if (!is_string($tag) || $tag === '' || strlen($tag) > PIPLET_MAX_TAG_BYTES || preg_match('//u', $tag) !== 1) {
                throw new RuntimeException('Invalid note tag.');
            }
        }
        if (array_key_exists('createToken', $note)) {
            $token = $note['createToken'];
            if (!piplet_is_version($token) || isset($createTokens[$token])) {
                throw new RuntimeException('Invalid note creation token.');
            }
            $createTokens[$token] = true;
        }
    }
}

function piplet_validate_legacy_document(array $document): void
{
    if (($document['format'] ?? null) !== 1
        || !piplet_validate_revision($document['revision'] ?? null)) {
        throw new RuntimeException('Unsupported embedded data format.');
    }
    if (array_key_exists('generation', $document)) {
        throw new RuntimeException('Legacy data uses a reserved security field.');
    }
    if (array_key_exists('appearance', $document)) {
        $appearance = $document['appearance'];
        if (!piplet_is_record($appearance)
            || !piplet_validate_revision($appearance['revision'] ?? null, 1)
            || $appearance['revision'] > $document['revision']
            || array_key_exists('version', $appearance)) {
            throw new RuntimeException('Invalid legacy appearance record.');
        }
    }
    piplet_validate_note_collection($document, false);
    foreach ($document['notes'] as $note) {
        if (is_array($note) && array_key_exists('version', $note)) {
            throw new RuntimeException('Legacy data uses a reserved note field.');
        }
    }
}

function piplet_upgrade_legacy_document(array $document, string $json): array
{
    $generation = substr(hash('sha256', "piplet/v1/generation\0" . $json), 0, 32);
    foreach ($document['notes'] as $id => &$note) {
        $note['version'] = substr(hash('sha256', "piplet/v1/note\0" . $generation . "\0" . $id), 0, 32);
    }
    unset($note);

    $storedAppearance = $document['appearance'] ?? [];
    $storedAppearance = piplet_is_record($storedAppearance) ? $storedAppearance : [];
    $document['appearance'] = array_replace($storedAppearance, [
        'revision' => is_int($storedAppearance['revision'] ?? null) ? $storedAppearance['revision'] : 0,
        'version' => substr(hash('sha256', "piplet/v1/appearance\0" . $generation), 0, 32),
    ], piplet_appearance_values($storedAppearance, false));
    $document['format'] = PIPLET_FORMAT;
    $document['generation'] = $generation;
    return $document;
}

function piplet_validate_document(array $document): void
{
    if (($document['format'] ?? null) !== PIPLET_FORMAT) {
        throw new RuntimeException('Unsupported embedded data format.');
    }
    if (!piplet_validate_revision($document['revision'] ?? null)) {
        throw new RuntimeException('Invalid document revision.');
    }
    if (!piplet_is_version($document['generation'] ?? null)) {
        throw new RuntimeException('Invalid document generation.');
    }
    $appearance = $document['appearance'] ?? null;
    if (!piplet_is_record($appearance)
        || !piplet_validate_revision($appearance['revision'] ?? null)
        || $appearance['revision'] > $document['revision']
        || !piplet_is_version($appearance['version'] ?? null)) {
        throw new RuntimeException('Invalid appearance record.');
    }
    piplet_validate_note_collection($document, true);
}

function piplet_write_all($handle, string $bytes): void
{
    $length = strlen($bytes);
    $written = 0;
    while ($written < $length) {
        $chunk = substr($bytes, $written, PIPLET_WRITE_CHUNK_BYTES);
        $count = @fwrite($handle, $chunk);
        if ($count === false || $count === 0) {
            throw new RuntimeException('Could not finish writing the new snapshot.');
        }
        $written += $count;
    }
}

/** Exact length under PIPLET_JSON_FLAGS, without allocating the full JSON string. */
function piplet_json_encoded_length(mixed $value): int
{
    if ($value instanceof stdClass || is_array($value)) {
        $items = $value instanceof stdClass ? get_object_vars($value) : $value;
        $object = $value instanceof stdClass || !array_is_list($value);
        $length = 2;
        $first = true;
        foreach ($items as $key => $item) {
            if (!$first) $length++;
            $first = false;
            if ($object) {
                $length += strlen(json_encode((string) $key, PIPLET_JSON_FLAGS)) + 1;
            }
            $length += piplet_json_encoded_length($item);
        }
        return $length;
    }
    return strlen(json_encode($value, PIPLET_JSON_FLAGS));
}

/** Create a private snapshot beside the live file, ready for atomic rename. */
function piplet_open_temp(string $path): array
{
    $directory = dirname($path);
    $created = @tempnam($directory, '.piplet-tmp-');
    if (!is_string($created) || realpath(dirname($created)) !== realpath($directory)) {
        if (is_string($created)) {
            @unlink($created);
        }
        throw new RuntimeException('Cannot create a snapshot beside the piplet.');
    }
    try {
        $temp = $created . '-' . bin2hex(random_bytes(8)) . '.php';
    } catch (Throwable $error) {
        @unlink($created);
        throw $error;
    }
    if (!@rename($created, $temp)) {
        @unlink($created);
        throw new RuntimeException('Cannot name the temporary PHP snapshot.');
    }

    $handle = null;
    try {
        // tempnam() creates the inode as 0600. chmod restores owner access when
        // an unusually restrictive umask removed it; it never widens an exposed
        // group/other-readable interval.
        if (!@chmod($temp, 0600)) {
            throw new RuntimeException('Cannot secure the temporary snapshot.');
        }
        $handle = @fopen($temp, 'r+b');
        $stat = $handle === false ? false : fstat($handle);
        $pathStat = @lstat($temp);
        if (
            $handle === false || $stat === false || $pathStat === false
            || (((int) $stat['mode']) & 0170000) !== 0100000
            || (((int) $stat['mode']) & 0077) !== 0
            || ($stat['nlink'] ?? 0) !== 1
            || !piplet_same_inode($stat, $pathStat)
        ) {
            throw new RuntimeException('Cannot open a private temporary snapshot.');
        }
        return [$temp, $handle, $stat];
    } catch (Throwable $error) {
        if (is_resource($handle)) {
            @fclose($handle);
        }
        @unlink($temp);
        throw $error;
    }
}

function piplet_persist(string $prefix, array $document, array $lockedStat): int
{
    piplet_validate_document($document);
    $encodedDocument = $document;
    if ($encodedDocument['notes'] === []) $encodedDocument['notes'] = new stdClass();
    $jsonLength = piplet_json_encoded_length($encodedDocument);
    $fixedLength = strlen($prefix) + strlen(PIPLET_DATA_HEADER) + 1;
    if ($fixedLength > PIPLET_MAX_FILE_BYTES
        || $jsonLength > PIPLET_MAX_FILE_BYTES - $fixedLength) {
        throw new PipletHttpError(413, 'This save would make the piplet larger than 8 MiB.');
    }
    try {
        $json = json_encode($encodedDocument, PIPLET_JSON_FLAGS);
    } catch (JsonException $error) {
        throw new RuntimeException('Could not encode the new snapshot.', 0, $error);
    }
    if (strlen($json) !== $jsonLength) {
        throw new RuntimeException('Snapshot size projection failed.');
    }
    if (!piplet_json_within_budget(
        $json,
        PIPLET_MAX_STORED_STRUCTURE,
        PIPLET_MAX_STORED_CONTAINERS,
        PIPLET_MAX_STORED_JSON_DEPTH
    )) {
        throw new PipletHttpError(413, 'This save would make the snapshot too structurally complex.');
    }

    $path = piplet_path();
    $temp = '';
    $tempHandle = null;
    $tempStat = false;
    $committed = false;

    try {
        [$temp, $tempHandle, $tempStat] = piplet_open_temp($path);
        foreach ([$prefix, PIPLET_DATA_HEADER, $json, "\n"] as $part) {
            piplet_write_all($tempHandle, $part);
        }
        if (!@fflush($tempHandle) || !@fsync($tempHandle)) {
            throw new RuntimeException('Cannot sync the new snapshot.');
        }

        $pathStat = @lstat($temp);
        if (!piplet_same_inode($tempStat, $pathStat) || ($pathStat['nlink'] ?? 0) !== 1) {
            throw new RuntimeException('The temporary snapshot changed during the save.');
        }
        $mode = ((int) ($lockedStat['mode'] ?? 0600)) & 0777;
        if (!@chmod($temp, $mode)) {
            throw new RuntimeException('Cannot preserve the piplet permissions.');
        }
        $pathStat = @lstat($temp);
        if (!piplet_same_inode($tempStat, $pathStat) || ($pathStat['nlink'] ?? 0) !== 1
            || !@fsync($tempHandle)) {
            throw new RuntimeException('Cannot sync the snapshot permissions.');
        }
        if (!@fclose($tempHandle)) {
            throw new RuntimeException('Cannot close the new snapshot.');
        }
        $tempHandle = null;

        // Refuse to clobber an out-of-band replacement made while we prepared.
        clearstatcache(true, $path);
        if (!piplet_same_inode(@stat($path), $lockedStat)) {
            throw new RuntimeException('The piplet changed during the save; please retry.');
        }
        if (!@rename($temp, $path)) {
            throw new RuntimeException('Cannot atomically replace the piplet.');
        }
        $committed = true;
        clearstatcache(true, $path);
        return $fixedLength + $jsonLength;
    } finally {
        if (is_resource($tempHandle)) @fclose($tempHandle);
        if (!$committed && $temp !== '') {
            $cleanupStat = @lstat($temp);
            if (piplet_same_inode($tempStat, $cleanupStat) && ($cleanupStat['nlink'] ?? 0) === 1) {
                @chmod($temp, 0600);
                if (!@unlink($temp)) error_log('piplet could not remove a private temporary snapshot');
            } elseif ($cleanupStat !== false) {
                error_log('piplet left an unrecognized temporary path untouched');
            }
        }
    }
}

/**
 * Serialize a read-modify-write without a permanent lock sidecar.
 *
 * rename() swaps inodes, so locking the first file we open is not enough: a
 * waiter may have opened the old inode. We lock, compare the descriptor's
 * device/inode with the current path, and retry until we own the live file.
 */
function piplet_mutate(callable $change): array
{
    piplet_require_runtime();
    if (!function_exists('fsync')) {
        throw new PipletHttpError(503, 'Saving is unavailable because file synchronization is disabled.');
    }
    $path = piplet_path();
    $deadline = hrtime(true) + PIPLET_LOCK_TIMEOUT_NS;

    while (hrtime(true) < $deadline) {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Cannot open the piplet for saving.');
        }

        try {
            $wouldBlock = 0;
            if (!@flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
                @fclose($handle);
                $handle = null;
                if (!$wouldBlock) throw new RuntimeException('Cannot lock the piplet for saving.');
                usleep(random_int(5000, 20000));
                continue;
            }
            $lockedStat = fstat($handle);
            if ($lockedStat === false || (((int) ($lockedStat['mode'] ?? 0)) & 0170000) !== 0100000) {
                throw new RuntimeException('The live piplet is not a regular file.');
            }
            clearstatcache(true, $path);
            $currentStat = @stat($path);

            if (!piplet_same_inode($lockedStat, $currentStat)) {
                @flock($handle, LOCK_UN);
                @fclose($handle);
                $handle = null;
                usleep(random_int(5000, 20000));
                continue;
            }
            if (($lockedStat['nlink'] ?? 1) !== 1) {
                throw new RuntimeException('Hard-linked piplets cannot be saved safely.');
            }

            $raw = piplet_read_stream($handle);
            $document = piplet_decode($raw);
            $result = $change($document);
            if ($result instanceof PipletUnchanged) {
                return ['result' => $result->result, 'document' => $document, 'bytes' => (int) $lockedStat['size']];
            }
            if ($document['revision'] >= PIPLET_MAX_REVISION) {
                throw new PipletHttpError(409, 'The revision limit was reached; import and rekey the data before saving.');
            }
            $document['revision']++;

            $prefix = substr($raw, 0, __COMPILER_HALT_OFFSET__);
            $bytes = piplet_persist($prefix, $document, $lockedStat);
            return ['result' => $result, 'document' => $document, 'bytes' => $bytes];
        } finally {
            if (is_resource($handle)) {
                @flock($handle, LOCK_UN);
                @fclose($handle);
            }
        }
    }

    throw new PipletHttpError(503, 'The piplet is busy; please retry.');
}

/** Replace only the data behind the running executable, even if its old trailer is corrupt. */
function piplet_replace_document(array $document): int
{
    piplet_require_runtime();
    if (!function_exists('fsync')) {
        throw new PipletHttpError(503, 'Saving is unavailable because file synchronization is disabled.');
    }
    $path = piplet_path();
    $deadline = hrtime(true) + PIPLET_LOCK_TIMEOUT_NS;

    while (hrtime(true) < $deadline) {
        $handle = @fopen($path, 'rb');
        if ($handle === false) throw new RuntimeException('Cannot open the piplet for recovery.');
        try {
            $wouldBlock = 0;
            if (!@flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
                @fclose($handle);
                $handle = null;
                if (!$wouldBlock) throw new RuntimeException('Cannot lock the piplet for recovery.');
                usleep(random_int(5000, 20000));
                continue;
            }
            $lockedStat = fstat($handle);
            clearstatcache(true, $path);
            if ($lockedStat === false
                || (((int) ($lockedStat['mode'] ?? 0)) & 0170000) !== 0100000
                || ($lockedStat['nlink'] ?? 1) !== 1) {
                throw new RuntimeException('The live piplet is not a private regular file.');
            }
            if (!piplet_same_inode($lockedStat, @stat($path))) {
                @flock($handle, LOCK_UN);
                @fclose($handle);
                $handle = null;
                usleep(random_int(5000, 20000));
                continue;
            }
            if (!rewind($handle)) throw new RuntimeException('Cannot rewind the application file.');
            $prefix = stream_get_contents($handle, __COMPILER_HALT_OFFSET__);
            if (!is_string($prefix) || strlen($prefix) !== __COMPILER_HALT_OFFSET__) {
                throw new RuntimeException('The trusted executable prefix is incomplete.');
            }
            return piplet_persist($prefix, $document, $lockedStat);
        } finally {
            if (is_resource($handle)) {
                @flock($handle, LOCK_UN);
                @fclose($handle);
            }
        }
    }
    throw new PipletHttpError(503, 'The piplet is busy; please retry.');
}

function piplet_rekey_document(array &$document): void
{
    $document['format'] = PIPLET_FORMAT;
    $document['generation'] = piplet_version();
    $document['revision'] = 0;
    foreach ($document['notes'] as &$note) {
        $note['version'] = piplet_version();
        $note['revision'] = 1;
    }
    unset($note);
    $document['appearance']['version'] = piplet_version();
    $document['appearance']['revision'] = 0;
}

function piplet_cli(array $arguments): never
{
    try {
        if ($arguments === ['--check']) {
            $document = piplet_read();
            fwrite(STDOUT, "ok — format {$document['format']}, revision {$document['revision']}, "
                . count($document['notes']) . " notes\n");
            exit(0);
        }
        if ($arguments === ['--rekey']) {
            $saved = piplet_mutate(function (array &$document): array {
                piplet_rekey_document($document);
                return ['notes' => count($document['notes'])];
            });
            fwrite(STDOUT, "ok — rekeyed {$saved['result']['notes']} notes\n");
            exit(0);
        }
        if (count($arguments) === 3 && $arguments[0] === '--import-snapshot-data'
            && $arguments[2] === '--rekey' && $arguments[1] !== '') {
            $import = piplet_read_snapshot_data($arguments[1]);
            piplet_rekey_document($import);
            $import['revision']++;
            piplet_replace_document($import);
            fwrite(STDOUT, 'ok — imported and rekeyed ' . count($import['notes']) . " notes\n");
            exit(0);
        }
        fwrite(STDERR, "Usage:\n"
            . "  php wiki-piplet.php --check\n"
            . "  php wiki-piplet.php --rekey\n"
            . "  php wiki-piplet.php --import-snapshot-data BACKUP --rekey\n");
        exit(64);
    } catch (Throwable $error) {
        fwrite(STDERR, "piplet: {$error->getMessage()}\n");
        exit(1);
    }
}

function piplet_now(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

function piplet_slug(string $title, array $notes): string
{
    $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title) : $title;
    $ascii = is_string($ascii) ? $ascii : $title;
    $base = strtolower($ascii);
    $base = preg_replace('/[^a-z0-9]+/', '-', $base) ?? '';
    $base = trim($base, '-');
    $base = substr($base === '' ? 'note' : $base, 0, 64);
    if (ctype_digit($base)) {
        $base = 'note-' . $base;
    }
    $id = $base;
    $suffix = 2;
    while (isset($notes[$id])) {
        $id = substr($base, 0, 70) . '-' . $suffix++;
    }
    return $id;
}

function piplet_normalize_tags(mixed $value): array
{
    if (!is_array($value) || !array_is_list($value)) {
        throw new PipletHttpError(422, 'Tags must be a list.');
    }
    if (count($value) > PIPLET_MAX_TAGS) {
        throw new PipletHttpError(422, 'Use at most 12 tags.');
    }

    foreach ($value as $tag) {
        if (!is_string($tag) || preg_match('//u', $tag) !== 1) {
            throw new PipletHttpError(422, 'Every tag must be valid text.');
        }
        if ($tag === '') {
            throw new PipletHttpError(422, 'Tags cannot be empty.');
        }
        if (strlen($tag) > PIPLET_MAX_TAG_BYTES) {
            throw new PipletHttpError(422, 'A tag is too long.');
        }
    }
    return $value;
}

function piplet_expect_fields(array $input, array $fields): void
{
    foreach ($fields as $field) {
        if (!array_key_exists($field, $input)) {
            $status = in_array($field, ['baseGeneration', 'baseRevision', 'baseVersion'], true) ? 428 : 422;
            throw new PipletHttpError($status, "Missing request field: $field.");
        }
    }
    foreach ($input as $field => $_) {
        if (!is_string($field) || !in_array($field, $fields, true)) {
            throw new PipletHttpError(422, 'The request contains an unsupported field.');
        }
    }
}

function piplet_text(mixed $value, string $name, int $maxBytes = PIPLET_MAX_REQUEST_BYTES): string
{
    if (!is_string($value) || preg_match('//u', $value) !== 1) {
        throw new PipletHttpError(422, "$name must be valid UTF-8 text.");
    }
    if (strlen($value) > $maxBytes) {
        throw new PipletHttpError(422, "$name is too long.");
    }
    return $value;
}

function piplet_legacy_css(mixed $value): string
{
    if ($value instanceof stdClass) {
        $value = get_object_vars($value);
    }
    if (!piplet_is_record($value)) return '';

    $tokens = [];
    foreach (PIPLET_LEGACY_TOKEN_RULES as $name => $rule) {
        if (!array_key_exists($name, $value)) {
            continue;
        }
        $candidate = $value[$name];
        $valid = is_string($candidate);
        $candidate = $valid ? trim($candidate) : '';

        if ($valid) {
            $unit = $rule[0];
            $valid = preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?' . preg_quote($unit, '/') . '$/D', $candidate) === 1;
            $number = $valid ? (float) substr($candidate, 0, -strlen($unit)) : 0.0;
            $valid = $valid && $number >= $rule[1] && $number <= $rule[2];
            if ($valid) {
                $formatted = rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
                $candidate = ($formatted === '' ? '0' : $formatted) . $unit;
            }
        }

        if ($valid) $tokens[] = "  $name: $candidate;";
    }
    return $tokens ? ':' . "root {\n" . implode("\n", $tokens) . "\n}" : '';
}

function piplet_appearance_values(mixed $value, bool $strict = true): array
{
    if ($value instanceof stdClass) {
        $value = get_object_vars($value);
    }
    if (!piplet_is_record($value)) {
        if ($strict) {
            throw new PipletHttpError(422, 'Appearance must be an object.');
        }
        $value = [];
    }

    if ($strict) {
        $allowed = [...array_keys(PIPLET_APPEARANCE_DEFAULTS), 'customCss'];
        foreach ($value as $key => $_) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new PipletHttpError(422, 'Appearance contains an unsupported setting.');
            }
        }
    }

    $normalized = [];
    foreach (PIPLET_APPEARANCE_OPTIONS as $key => $options) {
        $choice = $value[$key] ?? null;
        if (!is_string($choice) || !in_array($choice, $options, true)) {
            if ($strict) {
                throw new PipletHttpError(422, "Invalid appearance setting: $key.");
            }
            $choice = PIPLET_APPEARANCE_DEFAULTS[$key];
        }
        $normalized[$key] = $choice;
    }
    $customCss = $value['customCss'] ?? null;
    if ($customCss === null) {
        if ($strict) throw new PipletHttpError(422, 'Custom CSS must be a string.');
        $customCss = piplet_legacy_css($value['tokens'] ?? null);
    }
    if (!is_string($customCss) || preg_match('//u', $customCss) !== 1 || strlen($customCss) > PIPLET_MAX_CUSTOM_CSS_BYTES) {
        if ($strict) throw new PipletHttpError(422, 'Custom CSS must be valid UTF-8 and no larger than 32 KiB.');
        $customCss = '';
    }
    $normalized['customCss'] = $customCss;
    return $normalized;
}

function piplet_current_appearance(array $document): array
{
    $stored = $document['appearance'] ?? null;
    $stored = piplet_is_record($stored) ? $stored : [];
    return [
        'revision' => is_int($stored['revision'] ?? null) ? $stored['revision'] : 0,
        'version' => is_string($stored['version'] ?? null) ? $stored['version'] : '',
    ]
        + piplet_appearance_values($stored, false);
}

function piplet_save_appearance(array $input): array
{
    piplet_expect_fields($input, ['baseGeneration', 'baseRevision', 'baseVersion', 'appearance']);
    $baseGeneration = $input['baseGeneration'];
    $baseRevision = $input['baseRevision'];
    $baseVersion = $input['baseVersion'];
    if (!piplet_is_version($baseGeneration) || !piplet_is_version($baseVersion)) {
        throw new PipletHttpError(422, 'Invalid appearance precondition.');
    }
    if (!piplet_validate_revision($baseRevision)) {
        throw new PipletHttpError(422, 'Invalid appearance revision.');
    }
    $values = piplet_appearance_values($input['appearance']);

    return piplet_mutate(function (array &$document) use ($baseGeneration, $baseRevision, $baseVersion, $values) {
        $current = piplet_current_appearance($document);
        if (!hash_equals($document['generation'], $baseGeneration)
            || $current['revision'] !== $baseRevision
            || !hash_equals($current['version'], $baseVersion)) {
            throw new PipletConflict($current, 'The appearance changed after you opened it.', $document['generation']);
        }
        $currentValues = $current;
        unset($currentValues['revision'], $currentValues['version']);
        if ($currentValues === $values) {
            return new PipletUnchanged($current);
        }
        $stored = $document['appearance'] ?? [];
        $stored = piplet_is_record($stored) ? $stored : [];
        $appearance = array_replace($stored, $values, [
            'revision' => $document['revision'] + 1,
            'version' => piplet_version(),
        ]);
        $document['appearance'] = $appearance;
        return piplet_current_appearance($document);
    });
}

function piplet_save_note(array $input): array
{
    piplet_expect_fields($input, [
        'id', 'baseGeneration', 'baseRevision', 'baseVersion', 'createToken', 'title', 'body', 'tags'
    ]);
    $id = $input['id'];
    if ($id !== null && !piplet_is_note_id($id)) {
        throw new PipletHttpError(422, 'Invalid note identifier.');
    }
    $baseGeneration = $input['baseGeneration'];
    $baseRevision = $input['baseRevision'];
    $baseVersion = $input['baseVersion'];
    if (!piplet_is_version($baseGeneration)
        || !piplet_validate_revision($baseRevision)
        || ($id === null ? $baseVersion !== null : !piplet_is_version($baseVersion))) {
        throw new PipletHttpError(422, 'Invalid base revision.');
    }
    $title = piplet_text($input['title'], 'Title', PIPLET_MAX_TITLE_BYTES);
    if ($title === '') {
        throw new PipletHttpError(422, 'Give the note a title.');
    }
    $body = piplet_text($input['body'], 'Body');
    $tags = piplet_normalize_tags($input['tags']);
    $createToken = $input['createToken'];
    if (($id === null && !piplet_is_version($createToken))
        || ($id !== null && $createToken !== null)) {
        throw new PipletHttpError(422, 'Invalid note creation token.');
    }

    return piplet_mutate(function (array &$document) use (
        $id, $baseGeneration, $baseRevision, $baseVersion, $title, $body, $tags, $createToken
    ) {
        $notes = &$document['notes'];
        if (!hash_equals($document['generation'], $baseGeneration)) {
            throw new PipletConflict($id === null ? null : ($notes[$id] ?? null), 'This piplet changed lineage; reload before saving.', $document['generation']);
        }
        if ($id === null) {
            if ($baseRevision !== 0) {
                throw new PipletConflict(null, generation: $document['generation']);
            }
            foreach ($notes as $existing) {
                if (($existing['createToken'] ?? null) !== $createToken) continue;
                if ($existing['title'] === $title && $existing['body'] === $body && $existing['tags'] === $tags) {
                    return new PipletUnchanged($existing);
                }
                throw new PipletConflict($existing, 'This new note was already saved with different content.', $document['generation']);
            }
            if (count($notes) >= PIPLET_MAX_NOTES) {
                throw new PipletHttpError(413, 'This piplet has reached its note limit.');
            }
            $id = piplet_slug($title, $notes);
            $created = piplet_now();
            $storedToken = $createToken;
            $current = [];
        } else {
            $current = $notes[$id] ?? null;
            if (!is_array($current) || $current['revision'] !== $baseRevision
                || !hash_equals($current['version'], $baseVersion)) {
                throw new PipletConflict($current, generation: $document['generation']);
            }
            if ($current['title'] === $title && $current['body'] === $body && $current['tags'] === $tags) {
                return new PipletUnchanged($current);
            }
            $created = $current['created'];
            $storedToken = $current['createToken'] ?? null;
        }

        $revision = $document['revision'] + 1;
        $note = array_replace($current, [
            'id' => $id,
            'title' => $title,
            'body' => $body,
            'tags' => $tags,
            'revision' => $revision,
            'version' => piplet_version(),
            'created' => $created,
            'updated' => piplet_now(),
        ]);
        if ($storedToken !== null) {
            $note['createToken'] = $storedToken;
        }
        $notes[$id] = $note;
        return $note;
    });
}

function piplet_delete_note(array $input): array
{
    piplet_expect_fields($input, ['id', 'baseGeneration', 'baseRevision', 'baseVersion']);
    $id = $input['id'];
    $baseGeneration = $input['baseGeneration'];
    $baseRevision = $input['baseRevision'];
    $baseVersion = $input['baseVersion'];
    if (!piplet_is_note_id($id) || !piplet_is_version($baseGeneration)
        || !piplet_validate_revision($baseRevision) || !piplet_is_version($baseVersion)) {
        throw new PipletHttpError(422, 'Invalid delete request.');
    }

    return piplet_mutate(function (array &$document) use ($id, $baseGeneration, $baseRevision, $baseVersion): array {
        $current = $document['notes'][$id] ?? null;
        if (!hash_equals($document['generation'], $baseGeneration)
            || !is_array($current) || $current['revision'] !== $baseRevision
            || !hash_equals($current['version'], $baseVersion)) {
            throw new PipletConflict($current, generation: $document['generation']);
        }
        unset($document['notes'][$id]);
        return ['id' => $id];
    });
}

function piplet_is_api(): bool
{
    return isset($_GET['api']);
}

function piplet_json(array $value, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function piplet_provided_password(): string
{
    $provided = $_SERVER['PHP_AUTH_PW'] ?? null;
    if (!is_string($provided) || $provided === '') {
        $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (is_string($authorization) && preg_match('/^Basic +([A-Za-z0-9+\/=]+)$/Di', $authorization, $match)) {
            $decoded = base64_decode($match[1], true);
            $parts = is_string($decoded) ? explode(':', $decoded, 2) : [];
            $provided = count($parts) === 2 ? $parts[1] : '';
        } else {
            $provided = '';
        }
    }
    return is_string($provided) ? $provided : '';
}

function piplet_require_access(): void
{
    $password = getenv('PIPLET_PASSWORD');
    $password = is_string($password) ? $password : '';
    $provided = piplet_provided_password();
    if ($password !== '' && hash_equals($password, $provided)) {
        return;
    }

    if ($password !== '') {
        header('WWW-Authenticate: Basic realm="piplet", charset="UTF-8"');
        $message = 'Authentication required.';
        $status = 401;
    } else {
        $message = 'Set PIPLET_PASSWORD before serving piplet over HTTP.';
        $status = 403;
    }

    if (piplet_is_api()) {
        piplet_json(['ok' => false, 'error' => $message], $status);
    }
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    exit($message);
}

/** Block browser subresources from spending authenticated work cross-origin. */
function piplet_require_browser_context(): void
{
    $site = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
    if (!in_array($site, ['same-site', 'cross-site'], true)) return;
    $mode = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_MODE'] ?? ''));
    $destination = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? ''));
    if ($mode === 'navigate' && $destination === 'document') return;

    if (piplet_is_api()) {
        piplet_json(['ok' => false, 'error' => 'Cross-origin browser requests are not accepted.'], 403);
    }
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    exit('Cross-origin browser requests are not accepted.');
}

function piplet_cookie_name(): string
{
    $script = $_SERVER['SCRIPT_NAME'] ?? __FILE__;
    return 'piplet_csrf_' . substr(hash('sha256', $script), 0, 10);
}

function piplet_cookie_path(string $script): string
{
    $directory = dirname(str_replace('\\', '/', $script));
    return $directory === '/' || $directory === '.' ? '/' : rtrim($directory, '/') . '/';
}

function piplet_request_is_https(): bool
{
    $https = strtolower(trim((string) ($_SERVER['HTTPS'] ?? '')));
    return getenv('PIPLET_PUBLIC_HTTPS') === '1' || !in_array($https, ['', '0', 'off'], true);
}

function piplet_csrf_token(): string
{
    $name = piplet_cookie_name();
    $existing = $_COOKIE[$name] ?? '';
    $token = is_string($existing) && preg_match('/^[a-f0-9]{64}$/D', $existing)
        ? $existing
        : bin2hex(random_bytes(32));
    $path = piplet_cookie_path($_SERVER['SCRIPT_NAME'] ?? '/');
    if (!setcookie($name, $token, [
        'expires' => 0,
        'path' => $path,
        'secure' => piplet_request_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ])) {
        throw new RuntimeException('The browser security cookie could not be issued.');
    }
    return $token;
}

function piplet_require_csrf(): void
{
    $cookie = $_COOKIE[piplet_cookie_name()] ?? '';
    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($cookie) || !is_string($header) || $cookie === '' || !hash_equals($cookie, $header)) {
        throw new PipletHttpError(403, 'Refresh the page before saving again.');
    }
}

function piplet_request_json(): array
{
    $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
    if ($contentType !== 'application/json') {
        throw new PipletHttpError(415, 'Use application/json.');
    }
    $declared = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($declared > PIPLET_MAX_REQUEST_BYTES) {
        throw new PipletHttpError(413, 'The request is too large.');
    }
    $raw = file_get_contents('php://input', false, null, 0, PIPLET_MAX_REQUEST_BYTES + 1);
    if ($raw === false || strlen($raw) > PIPLET_MAX_REQUEST_BYTES) {
        throw new PipletHttpError(413, 'The request is too large.');
    }
    if (!piplet_json_within_budget(
        $raw,
        PIPLET_MAX_REQUEST_STRUCTURE,
        PIPLET_MAX_REQUEST_CONTAINERS,
        PIPLET_MAX_REQUEST_JSON_DEPTH
    )) {
        throw new PipletHttpError(413, 'The request is too structurally complex.');
    }
    if (!piplet_json_members_are_unique($raw)) {
        throw new PipletHttpError(400, 'The request contains duplicate object member names.');
    }
    try {
        $input = json_decode($raw, false, PIPLET_MAX_REQUEST_JSON_DEPTH + 1, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new PipletHttpError(400, 'The request is not valid JSON.');
    }
    if (!$input instanceof stdClass) {
        throw new PipletHttpError(400, 'The request must be a JSON object.');
    }
    return get_object_vars($input);
}

function piplet_handle_api(): never
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        header('Allow: POST');
        piplet_json(['ok' => false, 'error' => 'This endpoint accepts POST only.'], 405);
    }

    try {
        $action = $_GET['api'] ?? '';
        if (!in_array($action, ['save', 'delete', 'appearance'], true)) {
            throw new PipletHttpError(404, 'Unknown action.');
        }
        piplet_require_csrf();
        $input = piplet_request_json();
        $saved = match ($action) {
            'save' => piplet_save_note($input),
            'delete' => piplet_delete_note($input),
            'appearance' => piplet_save_appearance($input),
        };
        piplet_json([
            'ok' => true,
            'result' => $saved['result'],
            'documentRevision' => $saved['document']['revision'],
            'generation' => $saved['document']['generation'],
            'bytes' => $saved['bytes'],
        ]);
    } catch (PipletConflict $error) {
        piplet_json([
            'ok' => false,
            'error' => $error->getMessage(),
            'current' => $error->current,
            'generation' => $error->generation,
        ], 409);
    } catch (PipletHttpError $error) {
        if ($error->status === 503) header('Retry-After: 1');
        piplet_json(['ok' => false, 'error' => $error->getMessage()], $error->status);
    } catch (Throwable $error) {
        error_log('piplet save failed: ' . $error->getMessage());
        piplet_json(['ok' => false, 'error' => 'The save could not be confirmed. Reload before retrying.'], 500);
    }
}

function piplet_download(bool $head = false): never
{
    $path = piplet_path();
    $handle = @fopen($path, 'rb');
    $stat = $handle === false ? false : fstat($handle);
    if ($handle === false || $stat === false
        || (((int) ($stat['mode'] ?? 0)) & 0170000) !== 0100000
        || ($stat['size'] ?? PIPLET_MAX_FILE_BYTES + 1) > PIPLET_MAX_FILE_BYTES) {
        if (is_resource($handle)) fclose($handle);
        http_response_code(500);
        exit('The snapshot could not be opened.');
    }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="wiki-piplet-snapshot.php"');
    header('Content-Length: ' . (string) $stat['size']);
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    if (!$head) fpassthru($handle);
    fclose($handle);
    exit;
}

function piplet_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function piplet_render_failure(Throwable $error): never
{
    error_log('piplet read failed: ' . $error->getMessage());
    $nonce = base64_encode(random_bytes(18));
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'none'; style-src 'nonce-$nonce'; base-uri 'none'; frame-ancestors 'none'");
    ?><!doctype html>
<html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>piplet needs attention</title>
<style nonce="<?= piplet_h($nonce) ?>">body{margin:0;background:#f3f1ea;color:#22231f;font:17px/1.6 ui-serif,Georgia,serif}.box{max-width:42rem;margin:12vh auto;padding:2rem;border-top:3px solid #9b342f}h1{font-size:2rem}code{font:14px ui-monospace,monospace;background:#e9e6dc;padding:.15rem .35rem}</style>
<main class="box"><h1>The notes are still here, but they cannot be read.</h1><p>The embedded data failed validation, so piplet has stopped instead of replacing it. Restore a known-good copy or inspect the data after <code>__halt_compiler()</code>.</p></main></html><?php
    exit;
}

function piplet_run(): void
{
    piplet_require_browser_context();
    piplet_require_access();

    $api = isset($_GET['api']);
    $download = isset($_GET['download']);
    if ($api && $download) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Choose one route.');
    }
    if ($api) {
        piplet_handle_api();
    }
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        header('Allow: GET, HEAD');
        http_response_code(405);
        exit;
    }
    if ($download) piplet_download($method === 'HEAD');

    try {
        $document = piplet_read();
    } catch (Throwable $error) {
        piplet_render_failure($error);
    }

    $csrf = piplet_csrf_token();
    $nonce = base64_encode(random_bytes(18));
    $path = piplet_path();
    $pathStat = @stat($path);
    $writable = is_readable($path) && is_writable(dirname($path))
        && ($pathStat['nlink'] ?? 1) === 1 && function_exists('fsync');
    $appearance = piplet_current_appearance($document);
    $safeAppearance = ($_GET['safe'] ?? null) === '1';
    $boot = [
        'document' => $document,
        'appearance' => $appearance,
        'appearanceDefaults' => PIPLET_APPEARANCE_DEFAULTS,
        'maxCustomCssBytes' => PIPLET_MAX_CUSTOM_CSS_BYTES,
        'safeAppearance' => $safeAppearance,
        'writable' => $writable,
    ];
    try {
        $bootJson = json_encode($boot, PIPLET_JSON_FLAGS);
        if (strlen($bootJson) > PIPLET_MAX_BOOT_JSON_BYTES) {
            throw new RuntimeException('The browser state exceeds its configured limit.');
        }
        $bootBase64 = base64_encode($bootJson);
        if (strlen($bootBase64) > PIPLET_MAX_BOOT_BASE64_BYTES) {
            throw new RuntimeException('The browser state transport exceeds its configured limit.');
        }
    } catch (Throwable $error) {
        piplet_render_failure($error);
    }
    $customCssJson = json_encode(
        $safeAppearance ? '' : $appearance['customCss'],
        JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    $appearanceGroups = [
        'palette' => ['Palette', 'Light and dark', 'palette-options'],
        'font' => ['Reading font', '', ''],
        'scale' => ['Text size', '', ''],
        'measure' => ['Line length', 'Wide screens', ''],
    ];
    $appearanceLabels = ['compact' => 'Small', 'comfortable' => 'Default'];

    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'none'; style-src 'nonce-$nonce'; script-src 'nonce-$nonce'; connect-src 'self'; img-src data:; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
    ?>
<!doctype html>
<html lang="en" data-theme="light" data-palette="<?= piplet_h($appearance['palette']) ?>" data-font="<?= piplet_h($appearance['font']) ?>" data-scale="<?= piplet_h($appearance['scale']) ?>" data-measure="<?= piplet_h($appearance['measure']) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <meta name="piplet-csrf" content="<?= piplet_h($csrf) ?>">
    <script nonce="<?= piplet_h($nonce) ?>">
        (() => {
            if (location.username || location.password) {
                const path = location.pathname.replace(/^\/+/, '/');
                history.replaceState(null, '', `${path}${location.search}${location.hash}`);
            }
            let choice = 'system';
            try { choice = localStorage.getItem(`piplet:${location.pathname}:theme`) || 'system'; } catch (_) {}
            if (!['system', 'light', 'dark'].includes(choice)) choice = 'system';
            document.documentElement.dataset.theme = choice === 'system'
                ? (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
                : choice;
        })();
    </script>
    <title>piplet — a small place for notes</title>
    <style nonce="<?= piplet_h($nonce) ?>">
        @layer reset, theme, base, layout, components, states;

        @layer reset {
            *, *::before, *::after { box-sizing: border-box; }
            html { -webkit-text-size-adjust: 100%; }
            body, h1, h2, h3, h4, h5, p, blockquote, pre { margin: 0; }
            button, input, textarea, select { font: inherit; }
            button { color: inherit; }
        }

        /* Appearance variables and the presets exposed by the interface. */
        @layer theme {
            :root {
                color-scheme: light;
                --canvas: #f1efe8;
                --paper: #fffef9;
                --ink: #20221f;
                --muted: #666960;
                --faint: #666960;
                --line: #d7d4c9;
                --line-strong: #89877f;
                --accent: #176b63;
                --accent-hover: #10554f;
                --accent-wash: #e2efeb;
                --accent-ink: #ffffff;
                --danger: #9b342f;
                --danger-wash: #f6e7e3;
                --selection: #bcded7;
                --shadow: 0 18px 50px rgb(31 35 31 / .16);
                --overlay: rgb(10 14 11 / .42);
                --font-ui: ui-sans-serif, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                --font-editorial: ui-serif, "Iowan Old Style", "Palatino Linotype", Palatino, Georgia, serif;
                --font-copy: var(--font-editorial);
                --font-code: ui-monospace, "SFMono-Regular", Consolas, monospace;
                --measure: 68ch;
                --story-width: 54rem;
                --title-size: clamp(2rem, 5vw, 3rem);
                --copy-size: clamp(1.05rem, 2vw, 1.14rem);
                --sidebar: 17.5rem;
                --bar: 3.5rem;
                --radius-sm: 3px;
                --radius: 6px;
                --space-1: .25rem;
                --space-2: .5rem;
                --space-3: .75rem;
                --space-4: 1rem;
                --space-5: 1.5rem;
                --space-6: 2rem;
                --space-7: 3rem;
                --motion: 140ms ease;
            }
            html[data-theme="dark"] {
                color-scheme: dark;
                --canvas: #181b19;
                --paper: #202421;
                --ink: #ecece5;
                --muted: #a9ada5;
                --faint: #a9ada5;
                --line: #393e39;
                --line-strong: #70766f;
                --accent: #72bdb2;
                --accent-hover: #91cec5;
                --accent-wash: #253d39;
                --accent-ink: #102a26;
                --danger: #e6877e;
                --danger-wash: #412b29;
                --selection: #315e57;
                --shadow: 0 18px 50px rgb(0 0 0 / .35);
            }
            html[data-palette="ocean"] {
                --canvas: #edf2f4;
                --paper: #fbfdfe;
                --ink: #1d2932;
                --muted: #5d6972;
                --faint: #5d6972;
                --line: #d0dadd;
                --line-strong: #7a8a91;
                --accent: #165d82;
                --accent-hover: #104966;
                --accent-wash: #dcecf3;
                --accent-ink: #ffffff;
                --selection: #b7d9e8;
            }
            html[data-theme="dark"][data-palette="ocean"] {
                --canvas: #151b1e;
                --paper: #1c2428;
                --ink: #eaf0f2;
                --muted: #a7b2b7;
                --faint: #a7b2b7;
                --line: #354147;
                --line-strong: #708087;
                --accent: #80c2df;
                --accent-hover: #a3d5e9;
                --accent-wash: #243d48;
                --accent-ink: #102730;
                --selection: #2f6074;
            }
            html[data-palette="plum"] {
                --canvas: #f2edef;
                --paper: #fffafb;
                --ink: #292126;
                --muted: #6f6269;
                --faint: #6f6269;
                --line: #ded1d7;
                --line-strong: #8f7d86;
                --accent: #7b3f68;
                --accent-hover: #623153;
                --accent-wash: #f0dfe9;
                --accent-ink: #ffffff;
                --selection: #e5bdd2;
            }
            html[data-theme="dark"][data-palette="plum"] {
                --canvas: #1c181b;
                --paper: #251f23;
                --ink: #f1e9ed;
                --muted: #b7a8af;
                --faint: #b7a8af;
                --line: #44373e;
                --line-strong: #806b76;
                --accent: #d89abd;
                --accent-hover: #e8b8d1;
                --accent-wash: #462c3a;
                --accent-ink: #351326;
                --selection: #70465e;
            }
            html[data-palette="mono"] {
                --canvas: #eeeeec;
                --paper: #fdfdfb;
                --ink: #202120;
                --muted: #646664;
                --faint: #646664;
                --line: #d4d5d2;
                --line-strong: #81847f;
                --accent: #4b514e;
                --accent-hover: #343936;
                --accent-wash: #e5e6e3;
                --accent-ink: #ffffff;
                --selection: #cfd2ce;
            }
            html[data-theme="dark"][data-palette="mono"] {
                --canvas: #181918;
                --paper: #202220;
                --ink: #eceeec;
                --muted: #aaaeaa;
                --faint: #aaaeaa;
                --line: #393c39;
                --line-strong: #737873;
                --accent: #b7bcb8;
                --accent-hover: #d2d5d2;
                --accent-wash: #343734;
                --accent-ink: #202220;
                --selection: #4d524e;
            }
            html[data-font="modern"] { --font-copy: var(--font-ui); }
            html[data-font="typewriter"] { --font-copy: var(--font-code); }
            html[data-scale="compact"] {
                --title-size: clamp(1.85rem, 5vw, 2.7rem);
                --copy-size: clamp(1rem, 1.8vw, 1.07rem);
            }
            html[data-scale="large"] {
                --title-size: clamp(2.2rem, 5.5vw, 3.25rem);
                --copy-size: clamp(1.15rem, 2.2vw, 1.26rem);
            }
            html[data-measure="focused"] {
                --measure: 58ch;
                --story-width: 48rem;
            }
            html[data-measure="wide"] {
                --measure: 78ch;
                --story-width: 64rem;
            }
        }

        @layer base {
            html { scroll-behavior: smooth; background: var(--canvas); }
            body { min-height: 100vh; overflow-x: hidden; background: var(--canvas); color: var(--ink); font-family: var(--font-ui); }
            ::selection { background: var(--selection); }
            a { color: var(--accent); text-underline-offset: .17em; }
            button, a, input, textarea, select, summary { outline-color: var(--accent); }
            :focus-visible { outline: 2px solid var(--accent); outline-offset: 3px; }
            button { border: 0; background: none; cursor: pointer; }
            button:disabled { cursor: not-allowed; opacity: .5; }
            .skip-link { position: fixed; z-index: 100; left: 1rem; top: -5rem; padding: .7rem 1rem; background: var(--ink); color: var(--paper); }
            .skip-link:focus { top: 1rem; }
        }

        @layer layout {
            .app-bar { position: sticky; z-index: 30; top: 0; height: var(--bar); display: grid; grid-template-columns: var(--sidebar) 1fr auto; align-items: center; border-bottom: 1px solid var(--line); background: var(--paper); }
            .brand { height: 100%; display: flex; align-items: center; gap: .65rem; padding: 0 var(--space-5); border-right: 1px solid var(--line); font-family: var(--font-copy); font-size: 1.25rem; font-weight: 700; letter-spacing: -.02em; }
            .brand-mark { width: .7rem; height: .7rem; border: 2px solid var(--accent); transform: rotate(45deg); }
            .bar-context { min-width: 0; padding: 0 var(--space-5); color: var(--muted); font-size: .79rem; letter-spacing: .02em; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .bar-actions { display: flex; align-items: center; gap: .35rem; padding-right: var(--space-4); }
            .shell { display: grid; grid-template-columns: var(--sidebar) minmax(0, 1fr); min-height: calc(100vh - var(--bar)); }
            .library { position: sticky; top: var(--bar); align-self: start; height: calc(100vh - var(--bar)); overflow: auto; padding: var(--space-5); border-right: 1px solid var(--line); background: var(--canvas); }
            .story-wrap { min-width: 0; overflow: hidden; background: var(--paper); }
            .story { width: min(100%, var(--story-width)); min-height: calc(100vh - var(--bar)); margin: 0 auto; padding: var(--space-7) clamp(1.25rem, 5vw, 4rem) 18vh; }
            .mobile-only, .drawer-shade { display: none; }
        }

        @layer components {
            .button { min-height: 2.75rem; display: inline-flex; align-items: center; justify-content: center; gap: .45rem; padding: .5rem .8rem; border: 1px solid var(--line-strong); border-radius: var(--radius-sm); color: var(--ink); font-size: .84rem; font-weight: 650; transition: border-color var(--motion), background var(--motion), color var(--motion); }
            .button:hover { border-color: var(--ink); background: var(--canvas); }
            .button-primary { border-color: var(--accent); background: var(--accent); color: var(--accent-ink); }
            .button-primary:hover { border-color: var(--accent-hover); background: var(--accent-hover); }
            .button-quiet { border-color: transparent; color: var(--muted); }
            .button-quiet:hover { border-color: var(--line); color: var(--ink); }
            .button-danger { border-color: var(--danger); color: var(--danger); }
            .button svg { width: 1rem; height: 1rem; flex: none; fill: none; stroke: currentColor; stroke-width: 1.8; }
            .icon-button { width: 2.75rem; height: 2.75rem; display: grid; place-items: center; border: 1px solid transparent; border-radius: var(--radius-sm); color: var(--muted); }
            .icon-button:hover { border-color: var(--line); color: var(--ink); background: var(--canvas); }
            .icon-button svg { width: 1.05rem; height: 1.05rem; fill: none; stroke: currentColor; stroke-width: 1.8; }
            .search { position: relative; margin-bottom: var(--space-5); }
            .search label { display: block; margin-bottom: .45rem; color: var(--muted); font-size: .72rem; font-weight: 750; letter-spacing: .1em; text-transform: uppercase; }
            .search input { width: 100%; height: 2.65rem; border: 0; border-bottom: 1px solid var(--line-strong); border-radius: 0; background: transparent; color: var(--ink); }
            .search input::placeholder { color: var(--faint); }
            .library-heading { display: flex; align-items: baseline; justify-content: space-between; margin: var(--space-6) 0 var(--space-2); color: var(--muted); font-size: .72rem; font-weight: 750; letter-spacing: .1em; text-transform: uppercase; }
            .note-count { color: var(--faint); font-variant-numeric: tabular-nums; }
            .library-list { list-style: none; margin: 0; padding: 0; }
            .library-item { width: 100%; display: block; position: relative; padding: .75rem .6rem .75rem .85rem; border-left: 2px solid transparent; text-align: left; }
            .library-item:hover { background: color-mix(in srgb, var(--paper) 58%, transparent); }
            .library-item[aria-current="true"] { border-left-color: var(--accent); background: var(--accent-wash); }
            .library-title { display: block; overflow: hidden; color: var(--ink); font-family: var(--font-copy); font-size: 1rem; font-weight: 650; line-height: 1.25; text-overflow: ellipsis; white-space: nowrap; }
            .library-meta { display: block; margin-top: .3rem; overflow: hidden; color: var(--faint); font-size: .72rem; text-overflow: ellipsis; white-space: nowrap; }
            .library-empty { padding: 1rem .8rem; color: var(--muted); font-size: .85rem; line-height: 1.5; }
            .storage { margin-top: var(--space-6); padding-top: var(--space-4); border-top: 1px solid var(--line); color: var(--muted); font-size: .75rem; line-height: 1.55; }
            .storage strong { color: var(--ink); font-weight: 650; }
            .storage a { display: inline-block; margin-top: .45rem; }
            .note { position: relative; scroll-margin-top: calc(var(--bar) + var(--space-4)); padding: 0 0 var(--space-7); border-top: 1px solid var(--line); }
            .note + .note { margin-top: var(--space-7); }
            .note:first-child { border-top: 0; }
            .note-header { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: var(--space-5); align-items: start; padding-top: var(--space-2); margin-bottom: var(--space-5); }
            .note:first-child .note-header { padding-top: 0; }
            .note-title { max-width: 18ch; font-family: var(--font-copy); font-size: var(--title-size); font-weight: 600; letter-spacing: -.035em; line-height: 1.03; text-wrap: balance; }
            .note-actions { display: flex; gap: .15rem; }
            .note-meta { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem .7rem; margin: -.45rem 0 var(--space-5); color: var(--faint); font-size: .75rem; }
            .tag { display: inline-flex; align-items: center; min-height: 1.55rem; padding: .18rem .48rem; border-radius: 999px; background: var(--accent-wash); color: var(--accent); font-size: .72rem; font-weight: 650; }
            button.tag:hover { text-decoration: underline; }
            .note-backlinks { display: flex; flex-wrap: wrap; align-items: baseline; gap: .35rem .7rem; margin-top: var(--space-4); padding-top: var(--space-3); border-top: 1px solid var(--line); font-size: .82rem; font-family: var(--font-ui); }
            .backlinks-label { color: var(--faint); font-size: .7rem; font-weight: 650; letter-spacing: .06em; text-transform: uppercase; }
            .note-backlinks a { color: var(--accent); text-decoration: none; }
            .note-backlinks a:hover, .note-backlinks a:focus-visible { text-decoration: underline; }
            .backlinks-more { color: var(--faint); }
            .prose { min-width: 0; max-width: var(--measure); overflow-wrap: break-word; color: var(--ink); font-family: var(--font-copy); font-size: var(--copy-size); line-height: 1.72; }
            .prose > * + * { margin-top: 1.05em; }
            .prose h3, .prose h4, .prose h5 { margin-top: 1.55em; font-weight: 650; line-height: 1.2; letter-spacing: -.02em; }
            .prose h3 { font-size: 1.7em; } .prose h4 { font-size: 1.4em; } .prose h5 { font-size: 1.18em; }
            .prose ul, .prose ol { padding-left: 1.25em; }
            .prose li + li { margin-top: .3em; }
            .prose blockquote { padding-left: 1em; border-left: 2px solid var(--accent); color: var(--muted); font-style: italic; }
            .prose code { overflow-wrap: anywhere; padding: .12em .32em; border-radius: var(--radius-sm); background: var(--canvas); font-family: var(--font-code); font-size: .82em; }
            .prose pre { overflow: auto; padding: 1rem; border: 1px solid var(--line); background: var(--canvas); font: .83rem/1.55 var(--font-code); }
            .prose pre code { padding: 0; background: none; font: inherit; }
            .prose hr { margin: 1.8em 0; border: 0; border-top: 1px solid var(--line); }
            .prose-plain { max-width: var(--measure); }
            .render-notice { padding: .65rem .75rem; border-left: 3px solid var(--accent); background: var(--accent-wash); color: var(--muted); font: .8rem/1.5 var(--font-ui); }
            .plain-note { width: 100%; min-height: 20rem; resize: vertical; padding: 1rem; border: 1px solid var(--line); border-radius: var(--radius); background: var(--canvas); color: var(--ink); font: .85rem/1.55 var(--font-code); }
            .empty-story { max-width: 32rem; padding: 12vh 0; }
            .empty-story .kicker { margin-bottom: var(--space-3); color: var(--accent); font-size: .72rem; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; }
            .empty-story h2 { margin-bottom: var(--space-4); font-family: var(--font-copy); font-size: clamp(1.8rem, 6vw, 2.6rem); font-weight: 550; letter-spacing: -.035em; line-height: 1.05; }
            .empty-story p { color: var(--muted); line-height: 1.6; }
            .empty-story .button { margin-top: var(--space-5); }
            .editor { padding-top: var(--space-2); }
            .editor-grid { display: grid; gap: var(--space-5); }
            .field label, .preview-label { display: flex; justify-content: space-between; margin-bottom: .5rem; color: var(--muted); font-size: .72rem; font-weight: 760; letter-spacing: .09em; text-transform: uppercase; }
            .field input, .field textarea { width: 100%; border: 1px solid var(--line-strong); border-radius: var(--radius); background: var(--paper); color: var(--ink); transition: border-color var(--motion), box-shadow var(--motion); }
            .field input:focus, .field textarea:focus, .appearance-custom textarea:focus { border-color: var(--accent); box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 16%, transparent); }
            .field textarea { min-height: 15rem; resize: vertical; padding: 1rem; font: .94rem/1.65 var(--font-code); tab-size: 2; }
            .field-title textarea { min-height: 3.6rem; padding: .55rem 0; border-width: 0 0 1px; border-radius: 0; font: 600 clamp(2rem, 6vw, 3rem)/1.1 var(--font-copy); letter-spacing: -.03em; }
            .field-tags textarea { min-height: 5rem; padding: .6rem .75rem; }
            .field-hint { margin-top: .45rem; color: var(--faint); font-size: .75rem; line-height: 1.45; }
            .editor-preview { padding: var(--space-5) 0; border-top: 1px solid var(--line); border-bottom: 1px solid var(--line); }
            .editor-actions { position: sticky; z-index: 5; bottom: 0; display: flex; align-items: center; gap: .6rem; padding: .8rem 0; background: var(--paper); }
            .save-status { min-width: 0; margin-left: .3rem; color: var(--muted); font-size: .8rem; }
            .save-status[data-kind="error"], .global-status[data-kind="error"], .appearance-status[data-kind="error"] { color: var(--danger); }
            .conflict-panel { padding: .9rem; border-left: 3px solid var(--danger); background: var(--danger-wash); color: var(--ink); font-size: .84rem; line-height: 1.5; }
            .conflict-panel p { margin-top: .25rem; color: var(--muted); }
            .conflict-actions { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: .75rem; }
            .delete-row { display: flex; flex-wrap: wrap; align-items: center; gap: .6rem; margin-top: var(--space-5); padding: .8rem; border-left: 3px solid var(--danger); background: var(--danger-wash); font-size: .84rem; }
            .delete-row span { margin-right: auto; }
            .global-status { color: var(--muted); }
            .appearance-dialog { width: min(calc(100% - 2rem), 40rem); max-height: min(88vh, 48rem); max-height: min(88dvh, 48rem); padding: 0; border: 1px solid var(--line-strong); border-radius: var(--radius); background: var(--paper); color: var(--ink); box-shadow: var(--shadow); font: 16px/1.45 var(--font-ui); }
            .appearance-dialog::backdrop { background: var(--overlay); }
            .appearance-form { max-height: inherit; display: grid; grid-template-rows: auto minmax(0, 1fr) auto; }
            .appearance-header { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: var(--space-4); align-items: start; padding: var(--space-5) var(--space-5) var(--space-4); border-bottom: 1px solid var(--line); }
            .appearance-kicker { margin-bottom: .2rem; color: var(--accent); font-size: .7rem; font-weight: 800; letter-spacing: .11em; text-transform: uppercase; }
            .appearance-header h2 { font-family: var(--font-editorial); font-size: 1.65rem; font-weight: 620; letter-spacing: -.025em; line-height: 1.1; }
            .appearance-body { min-height: 0; overflow: auto; padding: var(--space-5); }
            .appearance-intro { margin-bottom: var(--space-5); color: var(--muted); font-size: .87rem; }
            .appearance-group { min-width: 0; margin: 0 0 var(--space-5); padding: 0; border: 0; }
            .appearance-group legend { width: 100%; margin-bottom: .55rem; color: var(--ink); font-size: .78rem; font-weight: 720; }
            .appearance-group legend span, .appearance-custom summary span { float: right; color: var(--faint); font-size: .7rem; font-weight: 500; }
            .appearance-options { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .45rem; }
            .appearance-options.palette-options { grid-template-columns: repeat(4, minmax(0, 1fr)); }
            .appearance-option { position: relative; min-width: 0; cursor: pointer; }
            .appearance-option input { position: absolute; width: 1px; height: 1px; opacity: 0; }
            .appearance-option > span { min-height: 2.75rem; display: flex; align-items: center; justify-content: center; gap: .45rem; padding: .55rem .45rem; border: 1px solid var(--line); border-radius: var(--radius-sm); color: var(--muted); font-size: .78rem; font-weight: 650; text-align: center; transition: border-color var(--motion), background var(--motion), color var(--motion); }
            .appearance-option:hover > span { border-color: var(--line-strong); color: var(--ink); }
            .appearance-option input:checked + span { border-color: var(--accent); background: var(--accent-wash); color: var(--ink); box-shadow: inset 0 0 0 1px var(--accent); }
            .appearance-option input:focus-visible + span { outline: 2px solid var(--accent); outline-offset: 3px; }
            .appearance-option:has(input:disabled) { cursor: not-allowed; }
            .appearance-option input:disabled + span { opacity: .5; }
            .appearance-custom { margin: 0 0 var(--space-5); border: 1px solid var(--line); border-radius: var(--radius); }
            .appearance-custom summary { padding: .8rem .9rem; color: var(--ink); cursor: pointer; font-size: .8rem; font-weight: 720; }
            .appearance-custom-body { padding: 0 .9rem .9rem; }
            .appearance-custom-body p { margin-bottom: .65rem; color: var(--muted); font-size: .76rem; line-height: 1.5; }
            .appearance-custom textarea { width: 100%; min-height: 9rem; resize: vertical; padding: .75rem; border: 1px solid var(--line-strong); border-radius: var(--radius-sm); background: var(--canvas); color: var(--ink); font: .78rem/1.55 var(--font-code); tab-size: 2; }
            .palette-swatch { width: .8rem; height: .8rem; flex: none; border: 1px solid rgb(0 0 0 / .16); border-radius: 50%; background: #176b63; }
            .palette-swatch[data-palette="ocean"] { background: #247dab; }
            .palette-swatch[data-palette="plum"] { background: #8d4b78; }
            .palette-swatch[data-palette="mono"] { background: #686e6a; }
            .appearance-readonly { margin: calc(-1 * var(--space-2)) 0 var(--space-5); padding: .65rem .75rem; border-left: 3px solid var(--danger); background: var(--danger-wash); color: var(--ink); font-size: .78rem; }
            .safe-appearance { position: fixed; z-index: 100; right: 1rem; bottom: 1rem; max-width: 28rem; padding: .75rem 1rem; border: 1px solid var(--line-strong); border-radius: var(--radius); background: var(--paper); color: var(--ink); box-shadow: var(--shadow); font: .82rem/1.45 var(--font-ui); }
            .safe-appearance a { color: var(--accent); font-weight: 700; }
            .appearance-actions { display: flex; align-items: center; gap: .5rem; padding: var(--space-4) var(--space-5); border-top: 1px solid var(--line); background: var(--paper); }
            .appearance-status { min-width: 0; margin-right: auto; color: var(--muted); font-size: .76rem; }
            .visually-hidden { position: absolute !important; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0; }
            noscript { display: block; padding: 2rem; font-family: var(--font-ui); }
        }

        @layer states {
            [hidden] { display: none !important; }
            body[data-appearance="open"] { overflow: hidden; }
            .icon-button.mobile-only { display: none; }
            @media (max-width: 760px) {
                .app-bar { grid-template-columns: auto 1fr auto; }
                .brand { padding: 0 .8rem; border-right: 0; }
                .brand-mark { display: none; }
                .bar-context { padding: 0 .4rem; text-align: center; }
                .bar-actions { padding-right: .5rem; }
                .appearance-button { width: 2.75rem; padding: 0; font-size: 0; }
                .bar-actions .button-primary { width: 2.75rem; padding: 0; font-size: 0; }
                .bar-actions .button-primary::after { content: "+"; font-size: 1.35rem; font-weight: 400; }
                .icon-button.mobile-only { display: grid; }
                .shell { display: block; }
                .library { position: fixed; z-index: 50; inset: var(--bar) auto 0 0; width: min(88vw, 20rem); height: calc(100vh - var(--bar)); height: calc(100dvh - var(--bar)); visibility: hidden; pointer-events: none; transform: translateX(-102%); transition: transform var(--motion), visibility 0s linear var(--motion); box-shadow: var(--shadow); }
                body[data-drawer="open"] { overflow: hidden; }
                body[data-drawer="open"] .library { visibility: visible; pointer-events: auto; transform: translateX(0); transition-delay: 0s; }
                .drawer-shade { position: fixed; z-index: 40; inset: var(--bar) 0 0; display: block; border: 0; background: var(--overlay); opacity: 0; pointer-events: none; transition: opacity var(--motion); }
                body[data-drawer="open"] .drawer-shade { opacity: 1; pointer-events: auto; }
                .story { padding: var(--space-6) 1.2rem 20vh; }
                .note-header { gap: .5rem; }
                .note-actions { flex-direction: column; }
                .editor-actions { flex-wrap: wrap; padding-bottom: calc(.8rem + env(safe-area-inset-bottom)); }
                .save-status { order: 2; flex-basis: 100%; margin-left: 0; }
                .field input, .field textarea { font-size: 16px; }
                .field-title textarea { font-size: var(--title-size); }
            }
            @media (max-width: 600px) {
                .appearance-dialog { width: 100%; max-width: none; height: 100vh; height: 100dvh; max-height: none; margin: 0; border: 0; border-radius: 0; }
                .appearance-form { height: 100%; }
                .appearance-header, .appearance-body, .appearance-actions { padding-left: 1rem; padding-right: 1rem; }
                .appearance-options.palette-options { grid-template-columns: repeat(2, minmax(0, 1fr)); }
                .appearance-actions { flex-wrap: wrap; padding-bottom: calc(1rem + env(safe-area-inset-bottom)); }
                .appearance-status { order: 3; flex-basis: 100%; }
            }
            @media (prefers-reduced-motion: reduce) {
                html { scroll-behavior: auto; }
                *, *::before, *::after { transition-duration: .01ms !important; animation-duration: .01ms !important; }
            }
            @media (forced-colors: active) {
                .library-item[aria-current="true"] { border-left-width: 4px; }
                .tag, .button, .appearance-option > span { border: 1px solid currentColor; }
                .appearance-option input:checked + span { outline: 2px solid SelectedItem; }
            }
        }
    </style>
    <style nonce="<?= piplet_h($nonce) ?>" id="piplet-custom-style"></style>
    <script nonce="<?= piplet_h($nonce) ?>">document.getElementById('piplet-custom-style').textContent = <?= $customCssJson ?>;</script>
</head>
<body>
    <a class="skip-link" href="#main">Skip to notes</a>
    <?php if ($safeAppearance): ?><p class="safe-appearance" role="status">Custom CSS is off for this page. You can edit or clear it in Appearance, then <a href="?">leave safe mode</a>.</p><?php endif ?>
    <header class="app-bar">
        <div class="brand"><button class="icon-button mobile-only" id="menu-button" aria-label="Open note index" aria-expanded="false" aria-controls="library"><svg viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button><span class="brand-mark" aria-hidden="true"></span><span>piplet</span></div>
        <div class="bar-context" id="bar-context"><span class="global-status" id="global-status" role="status" aria-live="polite" aria-atomic="true"></span></div>
        <div class="bar-actions">
            <button class="button button-quiet appearance-button" id="appearance-button" type="button" aria-haspopup="dialog" aria-controls="appearance-dialog"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h10m4 0h2M4 17h2m4 0h10M14 4v6M6 14v6"/></svg><span>Appearance</span></button>
            <button class="button button-primary" id="new-button">New note</button>
        </div>
    </header>
    <div class="shell">
        <aside class="library" id="library" aria-label="Note index">
            <div class="search"><label for="search-input">Find a note</label><input id="search-input" type="search" placeholder="Title, text, or #tag" autocomplete="off"></div>
            <div class="library-heading"><span>Notes</span><span class="note-count" id="note-count"></span></div>
            <nav aria-label="All notes"><ul class="library-list" id="library-list"></ul></nav>
            <div class="storage"><strong id="storage-state"></strong><br><a href="?download=1">Download a snapshot</a></div>
        </aside>
        <button class="drawer-shade" id="drawer-shade" aria-label="Close note index" aria-hidden="true" tabindex="-1"></button>
        <main class="story-wrap" id="main" tabindex="-1"><h1 class="visually-hidden">piplet notes</h1><div class="story" id="story"></div></main>
    </div>
    <dialog class="appearance-dialog" id="appearance-dialog" aria-labelledby="appearance-title" aria-describedby="appearance-intro">
        <form class="appearance-form" id="appearance-form">
            <header class="appearance-header">
                <div><p class="appearance-kicker">Make it yours</p><h2 id="appearance-title">Appearance</h2></div>
                <button class="icon-button" id="appearance-close" type="button" aria-label="Close appearance"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></button>
            </header>
            <div class="appearance-body">
                <p class="appearance-intro" id="appearance-intro">Changes preview immediately. Save to keep the shared choices in this file.</p>
                <fieldset class="appearance-group">
                    <legend>Theme <span>This device only</span></legend>
                    <div class="appearance-options">
                        <label class="appearance-option"><input type="radio" name="appearance-theme" value="system"><span>System</span></label>
                        <label class="appearance-option"><input type="radio" name="appearance-theme" value="light"><span>Light</span></label>
                        <label class="appearance-option"><input type="radio" name="appearance-theme" value="dark"><span>Dark</span></label>
                    </div>
                </fieldset>
                <?php foreach ($appearanceGroups as $key => [$label, $hint, $class]): ?>
                <fieldset class="appearance-group appearance-shared">
                    <legend><?= piplet_h($label) ?><?php if ($hint !== ''): ?> <span><?= piplet_h($hint) ?></span><?php endif ?></legend>
                    <div class="appearance-options <?= piplet_h($class) ?>">
                        <?php foreach (PIPLET_APPEARANCE_OPTIONS[$key] as $value): ?>
                        <label class="appearance-option"><input type="radio" name="appearance-<?= piplet_h($key) ?>" value="<?= piplet_h($value) ?>"><span><?php if ($key === 'palette'): ?><i class="palette-swatch" data-palette="<?= piplet_h($value) ?>" aria-hidden="true"></i><?php endif ?><?= piplet_h($appearanceLabels[$value] ?? ucfirst($value)) ?></span></label>
                        <?php endforeach ?>
                    </div>
                </fieldset>
                <?php endforeach ?>
                <details class="appearance-custom appearance-shared">
                    <summary>Custom CSS <span>32 KiB max</span></summary>
                    <div class="appearance-custom-body">
                        <p>This stylesheet comes after the built-in theme and can change any selector. Remote assets are blocked. If a rule makes the interface unusable, reopen the page with <code>?safe=1</code>.</p>
                        <label class="visually-hidden" for="appearance-css">Custom CSS</label>
                        <textarea id="appearance-css" name="appearance-css" spellcheck="false" autocomplete="off" placeholder=":root { --story-width: 60rem; }&#10;.note-title { letter-spacing: 0; }"></textarea>
                    </div>
                </details>
                <p class="appearance-readonly" id="appearance-readonly" hidden>This file is read-only. Theme can still be saved on this device.</p>
            </div>
            <footer class="appearance-actions">
                <button class="button button-quiet" id="appearance-reset" type="button">Restore defaults</button>
                <span class="appearance-status" id="appearance-status" role="status" aria-live="polite"></span>
                <button class="button button-quiet" id="appearance-cancel" type="button">Cancel</button>
                <button class="button button-primary" id="appearance-save" type="submit">Save</button>
            </footer>
        </form>
    </dialog>
    <noscript>piplet needs JavaScript for its live editor. The data remains ordinary JSON inside this file.</noscript>
    <script type="application/octet-stream" id="piplet-state" nonce="<?= piplet_h($nonce) ?>"><?= $bootBase64 ?></script>
    <script nonce="<?= piplet_h($nonce) ?>">
    (() => {
        'use strict';

        const encodedState = document.querySelector('#piplet-state').textContent.trim();
        if (encodedState.length > 12 * 1024 * 1024) throw new Error('Browser state is too large.');
        const stateBytes = Uint8Array.from(atob(encodedState), character => character.charCodeAt(0));
        if (stateBytes.byteLength > 9 * 1024 * 1024) throw new Error('Browser state is too large.');
        const boot = JSON.parse(new TextDecoder('utf-8', {fatal: true}).decode(stateBytes));
        const csrf = document.querySelector('meta[name="piplet-csrf"]').content;
        const notes = new Map(Object.entries(boot.document.notes || {}));
        const els = Object.fromEntries([
            'story', 'library-list', 'search-input', 'note-count', 'new-button',
            'global-status', 'storage-state', 'menu-button',
            'drawer-shade', 'library', 'main', 'appearance-button',
            'appearance-dialog', 'appearance-form', 'appearance-close',
            'appearance-reset', 'appearance-cancel', 'appearance-save',
            'appearance-status', 'appearance-readonly', 'appearance-css',
            'piplet-custom-style'
        ].map(id => [id, document.getElementById(id)]));
        const storageScope = `piplet:${location.pathname}:`;
        const themeStorageKey = `${storageScope}theme`;
        const mobileViewport = matchMedia('(max-width: 760px)');
        const colorScheme = matchMedia('(prefers-color-scheme: dark)');
        const appearanceDefaults = Object.freeze(boot.appearanceDefaults);
        const appearanceKeys = Object.keys(appearanceDefaults);
        const maxOpenNotes = 20;
        const maxLibraryNotes = 40;

        function safeStorage(name) {
            return {
                read(key) { try { return window[name].getItem(key); } catch (_) { return null; } },
                readResult(key) {
                    try { return {ok: true, value: window[name].getItem(key)}; }
                    catch (_) { return {ok: false, value: null}; }
                },
                write(key, value) { try { window[name].setItem(key, value); return true; } catch (_) { return false; } },
                remove(key) { try { window[name].removeItem(key); return true; } catch (_) { return false; } }
            };
        }
        const {read: sessionRead, readResult: sessionReadResult, write: sessionWrite, remove: sessionRemove} = safeStorage('sessionStorage');
        const {read: localRead, write: localWrite} = safeStorage('localStorage');

        let openNotes = readOpenNotes();
        let editing = null;
        let pendingDelete = null;
        let query = '';
        let draftTimer = null;
        let previewTimer = null;
        let searchTimer = null;
        let globalStatusTimer = null;
        let noteSaving = false;
        let savedAppearance = {...appearanceDefaults, ...boot.appearance};
        let appearanceDraft = null;
        let savedThemeChoice = readThemeChoice();
        let themeDraft = savedThemeChoice;
        let appearanceSaving = false;

        function readThemeChoice() {
            const choice = localRead(themeStorageKey) || 'system';
            return ['system', 'light', 'dark'].includes(choice) ? choice : 'system';
        }

        function applyTheme(choice) {
            document.documentElement.dataset.theme = choice === 'system'
                ? (colorScheme.matches ? 'dark' : 'light')
                : choice;
        }

        function appearanceValues(record) {
            return {
                ...Object.fromEntries(appearanceKeys.map(key => [key, record[key] || appearanceDefaults[key]])),
                customCss: typeof record.customCss === 'string' ? record.customCss : ''
            };
        }

        function applyAppearance(record) {
            const values = appearanceValues(record);
            for (const key of appearanceKeys) {
                document.documentElement.dataset[key] = values[key];
            }
            els['piplet-custom-style'].textContent = boot.safeAppearance ? '' : values.customCss;
        }

        function contrastRatio(first, second) {
            const luminance = color => {
                const match = color.trim().match(/^#([0-9a-f]{6})$/i);
                if (!match) return null;
                const channels = match[1].match(/../g).map(value => parseInt(value, 16) / 255)
                    .map(value => value <= .04045 ? value / 12.92 : ((value + .055) / 1.055) ** 2.4);
                return .2126 * channels[0] + .7152 * channels[1] + .0722 * channels[2];
            };
            const a = luminance(first), b = luminance(second);
            return a === null || b === null ? Infinity : (Math.max(a, b) + .05) / (Math.min(a, b) + .05);
        }

        function appearanceContrastWarning() {
            const style = getComputedStyle(document.documentElement);
            const value = name => style.getPropertyValue(name);
            return contrastRatio(value('--ink'), value('--paper')) < 4.5
                || contrastRatio(value('--faint'), value('--canvas')) < 4.5
                || contrastRatio(value('--faint'), value('--accent-wash')) < 4.5;
        }

        function writeAppearanceForm() {
            const values = appearanceValues(appearanceDraft || savedAppearance);
            const choices = {theme: themeDraft, ...Object.fromEntries(appearanceKeys.map(key => [key, values[key]]))};
            for (const [key, value] of Object.entries(choices)) {
                const input = els['appearance-form'].querySelector(`input[name="appearance-${key}"][value="${value}"]`);
                if (input) input.checked = true;
            }
            els['appearance-css'].value = values.customCss;
        }

        function readAppearanceForm() {
            const data = new FormData(els['appearance-form']);
            themeDraft = String(data.get('appearance-theme') || themeDraft);
            const choices = {
                ...Object.fromEntries(appearanceKeys.map(key => [key, String(data.get(`appearance-${key}`) || appearanceDraft?.[key] || appearanceDefaults[key])])),
                customCss: els['appearance-css'].value
            };
            if (new Blob([choices.customCss]).size > boot.maxCustomCssBytes) throw new Error('Custom CSS must be no larger than 32 KiB.');
            appearanceDraft = choices;
            applyTheme(themeDraft);
            applyAppearance(appearanceDraft);
        }

        function previewAppearance() {
            try {
                readAppearanceForm();
                els['appearance-status'].dataset.kind = '';
                els['appearance-status'].textContent = appearanceContrastWarning()
                    ? 'Contrast warning: some text may be hard to read.'
                    : '';
                return true;
            } catch (error) {
                els['appearance-status'].dataset.kind = 'error';
                els['appearance-status'].textContent = error.message;
                return false;
            }
        }

        function setAppearanceBusy(busy) {
            appearanceSaving = busy;
            for (const input of els['appearance-form'].querySelectorAll('input, textarea')) {
                input.disabled = busy || (!boot.writable && input.name !== 'appearance-theme');
            }
            els['appearance-close'].disabled = busy;
            els['appearance-reset'].disabled = busy;
            els['appearance-cancel'].disabled = busy;
            els['appearance-save'].disabled = busy;
        }

        function openAppearance() {
            appearanceDraft = appearanceValues(savedAppearance);
            themeDraft = savedThemeChoice;
            writeAppearanceForm();
            applyTheme(themeDraft);
            applyAppearance(appearanceDraft);
            els['appearance-status'].dataset.kind = '';
            els['appearance-status'].textContent = '';
            els['appearance-readonly'].hidden = boot.writable;
            els['appearance-reset'].textContent = boot.writable ? 'Restore defaults' : 'Use system theme';
            els['appearance-save'].textContent = boot.writable ? 'Save' : 'Save theme';
            setAppearanceBusy(false);
            document.body.dataset.appearance = 'open';
            els['appearance-dialog'].showModal();
        }

        function closeAppearance(saved = false) {
            if (appearanceSaving) return;
            if (!saved) {
                applyTheme(savedThemeChoice);
                applyAppearance(savedAppearance);
            }
            appearanceDraft = null;
            document.body.dataset.appearance = '';
            els['appearance-dialog'].close();
            requestAnimationFrame(() => els['appearance-button'].focus());
        }

        function restoreAppearanceDefaults() {
            themeDraft = 'system';
            if (boot.writable) appearanceDraft = {...appearanceDefaults, customCss: ''};
            writeAppearanceForm();
            applyTheme(themeDraft);
            applyAppearance(appearanceDraft || savedAppearance);
            els['appearance-status'].dataset.kind = '';
            els['appearance-status'].textContent = boot.writable
                ? 'Defaults are previewed. Save to keep them.'
                : 'System theme is previewed. Save to keep it on this device.';
        }

        function element(tag, className, text) {
            const node = document.createElement(tag);
            if (className) node.className = className;
            if (text !== undefined) node.textContent = text;
            return node;
        }

        function textButton(text, className, onClick) {
            const button = element('button', className, text);
            button.type = 'button';
            button.addEventListener('click', onClick);
            return button;
        }

        function iconButton(label, path, onClick) {
            const button = textButton('', 'icon-button', onClick);
            button.setAttribute('aria-label', label);
            button.title = label;
            button.innerHTML = `<svg viewBox="0 0 24 24" aria-hidden="true"><path d="${path}"/></svg>`;
            return button;
        }

        function sortedNotes() {
            return [...notes.values()].sort((a, b) => b.updated.localeCompare(a.updated));
        }

        function readOpenNotes() {
            try {
                const raw = sessionRead(`${storageScope}open`) || '[]';
                if (raw.length > 8192) throw new Error('Open-note state is too large.');
                const stored = JSON.parse(raw);
                const valid = Array.isArray(stored) ? stored.filter(id => notes.has(id)) : [];
                if (valid.length) return [...new Set(valid)].slice(0, maxOpenNotes);
            } catch (_) {}
            const first = sortedNotes()[0];
            return first ? [first.id] : [];
        }

        function prioritizeOpenNote(id) {
            const prioritized = [id, ...openNotes.filter(open => open !== id)];
            const protectedId = editing?.id;
            if (protectedId && !prioritized.slice(0, maxOpenNotes).includes(protectedId)) {
                return [...prioritized.filter(open => open !== protectedId).slice(0, maxOpenNotes - 1), protectedId];
            }
            return prioritized.slice(0, maxOpenNotes);
        }

        function saveOpenNotes() {
            sessionWrite(`${storageScope}open`, JSON.stringify(openNotes));
        }

        function replaceHash(id = openNotes[0]) {
            const path = location.pathname.replace(/^\/+/, '/');
            history.replaceState(null, '', `${path}${location.search}${id ? `#${encodeURIComponent(id)}` : ''}`);
        }

        function shortDate(value) {
            const date = new Date(value);
            return Number.isNaN(date.valueOf()) ? '' : new Intl.DateTimeFormat(undefined, {month: 'short', day: 'numeric', year: date.getFullYear() === new Date().getFullYear() ? undefined : 'numeric'}).format(date);
        }

        function clearGlobalStatus() {
            clearTimeout(globalStatusTimer);
            globalStatusTimer = null;
            els['global-status'].dataset.kind = '';
            els['global-status'].textContent = '';
        }

        function clearTransientGlobalStatus() {
            if (globalStatusTimer !== null) clearGlobalStatus();
        }

        function setGlobalStatus(message, kind = '', transient = kind === '') {
            clearTimeout(globalStatusTimer);
            globalStatusTimer = null;
            els['global-status'].dataset.kind = kind;
            els['global-status'].textContent = message;
            if (message && transient) {
                globalStatusTimer = setTimeout(clearGlobalStatus, 4000);
            }
        }

        function updateDrawerAccess() {
            const open = document.body.dataset.drawer === 'open';
            if (mobileViewport.matches) {
                els.library.toggleAttribute('inert', !open);
                els.main.toggleAttribute('inert', open);
                document.querySelector('.bar-actions').toggleAttribute('inert', open);
                if (open) {
                    els.library.setAttribute('role', 'dialog');
                    els.library.setAttribute('aria-modal', 'true');
                } else {
                    els.library.removeAttribute('role');
                    els.library.removeAttribute('aria-modal');
                }
            } else {
                els.library.removeAttribute('inert');
                els.main.removeAttribute('inert');
                document.querySelector('.bar-actions').removeAttribute('inert');
                els.library.removeAttribute('role');
                els.library.removeAttribute('aria-modal');
            }
            // The shade is a pointer target, not part of the modal drawer's
            // keyboard surface. Keep assistive-technology focus in #library.
            els['drawer-shade'].tabIndex = -1;
            els['drawer-shade'].setAttribute('aria-hidden', 'true');
        }

        function openDrawer() {
            if (!mobileViewport.matches) return;
            document.body.dataset.drawer = 'open';
            els['menu-button'].setAttribute('aria-expanded', 'true');
            updateDrawerAccess();
            requestAnimationFrame(() => els['search-input'].focus());
        }

        function closeDrawer(restoreFocus = false) {
            document.body.dataset.drawer = '';
            els['menu-button'].setAttribute('aria-expanded', 'false');
            updateDrawerAccess();
            if (restoreFocus && mobileViewport.matches) els['menu-button'].focus();
        }

        function resolveNote(target, encoded = false) {
            let clean = String(target || '');
            if (encoded) {
                try { clean = decodeURIComponent(clean); }
                catch (_) {}
            }
            clean = clean.toLowerCase();
            if (notes.has(clean)) return clean;
            const match = [...notes.values()].find(note => note.title.toLowerCase() === clean);
            return match?.id || null;
        }

        function openNote(id, updateHash = true) {
            if (noteSaving || !notes.has(id)) return;
            openNotes = prioritizeOpenNote(id);
            saveOpenNotes();
            if (updateHash) replaceHash(id);
            render();
            closeDrawer();
            requestAnimationFrame(() => {
                const note = document.getElementById(`piplet-note-${id}`);
                note?.scrollIntoView({block: 'start'});
                note?.querySelector('.note-title')?.focus();
            });
        }

        function closeNote(id) {
            if (noteSaving) return;
            if (editing?.id === id && !editing.readOnlyRecovery) {
                if (!flushDraft()) {
                    setEditorStatus('This browser could not keep a recovery draft. Save the note before closing it.');
                    return;
                }
                editing = null;
            }
            openNotes = openNotes.filter(open => open !== id);
            saveOpenNotes();
            replaceHash();
            render();
            requestAnimationFrame(() => {
                const target = openNotes[0] ? document.querySelector(`#piplet-note-${CSS.escape(openNotes[0])} .note-title`) : els['new-button'];
                target?.focus();
            });
        }

        function renderLibrary() {
            const normalized = query.trim().toLowerCase();
            const tagOnly = normalized.startsWith('#') ? normalized.slice(1) : null;
            const filtered = sortedNotes().filter(note => {
                if (!normalized) return true;
                if (tagOnly !== null) return note.tags.some(tag => tag.toLowerCase().includes(tagOnly));
                return `${note.title}\n${note.body}\n${note.tags.join(' ')}`.toLowerCase().includes(normalized);
            });

            els['library-list'].replaceChildren();
            for (const note of filtered.slice(0, maxLibraryNotes)) {
                const li = element('li');
                const button = textButton('', 'library-item', () => openNote(note.id));
                button.setAttribute('aria-current', openNotes[0] === note.id ? 'true' : 'false');
                button.append(element('span', 'library-title', note.title));
                const meta = note.tags.length ? note.tags.map(tag => `#${tag}`).join(' · ') : `Edited ${shortDate(note.updated)}`;
                button.append(element('span', 'library-meta', meta));
                li.append(button);
                els['library-list'].append(li);
            }
            if (filtered.length > maxLibraryNotes) {
                els['library-list'].append(element('li', 'library-empty', `Showing the newest ${maxLibraryNotes} of ${filtered.length} matches. Refine the search to find older notes.`));
            }
            if (!filtered.length) els['library-list'].append(element('li', 'library-empty', normalized ? 'No notes match that search.' : 'Your first note starts here.'));
            els['note-count'].textContent = normalized ? `${filtered.length}/${notes.size}` : String(notes.size);
        }

        function appendInline(parent, text, budget) {
            const append = node => {
                if (++budget.nodes > budget.max) { budget.exhausted = true; return false; }
                parent.append(node);
                return true;
            };
            let cursor = 0;
            const nextOpener = /\[\[|\*\*|`/g;
            while (cursor < text.length && !budget.exhausted) {
                nextOpener.lastIndex = cursor;
                const match = nextOpener.exec(text);
                if (!match) { append(document.createTextNode(text.slice(cursor))); break; }
                const start = match.index;
                const kind = match[0] === '[[' ? 'wiki' : match[0] === '**' ? 'strong' : 'code';
                if (start > cursor && !append(document.createTextNode(text.slice(cursor, start)))) break;

                const opener = kind === 'code' ? '`' : kind === 'wiki' ? '[[' : '**';
                const closer = kind === 'code' ? '`' : kind === 'wiki' ? ']]' : '**';
                const end = text.indexOf(closer, start + opener.length);
                if (end < 0 || end === start + opener.length || (kind === 'wiki' && text.slice(start + 2, end).includes(']'))) {
                    append(document.createTextNode(text.slice(start)));
                    break;
                }
                const inside = text.slice(start + opener.length, end);
                if (kind === 'wiki') {
                    const divider = inside.indexOf('|');
                    const label = (divider < 0 ? inside : inside.slice(0, divider)).trim();
                    const target = (divider < 0 ? inside : inside.slice(divider + 1)).trim();
                    const link = element('a', '', label || target);
                    try {
                        link.href = `#${encodeURIComponent(target)}`;
                    } catch (_) {
                        append(document.createTextNode(text.slice(start, end + closer.length)));
                        cursor = end + closer.length;
                        continue;
                    }
                    link.dataset.wiki = target;
                    append(link);
                } else if (kind === 'strong') {
                    append(element('strong', '', inside));
                } else {
                    append(element('code', '', inside));
                }
                cursor = end + closer.length;
            }
        }

        function renderPlainBody(body, preview = false) {
            const root = element('div', 'prose prose-plain');
            root.append(element('p', 'render-notice', preview
                ? 'Rich preview is paused for this large or highly structured note.'
                : 'Rich formatting is paused to keep this large note responsive. The complete text is below.'));
            if (!preview) {
                const plain = element('textarea', 'plain-note');
                plain.readOnly = true;
                plain.spellcheck = false;
                plain.setAttribute('aria-label', 'Complete note text');
                plain.value = body;
                root.append(plain);
            }
            return root;
        }

        function renderProse(body, preview = false, storyBudget = null) {
            if (body.length > 256 * 1024) return renderPlainBody(body, preview);
            if (storyBudget && (storyBudget.characters + body.length > storyBudget.maxCharacters
                || storyBudget.nodes >= storyBudget.maxNodes)) return renderPlainBody(body, preview);
            let lineCount = 1;
            for (let cursor = 0; cursor < body.length && lineCount <= 2000; cursor++) {
                const character = body.charCodeAt(cursor);
                if (character === 10 || (character === 13 && body.charCodeAt(cursor + 1) !== 10)) lineCount++;
            }
            if (lineCount > 2000) return renderPlainBody(body, preview);

            const root = element('div', 'prose');
            const lines = body.replace(/\r\n?/g, '\n').split('\n');
            const budget = {
                nodes: 0,
                max: Math.min(2000, storyBudget ? storyBudget.maxNodes - storyBudget.nodes : 2000),
                exhausted: false
            };
            let index = 0;
            while (index < lines.length) {
                if (budget.exhausted || budget.nodes > budget.max) return renderPlainBody(body, preview);
                const line = lines[index];
                if (!line.trim()) { index++; continue; }
                if (line.startsWith('```')) {
                    const code = [];
                    index++;
                    while (index < lines.length && !lines[index].startsWith('```')) code.push(lines[index++]);
                    if (index < lines.length) index++;
                    const pre = element('pre');
                    pre.append(element('code', '', code.join('\n')));
                    root.append(pre);
                    budget.nodes += 2;
                    continue;
                }
                const heading = line.match(/^(#{1,3})\s+(.+)$/);
                if (heading) {
                    const h = element(`h${heading[1].length + 2}`);
                    appendInline(h, heading[2], budget);
                    root.append(h); budget.nodes++; index++; continue;
                }
                if (/^---+$/.test(line.trim())) { root.append(element('hr')); budget.nodes++; index++; continue; }
                const listMatch = line.match(/^([-*]|\d+\.)\s+/);
                if (listMatch) {
                    const ordered = /^\d/.test(listMatch[1]);
                    const itemPattern = ordered ? /^\d+\.\s+/ : /^[-*]\s+/;
                    const list = element(ordered ? 'ol' : 'ul');
                    budget.nodes++;
                    while (index < lines.length && !budget.exhausted && budget.nodes <= budget.max && itemPattern.test(lines[index])) {
                        const item = element('li'); appendInline(item, lines[index].replace(itemPattern, ''), budget); list.append(item); budget.nodes++; index++;
                    }
                    root.append(list); continue;
                }
                if (/^>\s+/.test(line)) {
                    const quote = element('blockquote');
                    const parts = [];
                    while (index < lines.length && /^>\s+/.test(lines[index])) parts.push(lines[index++].replace(/^>\s+/, ''));
                    appendInline(quote, parts.join(' '), budget); root.append(quote); budget.nodes++; continue;
                }
                const paragraph = [];
                while (index < lines.length && lines[index].trim() && !/^(#{1,3}\s+|```|[-*]\s+|\d+\.\s+|>\s+|---+$)/.test(lines[index])) paragraph.push(lines[index++]);
                if (!paragraph.length) paragraph.push(lines[index++]);
                const p = element('p');
                paragraph.forEach((part, i) => { if (i) { p.append(document.createElement('br')); budget.nodes++; } appendInline(p, part, budget); });
                root.append(p);
                budget.nodes++;
                if (budget.exhausted || budget.nodes > budget.max) return renderPlainBody(body, preview);
            }
            if (!root.childNodes.length) root.append(element('p', '', 'This note is empty.'));
            if (storyBudget) {
                storyBudget.characters += body.length;
                storyBudget.nodes += budget.nodes;
            }
            return root;
        }

        function renderPreview(body) {
            return renderProse(body, true);
        }

        function tagButton(tag) {
            const button = textButton(tag, 'tag', () => {
                query = `#${tag}`;
                els['search-input'].value = query;
                renderLibrary();
                if (mobileViewport.matches) openDrawer();
                els['search-input'].focus();
            });
            button.title = `Find notes tagged ${tag}`;
            return button;
        }

        function collectLineWikiTargets(text, targets) {
            let cursor = 0;
            const nextOpener = /\[\[|\*\*|`/g;
            while (cursor < text.length) {
                nextOpener.lastIndex = cursor;
                const match = nextOpener.exec(text);
                if (!match) return;
                const start = match.index;
                const kind = match[0] === '[[' ? 'wiki' : match[0] === '**' ? 'strong' : 'code';
                const opener = kind === 'code' ? '`' : kind === 'wiki' ? '[[' : '**';
                const closer = kind === 'code' ? '`' : kind === 'wiki' ? ']]' : '**';
                const end = text.indexOf(closer, start + opener.length);
                if (end < 0 || end === start + opener.length || (kind === 'wiki' && text.slice(start + 2, end).includes(']'))) return;
                if (kind === 'wiki') {
                    const inside = text.slice(start + opener.length, end);
                    const divider = inside.indexOf('|');
                    targets.add((divider < 0 ? inside : inside.slice(divider + 1)).trim());
                }
                cursor = end + closer.length;
            }
        }

        function backlinkIndex() {
            const index = new Map();
            for (const source of sortedNotes()) {
                const targets = new Set();
                const lines = source.body.replace(/\r\n?/g, '\n').split('\n');
                let fenced = false;
                for (const line of lines) {
                    if (line.startsWith('```')) { fenced = !fenced; continue; }
                    if (!fenced) collectLineWikiTargets(line, targets);
                }
                const resolved = new Set();
                for (const target of targets) {
                    const id = resolveNote(target);
                    if (id && id !== source.id) resolved.add(id);
                }
                for (const id of resolved) {
                    if (!index.has(id)) index.set(id, []);
                    index.get(id).push(source);
                }
            }
            return index;
        }

        function renderNote(note, storyBudget, backlinks) {
            const article = element('article', 'note');
            article.id = `piplet-note-${note.id}`;
            const header = element('header', 'note-header');
            const title = element('h2', 'note-title', note.title);
            title.tabIndex = -1;
            header.append(title);
            const actions = element('div', 'note-actions');
            if (boot.writable) actions.append(iconButton('Edit note', 'M4 20h4L19 9l-4-4L4 16v4Zm9.5-13.5 4 4', () => editNote(note.id)));
            actions.append(iconButton('Close note', 'M6 6l12 12M18 6 6 18', () => closeNote(note.id)));
            header.append(actions);
            article.append(header);
            const meta = element('div', 'note-meta');
            meta.append(element('span', '', `Edited ${shortDate(note.updated)}`));
            note.tags.forEach(tag => meta.append(tagButton(tag)));
            article.append(meta, renderProse(note.body, false, storyBudget));

            const linking = backlinks?.get(note.id) || [];
            if (linking.length) {
                const row = element('nav', 'note-backlinks');
                row.setAttribute('aria-label', 'Notes that link here');
                row.append(element('span', 'backlinks-label', 'Linked from'));
                for (const source of linking.slice(0, 20)) {
                    const link = element('a', '', source.title || source.id);
                    link.href = `#${encodeURIComponent(source.id)}`;
                    link.dataset.wiki = source.id;
                    row.append(link);
                }
                if (linking.length > 20) row.append(element('span', 'backlinks-more', `and ${linking.length - 20} more`));
                article.append(row);
            }

            if (pendingDelete?.id === note.id) {
                const row = element('div', 'delete-row');
                row.tabIndex = -1;
                row.setAttribute('role', 'group');
                row.setAttribute('aria-label', 'Confirm deletion');
                row.append(element('span', '', 'Delete this note? This cannot be undone.'));
                const cancel = textButton('Keep it', 'button button-quiet', () => {
                    pendingDelete = null;
                    renderStory();
                    requestAnimationFrame(() => document.querySelector(`#piplet-note-${CSS.escape(note.id)} .note-title`)?.focus());
                });
                const remove = textButton('Delete note', 'button button-danger', () => deleteNote(note));
                row.append(cancel, remove); article.append(row);
            }
            return article;
        }

        const draftPrefix = `${storageScope}draft:`;
        let recoveryScanWarning = '';

        function secureToken() {
            if (!globalThis.crypto?.getRandomValues) return null;
            const bytes = new Uint8Array(16);
            crypto.getRandomValues(bytes);
            return [...bytes].map(value => value.toString(16).padStart(2, '0')).join('');
        }

        function wellFormedText(value) {
            if (typeof value.toWellFormed === 'function') return value.toWellFormed();
            return value.replace(/[\uD800-\uDFFF]/g, (unit, index, text) => {
                const code = unit.charCodeAt(0);
                const paired = code <= 0xDBFF
                    ? index + 1 < text.length && text.charCodeAt(index + 1) >= 0xDC00 && text.charCodeAt(index + 1) <= 0xDFFF
                    : index > 0 && text.charCodeAt(index - 1) >= 0xD800 && text.charCodeAt(index - 1) <= 0xDBFF;
                return paired ? unit : '\uFFFD';
            });
        }

        function isDraftKey(key) {
            return typeof key === 'string' && key.startsWith(draftPrefix)
                && key.length <= draftPrefix.length + 128;
        }

        function draftStructureWithin(raw) {
            let structure = 0, depth = 0, quoted = false, escaped = false;
            for (let index = 0; index < raw.length; index++) {
                const character = raw[index];
                if (quoted) {
                    if (escaped) escaped = false;
                    else if (character === '\\') escaped = true;
                    else if (character === '"') quoted = false;
                    continue;
                }
                if (character === '"') { quoted = true; continue; }
                if (!'{}[],:'.includes(character)) continue;
                if (++structure > 256) return false;
                if (character === '{' || character === '[') {
                    if (++depth > 8) return false;
                } else if (character === '}' || character === ']') depth--;
            }
            return true;
        }

        function validDraftRecord(draft) {
            return draft && typeof draft === 'object' && !Array.isArray(draft)
                && (draft.id === null || (typeof draft.id === 'string' && /^[a-z0-9][a-z0-9-]{0,79}$/.test(draft.id)))
                && Number.isSafeInteger(draft.baseRevision) && draft.baseRevision >= 0
                && (draft.baseVersion == null || /^[a-f0-9]{32}$/.test(draft.baseVersion))
                && (draft.baseGeneration == null || /^[a-f0-9]{32}$/.test(draft.baseGeneration))
                && typeof draft.title === 'string' && typeof draft.body === 'string'
                && Array.isArray(draft.tags) && draft.tags.length <= 12
                && draft.tags.every(tag => typeof tag === 'string')
                && (draft.createToken == null || /^[a-f0-9]{32}$/.test(draft.createToken))
                && (draft.draftId == null || /^[a-f0-9]{32}$/.test(draft.draftId))
                && (draft.storageVersion == null || /^[a-f0-9]{32}$/.test(draft.storageVersion));
        }

        function draftRecord(source, storageVersion) {
            return {
                recoveryFormat: 2,
                draftId: source.draftId,
                storageVersion,
                id: source.id,
                baseGeneration: source.baseGeneration,
                baseRevision: source.baseRevision,
                baseVersion: source.baseVersion,
                createToken: source.createToken || null,
                title: source.title,
                body: source.body,
                tags: [...source.tags],
                tagsText: typeof source.tagsText === 'string' ? source.tagsText : JSON.stringify(source.tags)
            };
        }

        function persistDraft(source) {
            if (!source.dirty) return true;
            if (source.body.length + source.title.length + (source.tagsText?.length || 0) > 512 * 1024) {
                source.recoveryWarning = 'This draft is too large for browser recovery. Keep the editor open until you save.';
                return false;
            }
            if (!source.draftId) source.draftId = secureToken();
            if (!source.draftId) {
                source.recoveryWarning = 'Secure browser recovery is unavailable. Keep the editor open until you save.';
                return false;
            }
            if (!source.recoveryKey) source.recoveryKey = `${draftPrefix}v2:${source.draftId}`;
            const storageVersion = secureToken();
            if (!storageVersion) {
                source.recoveryWarning = 'Secure browser recovery is unavailable. Keep the editor open until you save.';
                return false;
            }
            const raw = JSON.stringify(draftRecord(source, storageVersion));
            if (raw.length > 512 * 1024 || !sessionWrite(source.recoveryKey, raw)
                || sessionRead(source.recoveryKey) !== raw) {
                source.recoveryWarning = 'This browser could not store the latest draft. Keep the editor open until you save.';
                return false;
            }
            source.recoveryRaw = raw;
            delete source.recoveryWarning;
            return true;
        }

        function saveDraft() {
            if (!editing || !editing.dirty) return;
            clearTimeout(draftTimer);
            const editor = editing;
            draftTimer = setTimeout(() => {
                if (!persistDraft(editor)) {
                    setEditorStatus(editor.recoveryWarning || 'Recovery storage is unavailable. Keep this editor open until you save.', 'error');
                } else {
                    const status = document.querySelector('.editor .save-status');
                    if (status?.dataset.kind === 'error' && /recovery|too large|could not store/i.test(status.textContent)) {
                        status.dataset.kind = '';
                        status.textContent = 'Changes stay in this browser until you save.';
                    }
                }
            }, 250);
        }

        function flushDraft() {
            if (!editing || editing.readOnlyRecovery || !editing.dirty) return true;
            clearTimeout(draftTimer);
            draftTimer = null;
            return persistDraft(editing);
        }

        function removeStoredDraft(key, expectedRaw, draftId = null) {
            if (!key) return true;
            const read = sessionReadResult(key);
            if (!read.ok) return false;
            const raw = read.value;
            if (raw === null) return true;
            if (typeof expectedRaw === 'string' && raw !== expectedRaw) return false;
            if (draftId) {
                let stored;
                try { stored = JSON.parse(raw); } catch (_) { return false; }
                if (stored?.draftId !== draftId) return false;
            }
            return sessionRemove(key) || sessionWrite(key, 'null');
        }

        function removeEditorDraft(source) {
            clearTimeout(draftTimer);
            draftTimer = null;
            if (!source) return true;
            if (!removeStoredDraft(source.legacyRecoveryKey, source.legacyRecoveryRaw)) return false;
            source.legacyRecoveryKey = null;
            source.legacyRecoveryRaw = null;
            if (!removeStoredDraft(source.recoveryKey, source.recoveryRaw, source.draftId)) return false;
            source.recoveryKey = null;
            source.recoveryRaw = null;
            return true;
        }

        function storedDraftCandidates() {
            const candidates = [];
            let aggregate = 0;
            recoveryScanWarning = '';
            try {
                const length = sessionStorage.length;
                const inspected = Math.min(length, 2048);
                if (length > inspected) recoveryScanWarning = 'Additional browser recoveries were not scanned.';
                for (let index = 0; index < inspected; index++) {
                    const key = sessionStorage.key(index);
                    if (!isDraftKey(key)) continue;
                    const read = sessionReadResult(key);
                    if (!read.ok) {
                        recoveryScanWarning = 'Browser recovery storage could not be scanned.';
                        break;
                    }
                    const raw = read.value;
                    if (typeof raw !== 'string') continue;
                    if (raw.length > 512 * 1024 || aggregate + raw.length > 2 * 1024 * 1024) {
                        recoveryScanWarning = 'Additional browser recoveries were not scanned.';
                        break;
                    }
                    aggregate += raw.length;
                    if (!draftStructureWithin(raw)) continue;
                    let draft;
                    try { draft = JSON.parse(raw); } catch (_) { continue; }
                    if (!validDraftRecord(draft)) continue;
                    const canonical = draft.recoveryFormat === 2 && /^[a-f0-9]{32}$/.test(draft.draftId || '')
                        && key === `${draftPrefix}v2:${draft.draftId}`;
                    candidates.push({key, raw, id: draft.id, draft, canonical});
                }
            } catch (_) {
                recoveryScanWarning = 'Browser recovery storage could not be scanned.';
            }
            return candidates.sort((first, second) => Number(second.canonical) - Number(first.canonical));
        }

        function materializeDraft(candidate) {
            const canonical = candidate.canonical === true;
            const title = wellFormedText(candidate.draft.title);
            const body = wellFormedText(candidate.draft.body);
            const tags = candidate.draft.tags.map(wellFormedText);
            const rawTagsText = typeof candidate.draft.tagsText === 'string'
                ? candidate.draft.tagsText : JSON.stringify(candidate.draft.tags);
            const tagsText = wellFormedText(rawTagsText);
            const repairedText = title !== candidate.draft.title || body !== candidate.draft.body
                || tagsText !== rawTagsText || tags.some((tag, index) => tag !== candidate.draft.tags[index]);
            const newNote = candidate.draft.id === null;
            const draft = {
                id: candidate.draft.id,
                baseGeneration: candidate.draft.baseGeneration ?? null,
                baseRevision: newNote ? 0 : candidate.draft.baseRevision,
                baseVersion: newNote ? null : candidate.draft.baseVersion ?? null,
                createToken: candidate.draft.createToken || null,
                title,
                body,
                tags,
                tagsText,
                draftId: canonical ? candidate.draft.draftId : null,
                recoveryKey: canonical ? candidate.key : null,
                recoveryRaw: canonical ? candidate.raw : null,
                legacyRecoveryKey: canonical ? null : candidate.key,
                legacyRecoveryRaw: canonical ? null : candidate.raw,
                dirty: true
            };
            if (repairedText) draft.recoveryWarning = 'Invalid Unicode in this browser recovery was replaced so the draft can be saved.';
            if (recoveryScanWarning) draft.recoveryWarning = recoveryScanWarning;
            return draft;
        }

        function migrateLegacyDraft(draft) {
            if (!draft.legacyRecoveryKey) return true;
            if (!persistDraft(draft)) return false;
            if (!removeStoredDraft(draft.legacyRecoveryKey, draft.legacyRecoveryRaw)) {
                draft.recoveryWarning = 'The current recovery is safe, but an older browser copy could not be cleared.';
                return false;
            }
            draft.legacyRecoveryKey = null;
            draft.legacyRecoveryRaw = null;
            return true;
        }

        function readDraft(note) {
            const candidate = storedDraftCandidates().find(item => item.id === (note?.id ?? null));
            if (!candidate) return null;
            const draft = materializeDraft(candidate);
            if (note && draft.baseGeneration !== boot.document.generation) draft.conflict = {lineage: true};
            else if (note && (draft.baseRevision !== note.revision || draft.baseVersion !== note.version)) {
                draft.conflict = {deleted: false};
            }
            if (!note && !/^[a-f0-9]{32}$/.test(draft.createToken || '')) draft.createToken = createToken();
            migrateLegacyDraft(draft);
            return draft;
        }

        function recoverOrphanDraft() {
            if (!boot.writable) return null;
            const candidate = storedDraftCandidates().find(({id}) => id === null || !notes.has(id));
            if (!candidate) return null;
            const draft = materializeDraft(candidate);
            if (draft.id !== null && !notes.has(draft.id)) draft.conflict = {deleted: true};
            else if (draft.id === null && draft.baseGeneration !== boot.document.generation) draft.conflict = {lineage: true};
            if (draft.id === null && !/^[a-f0-9]{32}$/.test(draft.createToken || '')) draft.createToken = createToken();
            migrateLegacyDraft(draft);
            return draft;
        }

        function recoverWritableDraft() {
            const orphan = recoverOrphanDraft();
            if (orphan) return orphan;
            const candidate = storedDraftCandidates()[0];
            return candidate?.id && notes.has(candidate.id) ? readDraft(notes.get(candidate.id)) : null;
        }

        function recoverReadOnlyDraft() {
            const candidate = storedDraftCandidates()[0];
            return candidate ? {...materializeDraft(candidate), readOnlyRecovery: true} : null;
        }

        function setEditorStatus(message, kind = 'error') {
            const status = document.querySelector('.editor .save-status');
            if (!status) return;
            status.dataset.kind = kind;
            status.textContent = message;
        }

        function focusConflictAction() {
            const focus = () => document.querySelector('.conflict-actions button')?.focus();
            focus();
            requestAnimationFrame(focus);
            setTimeout(focus, 50);
        }

        function discardConflictedDraft(editor) {
            if (noteSaving || editing !== editor) return;
            if (!removeEditorDraft(editor)) {
                setEditorStatus('This browser could not discard its recovery copy. The editor is still open.');
                return;
            }
            editing = null;
            render();
            els['new-button'].focus();
        }

        function createToken() {
            return secureToken();
        }

        function cancelEditing() {
            if (!editing || noteSaving) return;
            if (editing.readOnlyRecovery) {
                setEditorStatus('Use “Dismiss recovery” if you no longer need this browser copy.');
                return;
            }
            if (editing.conflict?.deleted) {
                setEditorStatus('Choose “Save as new” or “Discard draft” first.');
                return;
            }
            const id = editing.id;
            const kept = Boolean(editing.conflict);
            clearTimeout(previewTimer);
            if (kept) {
                if (!flushDraft()) {
                    setEditorStatus('This browser could not keep the conflicted draft.');
                    return;
                }
            } else if (editing.dirty || editing.recoveryKey) {
                if (!removeEditorDraft(editing)) {
                    setEditorStatus('This browser could not discard its recovery copy. The editor is still open.');
                    return;
                }
            }
            editing = null;
            render();
            if (id && notes.has(id)) {
                setGlobalStatus(kept ? 'Draft kept in this tab; edit the note to return to it' : 'Unsaved changes discarded');
            }
            requestAnimationFrame(() => {
                const target = id && notes.has(id) ? document.querySelector(`#piplet-note-${CSS.escape(id)} .note-title`) : els['new-button'];
                target?.focus();
            });
        }

        function editNote(id) {
            const note = notes.get(id);
            if (!note || !boot.writable || noteSaving || editing?.id === id) return;
            if (!flushDraft()) {
                setEditorStatus('This browser could not keep a recovery draft. Save before switching notes.');
                return;
            }
            const recovery = recoverOrphanDraft();
            if (recovery) {
                openRecoveryEditor(recovery);
                return;
            }
            editing = readDraft(note) || {
                id,
                baseGeneration: boot.document.generation,
                baseRevision: note.revision,
                baseVersion: note.version,
                createToken: null,
                title: note.title,
                body: note.body,
                tags: [...note.tags],
                tagsText: JSON.stringify(note.tags),
                dirty: false
            };
            pendingDelete = null;
            if (!openNotes.includes(id)) {
                openNotes = prioritizeOpenNote(id);
                saveOpenNotes();
            }
            render();
            requestAnimationFrame(() => document.querySelector('.field-title textarea')?.focus());
        }

        function openRecoveryEditor(recovery) {
            editing = recovery;
            pendingDelete = null;
            if (editing.id && notes.has(editing.id)) {
                openNotes = prioritizeOpenNote(editing.id);
                saveOpenNotes();
            }
            render();
            closeDrawer();
            if (editing.conflict) focusConflictAction();
            else requestAnimationFrame(() => document.querySelector('.field-title textarea')?.focus());
        }

        function newNote() {
            if (!boot.writable || noteSaving || editing?.id === null) return;
            if (!flushDraft()) {
                setEditorStatus('This browser could not keep a recovery draft. Save before starting another note.');
                return;
            }
            const recovery = recoverOrphanDraft();
            if (recovery) {
                openRecoveryEditor(recovery);
                return;
            }
            editing = readDraft(null) || {
                id: null,
                baseGeneration: boot.document.generation,
                baseRevision: 0,
                baseVersion: null,
                createToken: createToken(),
                title: '',
                body: '',
                tags: [],
                tagsText: '[]',
                dirty: false
            };
            pendingDelete = null;
            render();
            closeDrawer();
            requestAnimationFrame(() => document.querySelector('.field-title textarea')?.focus());
        }

        function renderReadOnlyRecovery() {
            const article = element('article', 'note editor');
            article.append(element('p', 'kicker', 'Browser recovery'));
            article.append(element('h2', '', editing.title || 'Unsaved draft'));
            article.append(element('p', 'render-notice', 'The PHP file is read-only, but this browser still has an unsaved draft. Copy it before clearing browser data.'));
            const titleField = element('label', 'field field-tags', 'Exact title');
            const title = element('textarea');
            title.readOnly = true; title.rows = 2; title.value = editing.title;
            title.setAttribute('aria-label', 'Recovered draft title');
            titleField.append(title);
            const tagsField = element('label', 'field field-tags', 'Exact tags JSON');
            const tags = element('textarea');
            tags.readOnly = true; tags.rows = 3; tags.value = editing.tagsText ?? JSON.stringify(editing.tags);
            tags.setAttribute('aria-label', 'Recovered draft tags JSON');
            tagsField.append(tags);
            const body = element('textarea', 'plain-note');
            body.readOnly = true;
            body.value = editing.body;
            body.setAttribute('aria-label', 'Recovered draft text');
            const actions = element('div', 'editor-actions');
            const status = element('span', 'save-status');
            status.setAttribute('aria-live', 'polite');
            const dismiss = textButton('Dismiss recovery', 'button button-quiet', () => {
                if (!removeEditorDraft(editing)) {
                    status.dataset.kind = 'error';
                    status.textContent = 'This browser could not clear the recovery copy.';
                    return;
                }
                editing = recoverReadOnlyDraft();
                render();
                const restoreFocus = () => {
                    const target = editing?.readOnlyRecovery
                        ? document.querySelector('.editor button')
                        : els.main;
                    target?.focus();
                };
                restoreFocus();
                requestAnimationFrame(restoreFocus);
            });
            actions.append(dismiss, status);
            article.append(titleField, tagsField, body, actions);
            return article;
        }

        function renderEditor() {
            const editor = editing;
            const article = element('article', 'note editor');
            article.id = editor.id === null ? 'piplet-composer' : `piplet-note-${editor.id}`;
            const form = element('form', 'editor-grid');

            const titleField = element('div', 'field field-title');
            const titleLabel = element('label', '', 'Title'); titleLabel.htmlFor = 'edit-title';
            const title = element('textarea'); title.id = 'edit-title'; title.name = 'title'; title.required = true; title.maxLength = 240; title.rows = 1; title.value = editor.title; title.autocomplete = 'off';
            titleField.append(titleLabel, title);

            const bodyField = element('div', 'field');
            const bodyLabel = element('label', '', 'Note'); bodyLabel.htmlFor = 'edit-body';
            const body = element('textarea'); body.id = 'edit-body'; body.name = 'body'; body.value = editor.body; body.spellcheck = true;
            bodyField.append(bodyLabel, body, element('p', 'field-hint', 'Use # headings, - lists, **bold**, `code`, and [[wiki links]].'));

            const tagsField = element('div', 'field field-tags');
            const tagsLabel = element('label', '', 'Tags'); tagsLabel.htmlFor = 'edit-tags';
            const tags = element('textarea'); tags.id = 'edit-tags'; tags.name = 'tags'; tags.rows = 3; tags.value = editor.tagsText ?? JSON.stringify(editor.tags); tags.placeholder = '["ideas", "tag, with comma"]'; tags.autocomplete = 'off'; tags.spellcheck = false;
            tagsField.append(tagsLabel, tags, element('p', 'field-hint', 'Enter a JSON array. This preserves commas, spaces, and line breaks inside tags.'));

            const preview = element('div', 'editor-preview');
            preview.append(element('h2', 'preview-label', 'Live preview'));
            const previewBody = renderPreview(editor.body);
            preview.append(previewBody);

            if (editor.conflict) {
                const current = editor.id ? notes.get(editor.id) : null;
                const conflict = element('div', 'conflict-panel');
                conflict.setAttribute('role', 'alert');
                conflict.tabIndex = -1;
                conflict.append(element('strong', '', editor.conflict.lineage
                    ? 'This draft belongs to an earlier restored copy.'
                    : editor.conflict.deleted ? 'This note was deleted elsewhere.' : 'A newer saved version exists.'));
                conflict.append(element('p', '', editor.conflict.lineage
                    ? 'Your text is still here. Confirm before saving it into the current piplet lineage.'
                    : editor.conflict.deleted
                        ? 'Your text is still here and can become a new note.'
                        : `Your draft is preserved. The saved version is revision ${current?.revision || 'unknown'}.`));
                const choices = element('div', 'conflict-actions');
                if (editor.conflict.lineage) {
                    const adopt = textButton('Save as new in this piplet', 'button button-primary', () => {
                        if (noteSaving || editing !== editor) return;
                        const token = /^[a-f0-9]{32}$/.test(editor.createToken || '') ? editor.createToken : createToken();
                        if (!token) {
                            setEditorStatus('Secure randomness is unavailable, so this draft cannot be saved safely.');
                            return;
                        }
                        editor.id = null;
                        editor.baseGeneration = boot.document.generation;
                        editor.baseRevision = 0;
                        editor.baseVersion = null;
                        editor.createToken = token;
                        delete editor.conflict;
                        persistDraft(editor);
                        render();
                        requestAnimationFrame(() => document.querySelector('#edit-title')?.focus());
                    });
                    const discard = textButton('Discard draft', 'button button-quiet', () => discardConflictedDraft(editor));
                    choices.append(adopt, discard);
                } else if (editor.conflict.deleted) {
                    const keep = textButton('Save as new', 'button button-primary', () => {
                        if (noteSaving || editing !== editor) return;
                        if (editor.id !== null) {
                            const token = createToken();
                            if (!token) {
                                setEditorStatus('Secure randomness is unavailable, so this draft cannot be saved safely.');
                                return;
                            }
                            editor.id = null;
                            editor.baseGeneration = boot.document.generation;
                            editor.baseRevision = 0;
                            editor.baseVersion = null;
                            editor.createToken = token;
                        }
                        delete editor.conflict;
                        delete editor.recoveryWarning;
                        if (!persistDraft(editor) && !editor.recoveryWarning) {
                            editor.recoveryWarning = 'This browser cannot store a recovery copy. Keep the editor open until you save.';
                        }
                        render();
                        requestAnimationFrame(() => document.querySelector('#edit-title')?.focus());
                    });
                    const discard = textButton('Discard draft', 'button button-quiet', () => discardConflictedDraft(editor));
                    choices.append(keep, discard);
                } else if (current) {
                    const replace = textButton('Replace saved version', 'button button-primary', () => {
                        if (noteSaving || editing !== editor) return;
                        editor.baseGeneration = boot.document.generation;
                        editor.baseRevision = current.revision;
                        editor.baseVersion = current.version;
                        editor.createToken = null;
                        delete editor.conflict;
                        saveDraft();
                        render();
                        requestAnimationFrame(() => document.querySelector('#edit-body')?.focus());
                    });
                    const useSaved = textButton('Use saved version', 'button button-quiet', () => {
                        if (noteSaving || editing !== editor) return;
                        if (!removeEditorDraft(editor)) {
                            setEditorStatus('This browser could not discard its recovery copy. The editor is still open.');
                            return;
                        }
                        editing = null;
                        render();
                        requestAnimationFrame(() => document.querySelector(`#piplet-note-${CSS.escape(current.id)} .note-title`)?.focus());
                    });
                    choices.append(replace, useSaved);
                }
                conflict.append(choices);
                form.append(conflict);
            }

            const actions = element('div', 'editor-actions');
            const save = element('button', 'button button-primary', 'Save note'); save.type = 'submit';
            const cancel = textButton('Cancel', 'button button-quiet', cancelEditing);
            const status = element('span', 'save-status', editor.recoveryWarning || (editor.conflict ? 'Choose how to resolve the saved-version conflict.' : 'Changes stay in this browser until you save.'));
            status.setAttribute('aria-live', 'polite');
            if (editor.recoveryWarning) status.dataset.kind = 'error';
            actions.append(save, cancel);
            if (editor.id) {
                const remove = textButton('Delete', 'button button-quiet', () => {
                    if (noteSaving || editing !== editor) return;
                    if (!flushDraft()) {
                        status.dataset.kind = 'error';
                        status.textContent = 'This browser could not keep the draft. Save before deleting.';
                        return;
                    }
                    pendingDelete = {id: editor.id, editor};
                    editing = null;
                    render();
                    requestAnimationFrame(() => document.querySelector('.delete-row')?.focus());
                });
                actions.append(remove);
            }
            actions.append(status);

            const markDirty = () => {
                if (editing !== editor || noteSaving) return false;
                editor.dirty = true;
                saveDraft();
                const recoveryCharacters = editor.body.length + editor.title.length + editor.tagsText.length;
                if (recoveryCharacters > 511 * 1024 && status.dataset.kind !== 'error') {
                    status.dataset.kind = 'error';
                    status.textContent = 'This draft is too large for browser recovery. Save before leaving it.';
                }
                return true;
            };
            title.addEventListener('input', () => { editor.title = title.value; markDirty(); });
            tags.addEventListener('input', () => { editor.tagsText = tags.value; markDirty(); });
            body.addEventListener('input', () => {
                editor.body = body.value;
                markDirty();
                clearTimeout(previewTimer);
                previewTimer = setTimeout(() => preview.replaceChild(renderPreview(body.value), preview.lastElementChild), 120);
            });
            form.addEventListener('submit', async event => {
                event.preventDefault();
                if (noteSaving || editing !== editor) return;
                if (editor.conflict) {
                    status.dataset.kind = 'error';
                    status.textContent = 'Choose a conflict action before saving.';
                    return;
                }
                clearTimeout(previewTimer);
                try {
                    const encoder = new TextEncoder();
                    if (!editor.title || encoder.encode(editor.title).length > 240) {
                        throw new Error('Give the note a title no longer than 240 UTF-8 bytes.');
                    }
                    const parsedTags = JSON.parse(editor.tagsText);
                    if (!Array.isArray(parsedTags) || parsedTags.length > 12
                        || parsedTags.some(tag => typeof tag !== 'string' || tag === '' || encoder.encode(tag).length > 48)) {
                        throw new Error('Tags must be a JSON array of up to 12 nonempty strings, each at most 48 UTF-8 bytes.');
                    }
                    editor.tags = parsedTags;
                } catch (error) {
                    status.dataset.kind = 'error';
                    status.textContent = error instanceof SyntaxError ? 'Tags must be a valid JSON array.' : error.message;
                    return;
                }
                if (editor.id === null && !/^[a-f0-9]{32}$/.test(editor.createToken || '')) editor.createToken = createToken();
                if (editor.id === null && !editor.createToken) {
                    status.dataset.kind = 'error';
                    status.textContent = 'Secure randomness is unavailable, so this new note cannot be saved safely.';
                    return;
                }
                const recoveryStored = flushDraft();
                const payload = {
                    id: editor.id,
                    baseGeneration: editor.baseGeneration,
                    baseRevision: editor.baseRevision,
                    baseVersion: editor.baseVersion,
                    createToken: editor.id === null ? editor.createToken : null,
                    title: editor.title,
                    body: editor.body,
                    tags: [...editor.tags]
                };
                const setBusy = busy => {
                    for (const control of form.querySelectorAll('input, textarea, button')) control.disabled = busy;
                    els['new-button'].disabled = busy || !boot.writable;
                    els['appearance-button'].disabled = busy;
                };
                noteSaving = true;
                setBusy(true);
                status.dataset.kind = ''; status.textContent = 'Saving…';
                try {
                    const response = await api('save', payload);
                    const note = response.result;
                    notes.set(note.id, note);
                    boot.document.generation = response.generation;
                    const draftsCleared = removeEditorDraft(editor);
                    openNotes = prioritizeOpenNote(note.id);
                    if (editing === editor) editing = null;
                    noteSaving = false;
                    setBusy(false);
                    saveOpenNotes();
                    setGlobalStatus(draftsCleared ? `Saved: ${note.title}` : `Saved: ${note.title}; browser recovery could not be cleared`, draftsCleared ? '' : 'error');
                    replaceHash(note.id);
                    render();
                    requestAnimationFrame(() => document.querySelector(`#piplet-note-${CSS.escape(note.id)} .note-title`)?.focus());
                } catch (error) {
                    noteSaving = false;
                    setBusy(false);
                    if (error.status === 409) {
                        const responseGeneration = error.payload.generation;
                        const lineageChanged = /^[a-f0-9]{32}$/.test(responseGeneration || '')
                            && responseGeneration !== editor.baseGeneration;
                        if (responseGeneration) boot.document.generation = responseGeneration;
                        editor.dirty = true; // A conflict can make even an untouched old copy unique.
                        if (lineageChanged) {
                            if (error.payload.current) notes.set(error.payload.current.id, error.payload.current);
                            else if (editor.id) {
                                notes.delete(editor.id);
                                openNotes = openNotes.filter(id => id !== editor.id);
                                saveOpenNotes();
                                replaceHash();
                            }
                            editor.conflict = {lineage: true};
                            if (!persistDraft(editor) && !editor.recoveryWarning) {
                                editor.recoveryWarning = 'The lineage conflict is visible, but this browser could not store its recovery copy. Keep the editor open.';
                            }
                            if (editing === editor) {
                                render();
                                focusConflictAction();
                            }
                            return;
                        }
                        if (error.payload.current) {
                            const current = error.payload.current;
                            notes.set(current.id, current);
                            if (editor.id === null) {
                                editor.id = current.id;
                                editor.createToken = null;
                                openNotes = prioritizeOpenNote(editor.id);
                                saveOpenNotes();
                                replaceHash(editor.id);
                            }
                            editor.conflict = {deleted: false};
                            if (!persistDraft(editor) && !editor.recoveryWarning) {
                                editor.recoveryWarning = 'The conflict is visible, but this browser could not store its recovery copy. Keep the editor open.';
                            }
                        } else {
                            if (editor.id === null) {
                                editor.conflict = {lineage: true};
                                if (!persistDraft(editor) && !editor.recoveryWarning) {
                                    editor.recoveryWarning = 'The lineage conflict is visible, but this browser could not store its recovery copy. Keep the editor open.';
                                }
                                if (editing === editor) {
                                    render();
                                    focusConflictAction();
                                }
                                return;
                            }
                            if (editor.id) {
                                notes.delete(editor.id);
                                openNotes = openNotes.filter(id => id !== editor.id);
                                saveOpenNotes();
                                replaceHash();
                            }
                            editor.conflict = {deleted: true};
                            if (!persistDraft(editor) && !editor.recoveryWarning) {
                                editor.recoveryWarning = 'The conflict is visible, but this browser could not store its recovery copy. Keep the editor open.';
                            }
                        }
                        if (editing === editor) {
                            render();
                            focusConflictAction();
                        }
                        return;
                    }
                    status.dataset.kind = 'error';
                    status.textContent = error.status === 403 && !recoveryStored
                        ? 'The browser security token changed and this draft is not in recovery storage. Keep the editor open and copy your changes before refreshing.'
                        : error.message;
                }
            });

            form.append(titleField, bodyField, tagsField, preview, actions);
            article.append(form);
            return article;
        }

        async function api(action, payload) {
            let response;
            try {
                response = await fetch(`?api=${encodeURIComponent(action)}`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
                    body: JSON.stringify(payload)
                });
            } catch (_) {
                throw Object.assign(new Error('Could not reach the server.'), {status: 0, payload: {}});
            }
            let payloadBody = {};
            try { payloadBody = await response.json(); } catch (_) {}
            if (!response.ok) throw Object.assign(new Error(payloadBody.error || 'The save failed.'), {status: response.status, payload: payloadBody});
            return payloadBody;
        }

        async function deleteNote(note) {
            if (noteSaving) return;
            noteSaving = true;
            els['new-button'].disabled = true;
            els['appearance-button'].disabled = true;
            setGlobalStatus(`Deleting: ${note.title}…`, '', false);
            const recoveryEditor = pendingDelete?.editor || null;
            try {
                const response = await api('delete', {
                    id: note.id,
                    baseGeneration: boot.document.generation,
                    baseRevision: note.revision,
                    baseVersion: note.version
                });
                notes.delete(note.id);
                const draftCleared = removeEditorDraft(recoveryEditor);
                openNotes = openNotes.filter(id => id !== note.id);
                replaceHash();
                pendingDelete = null;
                boot.document.generation = response.generation;
                noteSaving = false;
                els['appearance-button'].disabled = false;
                saveOpenNotes();
                setGlobalStatus(draftCleared ? `Deleted: ${note.title}` : `Deleted: ${note.title}; browser recovery could not be cleared`, draftCleared ? '' : 'error');
                render();
                requestAnimationFrame(() => {
                    const target = openNotes[0] ? document.querySelector(`#piplet-note-${CSS.escape(openNotes[0])} .note-title`) : els['new-button'];
                    target?.focus();
                });
            } catch (error) {
                noteSaving = false;
                els['appearance-button'].disabled = false;
                pendingDelete = null;
                if (error.status === 409) {
                    if (error.payload.generation) boot.document.generation = error.payload.generation;
                    if (error.payload.current) notes.set(error.payload.current.id, error.payload.current);
                    else {
                        notes.delete(note.id);
                        openNotes = openNotes.filter(id => id !== note.id);
                        replaceHash();
                    }
                }
                setGlobalStatus(error.status === 409 ? 'That note changed; delete cancelled.' : error.message, 'error');
                render();
                requestAnimationFrame(() => {
                    const target = error.payload.current
                        ? document.querySelector(`#piplet-note-${CSS.escape(error.payload.current.id)} .note-title`)
                        : els['new-button'];
                    target?.focus();
                });
            }
        }

        function renderStory() {
            els.story.replaceChildren();
            const storyBudget = {characters: 0, nodes: 0, maxCharacters: 256 * 1024, maxNodes: 4000};
            const backlinks = backlinkIndex();
            if (editing?.readOnlyRecovery) els.story.append(renderReadOnlyRecovery());
            else if (editing?.id === null) els.story.append(renderEditor());
            else if (editing && !notes.has(editing.id)) els.story.append(renderEditor());
            for (const id of openNotes) {
                const note = notes.get(id);
                if (!note) continue;
                els.story.append(!editing?.readOnlyRecovery && editing?.id === id ? renderEditor() : renderNote(note, storyBudget, backlinks));
            }
            if (!els.story.childNodes.length) {
                const empty = element('section', 'empty-story');
                empty.append(element('p', 'kicker', notes.size ? 'No notes open' : 'A single-file notebook'));
                empty.append(element('h2', '', notes.size ? 'Choose a note.' : 'No notes yet.'));
                empty.append(element('p', '', notes.size ? 'Use the index, or create a new note.' : 'Create one here. Saving makes it part of this PHP file.'));
                if (boot.writable) {
                    empty.append(textButton('Write a note', 'button button-primary', newNote));
                }
                els.story.append(empty);
            }
        }

        function render() {
            renderLibrary();
            renderStory();
            els['new-button'].disabled = !boot.writable || noteSaving;
            els['storage-state'].textContent = boot.writable ? recoveryScanWarning : 'Read-only';
        }

        document.addEventListener('click', clearTransientGlobalStatus, true);
        document.addEventListener('keydown', clearTransientGlobalStatus, true);
        els['appearance-button'].addEventListener('click', openAppearance);
        els['appearance-form'].addEventListener('input', previewAppearance);
        els['appearance-close'].addEventListener('click', () => closeAppearance());
        els['appearance-cancel'].addEventListener('click', () => closeAppearance());
        els['appearance-reset'].addEventListener('click', restoreAppearanceDefaults);
        els['appearance-dialog'].addEventListener('cancel', event => {
            event.preventDefault();
            closeAppearance();
        });
        els['appearance-form'].addEventListener('submit', async event => {
            event.preventDefault();
            if (appearanceSaving) return;
            if (!previewAppearance()) return;
            const sharedChanged = JSON.stringify(appearanceValues(appearanceDraft)) !== JSON.stringify(appearanceValues(savedAppearance));
            const themeChanged = themeDraft !== savedThemeChoice;
            if (!boot.writable || !sharedChanged) {
                if (themeChanged && !localWrite(themeStorageKey, themeDraft)) {
                    els['appearance-status'].dataset.kind = 'error';
                    els['appearance-status'].textContent = 'This browser could not store the theme.';
                    return;
                }
                savedThemeChoice = themeDraft;
                closeAppearance(true);
                setGlobalStatus(themeChanged ? 'Theme saved on this device' : 'No appearance changes');
                return;
            }

            setAppearanceBusy(true);
            els['appearance-status'].dataset.kind = '';
            els['appearance-status'].textContent = 'Saving…';
            try {
                const response = await api('appearance', {
                    baseGeneration: boot.document.generation,
                    baseRevision: savedAppearance.revision,
                    baseVersion: savedAppearance.version,
                    appearance: appearanceValues(appearanceDraft)
                });
                savedAppearance = {...appearanceDefaults, ...response.result};
                boot.document.generation = response.generation;
                const themeStored = !themeChanged || localWrite(themeStorageKey, themeDraft);
                if (themeStored) savedThemeChoice = themeDraft;
                applyAppearance(savedAppearance);
                applyTheme(savedThemeChoice);
                setAppearanceBusy(false);
                closeAppearance(true);
                setGlobalStatus(themeStored ? 'Appearance saved' : 'Appearance saved; this browser could not store its theme', themeStored ? '' : 'error');
            } catch (error) {
                if (error.status === 409 && error.payload.current) {
                    if (error.payload.generation) boot.document.generation = error.payload.generation;
                    savedAppearance = {...appearanceDefaults, ...error.payload.current};
                }
                els['appearance-status'].dataset.kind = 'error';
                els['appearance-status'].textContent = error.status === 409
                    ? 'Appearance changed elsewhere. Your preview is safe; save again to use it.'
                    : error.message;
                setAppearanceBusy(false);
            }
        });
        els['search-input'].addEventListener('input', event => {
            query = event.target.value;
            clearTimeout(searchTimer);
            searchTimer = setTimeout(renderLibrary, 100);
        });
        els['new-button'].addEventListener('click', newNote);
        els['menu-button'].addEventListener('click', () => {
            const open = document.body.dataset.drawer !== 'open';
            if (open) openDrawer(); else closeDrawer(true);
        });
        els['drawer-shade'].addEventListener('click', () => closeDrawer(true));
        mobileViewport.addEventListener?.('change', () => {
            if (!mobileViewport.matches) closeDrawer();
            else if (document.body.dataset.drawer !== 'open' && els.library.contains(document.activeElement)) els['menu-button'].focus();
            updateDrawerAccess();
        });
        document.addEventListener('click', event => {
            const link = event.target.closest('[data-wiki]');
            if (!link) return;
            const id = resolveNote(link.dataset.wiki);
            if (id) { event.preventDefault(); openNote(id); }
        });
        window.addEventListener('hashchange', () => {
            const id = resolveNote(location.hash.slice(1), true);
            if (id && openNotes[0] !== id) openNote(id, false);
        });
        window.addEventListener('pagehide', () => { flushDraft(); });
        window.addEventListener('beforeunload', event => {
            if (editing && !flushDraft()) {
                event.preventDefault();
                event.returnValue = '';
            }
        });
        document.addEventListener('keydown', event => {
            const appearanceOpen = els['appearance-dialog'].open;
            if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 's' && appearanceOpen) {
                event.preventDefault();
                els['appearance-form'].requestSubmit();
                return;
            }
            if (appearanceOpen) return;
            const typing = /^(INPUT|TEXTAREA|SELECT)$/.test(event.target.tagName);
            if (event.key === 'Tab' && document.body.dataset.drawer === 'open') {
                const focusable = [...els.library.querySelectorAll('button:not(:disabled), input, a[href]')];
                const first = focusable[0], last = focusable.at(-1);
                if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
                else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
            }
            if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 's' && editing) {
                event.preventDefault(); document.querySelector('.editor form')?.requestSubmit(); return;
            }
            if (event.key === 'Escape') {
                if (document.body.dataset.drawer === 'open') closeDrawer(true);
                else if (editing) cancelEditing();
                return;
            }
            if (typing || event.metaKey || event.ctrlKey || event.altKey) return;
            if (event.key === '/') {
                event.preventDefault();
                if (mobileViewport.matches) openDrawer(); else els['search-input'].focus();
            }
            if (event.key.toLowerCase() === 'n') newNote();
            if (event.key.toLowerCase() === 'e' && openNotes[0]) editNote(openNotes[0]);
        });

        colorScheme.addEventListener?.('change', () => {
            const choice = appearanceDraft ? themeDraft : savedThemeChoice;
            if (choice === 'system') applyTheme(choice);
        });

        const initialHash = resolveNote(location.hash.slice(1), true);
        if (initialHash) openNotes = prioritizeOpenNote(initialHash);
        editing = boot.writable ? recoverWritableDraft() : recoverReadOnlyDraft();
        if (editing?.id && notes.has(editing.id)) {
            openNotes = prioritizeOpenNote(editing.id);
            saveOpenNotes();
        }
        applyAppearance(savedAppearance);
        applyTheme(savedThemeChoice);
        updateDrawerAccess();
        render();
    })();
    </script>
</body>
</html>
<?php
}

if (!defined('PIPLET_LIBRARY_ONLY')) {
    if (PHP_SAPI === 'cli' && isset($argv[1])) {
        piplet_cli(array_slice($argv, 1));
    }
    piplet_run();
}

__halt_compiler();
PIPLET-DATA/2
{"format":2,"revision":7,"generation":"09de7b90d13c42b66ecd2a9a02b6d63f","appearance":{"revision":0,"version":"1b18ccc25bfbbcc728841710b88585e3","palette":"quiet","font":"editorial","scale":"comfortable","measure":"balanced","customCss":""},"notes":{"welcome":{"id":"welcome","title":"Hello, piplet","body":"This is a **piplet**: a single file php application.\n\n## markup\n\n- `#` makes a heading\n- `-` makes a list\n- `**words**` adds emphasis\n- `[[Hello, piplet|welcome]]` links one note to another","tags":["welcome","simplicity"],"revision":7,"version":"f5bd95f91a7755e0eb16a431dca5d34d","created":"2026-08-15T05:30:00Z","updated":"2026-08-17T01:19:34Z"}}}
