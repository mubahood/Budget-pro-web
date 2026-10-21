<?php

namespace Tests\Feature\Sacco;

use PHPUnit\Framework\TestCase;

/**
 * Akiba Ledger P1.5 (ADR 0009 §4.1): PostingService is the only code that writes the journal and the balance
 * caches. This scans every PHP statement under app/ that names a ledger table or model and fails when one that
 * writes is anywhere else. (The sub-ledger caches are written by each SubLedger's project(), which only
 * PostingService calls; LedgerVerifier proves they equal their lines.)
 */
class LedgerWritersTest extends TestCase
{
    /** The ledger's tables and models that only PostingService writes. */
    private const GUARDED = ['sacco_journal_entries', 'sacco_journal_lines', 'sacco_account_balances', 'sacco_ledger_heads',
        'SaccoJournalEntry', 'SaccoJournalLine', 'SaccoAccountBalance', 'SaccoLedgerHead'];

    /** Files allowed to write them, and why. */
    private const ALLOWED = [
        'app/Services/Sacco/Ledger/PostingService.php' => 'the one writer',
        // The chart creates each account's empty balance row and the empty head when the books are set up.
        'app/Services/Sacco/Ledger/ChartOfAccounts.php' => 'creates empty balance rows and the head with the chart',
    ];

    private const WRITES = '/->\s*(insert|insertGetId|insertOrIgnore|upsert|update|updateOrInsert|increment|decrement|delete|forceDelete|truncate|save|fill|push)\s*\(|::\s*(create|insert|upsert|updateOrCreate|firstOrCreate|destroy|truncate)\s*\(|new\s+(SaccoJournalEntry|SaccoJournalLine|SaccoAccountBalance|SaccoLedgerHead)\b/';

    public function test_only_the_posting_service_writes_the_ledger(): void
    {
        $root = dirname(__DIR__, 3);
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/app', \FilesystemIterator::SKIP_DOTS));
        $seen = 0;
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root) + 1);
            $code = (string) file_get_contents($file->getPathname());
            foreach (preg_split('/;\s*\n/', $code) ?: [] as $statement) {
                $names = array_filter(self::GUARDED, fn ($g) => preg_match('/\b'.$g.'\b/', $statement) === 1);
                if ($names === [] || preg_match(self::WRITES, $statement) !== 1) {
                    continue;
                }
                if (preg_match('#^app/Models/Sacco#', $relative) === 1 && preg_match('/static::(updating|deleting)/', $statement) === 1) {
                    continue; // the models' own guards
                }
                $seen++;
                if (! isset(self::ALLOWED[$relative])) {
                    $offenders[] = $relative.': '.trim(mb_substr(preg_replace('/\s+/', ' ', $statement), -160));
                }
            }
        }
        $this->assertGreaterThan(3, $seen, 'the scan sees the posting service\'s own writes');
        $this->assertSame([], $offenders, "Only PostingService writes the SACCO journal and its caches (ADR 0009 §4.1):\n".implode("\n", $offenders));
    }
}
