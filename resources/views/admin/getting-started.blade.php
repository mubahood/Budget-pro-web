<div class="box box-success" id="getting-started">
    <div class="box-header with-border">
        <h3 class="box-title"><i class="fa fa-flag-checkered"></i> Getting started — {{ $checklist['done'] }} of {{ $checklist['total'] }} done</h3>
        <div class="box-tools pull-right">
            <form method="post" action="{{ admin_url('setup/dismiss') }}" style="display:inline">@csrf<button class="btn btn-box-tool" title="Hide"><i class="fa fa-times"></i></button></form>
        </div>
    </div>
    <div class="box-body">
        <div class="progress progress-sm" style="margin-bottom:12px"><div class="progress-bar progress-bar-success" style="width: {{ $checklist['percent'] }}%"></div></div>
        @php $links = ['add_products' => 'setup', 'first_sale' => 'sale-records/create', 'invite_staff' => 'employees', 'set_up_momo' => 'setup', 'whatsapp_receipts' => 'setup']; @endphp
        <ul class="list-unstyled" style="margin:0">
            @foreach($checklist['items'] as $item)
                <li style="padding:4px 0">
                    <i class="fa {{ $item['done'] ? 'fa-check-circle text-success' : 'fa-circle-o text-muted' }}"></i>
                    @if($item['done']) <span class="text-muted">{{ $item['label'] }}</span>
                    @else <a href="{{ admin_url($links[$item['key']]) }}">{{ $item['label'] }}</a> @endif
                </li>
            @endforeach
        </ul>
    </div>
</div>
