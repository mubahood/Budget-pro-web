<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\StandaloneLicence;
use App\Models\StandaloneOrder;
use App\Models\StandaloneProduct;
use App\Services\StandaloneSalesService;
use App\Support\BuyerLink;
use Encore\Admin\Layout\Content;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Platform › Standalone licences (docs/STANDALONE_EDITION_PLAN.md §11): every licence, online and
 * offline, and the orders behind them. Issue a licence for a sale made outside checkout (it is emailed
 * at once), and act for a buyer: send or copy their link (live has no SMTP yet: staff copy the link
 * and send it by WhatsApp), renew by the product's months, change the address, revoke or reinstate,
 * download licence.json. Platform group: 404 on a standalone copy, 403 for shop users.
 */
class StandaloneLicenceController extends Controller
{
    public function index(Request $request, Content $content, StandaloneSalesService $sales)
    {
        $term = trim((string) $request->query('q', ''));
        $licences = StandaloneLicence::query()->with('product')->withCount('orders')
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('customer', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('domains', 'like', "%{$term}%")->orWhere('reference', 'like', "%{$term}%")->orWhere('id', $term)))
            ->orderByDesc('created_at')->paginate(30)->withQueryString();
        $downloads = DB::table('standalone_downloads')->whereIn('licence_id', $licences->pluck('id'))
            ->groupBy('licence_id')->pluck(DB::raw('count(*)'), 'licence_id');

        return $content->title('Standalone licences')->description('Issue, renew, move and revoke licences; orders from checkout')
            ->body(view('admin.standalone-licences', [
                'licences' => $licences, 'downloads' => $downloads, 'term' => $term,
                'products' => StandaloneProduct::query()->orderBy('sort')->orderBy('name')->get(),
                'orders' => StandaloneOrder::query()->with('product')->orderByDesc('id')->limit(25)->get(),
                'signing' => $sales->signingReady(),
            ]));
    }

    public function issue(Request $request, StandaloneSalesService $sales)
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:standalone_products,id'],
            'business' => ['required', 'string', 'min:2', 'max:190'],
            'name' => ['nullable', 'string', 'max:190'],
            'email' => ['required', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:40'],
            'domain' => ['required', 'string', 'max:253'],
            'reference' => ['required', 'string', 'min:3', 'max:190'],
        ]);

        return $this->attempt(function () use ($sales, $data) {
            $licence = $sales->issueOffline(StandaloneProduct::query()->findOrFail($data['product_id']), $data, $data['reference']);

            return ['Licence issued', "Issued to {$licence->customer} and emailed to {$licence->email}. Use Copy buyer link to send it by WhatsApp too."];
        }, true);
    }

    public function act(Request $request, string $id, string $action, StandaloneSalesService $sales)
    {
        $licence = StandaloneLicence::query()->with('product')->findOrFail($id);

        if ($action === 'copy-link') {
            return redirect(admin_url('standalone-licences').'?q='.urlencode($licence->id))
                ->with('buyer_link', ['licence' => $licence->id, 'url' => BuyerLink::make($licence->id)]);
        }

        return $this->attempt(function () use ($action, $licence, $sales, $request): array {
            switch ($action) {
                case 'send-link':
                    $sales->sendLink($licence);

                    return ['Link sent', "A private link is on its way to {$licence->email}. It works for ".BuyerLink::HOURS.' hours from when the email goes out.'];
                case 'renew':
                    $months = (int) ($licence->product?->updates_months ?? 12);

                    return ['Renewed', "Updates extended by {$months} months, to ".$sales->extend($licence, $months)->updates_until->format('j M Y').'. Record any payment for it separately.'];
                case 'domain':
                    return ['Address changed', 'The licence now covers '.$sales->changeDomain($licence, (string) $request->input('domain'), true)->domain().'. The buyer must download licence.json again.'];
                case 'revoke':
                    $sales->revoke($licence);

                    return ['Revoked', 'Downloads and updates stopped. The buyer\'s installed copy keeps working.'];
                default: // reinstate
                    $sales->reinstate($licence);

                    return ['Reinstated', 'Downloads and updates are available again.'];
            }
        });
    }

    public function document(string $id, StandaloneSalesService $sales)
    {
        $licence = StandaloneLicence::query()->findOrFail($id);
        try {
            $document = $sales->document($licence);
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            admin_error('Not done', $e->getMessage());

            return redirect(admin_url('standalone-licences'));
        }

        return response(json_encode($document, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 200, [
            'Content-Type' => 'application/json', 'Content-Disposition' => 'attachment; filename="licence.json"', 'Cache-Control' => 'no-store, private',
        ]);
    }

    private function attempt(callable $action, bool $keepInput = false)
    {
        $back = redirect()->back(302, [], admin_url('standalone-licences'));
        try {
            [$title, $message] = $action();
        } catch (\DomainException|\RuntimeException|\InvalidArgumentException $e) {
            admin_error('Not done', $e->getMessage());

            return $keepInput ? $back->withInput() : $back;
        }
        admin_success($title, $message);

        return $back;
    }
}
