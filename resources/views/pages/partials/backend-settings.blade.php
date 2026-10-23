@php
    $prefix = '/'.trim((string) config('login.api.prefix'), '/');
    $secretTtl = (int) config('login.api.secret_ttl');
@endphp

<div class="api-guide">
    <h4 class="mb-2">Tạo secret cho add-device</h4>
    <p class="text-body-secondary">
        Sinh <code>secret</code> để gọi <code>POST {{ $prefix }}/add-device</code> khi test API — tương đương
        <code>php artisan login:create-device-token</code> (encode). Secret được mã hoá ở server bằng
        <code>JWT_OPENSSL_DEVICE_SECRET</code>, hợp lệ trong <strong>{{ $secretTtl }} giây</strong>
        @if (config('app.debug'))
            <span class="badge bg-warning text-dark">APP_DEBUG=true: server không kiểm tra hạn</span>
        @endif
    </p>

    <form id="device-secret-form" class="row g-3 mb-4" novalidate>
        <div class="col-md-4">
            <label for="ds-client-id" class="form-label">Client ID</label>
            <input type="text" class="form-control" id="ds-client-id" name="client_id" value="123" required maxlength="255">
        </div>
        <div class="col-md-4">
            <label for="ds-platform" class="form-label">Platform</label>
            <select class="form-select" id="ds-platform" name="platform">
                <option value="1" selected>1 — android</option>
                <option value="2">2 — ios</option>
                <option value="0">0 — unknown</option>
            </select>
        </div>
        <div class="col-md-4">
            <label for="ds-package-id" class="form-label">Package ID</label>
            <input type="text" class="form-control" id="ds-package-id" name="package_id" value="app_id_1" required maxlength="255">
        </div>
        <div class="col-12">
            <button type="submit" class="btn btn-primary">Tạo secret</button>
        </div>
    </form>

    <div id="ds-error" class="alert alert-danger d-none" role="alert"></div>

    <div id="ds-result" class="d-none">
        <label for="ds-secret" class="form-label fw-semibold">Secret</label>
        <div class="input-group mb-3">
            <textarea class="form-control font-monospace" id="ds-secret" rows="3" readonly></textarea>
            <button class="btn btn-outline-secondary" type="button" id="ds-copy">Copy</button>
        </div>
        <dl class="row mb-0">
            <dt class="col-sm-3">Payload (JSON)</dt>
            <dd class="col-sm-9"><code id="ds-payload"></code></dd>
            <dt class="col-sm-3">Tạo lúc</dt>
            <dd class="col-sm-9" id="ds-created"></dd>
            <dt class="col-sm-3">Hết hạn lúc</dt>
            <dd class="col-sm-9"><span id="ds-expires"></span> <span id="ds-countdown" class="badge ms-1"></span></dd>
        </dl>
    </div>
</div>

@push('scripts')
    @php($secretUrl = route('login.api-docs.device-secret', [], false))
    <script>
        (function () {
            const form = document.getElementById('device-secret-form');
            const errorBox = document.getElementById('ds-error');
            const result = document.getElementById('ds-result');
            const countdown = document.getElementById('ds-countdown');
            let timer = null;

            const fmt = (ts) => new Date(ts * 1000).toLocaleString();

            function tick(expiresAt, enforced) {
                const left = expiresAt - Math.floor(Date.now() / 1000);
                if (left > 0) {
                    countdown.className = 'badge ms-1 bg-success';
                    countdown.textContent = 'còn ' + left + ' giây';
                } else {
                    countdown.className = 'badge ms-1 ' + (enforced ? 'bg-danger' : 'bg-secondary');
                    countdown.textContent = enforced ? 'đã hết hạn' : 'đã hết hạn (APP_DEBUG bỏ qua)';
                    clearInterval(timer);
                }
            }

            form.addEventListener('submit', async function (e) {
                e.preventDefault();
                errorBox.classList.add('d-none');
                const body = Object.fromEntries(new FormData(form));
                try {
                    const res = await fetch(@json($secretUrl, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES), {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                        body: JSON.stringify(body),
                    });
                    const data = await res.json();
                    if (!res.ok) {
                        const errors = data.errors ? Object.values(data.errors).flat().join(' ') : (data.message || res.statusText);
                        throw new Error(errors);
                    }
                    document.getElementById('ds-secret').value = data.secret;
                    document.getElementById('ds-payload').textContent = data.payload;
                    document.getElementById('ds-created').textContent = fmt(data.created_at);
                    document.getElementById('ds-expires').textContent = fmt(data.expires_at) + ' (TTL ' + data.ttl + ' giây)';
                    result.classList.remove('d-none');
                    clearInterval(timer);
                    tick(data.expires_at, data.expiry_enforced);
                    timer = setInterval(() => tick(data.expires_at, data.expiry_enforced), 1000);
                } catch (err) {
                    errorBox.textContent = err.message;
                    errorBox.classList.remove('d-none');
                }
            });

            document.getElementById('ds-copy').addEventListener('click', function () {
                const secret = document.getElementById('ds-secret');
                navigator.clipboard ? navigator.clipboard.writeText(secret.value) : (secret.select(), document.execCommand('copy'));
                this.textContent = 'Đã copy';
                setTimeout(() => (this.textContent = 'Copy'), 1500);
            });
        })();
    </script>
@endpush
