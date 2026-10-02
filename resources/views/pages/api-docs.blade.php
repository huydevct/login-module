@extends('login::layouts.master')

@section('title', 'API Docs · '.config('login.cms.title'))

@push('style')
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.33.1/swagger-ui.css">
@endpush

@section('content')
    <div class="body flex-grow-1 px-3">
        <div class="container-lg">
            <nav aria-label="breadcrumb" class="mb-4">
                <ol class="breadcrumb my-0">
                    <li class="breadcrumb-item">Trang chủ</li>
                    <li class="breadcrumb-item active"><span>API Docs</span></li>
                </ol>
            </nav>
            <div class="card mb-4">
                <div class="card-body">
                    <div id="swagger-ui"></div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.33.1/swagger-ui-bundle.js"></script>
    {{-- Gan bien truoc: @json tach tham so theo dau phay nen khong goi route(..., [], false) truc tiep duoc --}}
    @php($specUrl = route('login.api-docs.spec', [], false))
    <script>
        window.SwaggerUIBundle({
            url: @json($specUrl, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES),
            dom_id: '#swagger-ui',
            deepLinking: true,
            persistAuthorization: true,
        });
    </script>
@endpush
