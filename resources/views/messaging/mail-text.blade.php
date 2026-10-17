{!! $body !!}

--
{!! $name !!}@foreach($contact as $line) · {!! $line !!}@endforeach

@if($supportText !== '')
{!! $supportText !!}
@if($supportLink !== '')
{!! $supportLink !!}
@endif
@endif

@if($poweredBy)
Powered by Budget Pro
@endif
