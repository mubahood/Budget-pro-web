{{-- Fiscal receipt block (supermarket plan F2): only for sales queued for fiscalisation; nothing otherwise. --}}
@php
    $fiscal = \App\Services\Fiscal\FiscalService::forReceipt($sale);
@endphp
@if($fiscal)
    <div style="margin-top: 10px; border: 1px solid #333; padding: 6px; text-align: center; font-size: 9px;">
        @if($fiscal['status'] === 'sent')
            <div style="font-weight: bold; text-transform: uppercase;">Fiscal receipt</div>
            <div>Fiscal document no: <strong>{{ $fiscal['number'] }}</strong></div>
            @if($fiscal['code'])<div>Verification code: <strong>{{ $fiscal['code'] }}</strong></div>@endif
            @if($fiscal['qr'])<img src="{{ \App\Support\FiscalQr::dataUri($fiscal['qr'], 3) }}" alt="Fiscal QR code" style="width: 90px; height: 90px; margin-top: 4px;">@endif
        @else
            <div style="font-weight: bold;">Fiscal receipt pending</div>
        @endif
    </div>
@endif
