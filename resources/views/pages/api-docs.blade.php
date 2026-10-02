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
                <div class="card-header">
                    <ul class="nav nav-tabs card-header-tabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" data-coreui-toggle="tab" data-coreui-target="#api-guide" type="button" role="tab" aria-controls="api-guide" aria-selected="true">Hướng dẫn tích hợp app</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" data-coreui-toggle="tab" data-coreui-target="#api-swagger" type="button" role="tab" aria-controls="api-swagger" aria-selected="false">API (Swagger)</button>
                        </li>
                    </ul>
                </div>
                <div class="card-body tab-content">
                    <div class="tab-pane fade show active" id="api-guide" role="tabpanel">
                        @include('login::pages.partials.api-guide')
                    </div>
                    <div class="tab-pane fade" id="api-swagger" role="tabpanel">
                        <div id="swagger-ui"></div>
                    </div>
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
