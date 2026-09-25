<div class="row">
    <div class="col-md-6">
        <div class="box box-primary">
            <div class="box-header with-border"><h3 class="box-title"><i class="fa fa-mobile"></i> Receive mobile money</h3></div>
            <div class="box-body">
                <p>Ask customers to pay from their phone: they get a prompt, approve with their PIN, and the sale is marked paid. The money goes to this number.</p>
                @if($c->momo_subaccount_id)<p class="text-success"><i class="fa fa-check"></i> Receiving on {{ $c->momo_payout_phone }} ({{ $c->momo_payout_network }})</p>@endif
                @if($networks)
                    <form method="post" action="{{ admin_url('engagement/momo') }}">@csrf
                        <div class="form-group"><label>Your mobile money number</label><input class="form-control" name="phone" value="{{ $c->momo_payout_phone }}" required></div>
                        <div class="form-group"><label>Network</label><select class="form-control" name="network">@foreach($networks as $n)<option @selected($c->momo_payout_network === $n)>{{ $n }}</option>@endforeach</select></div>
                        <button class="btn btn-primary">{{ $c->momo_subaccount_id ? 'Change number' : 'Set up' }}</button>
                    </form>
                @else
                    <p class="text-muted">Not available for {{ $c->currency }} yet.</p>
                @endif
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="box">
            <div class="box-header with-border"><h3 class="box-title"><i class="fa fa-bell"></i> Debt reminders</h3></div>
            <div class="box-body">
                <form method="post" action="{{ admin_url('engagement') }}">@csrf
                    <div class="checkbox"><label><input type="checkbox" name="debt_reminders_enabled" value="1" @checked($c->debt_reminders_enabled)> Remind customers with overdue balances on WhatsApp/SMS (at most once a week)</label></div>
                    <div class="form-group"><label>Customers pay within (days)</label><input type="number" min="0" max="365" class="form-control" name="credit_terms_days" value="{{ $c->credit_terms_days }}"></div>
                    <button class="btn btn-default">Save</button>
                </form>
            </div>
        </div>
    </div>
</div>
