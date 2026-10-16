{!! $body !!}

--
{!! $name !!}@foreach($contact as $line) · {!! $line !!}@endforeach

@if($poweredBy)
Powered by Budget Pro
@endif
