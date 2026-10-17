{{-- One "who to ask" line for the classic screens (App\Support\SupportContact, docs/roadmap/06 §4): WhatsApp
     with the context's prefilled message, else a phone, else an email. $lead starts the sentence ("Trouble
     signing in?"), $context names the message, $vars fills it (a shop's name, never a customer's), $hoursBelow puts
     the hours on their own line. --}}
@php
  $bpSupport = \App\Support\SupportContact::current();
  $bpWho = $bpSupport->isPlatform() ? 'us' : $bpSupport->name();
  $bpWa = $bpSupport->goLink($context ?? 'admin.help', $vars ?? [], '/'.ltrim(request()->path(), '/'), 'classic');
@endphp
@if($bpWa !== '')
<p class="bp-support {{ $class ?? '' }}">{{ $lead ?? 'Need a hand?' }} <a href="{{ $bpWa }}" target="_blank" rel="noopener"><i class="fa fa-whatsapp" aria-hidden="true"></i> WhatsApp {{ $bpWho }} on {{ $bpSupport->whatsappDisplay() }}</a>@if($bpSupport->hours() !== '')@if($hoursBelow ?? false)<br>@else · @endif<span class="bp-support-hours">{{ $bpSupport->hours() }}</span>@endif</p>
@elseif($bpSupport->phoneLink() !== '')
<p class="bp-support {{ $class ?? '' }}">{{ $lead ?? 'Need a hand?' }} <a href="{{ $bpSupport->phoneLink() }}"><i class="fa fa-phone" aria-hidden="true"></i> Call {{ $bpWho }} on {{ $bpSupport->phone() }}</a></p>
@elseif($bpSupport->emailLink() !== '')
<p class="bp-support {{ $class ?? '' }}">{{ $lead ?? 'Need a hand?' }} <a href="{{ $bpSupport->emailLink() }}"><i class="fa fa-envelope" aria-hidden="true"></i> Email {{ $bpWho }}</a></p>
@endif
