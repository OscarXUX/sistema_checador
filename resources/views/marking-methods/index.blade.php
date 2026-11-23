@extends('layouts.app')

@section('title', 'Medios de Marcaje — Checador Hikvision')
@section('page_title', 'Medios de Marcaje y Capacidades ISAPI')
@section('page_subtitle', 'Detección de hardware en la terminal Hikvision, catálogo aceptado por la empresa y diagnóstico de currentVerifyMode')

@section('content')
<section class="surface-card p-3 p-md-4 mb-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
        <div>
            <h2 class="h6 fw-semibold mb-1">Catálogo "Medios de Marcaje"</h2>
            <small class="text-secondary">
                Terminal configurada: <span class="font-mono-tabular text-info">{{ $deviceIp }}</span>.
                El PIN nunca se guarda en la base de datos y el reconocimiento facial requiere el aviso de privacidad <strong>TE-02</strong>.
            </small>
        </div>

        @can('medios.administrar')
        <form method="POST" action="{{ route('marking-methods.detect') }}" class="flex-shrink-0">
            @csrf
            <button type="submit" class="btn btn-info btn-sm text-dark fw-semibold">
                <i class="bi bi-radar me-1" aria-hidden="true"></i>Detectar en Terminal (ISAPI)
            </button>
        </form>
        @endcan
    </div>

    <div class="row g-3">
        @foreach($methods as $method)
            @php
                $blockedByPrivacy = $method->isBlockedByPrivacyNotice();
                $icon = match ($method->code) {
                    'fingerprint' => 'bi-fingerprint',
                    'face' => 'bi-person-bounding-box',
                    'card' => 'bi-credit-card-2-front',
                    'pin' => 'bi-key',
                    default => 'bi-shield-check',
                };
            @endphp
            <div class="col-12 col-md-6">
                <article class="kpi-card p-3 p-md-4 h-100 d-flex flex-column justify-content-between gap-3">
                    <div>
                        <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
                            <div class="d-flex align-items-center gap-2">
                                <span class="kpi-icon bg-dark border border-secondary-subtle text-info">
                                    <i class="bi {{ $icon }}" aria-hidden="true"></i>
                                </span>
                                <div>
                                    <h3 class="h6 fw-semibold mb-0">{{ $method->name }}</h3>
                                    <small class="font-mono-tabular text-secondary">{{ $method->code }}</small>
                                </div>
                            </div>

                            <div class="d-flex flex-wrap gap-1 justify-content-end">
                                @if($method->is_biometric)
                                    <span class="badge bg-dark border border-warning text-warning">Biométrico</span>
                                @else
                                    <span class="badge bg-dark border border-secondary text-secondary">No biométrico</span>
                                @endif

                                @if($method->device_supported === true)
                                    <span class="badge text-bg-success">Aceptado en Terminal</span>
                                @elseif($method->device_supported === false)
                                    <span class="badge text-bg-danger">No Soportado</span>
                                @else
                                    <span class="badge text-bg-secondary">Sin Detectar</span>
                                @endif
                            </div>
                        </div>

                        @if($method->last_detected_at)
                            <div class="small text-secondary mb-2">
                                Última detección ISAPI:
                                <span class="font-mono-tabular">{{ $method->last_detected_at->format('d/m/Y H:i:s') }}</span>
                            </div>
                        @endif

                        @if($blockedByPrivacy)
                            <div class="alert alert-warning py-2 small mb-0" role="note">
                                <i class="bi bi-shield-lock-fill me-1" aria-hidden="true"></i>
                                En espera del aviso de privacidad biométrico <strong>{{ $method->privacy_notice_code }}</strong>. No puede activarse ni enrolarse rostro hasta contar con dicho aviso.
                            </div>
                        @elseif($method->code === 'pin')
                            <div class="small text-secondary">
                                Seguridad O3: al asignar PIN a un empleado, se envía directo por ISAPI y <strong>nunca se almacena</strong> en PostgreSQL/MariaDB ni bitácoras.
                            </div>
                        @elseif($method->code === 'card')
                            <div class="small text-secondary">
                                Permite asociar el número de tarjeta RFID al usuario en la terminal desde el padrón de empleados.
                            </div>
                        @else
                            <div class="small text-secondary">
                                Captura óptica remota vía <code>CaptureFingerPrint</code> y asociación directa en la terminal.
                            </div>
                        @endif
                    </div>

                    <div class="pt-3 border-top border-secondary-subtle d-flex align-items-center justify-content-between gap-2">
                        <span class="small">
                            Empresa:
                            <strong class="{{ $method->is_enabled ? 'text-success' : 'text-secondary' }}">
                                {{ $method->is_enabled ? 'Habilitado' : 'Deshabilitado' }}
                            </strong>
                        </span>

                        @if($blockedByPrivacy)
                            <button type="button" class="btn btn-outline-warning btn-sm" disabled>
                                Bloqueado ({{ $method->privacy_notice_code }})
                            </button>
                        @else
                            @can('medios.administrar')
                            <form method="POST" action="{{ route('marking-methods.update', $method) }}">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="is_enabled" value="{{ $method->is_enabled ? '0' : '1' }}">
                                <button type="submit" class="btn btn-sm {{ $method->is_enabled ? 'btn-outline-danger' : 'btn-outline-success' }}">
                                    {{ $method->is_enabled ? 'Deshabilitar' : 'Habilitar' }}
                                </button>
                            </form>
                            @endcan
                        @endif
                    </div>
                </article>
            </div>
        @endforeach
    </div>
</section>

<section class="surface-card p-3 p-md-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
        <div>
            <h2 id="verify-modes-heading" class="h6 fw-semibold mb-0">Valores reales de <code>currentVerifyMode</code> y <code>minor</code> observados</h2>
            <small class="text-secondary">
                Resumen de eventos almacenados en <code>attendance_logs</code> y mapeo aplicado por <code>HikvisionIsapiService::mapVerificationMode</code>
            </small>
        </div>
        <span class="badge text-bg-dark border border-secondary-subtle font-mono-tabular">
            CLI: php artisan hikvision:inspect-verify-modes --live
        </span>
    </div>

    <div tabindex="0" role="region" aria-labelledby="verify-modes-heading" class="shifts-table-wrapper">
        <table class="table table-dark table-hover align-middle mb-0 shifts-table">
            <thead>
                <tr class="text-secondary small text-uppercase">
                    <th scope="col">currentVerifyMode (ISAPI)</th>
                    <th scope="col">Código minor</th>
                    <th scope="col">Medio Mapeado</th>
                    <th scope="col" class="text-end">Eventos</th>
                </tr>
            </thead>
            <tbody>
                @forelse($observedModes as $row)
                    <tr>
                        <th scope="row" class="font-mono-tabular">{{ $row['current_verify_mode'] }}</th>
                        <td data-label="Código minor" class="font-mono-tabular">{{ $row['minor'] }}</td>
                        <td data-label="Medio Mapeado">{{ $row['mapped'] }}</td>
                        <td data-label="Eventos" class="text-end font-mono-tabular">{{ $row['count'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="text-center text-secondary py-4">
                            Aún no hay eventos en <code>attendance_logs</code>. Al estar en la red de la terminal ({{ $deviceIp }}), ejecuta
                            <code>php artisan hikvision:inspect-verify-modes --live</code> para inspeccionar el hardware real.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection
