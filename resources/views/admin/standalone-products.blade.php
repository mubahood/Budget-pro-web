@php
    $money = fn ($cur, $v) => $v === null ? '<span class="text-muted">—</span>' : e(\App\Models\StandaloneProduct::money($cur, (float) $v));
    $p = $editing;
    $limits = $p ? $p->licenceLimits() : ['max_users' => 5, 'max_locations' => 1, 'max_devices' => 5]; // §22 starting point: a shop
    $val = fn ($key, $default = null) => old($key, $p ? $p->{$key} : $default);
@endphp
<div class="row">
    <div class="col-md-8">
        <div class="box">
            <div class="box-header with-border"><h3 class="box-title">Products</h3></div>
            <div class="box-body no-padding table-responsive">
                <table class="table table-condensed">
                    <tr><th>Product</th><th class="text-right">Price</th><th class="text-right">Renewal</th><th>Updates</th><th>Limits</th><th>Sale</th><th></th></tr>
                    @forelse($products as $product)
                        <tr>
                            <td><strong>{{ $product->name }}</strong>@if($product->white_label) <span class="label label-default">white-label</span>@endif
                                <br><small class="text-muted">{{ $product->slug }}</small></td>
                            <td class="text-right"><small>{!! $money('UGX', $product->price_ugx) !!}<br>{!! $money('USD', $product->price_usd) !!}</small></td>
                            <td class="text-right"><small>{!! $money('UGX', $product->renewal_price_ugx) !!}<br>{!! $money('USD', $product->renewal_price_usd) !!}</small></td>
                            <td><small>{{ $product->updates_months }} months</small></td>
                            <td><small>{{ \App\Models\StandaloneProduct::describeLimits($product->limits) }}</small></td>
                            <td>{!! $product->is_active ? '<span class="label label-success">On sale</span>' : '<span class="label label-default">Off</span>' !!}</td>
                            <td class="text-nowrap">
                                <a class="btn btn-xs btn-default" href="{{ admin_url('standalone-products?edit='.$product->id) }}">Edit</a>
                                <form method="post" action="{{ admin_url('standalone-products/'.$product->id.'/toggle') }}" style="display:inline">@csrf
                                    <button class="btn btn-xs {{ $product->is_active ? 'btn-warning' : 'btn-success' }}">{{ $product->is_active ? 'Take off sale' : 'Put on sale' }}</button></form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-muted">No product yet. Add one on the right: the public page reads "Coming soon" until one is on sale.</td></tr>
                    @endforelse
                </table>
            </div>
            <div class="box-footer"><small class="text-muted">A starting point (plan §22): a <b>Shop</b> product with 1 store, 5 team logins and 5 phones; a <b>Supermarket / chain</b> product, unlimited, at about 2.5× that; renewal of updates at about 20–25% a year. An empty renewal price means renewals are by arrangement (staff use Renew on the licence).</small></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="box box-primary">
            <div class="box-header with-border"><h3 class="box-title">{{ $p ? 'Edit '.$p->name : 'Add a product' }}</h3>
                @if($p)<div class="box-tools"><a href="{{ admin_url('standalone-products') }}" class="btn btn-xs btn-default">New instead</a></div>@endif
            </div>
            <form method="post" action="{{ admin_url('standalone-products'.($p ? '/'.$p->id : '')) }}">@csrf
                <div class="box-body">
                    @if($errors->any())<div class="alert alert-danger" style="padding:8px 12px">{{ $errors->first() }}</div>@endif
                    <div class="form-group"><label for="sp-name">Name</label>
                        <input id="sp-name" name="name" class="form-control" required maxlength="190" value="{{ $val('name') }}" placeholder="Shop"></div>
                    <div class="form-group"><label for="sp-description">Description <small class="text-muted">(on the public page)</small></label>
                        <textarea id="sp-description" name="description" class="form-control" rows="2" maxlength="2000">{{ $val('description') }}</textarea></div>
                    <div class="row">
                        <div class="col-xs-6 form-group"><label for="sp-ugx">Price UGX</label><input id="sp-ugx" name="price_ugx" type="number" min="1" step="1" required class="form-control" value="{{ $val('price_ugx') !== null ? (float) $val('price_ugx') : '' }}"></div>
                        <div class="col-xs-6 form-group"><label for="sp-usd">Price US$ <small class="text-muted">(optional)</small></label><input id="sp-usd" name="price_usd" type="number" min="1" step="0.01" class="form-control" value="{{ $val('price_usd') !== null ? (float) $val('price_usd') : '' }}"></div>
                        <div class="col-xs-6 form-group"><label for="sp-rugx">Renewal UGX</label><input id="sp-rugx" name="renewal_price_ugx" type="number" min="1" step="1" class="form-control" value="{{ $val('renewal_price_ugx') !== null ? (float) $val('renewal_price_ugx') : '' }}"></div>
                        <div class="col-xs-6 form-group"><label for="sp-rusd">Renewal US$</label><input id="sp-rusd" name="renewal_price_usd" type="number" min="1" step="0.01" class="form-control" value="{{ $val('renewal_price_usd') !== null ? (float) $val('renewal_price_usd') : '' }}"></div>
                    </div>
                    <div class="form-group"><label for="sp-months">Months of updates included</label>
                        <input id="sp-months" name="updates_months" type="number" min="1" max="60" required class="form-control" value="{{ $val('updates_months', 12) }}"></div>
                    <label>Limits <small class="text-muted">(empty = unlimited)</small></label>
                    <div class="row">
                        @foreach(['max_users' => 'Team logins', 'max_locations' => 'Stores', 'max_devices' => 'Phones'] as $key => $label)
                            <div class="col-xs-4 form-group"><label for="sp-{{ $key }}" style="font-weight:normal">{{ $label }}</label>
                                <input id="sp-{{ $key }}" name="{{ $key }}" type="number" min="1" class="form-control" value="{{ old($key, $limits[$key]) }}"></div>
                        @endforeach
                    </div>
                    <div class="form-group"><label for="sp-sort">Order on the page</label><input id="sp-sort" name="sort" type="number" min="0" class="form-control" value="{{ $val('sort', 0) }}"></div>
                    <div class="checkbox"><label><input type="checkbox" name="white_label" value="1" @checked(old('white_label', $p?->white_label))> White-label (no "Powered by Budget Pro")</label></div>
                    <div class="checkbox"><label><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $p?->is_active))> On sale</label></div>
                </div>
                <div class="box-footer"><button class="btn btn-primary">Save</button></div>
            </form>
        </div>
    </div>
</div>
