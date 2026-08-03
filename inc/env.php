<?php
/**
 * Tiny .env loader (no Composer). Loads KEY=VALUE pairs into putenv / $_ENV.
 */

declare(strict_types=1);

/**
 * Load a .env file once. Existing real environment variables win (not overwritten).
 */
function tb_load_env(string $path): void
{
    static $loaded = [];
    $real = realpath($path) ?: $path;
    if (isset($loaded[$real])) {
        return;
    }
    $loaded[$real] = true;

    if (!is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (str_starts_with($line, 'export ')) {
            $line = trim(substr($line, 7));
        }
        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }
        $key = trim(substr($line, 0, $eq));
        $val = trim(substr($line, $eq + 1));
        if ($key === '' || !preg_match('/^[A-Z_][A-Z0-9_]*$/i', $key)) {
            continue;
        }
        // Do not override a value already set in the real environment.
        $existing = getenv($key);
        if ($existing !== false && $existing !== '') {
            continue;
        }

        if (
            (str_starts_with($val, '"') && str_ends_with($val, '"'))
            || (str_starts_with($val, "'") && str_ends_with($val, "'"))
        ) {
            $val = substr($val, 1, -1);
            $val = str_replace(['\\n', '\\r', '\\t', '\\"', "\\'", '\\\\'], ["\n", "\r", "\t", '"', "'", '\\'], $val);
        } else {
            // Unquoted: strip inline comments
            if (($hash = strpos($val, ' #')) !== false) {
                $val = rtrim(substr($val, 0, $hash));
            }
        }

        putenv($key . '=' . $val);
        $_ENV[$key] = $val;
        $_SERVER[$key] = $val;
    }
}

/** Read an env value with a default. */
function tb_env(string $key, ?string $default = null): ?string
{
    $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($v === false || $v === null || $v === '') {
        return $default;
    }
    return (string) $v;
}

function tb_env_bool(string $key, bool $default = false): bool
{
    $v = tb_env($key);
    if ($v === null) {
        return $default;
    }
    return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
}

function tb_env_int(string $key, int $default = 0): int
{
    $v = tb_env($key);
    if ($v === null || !is_numeric($v)) {
        return $default;
    }
    return (int) $v;
}
