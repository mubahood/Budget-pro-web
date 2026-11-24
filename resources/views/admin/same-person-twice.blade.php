<style>
    .sp-tbl td,.sp-tbl th{vertical-align:middle!important}
    .sp-by{color:#666;font-size:12px}
</style>
<p class="text-muted">
    The same email or phone number on more than one login. Logins opened with "join this app" and demo accounts are left out, since
    those are one person on purpose. Nothing here is merged: talk to the person, then use the account's own pages.
    <a href="{{ admin_url('platform-overview') }}">Back to the platform overview</a>
</p>
<div class="box">
    <div class="box-header with-border"><h3 class="box-title">{{ $groups->count() === 0 ? 'No one appears twice' : $groups->count().' '.\Illuminate\Support\Str::plural('person', $groups->count()).' with more than one login' }}</h3></div>
    @if($groups->isNotEmpty())
    <div class="box-body no-padding table-responsive">
        <table class="table table-condensed sp-tbl" style="margin:0">
            <tr><th>Shared</th><th>Login</th><th>Name</th><th>Email</th><th>Phone</th><th>Account</th><th>App</th><th>Since</th></tr>
            @foreach($groups as $g)
                @foreach($g['logins'] as $i => $u)
                    <tr @if($i === 0) style="border-top:2px solid #ddd" @endif>
                        <td>@if($i === 0)<span class="sp-by">{{ $g['by'] === 'email' ? 'Email' : 'Phone' }}</span><br><strong>{{ $g['value'] }}</strong>@endif</td>
                        <td>#{{ $u['id'] }}</td>
                        <td>{{ $u['name'] }}</td>
                        <td>{{ $u['email'] }}</td>
                        <td>{{ $u['phone'] }}</td>
                        <td>@if($u['company_id'])<a href="{{ admin_url('companies/'.$u['company_id']) }}">{{ $u['company'] ?: '#'.$u['company_id'] }}</a>@endif</td>
                        <td>{{ $apps[$u['app']]['name'] ?? $u['app'] }}</td>
                        <td>{{ $u['created'] ? \Illuminate\Support\Carbon::parse($u['created'])->format('d M Y') : '' }}</td>
                    </tr>
                @endforeach
            @endforeach
        </table>
    </div>
    @endif
</div>
