{{-- A payout's advice (PayoutAdvice): the amount big, in words, where it went, and what was owed before and after. A cash payout has room to sign. --}}
@extends('rentals.document')
@section('body')
  @if($a['reversed'])
    <div class="box">REVERSED on {{ $a['reversed']['on'] }}. {{ $a['reversed']['reason'] }}</div>
  @endif
  <table class="figs"><tr>
    <td style="width:50%"><div class="cap">Paid</div><div class="fig">{{ $a['amount'] }}</div></td>
    <td><div class="cap">Paid to</div><div>{{ $a['landlord'] }}</div></td>
  </tr></table>
  <p class="fignote">{{ $a['words'] }}</p>
  <table class="kv">
    <tr><td class="k">Advice number</td><td class="r">{{ $a['number'] }}</td></tr>
    <tr><td class="k">Date</td><td class="r">{{ $a['paid_on'] }}</td></tr>
    <tr><td class="k">Paid from</td><td class="r">{{ $a['method'] }}</td></tr>
    @if($a['paid_to'])<tr><td class="k">Sent to</td><td class="r">{{ $a['paid_to'] }}</td></tr>@endif
    @if($a['reference'])<tr><td class="k">Reference</td><td class="r">{{ $a['reference'] }}</td></tr>@endif
    @if($a['recorded_by'])<tr><td class="k">Recorded by</td><td class="r">{{ $a['recorded_by'] }}</td></tr>@endif
  </table>
  @if($a['before'] && $a['after'])
    <h2>Your account</h2>
    <table class="kv">
      <tr><td class="k">{{ $a['before'][0] }}</td><td class="m">{{ $a['before'][1] }}</td></tr>
      <tr><td class="k">Paid</td><td class="m">{{ $a['amount'] }}</td></tr>
      <tr class="b"><td>{{ $a['after'][0] }}</td><td class="m">{{ $a['after'][1] }}</td></tr>
    </table>
  @endif
  @if($a['ahead'])<p class="sum">Includes {{ $a['ahead'] }} paid ahead of what was collected for you.</p>@endif
  @if($a['cash'])
    <table class="sign"><tr>
      <td><div class="line">Received by the landlord: name and signature</div></td>
      <td><div class="line">Paid by the office: name and signature</div></td>
    </tr></table>
  @endif
@endsection
