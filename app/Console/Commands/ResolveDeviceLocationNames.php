<?php

namespace App\Console\Commands;

use App\Services\GeocodingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Safety-net backfill for the points a push request left unresolved — either
 * because geocoding was rate-capped mid-batch (see
 * TrackingController::resolvePlaceNames) or the live Nominatim call failed.
 * Runs on a schedule (see App\Console\Kernel::schedule) rather than a queue
 * worker since this app runs on shared hosting with QUEUE_CONNECTION=sync.
 */
class ResolveDeviceLocationNames extends Command
{
    protected $signature = 'tracking:backfill-location-names {--limit=20}';

    protected $description = 'Resolve place names for device_locations rows still missing one';

    public function handle(GeocodingService $geocoding): int
    {
        $limit = (int) $this->option('limit');

        $rows = DB::table('device_locations')
            ->whereNull('place_name')
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->get(['id', 'lat', 'lng']);

        if ($rows->isEmpty()) {
            $this->info('Nothing to backfill.');

            return 0;
        }

        $resolved = 0;
        foreach ($rows as $row) {
            $name = $geocoding->resolve((float) $row->lat, (float) $row->lng);
            if ($name !== null) {
                DB::table('device_locations')->where('id', $row->id)->update(['place_name' => $name]);
                $resolved++;
            }
        }

        $this->info("Resolved {$resolved}/{$rows->count()} location names.");

        return 0;
    }
}
