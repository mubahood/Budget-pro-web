@php
    $post = fn ($licence, $action) => admin_url('standalone-licences/'.$licence->id.'/'.$action);
    $orderBadge = ['pending' => 'default', 'paid' => 'success', 'failed' => 'danger', 'mismatch' => 'warning'];
    $link = session('buyer_link');
@endphp
@if(is_array($link))
    <div class="alert alert-info" style="margin-bottom:15px">
        <strong>Buyer link</strong> (works for {{ \App\Support\BuyerLink::HOURS }} hours; anyone with it opens this buyer's downloads, so send it only to them, for example by WhatsApp):
        <div class="input-group" style="margin-top:8px">
            <input id="buyer-link" class="form-control" readonly value="{{ $link['url'] }}" onclick="this.select()">
            <span class="input-group-btn"><button type="button" class="btn btn-default" onclick="var i=document.getElementById('buyer-link');i.select();(navigator.clipboard?navigator.clipboard.writeText(i.value):Promise.reject()).then(function(){},function(){document.execCommand('copy')});this.textContent='Copied'">Copy</button></span>
        </div>
    </div>
@endif
@unless($signing)
    <div class="alert alert-warning">Release signing is not configured (RELEASE_SIGNING_KEY_ID / RELEASE_SIGNING_SECRET): licences cannot be issued, signed or linked yet.</div>
@endunless
<div class="row">
    <div class="col-md-8">
        <div class="box">
            <div class="box-header with-border">
                <h3 class="box-title">Licences</h3>
                <form method="get" action="{{ admin_url('standalone-licences') }}" class="box-tools" style="width:280px">
                    <div class="input-group input-group-sm"><input name="q" class="form-control" value="{{ $term }}" placeholder="Name, email, phone, address, reference">
                        <span class="input-group-btn"><button class="btn btn-default"><i class="fa fa-search"></i></button></span></div>
                </form>
            </div>
            <div class="box-body no-padding table-responsive">
                <table class="table table-condensed">
                    <tr><th>Licensed to</th><th>Address</th><th>Product</th><th>Updates until</th><th>Status</th><th></th></tr>
                    @forelse($licences as $licence)
                        <tr>
                            <td><strong>{{ $licence->customer }}</strong><br><small>{{ $licence->email }}{{ $licence->phone ? ' · '.$licence->phone : '' }}</small>
                                <br><small class="text-muted">{{ $licence->source }}{{ $licence->reference ? ' · '.$licence->reference : '' }} · {{ $licence->created_at?->format('j M Y') }}
                                    · {{ (int) ($downloads[$licence->id] ?? 0) }} downloads · {{ $licence->orders_count }} orders</small>
                                <br><small class="text-muted" style="font-family:monospace">{{ $licence->id }}</small></td>
                            <td><code>{{ $licence->domain() }}</code>@if($licence->domain_changed_at)<br><small class="text-muted">moved {{ $licence->domain_changed_at->format('j M Y') }}</small>@endif</td>
                            <td><small>{{ $licence->product?->name ?? '—' }}</small></td>
                            <td><small>{{ $licence->updates_until->format('j M Y') }}</small>@if($licence->updatesEnded())<br><span class="label label-warning">ended</span>@endif</td>
                            <td>{!! $licence->isActive() ? '<span class="label label-success">Active</span>' : '<span class="label label-danger">Revoked</span>' !!}
                                @if($licence->last_link_sent_at)<br><small class="text-muted">link sent {{ $licence->last_link_sent_at->format('j M H:i') }}</small>@endif</td>
                            <td class="text-nowrap">
                                <form method="post" action="{{ $post($licence, 'send-link') }}" style="display:inline">@csrf<button class="btn btn-xs btn-default" title="Email the buyer a private link">Send link</button></form>
                                <form method="post" action="{{ $post($licence, 'copy-link') }}" style="display:inline">@csrf<button class="btn btn-xs btn-default" title="Make a link to send by WhatsApp">Copy buyer link</button></form>
                                <a class="btn btn-xs btn-default" href="{{ admin_url('standalone-licences/'.$licence->id.'/licence.json') }}" title="Download licence.json"><i class="fa fa-download"></i></a>
                                <br>
                                <form method="post" action="{{ $post($licence, 'renew') }}" style="display:inline">@csrf
                                    <button class="btn btn-xs btn-primary" onclick="return confirm('Add {{ $licence->product?->updates_months ?? 12 }} months of updates to this licence?')">Renew +{{ $licence->product?->updates_months ?? 12 }} mo</button></form>
                                @if($licence->isActive())
                                    <form method="post" action="{{ $post($licence, 'revoke') }}" style="display:inline">@csrf
                                        <button class="btn btn-xs btn-danger" onclick="return confirm('Stop downloads and updates for this licence? The installed copy keeps working.')">Revoke</button></form>
                                @else
                                    <form method="post" action="{{ $post($licence, 'reinstate') }}" style="display:inline">@csrf<button class="btn btn-xs btn-success">Reinstate</button></form>
                                @endif
                                <details style="margin-top:4px"><summary style="cursor:pointer;font-size:12px">Change address</summary>
                                    <form method="post" action="{{ $post($licence, 'domain') }}" style="margin-top:4px">@csrf
                                        <div class="input-group input-group-sm" style="width:220px"><input name="domain" class="form-control" required placeholder="shop.example.com">
                                            <span class="input-group-btn"><button class="btn btn-default">Move</button></span></div></form>
                                </details>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-muted">{{ $term !== '' ? 'No licence matches.' : 'No licence yet. Online sales issue their own; issue one by hand on the right.' }}</td></tr>
                    @endforelse
                </table>
            </div>
            @if($licences->hasPages())<div class="box-footer">{{ $licences->links() }}</div>@endif
        </div>
        <div class="box">
            <div class="box-header with-border"><h3 class="box-title">Recent orders</h3></div>
            <div class="box-body no-padding table-responsive">
                <table class="table table-condensed">
                    <tr><th>Reference</th><th>Kind</th><th>Buyer</th><th class="text-right">Amount</th><th>Status</th><th>When</th></tr>
                    @forelse($orders as $order)
                        <tr>
                            <td><small style="font-family:monospace">{{ $order->reference }}</small></td>
                            <td><small>{{ $order->kind }} · {{ $order->product?->name }}</small></td>
                            <td><small>{{ $order->buyer['business'] ?? '' }}<br>{{ $order->buyer['email'] ?? '' }} · <code>{{ $order->buyer['domain'] ?? '' }}</code></small></td>
                            <td class="text-right"><small>{{ \App\Models\StandaloneProduct::money($order->currency, (float) $order->amount) }}</small></td>
                            <td><span class="label label-{{ $orderBadge[$order->status] ?? 'default' }}">{{ $order->status }}</span></td>
                            <td><small>{{ ($order->paid_at ?? $order->created_at)?->format('j M H:i') }}</small></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-muted">No order yet.</td></tr>
                    @endforelse
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="box box-primary">
            <div class="box-header with-border"><h3 class="box-title">Issue a licence</h3></div>
            <form method="post" action="{{ admin_url('standalone-licences') }}">@csrf
                <div class="box-body">
                    <p class="text-muted"><small>For a sale outside checkout: a bank transfer, a contract, cash. The buyer is emailed their private link straight away.</small></p>
                    @if($errors->any())<div class="alert alert-danger" style="padding:8px 12px">{{ $errors->first() }}</div>@endif
                    <div class="form-group"><label for="sl-product">Product</label>
                        <select id="sl-product" name="product_id" class="form-control" required>
                            @foreach($products as $product)<option value="{{ $product->id }}" @selected((int) old('product_id') === $product->id)>{{ $product->name }} ({{ $product->updates_months }} months)</option>@endforeach
                        </select></div>
                    <div class="form-group"><label for="sl-business">Business name</label><input id="sl-business" name="business" class="form-control" required maxlength="190" value="{{ old('business') }}"></div>
                    <div class="form-group"><label for="sl-name">Contact name</label><input id="sl-name" name="name" class="form-control" maxlength="190" value="{{ old('name') }}"></div>
                    <div class="form-group"><label for="sl-email">Email</label><input id="sl-email" name="email" type="email" class="form-control" required maxlength="191" value="{{ old('email') }}"></div>
                    <div class="form-group"><label for="sl-phone">Phone / WhatsApp</label><input id="sl-phone" name="phone" class="form-control" maxlength="40" value="{{ old('phone') }}"></div>
                    <div class="form-group"><label for="sl-domain">Address it will run on</label><input id="sl-domain" name="domain" class="form-control" required maxlength="253" placeholder="shop.example.com" value="{{ old('domain') }}"></div>
                    <div class="form-group"><label for="sl-ref">Payment or contract reference</label><input id="sl-ref" name="reference" class="form-control" required maxlength="190" value="{{ old('reference') }}"></div>
                </div>
                <div class="box-footer"><button class="btn btn-primary" @disabled($products->isEmpty() || ! $signing)>Issue and email</button>
                    @if($products->isEmpty())<small class="text-muted">&nbsp;Add a product first.</small>@endif</div>
            </form>
        </div>
    </div>
</div>
