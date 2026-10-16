<?php

namespace Tests\Unit;

use App\Services\Install\EnvironmentWriter;
use Dotenv\Dotenv;
use PHPUnit\Framework\TestCase;

/** The installer's .env writer: every value survives Dotenv, the file is 0600, never after install. */
class EnvironmentWriterTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/bp-env-'.bin2hex(random_bytes(8));
        mkdir($this->directory.'/storage/app/private', 0700, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->directory.'/.env', $this->directory.'/storage/app/private/installed.json', $this->directory.'/marker.json'] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory.'/storage/app/private');
        @rmdir($this->directory.'/storage/app');
        @rmdir($this->directory.'/storage');
        @rmdir($this->directory);
        parent::tearDown();
    }

    public function test_awkward_values_round_trip_through_dotenv_into_a_private_file(): void
    {
        $file = $this->directory.'/.env';
        $settings = [
            'DB_PASSWORD' => 'a$B"c\\d#e + (f) ${HOME} \'q\'',
            'MAIL_FROM_NAME' => 'Fresh Mart Ltd',
            'APP_EDITION' => 'standalone',
            'MULTI' => "line one\nline two",
            'EMPTY' => '',
        ];
        (new EnvironmentWriter)->write($file, $settings);

        $parsed = Dotenv::parse((string) file_get_contents($file));
        foreach ($settings as $key => $value) {
            $this->assertSame($value, $parsed[$key], $key);
        }
        $this->assertSame(0600, fileperms($file) & 0777);
        $this->assertSame([], glob($this->directory.'/.env.*.tmp'));
    }

    public function test_invalid_names_and_nul_bytes_are_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new EnvironmentWriter)->write($this->directory.'/.env', ['db_password' => 'x']);
    }

    public function test_a_nul_byte_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new EnvironmentWriter)->write($this->directory.'/.env', ['DB_PASSWORD' => "x\0y"]);
    }

    public function test_nothing_is_written_once_the_copy_is_installed(): void
    {
        file_put_contents($this->directory.'/storage/app/private/installed.json', '{"edition":"standalone"}');
        try {
            (new EnvironmentWriter)->write($this->directory.'/.env', ['APP_EDITION' => 'standalone']);
            $this->fail('An installed copy must not have its .env rewritten.');
        } catch (\DomainException) {
            $this->assertFileDoesNotExist($this->directory.'/.env');
        }

        // The web app's .env is guarded by the kernel's marker, passed in.
        unlink($this->directory.'/storage/app/private/installed.json');
        file_put_contents($this->directory.'/marker.json', '{}');
        $this->expectException(\DomainException::class);
        (new EnvironmentWriter($this->directory.'/marker.json'))->write($this->directory.'/.env', ['APP_EDITION' => 'standalone']);
    }
}
