<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Budget\BudgetTemplates;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The budget templates (read only): GET budget-templates (summaries, filters type, culture, religion, size, q;
 * `full=1` for the whole data file, which the phone caches) and GET budget-templates/{key}?guests=N (one template
 * laid out for N guests with its totals). Both carry an ETag of the data file's version, so a phone that already
 * has this version gets 304 Not Modified. Prices are in the account's currency (BudgetTemplates::price).
 */
class BudgetTemplateController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse|Response
    {
        if ($this->fresh($request)) {
            return $this->notModified();
        }
        $currency = $this->currency($request);
        if ($request->boolean('full')) {
            return $this->cached($this->success(BudgetTemplates::data() + ['etag' => BudgetTemplates::etag()], 'Budget templates.'));
        }
        $f = $request->validate([
            'type' => ['nullable', 'string', 'max:40'], 'culture' => ['nullable', 'string', 'max:40'], 'religion' => ['nullable', 'string', 'max:40'],
            'size' => ['nullable', 'string', 'max:20'], 'q' => ['nullable', 'string', 'max:100'],
        ]);

        return $this->cached($this->success([
            'version' => BudgetTemplates::version(), 'etag' => BudgetTemplates::etag(), 'source_currency' => 'UGX', 'currency' => $currency,
            'event_types' => BudgetTemplates::eventTypes(), 'sizes' => BudgetTemplates::SIZES,
            'templates' => array_map(fn ($t) => BudgetTemplates::summary($t, $currency), BudgetTemplates::filter($f)),
        ], 'Budget templates.'));
    }

    public function show(Request $request, string $key): JsonResponse|Response
    {
        $template = BudgetTemplates::find($key);
        if ($template === null) {
            return $this->notFound('This budget template was not found.');
        }
        $guests = $request->validate(['guests' => ['nullable', 'integer', 'min:'.BudgetTemplates::MIN_GUESTS, 'max:'.BudgetTemplates::MAX_GUESTS]])['guests'] ?? null;
        if ($guests === null && $this->fresh($request)) {
            return $this->notModified();
        }
        $currency = $this->currency($request);
        $response = $this->success(BudgetTemplates::detail($template, $guests === null ? null : (int) $guests, $currency)
            + ['version' => BudgetTemplates::version(), 'currency' => $currency], 'Budget template.');

        return $guests === null ? $this->cached($response) : $response;
    }

    private function currency(Request $request): string
    {
        return strtoupper((string) (\Illuminate\Support\Facades\DB::table('companies')->where('id', (int) $request->user()->company_id)->value('currency') ?: 'UGX'));
    }

    private function fresh(Request $request): bool
    {
        $sent = array_map('trim', explode(',', (string) $request->header('If-None-Match', '')));

        return in_array(BudgetTemplates::etag(), $sent, true) || in_array('W/'.BudgetTemplates::etag(), $sent, true);
    }

    private function notModified(): Response
    {
        return response('', 304)->header('ETag', BudgetTemplates::etag())->header('Cache-Control', 'private, max-age=3600');
    }

    private function cached(JsonResponse $response): JsonResponse
    {
        return $response->header('ETag', BudgetTemplates::etag())->header('Cache-Control', 'private, max-age=3600');
    }
}
