<table class="sign"><tr>
  @foreach($officers as $role => $name)
    <td style="width:33%"><div class="line">{{ $role }}@if($name !== ''): {{ $name }}@endif</div></td>
  @endforeach
</tr></table>
