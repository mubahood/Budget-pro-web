@php
    $cur = app(\App\Services\Billing\BillingService::class)->currency($company);
    $plan = $subscription?->plan;
    $labels = ['users' => 'Team members', 'devices' => 'Phones', 'products' => 'Products', 'sales' => 'Sales this month', 'storage' => 'Files (MB)'];
@endphp
<div class="row">
    <div class="col-md-5">
        <div class="box box-primary">
            <div class="box-header with-border"><h3 class="box-title"><i class="fa fa-credit-card"></i> Your plan</h3></div>
            <div class="box-body">
                <h3 style="margin-top:0">{{ $plan?->name ?? 'No plan' }}
                    @if($subscription?->status === 'trialing')<span class="label label-info">Trial</span>@endif
                    @if($subscription?->status === 'past_due')<span class="label label-warning">Payment due</span>@endif
                    @if($subscription?->status === 'canceled')<span class="label label-default">Cancelled</span>@endif
                </h3>
                @if($subscription?->status === 'trialing' && ($subscription->trial_ends_at ?? $subscription->ends_at))
                    <p>Trial ends <strong>{{ ($subscription->trial_ends_at ?? $subscription->ends_at)->format('d M Y') }}</strong>. After that the shop moves to the Free plan unless you choose a plan.</p>
                @elseif($subscription?->status === 'canceled')
                    <p>Ends <strong>{{ $subscription->ends_at?->format('d M Y') }}</strong>, then the Free plan.</p>
                @elseif($subscription?->ends_at)
                    <p>{{ $state === 'active' ? 'Renews' : 'Ended' }} <strong>{{ $subscription->ends_at->format('d M Y') }}</strong>.</p>
                @elseif($plan?->isFree())
                    <p>Free forever. Upgrade any time for more phones, products and WhatsApp summaries.</p>
                @endif
                @if($canManage && $subscription?->status === 'active' && $subscription->ends_at && ! $plan?->isFree())
                    <form method="post" action="{{ admin_url('billing/cancel') }}" onsubmit="return confirm('Cancel at the end of the period?')">@csrf<button class="btn btn-default btn-sm">Cancel plan</button></form>
                @elseif($canManage && $subscription?->status === 'canceled')
                    <form method="post" action="{{ admin_url('billing/resume') }}">@csrf<button class="btn btn-success btn-sm">Keep my plan</button></form>
                @endif
            </div>
        </div>
        <div class="box">
            <div class="box-header with-border"><h3 class="box-title">Usage</h3></div>
            <div class="box-body">
                @foreach($usage as $key => $u)
                    @php $pct = $u['max'] ? min(100, round($u['used'] * 100 / max(1, $u['max']))) : 0; @endphp
                    <div style="margin-bottom:10px">
                        <div>{{ $labels[$key] ?? $key }} <span class="pull-right">{{ number_format($u['used']) }}{{ $u['max'] !== null ? ' / '.number_format($u['max']) : ' · unlimited' }}</span></div>
                        @if($u['max'] !== null)
                            <div class="progress progress-xs" style="margin:4px 0 0"><div class="progress-bar {{ $u['over'] || $pct >= 90 ? 'progress-bar-danger' : 'progress-bar-primary' }}" style="width: {{ $pct }}%"></div></div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    </div>
    <div class="col-md-7">
        <div class="box">
            <div class="box-header with-border"><h3 class="box-title">Plans</h3></div>
            <div class="box-body">
                <table class="table">
                    <thead><tr><th>Plan</th><th>Includes</th><th class="text-right">Price</th><th></th></tr></thead>
                    <tbody>
                    @foreach($plans as $p)
                        @php $q = $quotes[$p->id] ?? null; $l = $p->limits ?? []; @endphp
                        <tr @if($plan && $plan->id === $p->id) class="info" @endif>
                            <td><strong>{{ $p->name }}</strong><br><small class="text-muted">{{ $p->description }}</small></td>
                            <td><small>
                                {{ isset($l['max_devices']) ? $l['max_devices'].' phone(s)' : 'Unlimited phones' }} ·
                                {{ isset($l['max_products']) ? number_format($l['max_products']).' products' : 'Unlimited products' }} ·
                                {{ isset($l['max_users']) ? $l['max_users'].' users' : 'Unlimited users' }}
                            </small></td>
                            <td class="text-right">
                                @if($p->isFree()) Free
                                @elseif($q)
                                    {{ number_format($q['full_price']) }} {{ $q['currency'] }}/{{ $p->interval }}
                                    @if($q['credit'] > 0)<br><small class="text-success">−{{ number_format($q['credit']) }} credit → pay {{ number_format($q['amount']) }}</small>@endif
                                @endif
                            </td>
                            <td class="text-right">
                                @if($canManage && $q && ! $p->isFree())
                                    <form method="post" action="{{ admin_url('billing/checkout') }}">@csrf
                                        <input type="hidden" name="plan_id" value="{{ $p->id }}">
                                        <button class="btn btn-sm {{ $plan && $plan->id === $p->id ? 'btn-default' : 'btn-primary' }}">{{ $plan && $plan->id === $p->id ? 'Renew' : ($q['immediate'] ? 'Switch now' : 'Choose') }}</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                <p class="text-muted"><small>Pay by {{ $cur === 'UGX' ? 'MTN/Airtel Mobile Money or card' : ($cur === 'KES' ? 'M-Pesa or card' : 'mobile money or card') }}. Changing plan credits your unused days.
                    @unless($canManage) Only the owner can change the plan. @endunless</small></p>
            </div>
        </div>
        <div class="box">
            <div class="box-header with-border"><h3 class="box-title">Invoices</h3></div>
            <div class="box-body no-padding">
                <table class="table table-striped">
                    <tr><th>Date</th><th>Number</th><th class="text-right">Amount</th><th>Status</th><th></th></tr>
                    @forelse($invoices as $inv)
                        <tr>
                            <td>{{ $inv->created_at?->format('d M Y') }}</td>
                            <td>{{ $inv->number ?: '—' }}</td>
                            <td class="text-right">{{ number_format((float) $inv->amount) }} {{ $inv->currency }}</td>
                            <td>{{ ucfirst($inv->status) }}</td>
                            <td>@if($inv->status === 'paid')<a href="{{ admin_url('billing/invoices/'.$inv->id) }}" target="_blank"><i class="fa fa-file-pdf-o"></i> PDF</a>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-muted">No invoices yet.</td></tr>
                    @endforelse
                </table>
            </div>
        </div>
    </div>
</div>
