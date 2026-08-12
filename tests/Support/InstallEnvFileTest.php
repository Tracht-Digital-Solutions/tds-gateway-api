<?php
declare(strict_types=1);

namespace Tds\ApiGateway\Tests\Support;

use Dotenv\Dotenv;
use PHPUnit\Framework\TestCase;

/**
 * Guards the .env files that public/install.php generates.
 *
 * A fresh install used to write `MAIL_FROM_NAME=Tracht Digital Solutions`
 * unquoted. phpdotenv refuses a bare unquoted value containing a space, and
 * every service's `Bootstrap::createApp()` loads the .env before anything else
 * — so that one line took the WHOLE frontend service down at boot: the
 * gateway's aggregate health reported `"/frontend": {"status": 0}` and every
 * catch-all route answered `500 Slim Application Error`, with nothing in the
 * app log. auth and customer stayed green only because their values happen to
 * contain no spaces, which is exactly what made it look like a frontend bug.
 *
 * install.php is a deliberately self-contained single file (it calls
 * session_start() and renders HTML at top level), so it cannot be included.
 * These tests extract its pure helpers by name and exercise them directly.
 */
final class InstallEnvFileTest extends TestCase
{
    /** Every free-text field an operator can type, filled with hostile input. */
    private const HOSTILE = [
        'db_host' => '127.0.0.1',
        'db_port' => '3306',
        'db_user' => 'tds user',
        'db_pass' => 'p@ss word$1 "quoted" \\slash',
        'db_auth' => 'tds_auth',
        'db_customer' => 'tds_customer',
        'db_frontend' => 'tds_frontend',
        'admin_token' => 'tok en#hash',
        'admin_email' => 'admin@tracht-digital.de',
        'cors' => 'https://a.de, https://b.de',
        'cookie_domain' => '.tracht-digital.de',
        'jwt_issuer' => 'https://api.tracht-digital.de/auth',
        'jwt_key_id' => 'tds auth 2026',
        'auth_api_url' => 'https://api.tracht-digital.de/auth',
        'settings_encryption_key' => 'key with $VAR and spaces',
        'document_sign_secret' => 'sec ret',
        'document_root_dir' => 'C:\\Program Files\\tds\\customer files',
    ];

    private const DEFAULTS = [
        'db_host' => '127.0.0.1',
        'db_port' => '3306',
        'db_user' => 'tds',
        'db_pass' => 'secret',
        'db_auth' => 'tds_auth',
        'db_customer' => 'tds_customer',
        'db_frontend' => 'tds_frontend',
        'admin_token' => 'abc123',
        'admin_email' => 'admin@tracht-digital.de',
        'cors' => 'https://tracht-digital.de,https://app.tracht-digital.de',
        'cookie_domain' => '.tracht-digital.de',
        'jwt_issuer' => 'https://api.tracht-digital.de/auth',
        'jwt_key_id' => 'tds-auth-2026-1',
        'auth_api_url' => 'https://api.tracht-digital.de/auth',
        'settings_encryption_key' => 'deadbeef',
        'document_sign_secret' => 'cafebabe',
        'document_root_dir' => '/srv/tds/var/customer-files',
    ];

    public static function setUpBeforeClass(): void
    {
        self::loadInstallerHelpers();
    }

    /**
     * Pull the pure helpers out of public/install.php into the global namespace.
     * Brace-matched via the tokenizer rather than a text slice, so reordering or
     * re-commenting the installer doesn't quietly break the extraction.
     */
    private static function loadInstallerHelpers(): void
    {
        if (\function_exists('env_for')) {
            return;
        }
        $file = \dirname(__DIR__, 2) . '/public/install.php';
        $src = (string) \file_get_contents($file);
        $tokens = \token_get_all($src);
        $wanted = ['env_line', 'env_body', 'env_for', 'read_env_kv'];
        $code = '';

        foreach ($tokens as $i => $token) {
            if (!\is_array($token) || $token[0] !== \T_FUNCTION) {
                continue;
            }
            // Next meaningful token is the function name.
            $name = null;
            for ($j = $i + 1; $j < \count($tokens); $j++) {
                $t = $tokens[$j];
                if (\is_array($t) && ($t[0] === \T_WHITESPACE || $t[0] === \T_COMMENT || $t[0] === \T_DOC_COMMENT)) {
                    continue;
                }
                $name = \is_array($t) && $t[0] === \T_STRING ? $t[1] : null;
                break;
            }
            if ($name === null || !\in_array($name, $wanted, true)) {
                continue;
            }
            // Byte offset of "function" → brace-match to the closing brace.
            $offset = self::offsetOf($tokens, $i);
            $depth = 0;
            $started = false;
            for ($p = $offset; $p < \strlen($src); $p++) {
                if ($src[$p] === '{') {
                    $depth++;
                    $started = true;
                } elseif ($src[$p] === '}') {
                    $depth--;
                    if ($started && $depth === 0) {
                        $code .= \substr($src, $offset, $p - $offset + 1) . "\n";
                        break;
                    }
                }
            }
        }

        foreach ($wanted as $fn) {
            self::assertStringContainsString(
                'function ' . $fn . '(',
                $code,
                "could not extract {$fn}() from public/install.php"
            );
        }
        eval($code);
    }

    /** Byte offset of token $index in the original source. */
    private static function offsetOf(array $tokens, int $index): int
    {
        $offset = 0;
        for ($i = 0; $i < $index; $i++) {
            $offset += \strlen(\is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i]);
        }
        return $offset;
    }

    /** Write a body to a throwaway dir and parse it with the real phpdotenv. */
    private function parse(string $body): array
    {
        $dir = \sys_get_temp_dir() . '/tds-install-env-' . \bin2hex(\random_bytes(6));
        \mkdir($dir);
        try {
            \file_put_contents($dir . '/.env', $body);
            return Dotenv::createArrayBacked($dir)->load();
        } finally {
            @\unlink($dir . '/.env');
            @\rmdir($dir);
        }
    }

    /** @return iterable<string, array{string, array<string,string>}> */
    public static function serviceConfigProvider(): iterable
    {
        foreach (['auth', 'customer', 'frontend'] as $service) {
            yield "{$service} / defaults" => [$service, self::DEFAULTS];
            yield "{$service} / hostile" => [$service, self::HOSTILE];
        }
    }

    /**
     * The regression: whatever the operator typed, the generated file must be
     * loadable by the same parser the service uses at boot.
     *
     * @dataProvider serviceConfigProvider
     */
    public function testGeneratedEnvIsParseableByPhpdotenv(string $service, array $config): void
    {
        $values = $this->parse(env_for($service, $config));

        self::assertSame($config['db_pass'], $values['DB_PASS'] ?? null);
        self::assertSame($config['cors'], $values['CORS_ALLOWED_ORIGINS'] ?? null);
    }

    /** The exact line that broke every fresh install. */
    public function testFrontendMailFromNameSurvivesVerbatim(): void
    {
        $values = $this->parse(env_for('frontend', self::DEFAULTS));

        self::assertSame('Tracht Digital Solutions', $values['MAIL_FROM_NAME'] ?? null);
    }

    /** The frontend still gets the settings it needs, not just a parseable file. */
    public function testFrontendEnvCarriesItsRequiredKeys(): void
    {
        $values = $this->parse(env_for('frontend', self::DEFAULTS));

        self::assertSame('tds_frontend', $values['DB_NAME'] ?? null);
        self::assertSame(self::DEFAULTS['auth_api_url'], $values['AUTH_API_URL'] ?? null);
        self::assertSame(self::DEFAULTS['settings_encryption_key'], $values['SETTINGS_ENCRYPTION_KEY'] ?? null);
        self::assertSame(self::DEFAULTS['document_root_dir'], $values['DOCUMENT_ROOT_DIR'] ?? null);
    }

    /**
     * read_env_kv() feeds the DB credentials to the migration steps, so it must
     * be the exact inverse of env_line(). If it stopped undoing the escaping, a
     * password containing `$`, `"` or `\` would migrate against the wrong
     * credentials while the services themselves connected fine.
     *
     * @dataProvider serviceConfigProvider
     */
    public function testInstallerEnvReaderAgreesWithPhpdotenv(string $service, array $config): void
    {
        $body = env_for($service, $config);
        $dir = \sys_get_temp_dir() . '/tds-install-kv-' . \bin2hex(\random_bytes(6));
        \mkdir($dir);
        try {
            \file_put_contents($dir . '/.env', $body);
            $viaReader = read_env_kv($dir . '/.env');
            $viaDotenv = Dotenv::createArrayBacked($dir)->load();

            foreach ($viaDotenv as $key => $value) {
                self::assertSame(
                    (string) $value,
                    $viaReader[$key] ?? null,
                    "read_env_kv() disagrees with phpdotenv on {$key}"
                );
            }
        } finally {
            @\unlink($dir . '/.env');
            @\rmdir($dir);
        }
    }

    /**
     * Escaping round-trip on values chosen to break naive quoting. `$` matters
     * beyond parsing: phpdotenv interpolates `${VAR}` inside double quotes, so
     * an unescaped `$` would be silently rewritten rather than merely fail.
     */
    public function testEnvLineRoundTripsAwkwardValues(): void
    {
        $cases = [
            'spaces' => 'Tracht Digital Solutions',
            'empty' => '',
            'quote' => 'a "quoted" value',
            'backslash' => 'C:\\Program Files\\tds',
            'dollar' => 'costs $5 and ${HOME}',
            'hash' => 'value # not a comment',
            'equals' => 'a=b=c',
            'mixed' => 'p@ss word$1 "q" \\s',
        ];

        foreach ($cases as $label => $value) {
            $values = $this->parse(env_line('K', $value));
            $actual = $values['K'] ?? null;
            // phpdotenv yields null for an empty assignment; both mean "unset".
            self::assertSame($value, $value === '' ? (string) $actual : $actual, "round-trip failed for {$label}");
        }
    }

    /**
     * Belt and braces: no generated line may carry an unquoted value at all, so
     * a future key added without env_line() is caught even if today's inputs
     * happen to be space-free.
     */
    public function testEveryGeneratedLineIsQuoted(): void
    {
        foreach (['auth', 'customer', 'frontend'] as $service) {
            foreach (\explode("\n", \trim(env_for($service, self::DEFAULTS))) as $line) {
                if ($line === '') {
                    continue;
                }
                self::assertMatchesRegularExpression(
                    '/^[A-Z0-9_]+="(?:[^"\\\\]|\\\\.)*"$/',
                    $line,
                    "unquoted value in the {$service} .env: {$line}"
                );
            }
        }
    }
}
