{{-- One table of a paper document (RentalExport::paperTable): its heading stays with its first rows on the same page, and repeats on the next. --}}
<table class="t{{ $t['whole'] ? ' whole' : '' }}">
  <thead>
    @if($t['title'] !== null)<tr><th class="st" colspan="{{ count($t['head']) }}">{{ $t['title'] }}</th></tr>@endif
    @if($t['show_head'])<tr>@foreach($t['head'] as $c)<th class="{{ $c['class'] }}">{{ $c['label'] }}</th>@endforeach</tr>@endif
  </thead>
  <tbody>
    @foreach($t['rows'] as $r)
      <tr class="{{ $r['class'] }}">@foreach($r['cells'] as $c)<td class="{{ $c['class'] }}"@if($c['span'] > 1) colspan="{{ $c['span'] }}"@endif>{{ $c['text'] }}</td>@endforeach</tr>
    @endforeach
  </tbody>
</table>
