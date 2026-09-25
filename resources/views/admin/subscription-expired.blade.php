<div class="box box-warning">
    <div class="box-header with-border">
        <h3 class="box-title"><i class="fa fa-lock"></i> {{ $company->name ?? 'Your company' }} — subscription {{ $state === 'grace' ? 'in grace period' : 'expired' }}</h3>
    </div>
    <div class="box-body">
        @if ($state === 'active')
            <p>Your subscription is active. <a href="{{ admin_base_path('/') }}">Go to the dashboard</a>.</p>
        @else
            <p>
                @if ($plan)Plan: <strong>{{ $plan->name }}</strong>.@endif
                @if ($endedAt)Access ended on <strong>{{ $endedAt->format('d M Y') }}</strong>.@endif
            </p>
            <p>Your sales, stock and records are safe. Renew to continue using the web app:</p>
            <ul>
                <li><a href="{{ admin_url('billing') }}"><strong>Open Billing</strong></a> to renew by mobile money or card (or in the app: <em>Menu → Billing</em>), or</li>
                <li>contact support at <a href="mailto:{{ config('mail.from.address') }}">{{ config('mail.from.address') }}</a>.</li>
            </ul>
            @if ($state === 'grace')
                <p class="text-warning"><i class="fa fa-info-circle"></i> During the grace period you can still view and export everything, and your phones keep selling and syncing.</p>
            @endif
        @endif
        <p><a href="{{ admin_base_path('auth/logout') }}" class="btn btn-default"><i class="fa fa-sign-out"></i> Sign out</a></p>
    </div>
</div>
