<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * A shop's fiscal / e-invoicing connector (supermarket plan F2). Written by FiscalService only.
 * `config` is encrypted with Laravel Crypt and is never serialised: config() decrypts it for the adapter.
 */
class FiscalSetting extends Model
{
    protected $table = 'fiscal_settings';

    protected $guarded = ['id'];

    protected $hidden = ['config'];

    protected $casts = ['is_active' => 'boolean', 'last_test_ok' => 'boolean', 'last_tested_at' => 'datetime'];

    /** @return array<string, mixed> the decrypted config ([] when none or unreadable) */
    public function decrypted(): array
    {
        if (! $this->config) {
            return [];
        }
        try {
            return json_decode(Crypt::decryptString((string) $this->config), true) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param array<string, mixed> $config */
    public function encryptConfig(array $config): void
    {
        $this->config = Crypt::encryptString(json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
