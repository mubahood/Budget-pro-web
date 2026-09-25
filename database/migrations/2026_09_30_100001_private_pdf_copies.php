<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Receipts, invoices, budget and financial-report PDFs were written to public/storage/files with guessable
 * names (invoice-<id>.pdf, and every new financial report to the same "report-.pdf"), so anyone could read
 * other shops' documents. Sale/budget PDFs are now streamed only: the public copies move to private storage.
 * Financial-report PDFs get unguessable names; reports that pointed at the shared file are regenerated on
 * the next "Generate".
 */
return new class extends Migration
{
    public function up(): void
    {
        $dir = public_path('storage/files');
        $archive = storage_path('app/private-pdf-archive-2026-09-30');
        if (is_dir($dir)) {
            foreach (glob($dir.'/{invoice,receipt,budget}-*.pdf', GLOB_BRACE) ?: [] as $f) {
                $this->move($f, $archive);
            }
            if (is_file($dir.'/report-.pdf')) {
                $this->move($dir.'/report-.pdf', $archive);
            }
        }
        DB::table('sale_records')->where('receipt_pdf_url', 'like', 'files/%')->update(['receipt_pdf_url' => null, 'receipt_pdf_is_generated' => 'No']);
        DB::table('sale_records')->where('invoice_pdf_url', 'like', 'files/%')->update(['invoice_pdf_url' => null, 'invoice_pdf_is_generated' => 'No']);

        DB::table('financial_reports')->where('file', 'files/report-.pdf')->update(['file' => null, 'file_generated' => 'No']);
        foreach (DB::table('financial_reports')->where('file', 'like', 'files/report-%')->get(['id', 'file']) as $r) {
            if (! preg_match('/^files\/report-\d+\.pdf$/', (string) $r->file)) {
                continue;
            }
            $new = 'files/report-'.$r->id.'-'.Str::random(24).'.pdf';
            $from = public_path('storage/'.$r->file);
            if (is_file($from) && @rename($from, public_path('storage/'.$new))) {
                DB::table('financial_reports')->where('id', $r->id)->update(['file' => $new]);
            } else {
                DB::table('financial_reports')->where('id', $r->id)->update(['file' => null, 'file_generated' => 'No']);
            }
        }
    }

    public function down(): void
    {
        // Moving leaked documents back into public view is never wanted.
    }

    private function move(string $file, string $archive): void
    {
        if (! is_dir($archive)) {
            @mkdir($archive, 0700, true);
        }
        @rename($file, $archive.'/'.basename($file));
    }
};
