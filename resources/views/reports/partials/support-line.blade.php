{{-- The small "Powered by Budget Pro · WhatsApp …" line (App\Support\SupportContact::receiptLine): on by default
     on the SaaS, off on a standalone copy, switched per business in Settings › Receipts. Pass the business as
     $company (or $shop); $size sets the font size for the paper; $link (web pages only) makes it a WhatsApp
     link with the receipt.powered message. The number never appears in a view. --}}
@php
  $bpLineCompany = ($company ?? null) instanceof \App\Models\Company ? $company : (($shop ?? null) instanceof \App\Models\Company ? $shop : null);
  $bpLine = $bpLineCompany !== null ? \App\Support\SupportContact::receiptLine($bpLineCompany) : null;
  $bpLineHref = $bpLine && ($link ?? false) ? \App\Support\SupportContact::platform()->goLink('receipt.powered', [], '/r', 'site') : '';
@endphp
@if($bpLine)
<p class="bp-support-line" style="margin:6px 0 0;font-size:{{ $size ?? '8px' }};color:#6b7785;text-align:center;">@if($bpLineHref !== '')<a href="{{ $bpLineHref }}" rel="noopener" style="color:inherit;">{{ $bpLine }}</a>@else{{ $bpLine }}@endif</p>
@endif
