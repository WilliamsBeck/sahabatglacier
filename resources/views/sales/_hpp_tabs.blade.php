@php $currentHppTab = $currentHppTab ?? 'periode'; @endphp
<div class="page-header mb-2 d-flex justify-content-between align-items-start flex-wrap gap-2">
    <div>
    <h4 class="page-title mb-2">Analisa HPP</h4>
    <ul class="nav nav-pills">
        <li class="nav-item">
            <a class="nav-link {{ $currentHppTab === 'periode' ? 'active' : '' }}" href="{{ route('sales.hpp.index') }}">
                <i class="bi bi-calendar3 me-1"></i>Per Periode
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $currentHppTab === 'tren' ? 'active' : '' }}" href="{{ route('sales.hpp.trend') }}">
                <i class="bi bi-graph-up-arrow me-1"></i>Tren
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $currentHppTab === 'compare' ? 'active' : '' }}" href="{{ route('sales.hpp.compare') }}">
                <i class="bi bi-bar-chart-steps me-1"></i>Perbandingan Toko
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $currentHppTab === 'cone-cup' ? 'active' : '' }}" href="{{ route('sales.hpp.cone-cup') }}">
                <i class="bi bi-cup-straw me-1"></i>Cone &amp; Cup
            </a>
        </li>
    </ul>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">@yield('hpp_actions')</div>
</div>
