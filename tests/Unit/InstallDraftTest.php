<?php

namespace Tests\Unit;

use App\Services\Install\InstallDraft;
use PHPUnit\Framework\TestCase;

/** The installer's answers between browser steps: sealed under APP_KEY, bound to one browser. */
class InstallDraftTest extends TestCase
{
    public function test_answers_are_encrypted_and_bound_to_the_installing_browser(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'bp-draft-');
        $key = 'base64:'.base64_encode(random_bytes(32));
        $draft = new InstallDraft;

        try {
            $token = $draft->save($file, $key, ['owner_password' => 'unusual$\"password', 'business' => 'Fresh Mart']);
            $this->assertStringNotContainsString('unusual', (string) file_get_contents($file));
            $this->assertStringNotContainsString('Fresh Mart', (string) file_get_contents($file));
            $this->assertSame(0600, fileperms($file) & 0777);
            $this->assertSame('unusual$\"password', $draft->load($file, $key, $token)['owner_password']);

            foreach ([
                fn () => $draft->load($file, $key, 'another browser'),
                fn () => $draft->load($file, 'base64:'.base64_encode(random_bytes(32)), $token),
                fn () => $draft->load($file, 'too-short', $token),
                fn () => $draft->load($file.'-missing', $key, $token),
            ] as $i => $attempt) {
                try {
                    $attempt();
                    $this->fail("Attempt {$i} should have been refused.");
                } catch (\DomainException) {
                    $this->addToAssertionCount(1);
                }
            }

            file_put_contents($file, 'AAAA'.substr((string) file_get_contents($file), 4));
            $this->expectException(\DomainException::class);
            $draft->load($file, $key, $token);
        } finally {
            $draft->discard($file);
            $this->assertFileDoesNotExist($file);
        }
    }
}
