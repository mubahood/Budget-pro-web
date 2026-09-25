<?php

namespace App\Admin\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Models\StockItem;
use App\Models\StockTake;
use App\Services\Shop\StockTakeService;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Grid;
use Encore\Admin\Show;
use Encore\Admin\Widgets\Form as WidgetForm;

/** Stock counts (P2-7/P2-10): create a count, enter what is on the shelf, post. */
class StockTakeController extends TenantAdminController
{
    protected $title = 'Stock counts';

    protected function grid()
    {
        $grid = new Grid(new StockTake());
        $grid->model()->where('company_id', Admin::user()->company_id)->orderByDesc('id');
        $grid->actions(fn ($a) => $a->disableEdit()->disableDelete());
        $grid->disableCreateButton();
        $grid->tools(fn ($t) => $t->append('<a class="btn btn-sm btn-success" href="'.admin_url('stock-takes/create').'"><i class="fa fa-list-ol"></i> Count stock</a> '
            .'<form method="POST" action="'.admin_url('stock-takes').'" style="display:inline">'.csrf_field().'<button class="btn btn-sm btn-default" title="Start a separate count even if one is open"><i class="fa fa-plus"></i> New separate count</button></form>'));
        $grid->column('number', 'No.');
        $grid->column('name');
        $grid->column('status')->label(['draft' => 'warning', 'posted' => 'success']);
        $grid->column('posted_at', 'Posted');

        return $grid;
    }

    protected function detail($id)
    {
        $take = StockTake::where('company_id', Admin::user()->company_id)->with('items.product')->findOrFail($id);
        $show = new Show($take);
        if ($take->status === 'draft') {
            $show->panel()->tools(fn ($t) => $t->append('<a class="btn btn-sm btn-primary" href="'.admin_url('stock-takes/'.$take->id.'/count').'"><i class="fa fa-list-ol"></i> Enter counts</a>&nbsp;'
                .'<form style="display:inline" method="POST" action="'.admin_url('stock-takes/'.$take->id.'/post').'" onsubmit="return confirm(\'Set stock to the counted quantities?\')">'.csrf_field().'<button class="btn btn-sm btn-success"><i class="fa fa-check"></i> Post count</button></form>&nbsp;'));
        }
        $show->field('number');
        $show->field('name');
        $show->field('status');
        $show->field('items', 'Counts')->unescape()->as(function () use ($take) {
            $h = '<table class="table table-condensed"><tr><th>Product</th><th>System (when counted)</th><th>Counted</th><th>Difference</th></tr>';
            foreach ($take->items as $i) {
                // The difference is always against what the system showed when the product was counted.
                $sys = (float) ($i->system_quantity ?? $i->product?->current_quantity);
                $d = round((float) $i->counted_quantity - $sys, 3);
                $h .= '<tr><td>'.e($i->product?->name).'</td><td>'.e(StockItemController::qty($sys)).'</td><td>'.e(StockItemController::qty($i->counted_quantity)).'</td><td style="color:'.($d < 0 ? '#c0392b' : '#27ae60').'">'.($d > 0 ? '+' : '').e(StockItemController::qty($d)).'</td></tr>';
            }

            return $h.'</table>';
        });

        return $show;
    }

    /** Continue the open count (refreshing or re-opening the page never piles up empty drafts). */
    public function create(\Encore\Admin\Layout\Content $content)
    {
        $draft = StockTake::withoutGlobalScopes()->where('company_id', Admin::user()->company_id)->where('status', 'draft')->orderByDesc('id')->first();
        if ($draft !== null) {
            admin_info('Continuing your open count', $draft->number.' is not posted yet. Enter the counts, then press Post count on the count page.');

            return redirect(admin_url('stock-takes/'.$draft->id.'/count'));
        }

        return $this->store();
    }

    /** Start a new count (POST). */
    public function store()
    {
        $take = (new StockTakeService())->create((int) Admin::user()->company_id, (int) Admin::user()->id, 'Stock count '.now()->format('d M Y'));

        return redirect(admin_url('stock-takes/'.$take->id.'/count'));
    }

    public function countForm($id)
    {
        /** @var StockTake $take */
        $take = StockTake::where('company_id', Admin::user()->company_id)->findOrFail($id);
        if ($take->status !== 'draft') {
            admin_warning('Already posted', 'This count was posted. Start a new count to correct stock again.');

            return redirect(admin_url('stock-takes/'.$take->id));
        }
        $saved = \App\Models\StockTakeItem::withoutGlobalScopes()->where('stock_take_id', $take->id)->get()->keyBy('stock_item_id');

        return Admin::content(function ($content) use ($take, $saved) {
            $content->title('Count stock — '.$take->number)->description('Enter what is physically on the shelf; leave a product blank to skip it');
            $form = new WidgetForm();
            $form->action(admin_url('stock-takes/'.$take->id.'/count'));
            $form->html('<a class="btn btn-sm btn-default" href="'.admin_url('stock-takes/'.$take->id).'"><i class="fa fa-eye"></i> Review and post this count</a> '
                .'<span class="text-muted">Saved counts are kept; sales made after you count a product are not lost when you post.</span>');
            foreach (StockItem::where('company_id', $take->company_id)->where('is_deleted', 0)->where('track_stock', 1)->orderBy('name')->get() as $p) {
                $row = $saved->get($p->id);
                $help = $row
                    ? 'Counted '.StockItemController::qty($row->counted_quantity).' when the system showed '.StockItemController::qty($row->system_quantity).'. System now: '.StockItemController::qty($p->current_quantity)
                    : 'System now: '.StockItemController::qty($p->current_quantity);
                $form->decimal('counts['.$p->id.']', $p->name)->default($row ? StockItemController::qty($row->counted_quantity, '') : null)->help($help);
            }
            $content->body($form);
        });
    }

    public function saveCounts($id)
    {
        $take = StockTake::where('company_id', Admin::user()->company_id)->findOrFail($id);
        $counts = [];
        foreach ((array) request('counts', []) as $productId => $qty) {
            if ($qty !== null && $qty !== '') {
                $counts[] = ['stock_item_id' => (int) $productId, 'counted_quantity' => $qty];
            }
        }
        try {
            (new StockTakeService())->count($take, $counts);
            admin_success('Counts saved', count($counts).' products counted.');
        } catch (BusinessRuleException $e) {
            admin_error('Not saved', $e->getMessage());
        }

        return redirect(admin_url('stock-takes/'.$take->id));
    }

    public function post($id)
    {
        $take = StockTake::where('company_id', Admin::user()->company_id)->findOrFail($id);
        try {
            (new StockTakeService())->post($take, (int) Admin::user()->id);
            admin_success('Count posted', 'Stock now matches the count.');
        } catch (BusinessRuleException $e) {
            admin_error('Not posted', $e->getMessage());
        }

        return redirect(admin_url('stock-takes/'.$take->id));
    }

    protected function form()
    {
        return new \Encore\Admin\Form(new StockTake());
    }
}
