<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Play Store's non-negotiable (brief §7) modelled as data, not just a UI
 * checkbox (PLAN.md §3/§8): a device with no active (non-revoked) consent
 * row cannot have a location accepted. `tracked_devices.id` is `bigint
 * unsigned`, `admin_users.id` is `int unsigned` — both matched exactly for
 * real foreign keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pingpin_device_consents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('device_id');
            $table->unsignedInteger('consented_by_user_id');
            $table->timestamp('consented_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('consent_text_version');
            $table->timestamps();

            $table->index(['device_id', 'revoked_at']);
            $table->foreign('device_id')->references('id')->on('tracked_devices')->cascadeOnDelete();
            $table->foreign('consented_by_user_id')->references('id')->on('admin_users')->cascadeOnDelete();
        });

        // Every device already registered before this table existed gets a
        // consent row backfilled from its own user_id, on the same interim
        // "self-registration is the consent event" policy the controller
        // now applies going forward (DECISIONS.md D11) — otherwise this
        // migration would instantly lock out every already-tracked device
        // with no way to recover until the real consent UI (Phase 10) ships.
        $now = now();
        DB::table('tracked_devices as d')
            ->join('admin_users as u', 'd.user_id', '=', 'u.id') // only devices whose user_id actually resolves — matches the FK
            ->orderBy('d.id')
            ->select('d.id as device_id', 'd.user_id', 'd.created_at')
            ->chunk(200, function ($devices) use ($now) {
                $rows = [];
                foreach ($devices as $device) {
                    $rows[] = [
                        'device_id' => $device->device_id,
                        'consented_by_user_id' => $device->user_id,
                        'consented_at' => $device->created_at ?? $now,
                        'consent_text_version' => 'implicit-v0-backfill',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                if (! empty($rows)) {
                    DB::table('pingpin_device_consents')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('pingpin_device_consents');
    }
};
