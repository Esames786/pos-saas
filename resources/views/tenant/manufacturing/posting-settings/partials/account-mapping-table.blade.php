{{-- props: $fields (subset of field => meta), $setting, $title --}}
<div class="card table-list-card">
    <div class="card-header"><h6 class="mb-0">{{ $title }}</h6></div>
    <div class="card-body table-responsive p-0">
        <table class="table table-sm mb-0">
            <thead class="thead-light">
                <tr><th>Role</th><th>Account type</th><th>Mapped account</th><th class="text-end">Status</th></tr>
            </thead>
            <tbody>
            @foreach($fields as $field => $meta)
                @php
                    $rel = \Illuminate\Support\Str::camel(str_replace('_id', '', $field));
                    $acc = $setting->{$rel};
                @endphp
                <tr>
                    <td>{{ $meta['label'] }}@if($meta['required'])<span class="text-danger" title="Required to enable"> *</span>@endif</td>
                    <td><span class="badge bg-light text-dark text-capitalize">{{ $meta['type'] }}</span></td>
                    <td>{{ $acc ? $acc->code . ' — ' . $acc->name : '—' }}</td>
                    <td class="text-end">
                        @if($acc)
                            <span class="badge bg-success">Mapped</span>
                        @elseif($meta['required'])
                            <span class="badge bg-danger">Required</span>
                        @else
                            <span class="badge bg-secondary">Optional</span>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
