{{-- The maintenance page (docs/STANDALONE_EDITION_PLAN.md §12.2): `down --render=errors::503 --refresh=30`, which
     the standalone updater runs, pre-renders it once and serves it before Laravel boots, so it stands alone: inline
     styles, no build, no database, no signed-in user, no product name (a buyer's copy wears their own). It reloads
     itself (the Refresh header, and a fallback here). A plain abort(503) gets the same page without the reload. --}}
@php($maintenance = isset($retryAfter))
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex">
  @if($maintenance)<meta http-equiv="refresh" content="30">@endif
  <title>{{ $maintenance ? 'Being updated' : 'Temporarily unavailable' }}</title>
  <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;background:#f6f6f3;color:#1d2420;line-height:1.6;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px 16px}
    main{width:100%;max-width:560px}
    .code{font-size:14px;font-weight:600;color:#6b6f6a;margin-bottom:10px;letter-spacing:.02em}
    h1{font-size:clamp(28px,6vw,40px);line-height:1.15;letter-spacing:-.02em;font-weight:600;margin-bottom:14px}
    p{font-size:17px;color:#4a514c}
    .bar{height:4px;background:#e3e2dc;margin:26px 0 22px;overflow:hidden}
    .bar span{display:block;height:100%;width:35%;background:#5b6660;animation:slide 1.6s ease-in-out infinite}
    @keyframes slide{0%{transform:translateX(-100%)}100%{transform:translateX(300%)}}
    @media (prefers-reduced-motion:reduce){.bar span{animation:none;width:100%;opacity:.4}}
    a{display:inline-flex;align-items:center;min-height:44px;padding:0 18px;font-weight:600;font-size:15px;color:#1d2420;border:1px solid #c9c8c0;background:#fff;text-decoration:none}
    a:hover,a:focus-visible{border-color:#1d2420;outline:none}
    .small{margin-top:18px;font-size:14px;color:#6b6f6a}
  </style>
</head>
<body>
  <main role="main">
    <p class="code">503</p>
    <h1>{{ $maintenance ? 'Being updated — back in a few minutes' : 'Temporarily unavailable' }}</h1>
    <p>{{ $maintenance
        ? 'The system is closed for a few minutes while it is updated. Phones keep selling offline and send their sales when it is back. This page tries again by itself.'
        : 'Something this page needs is not available just now. Try again shortly.' }}</p>
    @if($maintenance)<div class="bar" aria-hidden="true"><span></span></div>@endif
    <a href="javascript:location.reload()">Try now</a>
    @if($maintenance)<p class="small">Reloads every 30 seconds.</p>@endif
  </main>
</body>
</html>
