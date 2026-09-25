<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }} — Join {{ $company ?? 'a business' }}</title>
    <style>
        body { margin: 0; font-family: -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #f4f6f8; color: #1f2933; }
        .card { max-width: 440px; margin: 48px auto; background: #fff; border-radius: 12px; padding: 32px 28px; box-shadow: 0 6px 24px rgba(16, 42, 67, .08); }
        h1 { font-size: 22px; margin: 0 0 8px; } p { line-height: 1.5; margin: 0 0 16px; }
        label { display: block; font-size: 13px; font-weight: 600; margin: 12px 0 4px; }
        input { width: 100%; box-sizing: border-box; padding: 11px 12px; border: 1px solid #cfd8e3; border-radius: 8px; font-size: 15px; }
        .btn { display: block; width: 100%; margin: 18px 0 0; padding: 12px 16px; border: 0; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 15px; text-align: center; cursor: pointer; }
        .primary { background: #1f6feb; color: #fff; } .secondary { background: #eef2f6; color: #1f2933; }
        .err { background: #fdecec; color: #a12020; padding: 10px 12px; border-radius: 8px; font-size: 14px; }
        .muted { color: #6b7785; font-size: 13px; }
    </style>
</head>
<body>
<div class="card">
    @if($done)
        <h1>Welcome to {{ $company }}!</h1>
        <p>You joined as <strong>{{ $roleLabel }}</strong>. Sign in with <strong>{{ $login }}</strong> and the password you just chose — on the web or in the {{ config('app.name') }} app.</p>
        <a class="btn primary" href="{{ url('auth/login') }}">Sign in on the web</a>
    @elseif(! $invite)
        <h1>This invite has expired</h1>
        <p>The link was already used, cancelled, or is older than {{ \App\Services\Team\TeamService::INVITE_DAYS }} days. Ask the shop owner to send a new one.</p>
    @else
        <h1>Join {{ $company }}</h1>
        <p>You have been invited as <strong>{{ $roleLabel }}</strong>. Choose your name and a password to finish.</p>
        @if($error)<p class="err">{{ $error }}</p>@endif
        @if($errors->any())<p class="err">{{ $errors->first() }}</p>@endif
        <form method="post" action="{{ url('invite/'.$token) }}">
            @csrf
            <label for="first_name">First name</label>
            <input id="first_name" name="first_name" value="{{ old('first_name', explode(' ', (string) $invite->name)[0] ?? '') }}" required>
            <label for="last_name">Last name</label>
            <input id="last_name" name="last_name" value="{{ old('last_name') }}" required>
            <label for="password">Password</label>
            <input id="password" type="password" name="password" minlength="6" required>
            <label for="password_confirmation">Repeat password</label>
            <input id="password_confirmation" type="password" name="password_confirmation" minlength="6" required>
            <button class="btn primary" type="submit">Join the team</button>
        </form>
        <p class="muted" style="margin-top:14px">You will sign in with {{ $invite->phone_e164 ?: $invite->email }}.</p>
    @endif
</div>
</body>
</html>
