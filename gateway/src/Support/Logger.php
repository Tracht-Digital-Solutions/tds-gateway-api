<?php
declare(strict_types=1);

namespace Tds\ApiGateway\Support;

/**
 * Tiny dependency-free file logger (one JSON object per line).
 *
 * The gateway runs under PHP-FPM on a Plesk host where PHP's default
 * `error_log()` sink is effectively invisible, so a 502 ("upstream
 * unavailable") gave no clue *why*. This writes structured lines to a known
 * file (default `<root>/logs/gateway.log`, already gitignored) so the cURL
 * errno/error behind a failed proxy hop is actually readable.
 *
 * Logging must never break the proxy: a bad path / unwritable dir degrades to
 * a single `error_log()` fallback and is then silenced — it never throws.
 */
final class Logger
{
    /** @var array<string, int> level name => severity */
    private const LEVELS = [
        'debug' => 10,
        'info' => 20,
        'warning' => 30,
        'error' => 40,
        'off' => 99,
    ];

    private int $threshold;
    private bool $disabled;
    private bool $fallbackWarned = false;

    public function __construct(
        private readonly string $path,
        string $minLevel = 'info',
    ) {
        $level = self::LEVELS[strtolower(trim($minLevel))] ?? self::LEVELS['info'];
        $this->threshold = $level;
        // Empty path or an `off` level/path turns the logger into a no-op.
        $this->disabled = $this->path === ''
            || strtolower(trim($this->path)) === 'off'
            || $level >= self::LEVELS['off'];
    }

    /**
     * Build from the gateway env accessor.
     *
     * `GATEWAY_LOG_FILE`  — target file; default `<rootDir>/logs/gateway.log`,
     *                       `off` disables. Relative paths resolve under root.
     * `GATEWAY_LOG_LEVEL` — debug|info|warning|error|off (default `info`).
     *
     * @param callable(string, ?string): string $env
     */
    public static function fromEnv(callable $env, string $rootDir): self
    {
        $configured = trim($env('GATEWAY_LOG_FILE', $rootDir . '/logs/gateway.log'));
        $level = $env('GATEWAY_LOG_LEVEL', 'info');

        // Resolve a relative path (other than the `off` sentinel) under root.
        if ($configured !== '' && strtolower($configured) !== 'off'
            && !self::isAbsolute($configured)) {
            $configured = $rootDir . '/' . ltrim($configured, '/\\');
        }

        return new self($configured, $level);
    }

    /** @param array<string, mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        if ($this->disabled) {
            return;
        }
        $severity = self::LEVELS[strtolower($level)] ?? self::LEVELS['info'];
        if ($severity < $this->threshold) {
            return;
        }

        $line = (string) json_encode(
            ['ts' => self::timestamp(), 'level' => strtolower($level), 'msg' => $message] + $context,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        $this->write($line . "\n");
    }

    private function write(string $line): void
    {
        $dir = \dirname($this->path);
        if (!is_dir($dir)) {
            // Suppress: a racing request may create it; @mkdir won't throw.
            @mkdir($dir, 0775, true);
        }

        if (@file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX) !== false) {
            return;
        }

        // Couldn't write the log file — fall back to PHP's error log once so a
        // misconfigured path is itself visible, then stay quiet to avoid noise.
        if (!$this->fallbackWarned) {
            error_log('[gateway] log write failed for ' . $this->path);
            $this->fallbackWarned = true;
        }
        error_log('[gateway] ' . rtrim($line, "\n"));
    }

    private static function timestamp(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->format('Y-m-d\TH:i:s.up');
    }

    private static function isAbsolute(string $path): bool
    {
        // POSIX `/x`, Windows `C:\x` or `C:/x`, and UNC `\\host`.
        return $path !== '' && (
            $path[0] === '/'
            || $path[0] === '\\'
            || (bool) preg_match('#^[A-Za-z]:[\\\\/]#', $path)
        );
    }
}
