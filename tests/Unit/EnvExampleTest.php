<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every key config/ reads is in .env.example (docs/roadmap/02 §1). CI copies .env.example to .env, so a
 * key missing here is a key a fresh server misses too, and nobody notices until something is quietly off.
 *
 * A key may be listed commented out (`# KEY=default`) when its default suits most copies. Stock framework
 * keys for drivers this host does not use are left out on purpose, each with its reason in LEFT_OUT, and
 * the list is checked for stale entries too.
 */
class EnvExampleTest extends TestCase
{
    private const REDIS = 'Redis is not used on shared hosting (ADR 0008); REDIS_HOST, REDIS_PASSWORD and REDIS_PORT stay as in Laravel\'s template.';

    private const MEMCACHED = 'Memcached is not used (ADR 0008); MEMCACHED_HOST stays as in Laravel\'s template.';

    private const S3 = 'No S3 disk on this host (ADR 0008); the AWS keys Laravel\'s template has stay listed.';

    /** Keys config/ reads that .env.example leaves out on purpose. */
    private const LEFT_OUT = [
        'MASTER_PASSWORD' => 'Removed (plaintext). config/saas.php reads it only so config:doctor can flag a leftover value; it must never be listed.',
        'ABLY_KEY' => 'Ably broadcasting is not used (BROADCAST_DRIVER=log).',
        'ASSET_URL' => 'Assets are served by the app itself, not a CDN.',
        'AWS_ENDPOINT' => self::S3,
        'AWS_URL' => self::S3,
        'BCRYPT_ROUNDS' => 'The framework default is right; tests set 4 in phpunit.xml.',
        'CACHE_PREFIX' => 'The default prefix (from APP_NAME) only matters for a shared cache server, which this host has not.',
        'DATABASE_URL' => 'The DB_* keys are used, not a URL.',
        'DB_FOREIGN_KEYS' => 'SQLite only; the app runs on MySQL.',
        'DYNAMODB_CACHE_TABLE' => 'The DynamoDB cache store is not used.',
        'DYNAMODB_ENDPOINT' => 'The DynamoDB cache store is not used.',
        'LOG_PAPERTRAIL_HANDLER' => 'The Papertrail log channel is not used.',
        'PAPERTRAIL_URL' => 'The Papertrail log channel is not used.',
        'PAPERTRAIL_PORT' => 'The Papertrail log channel is not used.',
        'LOG_SLACK_WEBHOOK_URL' => 'The Slack log channel is not used; errors go to the built-in tracker.',
        'LOG_STDERR_FORMATTER' => 'The stderr log channel is not used on shared hosting.',
        'MAILGUN_DOMAIN' => 'Mail goes through SMTP, not Mailgun.',
        'MAILGUN_ENDPOINT' => 'Mail goes through SMTP, not Mailgun.',
        'MAILGUN_SECRET' => 'Mail goes through SMTP, not Mailgun.',
        'POSTMARK_TOKEN' => 'Mail goes through SMTP, not Postmark.',
        'MAIL_EHLO_DOMAIN' => 'The SMTP greeting name defaults to the right value.',
        'MAIL_LOG_CHANNEL' => 'The log mailer writes to the default log channel.',
        'MAIL_SENDMAIL_PATH' => 'The sendmail mailer is not used.',
        'MAIL_URL' => 'MAIL_HOST, MAIL_PORT and the rest are used, not a URL.',
        'MEMCACHED_PASSWORD' => self::MEMCACHED,
        'MEMCACHED_PERSISTENT_ID' => self::MEMCACHED,
        'MEMCACHED_PORT' => self::MEMCACHED,
        'MEMCACHED_USERNAME' => self::MEMCACHED,
        'MYSQL_ATTR_SSL_CA' => 'MySQL runs on the same host; no TLS certificate to name.',
        'QUEUE_FAILED_DRIVER' => 'Failed jobs go to the failed_jobs table, the default.',
        'REDIS_CACHE_DB' => self::REDIS,
        'REDIS_CLIENT' => self::REDIS,
        'REDIS_CLUSTER' => self::REDIS,
        'REDIS_DB' => self::REDIS,
        'REDIS_PREFIX' => self::REDIS,
        'REDIS_QUEUE' => self::REDIS,
        'REDIS_URL' => self::REDIS,
        'REDIS_USERNAME' => self::REDIS,
        'SESSION_CONNECTION' => 'Only for database or Redis sessions; sessions are files.',
        'SESSION_STORE' => 'Only for cache-backed sessions; sessions are files.',
        'SQS_PREFIX' => 'The SQS queue is not used (database queue, ADR 0008).',
        'SQS_QUEUE' => 'The SQS queue is not used (database queue, ADR 0008).',
        'SQS_SUFFIX' => 'The SQS queue is not used (database queue, ADR 0008).',
        'VIEW_COMPILED_PATH' => 'Compiled views stay in storage/framework/views.',
    ];

    public function test_every_key_the_config_reads_is_in_the_example(): void
    {
        $missing = array_values(array_diff(array_keys(self::configKeys()), self::exampleKeys(), array_keys(self::LEFT_OUT)));
        $where = array_map(fn ($key) => $key.' ('.implode(', ', self::configKeys()[$key]).')', $missing);

        $this->assertSame([], $where, "Add these keys to .env.example, grouped and commented (or, for a stock framework key this host never uses, to LEFT_OUT with the reason):\n".implode("\n", $where));
    }

    public function test_the_left_out_list_has_no_stale_entries(): void
    {
        $read = self::configKeys();
        $listed = self::exampleKeys();
        foreach (self::LEFT_OUT as $key => $reason) {
            $this->assertArrayHasKey($key, $read, "{$key} is in LEFT_OUT, but no config file reads it any more: remove it from the list.");
            $this->assertNotContains($key, $listed, "{$key} is in LEFT_OUT and in .env.example: keep one ({$reason}).");
        }
    }

    public function test_the_example_is_safe_to_copy(): void
    {
        $example = (string) file_get_contents(self::root().'/.env.example');

        $this->assertMatchesRegularExpression('/^QUEUE_CONNECTION=database$/m', $example, 'The queue is database (ADR 0008).');
        $this->assertMatchesRegularExpression('/^APP_KEY=$/m', $example, 'APP_KEY is generated per copy.');
        $this->assertDoesNotMatchRegularExpression('/^\s*#?\s*MASTER_PASSWORD=/m', $example, 'The plaintext master password is removed.');
        $this->assertDoesNotMatchRegularExpression('/^FLW_SECRET_KEY=\S/m', $example, 'No Flutterwave key in the example.');
        $this->assertDoesNotMatchRegularExpression('/"\$\{APP_URL\}payment/', $example, 'FLW_REDIRECT_URL needs the slash after APP_URL.');
    }

    /** @return array<string, list<string>> key => the config files that read it */
    private static function configKeys(): array
    {
        static $keys = null;
        if ($keys !== null) {
            return $keys;
        }
        $keys = [];
        foreach (glob(self::root().'/config/*.php') ?: [] as $file) {
            foreach (self::envKeysIn((string) file_get_contents($file)) as $key) {
                $keys[$key][] = 'config/'.basename($file);
            }
        }
        ksort($keys);

        return $keys;
    }

    /**
     * The literal keys of every env('KEY', ...) call, read with the tokenizer so comments and other
     * functions do not count, and calls split over several lines do.
     *
     * @return list<string>
     */
    private static function envKeysIn(string $php): array
    {
        $tokens = token_get_all($php);
        $significant = fn ($t) => ! (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true));
        $previous = fn (int $i) => self::step($tokens, $i, -1, $significant);
        $next = fn (int $i) => self::step($tokens, $i, 1, $significant);
        $keys = [];
        foreach ($tokens as $i => $token) {
            if (! is_array($token) || $token[0] !== T_STRING || strtolower($token[1]) !== 'env') {
                continue;
            }
            $before = $previous($i);
            if ($before !== null && is_array($tokens[$before]) && in_array($tokens[$before][0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                continue;
            }
            $open = $next($i);
            $arg = $open !== null && $tokens[$open] === '(' ? $next($open) : null;
            if ($arg !== null && is_array($tokens[$arg]) && $tokens[$arg][0] === T_CONSTANT_ENCAPSED_STRING) {
                $keys[] = trim($tokens[$arg][1], '\'"');
            }
        }

        return array_values(array_unique($keys));
    }

    /** @param array<int, mixed> $tokens */
    private static function step(array $tokens, int $i, int $direction, callable $significant): ?int
    {
        for ($j = $i + $direction; isset($tokens[$j]); $j += $direction) {
            if ($significant($tokens[$j])) {
                return $j;
            }
        }

        return null;
    }

    /** Keys .env.example sets or shows commented out (`KEY=` or `# KEY=`). @return list<string> */
    private static function exampleKeys(): array
    {
        preg_match_all('/^\s*#?\s*([A-Z][A-Z0-9_]*)=/m', (string) file_get_contents(self::root().'/.env.example'), $m);

        return array_values(array_unique($m[1]));
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
