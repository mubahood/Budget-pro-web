@php
    $steps = ['business' => 'Business', 'products' => 'Products', 'money' => 'Money', 'team' => 'Team', 'done' => 'Done'];
    $country = config('onboarding.countries.'.($company->country ?: 'UG'));
    $methods = $company->payment_methods['methods'] ?? ['cash', 'mobile_money'];
    $momo = $company->payment_methods['momo'] ?? array_keys($country['momo'] ?? []);
    $channels = $company->receipt_channels ?? ['whatsapp'];
@endphp
<style>
    .setup-steps { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:16px; padding:0; list-style:none; }
    .setup-steps li { padding:6px 12px; border-radius:16px; background:#eef2f6; font-size:13px; }
    .setup-steps li.on { background:#3c8dbc; color:#fff; } .setup-steps li.ok { background:#dff0d8; color:#2e7d32; }
    .pack-table input[type=number] { width:100px; }
</style>
<ul class="setup-steps">
    @foreach($steps as $key => $label)
        <li class="{{ $step === $key ? 'on' : (in_array($key, $state['completed_steps'], true) ? 'ok' : '') }}">
            {!! in_array($key, $state['completed_steps'], true) ? '<i class="fa fa-check"></i> ' : '' !!}{{ $label }}
        </li>
    @endforeach
</ul>

<div class="box box-primary">
<div class="box-body">
@if($step === 'business')
    <h4>Tell us about your business</h4>
    <form method="post" action="{{ admin_url('setup/business') }}">@csrf
        <div class="row">
            <div class="col-sm-6 form-group"><label>Business name</label><input class="form-control" name="name" value="{{ old('name', $company->name) }}" required></div>
            <div class="col-sm-6 form-group"><label>Type of business</label>
                <select class="form-control" name="business_type">
                    @foreach(config('onboarding.business_types') as $k => $t)<option value="{{ $k }}" @selected(($company->business_type ?: 'retail') === $k)>{{ $t['label'] }}</option>@endforeach
                </select></div>
            <div class="col-sm-6 form-group"><label>Country</label>
                <select class="form-control" name="country">
                    @foreach(config('onboarding.countries') as $k => $c)<option value="{{ $k }}" @selected(($company->country ?: 'UG') === $k)>{{ $c['name'] }} ({{ $c['currency'] }})</option>@endforeach
                </select>
                <p class="help-block">Sets your currency, time zone and mobile money options.</p></div>
            <div class="col-sm-6 form-group"><label>Address (optional)</label><input class="form-control" name="address" value="{{ old('address', $company->address) }}"></div>
        </div>
        <button class="btn btn-primary">Continue</button>
    </form>
@elseif($step === 'products')
    <h4>Your products</h4>
    <p>Tick what you sell, fix prices if needed and enter how many you have now. You can change everything later.</p>
    <form method="post" action="{{ admin_url('setup/products') }}">@csrf
        <table class="table table-condensed pack-table">
            <thead><tr><th><input type="checkbox" onclick="document.querySelectorAll('.pick').forEach(c => c.checked = this.checked)"></th><th>Product</th><th>Category</th><th>Selling price ({{ $pack['currency'] }})</th><th>Cost price</th><th>In stock now</th></tr></thead>
            <tbody>
            @foreach($pack['items'] as $item)
                <tr>
                    <td><input class="pick" type="checkbox" name="items[{{ $item['key'] }}][pick]" value="1"></td>
                    <td>{{ $item['name'] }} <small class="text-muted">/ {{ $item['unit'] }}</small></td>
                    <td><small>{{ $item['category'] }}</small></td>
                    <td><input type="number" step="any" min="0" name="items[{{ $item['key'] }}][selling_price]" value="{{ $item['selling_price'] }}" class="form-control input-sm"></td>
                    <td><input type="number" step="any" min="0" name="items[{{ $item['key'] }}][buying_price]" value="{{ $item['buying_price'] }}" class="form-control input-sm"></td>
                    <td><input type="number" step="any" min="0" name="items[{{ $item['key'] }}][opening_stock]" value="0" class="form-control input-sm"></td>
                </tr>
            @endforeach
            @if($pack['items'] === [])<tr><td colspan="6" class="text-muted">No ready list for this business type yet — import a file or add products by hand.</td></tr>@endif
            </tbody>
        </table>
        <button class="btn btn-primary">Add ticked products</button>
    </form>
    <hr>
    <form method="post" action="{{ admin_url('setup/import') }}" enctype="multipart/form-data" class="form-inline">@csrf
        <label>Or import a CSV file</label>
        <input type="file" name="file" accept=".csv,text/csv" class="form-control" required>
        <button class="btn btn-default">Import</button>
        <p class="help-block">Columns: <code>name, category, selling_price, buying_price, opening_stock, unit, barcode</code> (only name and selling_price are required). Save from Excel as "CSV".</p>
    </form>
    <form method="post" action="{{ admin_url('setup/skip/products') }}">@csrf<button class="btn btn-link">Skip — I'll add products later</button></form>
@elseif($step === 'money')
    <h4>How you get paid</h4>
    <form method="post" action="{{ admin_url('setup/money') }}">@csrf
        <div class="form-group"><label>Payment methods</label><br>
            @foreach(config('onboarding.payment_methods') as $k => $label)
                <label class="checkbox-inline"><input type="checkbox" name="payment_methods[]" value="{{ $k }}" @checked(in_array($k, $methods, true))> {{ $label }}</label>
            @endforeach
        </div>
        <div class="form-group"><label>Mobile money</label><br>
            @foreach($country['momo'] ?? [] as $k => $label)
                <label class="checkbox-inline"><input type="checkbox" name="momo_providers[]" value="{{ $k }}" @checked(in_array($k, $momo, true))> {{ $label }}</label>
            @endforeach
        </div>
        <div class="form-group"><label>Receipts</label><br>
            <label class="checkbox-inline"><input type="checkbox" name="receipt_channels[]" value="whatsapp" @checked(in_array('whatsapp', $channels, true))> WhatsApp</label>
            <label class="checkbox-inline"><input type="checkbox" name="receipt_channels[]" value="print" @checked(in_array('print', $channels, true))> Printed</label>
            <label class="checkbox-inline"><input type="checkbox" name="receipt_channels[]" value="sms" @checked(in_array('sms', $channels, true))> SMS</label>
        </div>
        <div class="row">
            <div class="col-sm-4 form-group"><label>Cash in the drawer at opening</label><input type="number" min="0" step="any" class="form-control" name="opening_float" value="{{ $company->payment_methods['opening_float'] ?? 0 }}"></div>
            <div class="col-sm-8 form-group"><label>If stock runs out while selling</label>
                <select name="negative_stock_policy" class="form-control">
                    <option value="flag" @selected(($company->negative_stock_policy ?? 'flag') === 'flag')>Allow the sale and warn me (recommended)</option>
                    <option value="allow" @selected($company->negative_stock_policy === 'allow')>Allow the sale quietly</option>
                    <option value="block" @selected($company->negative_stock_policy === 'block')>Block the sale</option>
                </select></div>
        </div>
        <button class="btn btn-primary">Continue</button>
    </form>
@elseif($step === 'team')
    <h4>Invite your team</h4>
    <p>They get a WhatsApp or SMS link to join. You can do this later under Team.</p>
    <form method="post" action="{{ admin_url('setup/team') }}">@csrf
        <div class="row">
            <div class="col-sm-3 form-group"><label>Name</label><input class="form-control" name="name"></div>
            <div class="col-sm-3 form-group"><label>Phone</label><input class="form-control" name="phone" placeholder="07XX XXX XXX"></div>
            <div class="col-sm-3 form-group"><label>or Email</label><input class="form-control" type="email" name="email"></div>
            <div class="col-sm-3 form-group"><label>Role</label>
                <select class="form-control" name="role">
                    @foreach(config('permissions.roles') as $k => $r) @if($k !== 'owner')<option value="{{ $k }}" @selected($k === 'cashier')>{{ $r['label'] }}</option>@endif @endforeach
                </select></div>
        </div>
        <button class="btn btn-primary">Send invite</button>
    </form>
    <form method="post" action="{{ admin_url('setup/skip/team') }}">@csrf<button class="btn btn-link">Skip for now</button></form>
@else
    <h3><i class="fa fa-check-circle text-success"></i> You're set!</h3>
    <p>Everything also works offline in the {{ config('app.name') }} app — sales made without internet sync when you're back online.</p>
    <p><strong>{{ $checklist['done'] }} of {{ $checklist['total'] }}</strong> getting-started steps done.</p>
    <p>
        <a class="btn btn-success" href="{{ admin_url('sale-records/create') }}"><i class="fa fa-shopping-cart"></i> Make your first sale</a>
        <a class="btn btn-default" href="{{ admin_url('stock-items/create') }}">Add more products</a>
        <a class="btn btn-default" href="{{ admin_url('financial-reports') }}">See reports</a>
    </p>
    <a class="btn btn-link" href="{{ admin_url('/') }}">Go to the dashboard</a>
@endif
</div>
</div>
