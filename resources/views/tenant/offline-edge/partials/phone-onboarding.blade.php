{{--
    Edge 0.9 stream D — phone onboarding (Android + iPhone) for one branch.
    Input:
      $phone          array  from App\Services\Edge\EdgeCounterSetupRenderer::phoneOnboarding($lanProfile)   (required)
      $caDownloadUrl  string optional: absolute URL of the branch-CA download (stream C, route tenant.offline-edge.branch.ca)
      $branch         mixed  optional: the branch (model or id) used to build that route when $caDownloadUrl is not given
    QR codes are inline SVG from App\Services\Edge\EdgeCounterSetupQrCode (no CDN, no JavaScript). Public data only.
--}}
@php
    $phoneCa = $phone['ca'] ?? [];
    $phoneCaUrl = $caDownloadUrl ?? null;
    if ($phoneCaUrl === null && !empty($phoneCa['download_route']) && \Illuminate\Support\Facades\Route::has($phoneCa['download_route'])) {
        $phoneCaParams = $phoneCa['download_route_parameters'] ?? [];
        if (isset($branch)) {
            $phoneCaParams[$phoneCa['download_route_parameter'] ?? 'branch'] = $branch;
        }
        if ($phoneCaParams !== []) {
            try {
                $phoneCaUrl = route($phoneCa['download_route'], $phoneCaParams);
            } catch (\Throwable $e) {
                $phoneCaUrl = null;
            }
        }
    }
    $phoneCaQr = $phoneCaUrl ? \App\Services\Edge\EdgeCounterSetupQrCode::svg($phoneCaUrl, 'M', 180, 'QR 1: branch certificate download') : null;
    $phoneLoginQr = \App\Services\Edge\EdgeCounterSetupQrCode::svg($phone['qr_text'], 'M', 180, 'QR 2: Bingoo Offline login ' . $phone['login_url']);
@endphp
<div class="card mb-3" id="edge-phone-onboarding">
    <div class="card-header d-flex align-items-center justify-content-between">
        <div>
            <h5 class="card-title mb-0"><i class="ti ti-device-mobile me-1"></i>Phones and tablets (Android / iPhone)</h5>
            <div class="small text-muted">Mobile aur tablet: pehle certificate install karein, phir login QR scan karein.</div>
        </div>
        <span class="badge bg-light text-dark font-monospace">{{ $phone['branch_server']['ip'] }}:{{ $phone['branch_server']['https_port'] }}</span>
    </div>
    <div class="card-body">
        <div class="row g-4">
            <div class="col-lg-4">
                <div class="d-flex flex-wrap gap-3 justify-content-center">
                    <figure class="text-center mb-0" data-qr="ca">
                        @if($phoneCaQr)
                            <div class="border rounded p-1 bg-white d-inline-block">{!! $phoneCaQr !!}</div>
                            <figcaption class="small mt-1">
                                <strong>QR 1</strong> — branch certificate<br>
                                <a href="{{ $phoneCaUrl }}" class="text-break">{{ $phone['ca']['file_name'] }}</a>
                            </figcaption>
                        @else
                            <div class="border rounded p-3 bg-light small text-muted" style="width:180px">
                                Certificate link not available here. Download <span class="font-monospace">{{ $phone['ca']['file_name'] }}</span> from the Offline Edge page.
                            </div>
                            <figcaption class="small mt-1"><strong>QR 1</strong> — branch certificate</figcaption>
                        @endif
                    </figure>
                    <figure class="text-center mb-0" data-qr="login">
                        <div class="border rounded p-1 bg-white d-inline-block">{!! $phoneLoginQr !!}</div>
                        <figcaption class="small mt-1">
                            <strong>QR 2</strong> — Bingoo Offline login<br>
                            <span class="font-monospace text-break">{{ $phone['login_url'] }}</span>
                        </figcaption>
                    </figure>
                </div>
                <dl class="small mt-3 mb-0">
                    <dt>Certificate name</dt>
                    <dd>{{ $phone['ca']['name'] }}</dd>
                    <dt>SHA-256 fingerprint</dt>
                    <dd class="font-monospace text-break" style="font-size:.7rem">{{ $phone['ca']['sha256'] }}</dd>
                    <dt>Valid until</dt>
                    <dd class="mb-0">{{ $phone['ca']['valid_until'] }}</dd>
                </dl>
            </div>
            <div class="col-lg-8">
                <div class="row g-3">
                    @foreach($phone['platforms'] as $phonePlatformKey => $phonePlatform)
                        <div class="col-md-6" data-platform="{{ $phonePlatformKey }}">
                            <h6 class="mb-2"><i class="ti {{ $phonePlatformKey === 'ios' ? 'ti-brand-apple' : 'ti-brand-android' }} me-1"></i>{{ $phonePlatform['name'] }}</h6>
                            <ol class="ps-3 mb-0">
                                @foreach($phonePlatform['steps'] as $phoneStep)
                                    <li class="mb-2">
                                        <div>{{ $phoneStep['en'] }}</div>
                                        <div class="small text-muted" lang="ur-Latn">{{ $phoneStep['ur'] }}</div>
                                    </li>
                                @endforeach
                            </ol>
                        </div>
                    @endforeach
                </div>
                @foreach($phone['warnings'] as $phoneWarning)
                    <div class="alert alert-warning py-2 small mt-3 mb-0">
                        <i class="ti ti-alert-triangle me-1"></i>{{ $phoneWarning['en'] }}
                        <div class="text-muted" lang="ur-Latn">{{ $phoneWarning['ur'] }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>
