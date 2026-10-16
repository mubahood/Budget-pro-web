{{-- Messenger's email on a standalone copy (App\Mail\ShopMessage): the business's header and footer around the plain text. --}}
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>{{ $name }}</title></head>
<body style="margin:0;padding:0;background:#f5f7fa;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#111c2e;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7fa;padding:24px 12px;">
    <tr><td align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-top:4px solid {{ $colour }};">
        <tr><td style="padding:20px 24px 8px;">
          @if($wide)
            <img src="{{ $wide }}" alt="{{ $name }}" style="max-height:44px;max-width:240px;height:auto;width:auto;display:block;">
          @else
            <span style="font-size:18px;font-weight:600;color:{{ $colour }};">{{ $name }}</span>
          @endif
        </td></tr>
        <tr><td style="padding:12px 24px 24px;font-size:15px;line-height:1.6;">{!! nl2br(e($body)) !!}</td></tr>
        <tr><td style="padding:14px 24px 18px;border-top:1px solid #e2e8f0;font-size:12px;line-height:1.5;color:#5a6b80;">
          <strong style="color:#111c2e;">{{ $name }}</strong>@foreach($contact as $line) · {{ $line }}@endforeach
          @if($poweredBy)<br><span style="color:#93a1b3;">Powered by Budget Pro</span>@endif
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
