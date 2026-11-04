{{--
  Every Estate Ledger paper document (03-improvements.md §9): one letterhead with the account's name and logo on every
  page, one fixed type scale, figures in the mono face so columns of money line up, black ink and hairlines. Page numbers
  and the footer are drawn by RentalDocument::output.

  The type scale, in px, and nothing else: 8 captions and table headings · 9.5 text and table cells · 10.5 section
  headings · 12 the document's title · 14 the account's name · 18 big figures.

  $doc: title, sub (lines under the title), head (RentalDocument::letterhead), narrow (a till receipt: no fixed furniture), small (A5).
--}}
@php
  $narrow = ! empty($doc['narrow']);
  $head = $doc['head'];
  $sub = array_values(array_filter((array) ($doc['sub'] ?? []), fn ($s) => (string) $s !== ''));
  // A long name takes two lines, never a third, and then the letterhead keeps one line of how to reach the account.
  $small = ! empty($doc['small']);
  $perLine = $small ? 24 : ($head['logo'] ? 33 : 45);
  $name = mb_strimwidth((string) $head['name'], 0, $perLine * 2, '...');
  $headLines = mb_strlen($name) > $perLine ? array_slice($head['lines'], -1) : $head['lines'];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $doc['title'] }}{{ $sub !== [] ? ' · '.$sub[0] : '' }}</title>
<style>
  @page { margin: {{ $narrow ? '12px 13px 12px 13px' : '86px 34px 48px 34px' }}; }
  * { box-sizing: border-box; }
  body { font-family: "DejaVu Sans", sans-serif; font-size: 9.5px; line-height: 1.35; color: #1e2b33; background: #fff; margin: 0; }
  p { margin: 0; }

  header.sheet { position: fixed; top: -70px; left: 0; right: 0; height: 58px; border-bottom: 0.6px solid #1e2b33; }
  table.lh { width: 100%; border-collapse: collapse; }
  table.lh td { padding: 0; vertical-align: top; }
  table.lh td.mark { padding-right: 9px; width: 1px; }
  img.logo { max-height: 44px; max-width: 120px; width: auto; height: auto; }
  .acct { font-size: 14px; font-weight: bold; line-height: 1.2; }
  .cap { font-size: 8px; color: #55606a; }
  .lines { font-size: 8px; color: #55606a; white-space: nowrap; }
  .doc { font-size: 12px; font-weight: bold; line-height: 1.2; text-align: right; }
  .docsub { font-size: 8px; color: #55606a; text-align: right; }

  .sum { margin: 0 0 8px; }
  .sum b { font-weight: bold; }
  .num, .n { font-family: "DejaVu Sans Mono", monospace; }

  table.t { width: 100%; border-collapse: collapse; margin: 0 0 12px; }
  table.t.whole { page-break-inside: avoid; }
  table.t thead tr { page-break-after: avoid; }
  table.t th { font-size: 8px; font-weight: bold; text-align: left; padding: 2.5px 5px; border-bottom: 0.6px solid #1e2b33; vertical-align: bottom; }
  table.t td { padding: 2.5px 5px; border-bottom: 0.4px solid #d8dcdf; vertical-align: top; }
  table.t tr { page-break-inside: avoid; }
  table.t th.st { font-size: 10.5px; padding: 0 5px 3px 0; border-bottom: 0; }
  table.t tr.keep { page-break-after: avoid; }
  table.t .n { text-align: right; white-space: nowrap; }
  table.t th.n { font-family: "DejaVu Sans", sans-serif; text-align: right; }
  table.t .nw { white-space: nowrap; }
  table.t .muted, .muted { color: #55606a; }
  table.t tr.group { page-break-after: avoid; }
  table.t tr.group td { font-weight: bold; padding-top: 7px; border-bottom: 0.6px solid #1e2b33; }
  table.t tr.sub { page-break-before: avoid; }
  table.t tr.sub td { font-weight: bold; border-bottom: 0.6px solid #1e2b33; }
  table.t tr.total { page-break-before: avoid; }
  table.t tr.total td { font-weight: bold; border-top: 0.8px solid #1e2b33; border-bottom: 0.8px solid #1e2b33; }
  table.t td.empty { color: #55606a; }

  table.figs { width: 100%; border-collapse: collapse; border-bottom: 0.6px solid #1e2b33; margin: 0 0 8px; }
  table.figs td { padding: 0 8px 6px 0; vertical-align: top; }
  .fig { font-family: "DejaVu Sans Mono", monospace; font-size: 18px; font-weight: bold; line-height: 1.2; white-space: nowrap; }
  .fignote { margin: 0 0 8px; }

  h2 { font-size: 10.5px; font-weight: bold; margin: 0 0 3px; }
  table.kv { width: 100%; border-collapse: collapse; margin: 0 0 12px; }
  table.kv td { padding: 3px 5px 3px 0; border-bottom: 0.4px solid #d8dcdf; vertical-align: top; }
  table.kv td.k { color: #55606a; }
  table.kv td.r { text-align: right; }
  table.kv td.m { font-family: "DejaVu Sans Mono", monospace; text-align: right; white-space: nowrap; }
  table.kv tr.b td { font-weight: bold; border-top: 0.8px solid #1e2b33; border-bottom: 0.8px solid #1e2b33; color: #1e2b33; }
  .box { border: 0.8px solid #1e2b33; padding: 5px 7px; margin: 0 0 10px; font-weight: bold; }
  table.sign { width: 100%; border-collapse: collapse; margin: 16px 0 0; page-break-inside: avoid; }
  table.sign td { width: 50%; padding: 26px 16px 0 0; vertical-align: top; }
  table.sign .line { border-top: 0.6px solid #1e2b33; padding-top: 3px; font-size: 8px; color: #55606a; }
  .note { font-size: 8px; color: #55606a; margin: 0 0 8px; }

  .till { text-align: center; }
  .till img.logo { max-height: 40px; max-width: 150px; margin-bottom: 3px; }
  .till .acct { margin-bottom: 1px; }
</style>
</head>
<body>
@if($narrow)
  <div class="till">
    @if($head['logo'])<img class="logo" src="{{ $head['logo'] }}" alt="">@endif
    <div class="acct">{{ $head['name'] }}</div>
    @foreach($head['lines'] as $line)<div class="cap">{{ $line }}</div>@endforeach
  </div>
@else
  <header class="sheet">
    <table class="lh"><tr>
      @if($head['logo'])<td class="mark"><img class="logo" src="{{ $head['logo'] }}" alt=""></td>@endif
      <td>
        <div class="acct">{{ $name }}</div>
        @foreach($headLines as $line)<div class="lines">{{ $line }}</div>@endforeach
      </td>
      <td style="width:{{ $small ? '30' : '47' }}%">
        <div class="doc">{{ $doc['title'] }}</div>
        @foreach(array_slice($sub, 0, 3) as $line)<div class="docsub">{{ $line }}</div>@endforeach
      </td>
    </tr></table>
  </header>
@endif
@yield('body')
</body>
</html>
