<?php

namespace App\Admin\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\StockItem;
use App\Services\Shop\LocationStock;
use App\Services\Shop\TransferService;
use App\Support\Money;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Encore\Admin\Widgets\Form as WidgetForm;
use Illuminate\Support\Facades\DB;

/** Locations, which phone sells where, and stock transfers on the web (plan P4-4). */
class LocationController extends Controller
{
    private function companyId(): int
    {
        return (int) Admin::user()->company_id;
    }

    public function index(Content $content)
    {
        $cid = $this->companyId();
        LocationStock::defaultLocation($cid);
        $locations = DB::table('locations')->where('company_id', $cid)->orderByDesc('is_default')->orderBy('name')->get();
        $values = DB::table('stock_levels as s')->join('stock_items as p', 'p.id', '=', 's.stock_item_id')->where('s.company_id', $cid)->where('s.quantity', '>', 0)
            ->groupBy('s.location_id')->selectRaw('s.location_id, SUM(s.quantity * p.buying_price) AS v')->pluck('v', 'location_id');
        $devices = DB::table('devices')->where('company_id', $cid)->whereNull('revoked_at')->get(['id', 'name', 'location_id']);
        $multi = (new \App\Services\Billing\Quotas())->featureOn(Company::withoutGlobalScopes()->find($cid), 'multi_location');

        return $content->title('Locations')->description('Shops and stores, and which phone sells where')->body(view('admin.locations', compact('locations', 'values', 'devices', 'multi')));
    }

    public function store()
    {
        try {
            (new TransferService())->createLocation(Company::withoutGlobalScopes()->findOrFail($this->companyId()), (string) request('name'), request('address') ?: null);
            admin_success('Location added', (string) request('name'));
        } catch (BusinessRuleException $e) {
            admin_error('Not added', $e->getMessage());
        }

        return redirect(admin_url('locations'));
    }

    public function devices()
    {
        foreach ((array) request('device_location', []) as $deviceId => $locationId) {
            if ($locationId !== '' && ! DB::table('locations')->where('company_id', $this->companyId())->where('id', $locationId)->exists()) {
                continue;
            }
            DB::table('devices')->where('company_id', $this->companyId())->where('id', $deviceId)->update(['location_id' => $locationId === '' ? null : (int) $locationId, 'updated_at' => now()]);
        }
        admin_success('Saved', 'Phones will sell from their location from the next sync.');

        return redirect(admin_url('locations'));
    }

    public function transfers(Content $content)
    {
        $cid = $this->companyId();
        $rows = DB::table('stock_transfers as t')->where('t.company_id', $cid)->join('locations as f', 'f.id', '=', 't.from_location_id')->join('locations as to', 'to.id', '=', 't.to_location_id')
            ->orderByDesc('t.id')->limit(100)->get(['t.id', 't.number', 't.created_at', 'f.name as from', 'to.name as to', 't.notes']);
        $locations = DB::table('locations')->where('company_id', $cid)->where('is_active', true)->pluck('name', 'id');
        $form = new WidgetForm();
        $form->action(admin_url('stock-transfers'));
        $form->select('from_location_id', 'From')->options($locations)->default(LocationStock::defaultLocation($cid));
        $form->select('to_location_id', 'To')->options($locations);
        $form->table('items', 'Products', function ($t) use ($cid) {
            $t->select('stock_item_id', 'Product')->options(StockItem::where('company_id', $cid)->orderBy('name')->pluck('name', 'id'));
            $t->decimal('quantity', 'Quantity');
        });
        $form->text('notes', 'Note');

        return $content->title('Stock transfers')->body(view('admin.transfers', ['rows' => $rows]))->body($locations->count() > 1 ? $form->render() : '<p class="text-muted">Add a second location to move stock between them.</p>');
    }

    public function transfer()
    {
        $lines = array_values(array_filter((array) request('items', []), fn ($l) => ! empty($l['stock_item_id']) && (float) ($l['quantity'] ?? 0) > 0 && empty($l['_remove_'])));
        try {
            $id = (new TransferService())->transfer($this->companyId(), (int) Admin::user()->id, (int) request('from_location_id'), (int) request('to_location_id'), $lines, request('notes') ?: null);
            admin_success('Stock moved', (string) DB::table('stock_transfers')->where('id', $id)->value('number'));
        } catch (BusinessRuleException $e) {
            admin_error('Not moved', $e->getMessage());
        }

        return redirect(admin_url('stock-transfers'));
    }

    public static function value(mixed $v): string
    {
        return Money::format((float) $v);
    }
}
