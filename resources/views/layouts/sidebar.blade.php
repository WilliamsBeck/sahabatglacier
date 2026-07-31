<nav class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        {{-- Sidebar berlatar putih → logo putih tidak akan terlihat.
             Dipakai versi ubin teal (sama dengan favicon) agar tetap kontras & on-brand. --}}
        @if(file_exists(public_path('images/favicon-512.png')))
            <img src="{{ asset('images/favicon-512.png') }}" alt="Glacier" class="brand-logo">
        @elseif(file_exists(public_path('images/logo.png')))
            <img src="{{ asset('images/logo.png') }}" alt="Glacier" class="brand-logo">
        @else
            <span class="brand-icon">❄️</span>
        @endif
        <span class="brand-text">Glacier</span>
    </div>

    <div class="sidebar-menu">

        {{-- Dashboard --}}
        <a data-base-href="{{ route('dashboard') }}" href="{{ route('dashboard') }}" class="sidebar-link">
            <i class="bi bi-speedometer2"></i><span>Dashboard</span>
        </a>

        @if(auth()->user()->isSuperAdmin() || auth()->user()->isViewer())
            <a href="#masterMenu" class="sidebar-link collapsed" data-bs-toggle="collapse">
                <i class="bi bi-database"></i>
                <span>Master Data</span>
                <i class="bi bi-chevron-down ms-auto small"></i>
            </a>
            <div class="collapse" id="masterMenu">
                <a href="{{ route('master.stores.index') }}" class="sidebar-link sub-link"><i
                        class="bi bi-shop"></i><span>Toko</span></a>
                <a href="{{ route('master.suppliers.index') }}" class="sidebar-link sub-link"><i
                        class="bi bi-truck"></i><span>Supplier</span></a>
                <a href="{{ route('master.ingredients.index') }}" class="sidebar-link sub-link"><i
                        class="bi bi-box-seam"></i><span>Bahan Baku</span></a>
                <a href="{{ route('master.menus.index') }}" class="sidebar-link sub-link"><i
                        class="bi bi-cup-straw"></i><span>Menu & Resep</span></a>
                <a href="{{ route('master.categories.index') }}" class="sidebar-link sub-link"><i
                        class="bi bi-tags"></i><span>Kategori</span></a>
                <a href="{{ route('master.users.index') }}" class="sidebar-link sub-link"><i
                        class="bi bi-people"></i><span>User</span></a>
            </div>
        @elseif(auth()->user()->role === 'admin_area')
            <a href="#masterMenu" class="sidebar-link collapsed" data-bs-toggle="collapse">
                <i class="bi bi-database"></i>
                <span>Master Data</span>
                <i class="bi bi-chevron-down ms-auto small"></i>
            </a>
            <div class="collapse" id="masterMenu">
                <a href="{{ route('master.ingredients.index') }}" class="sidebar-link sub-link"><i
                        class="bi bi-box-seam"></i><span>Bahan Baku</span></a>
                <a href="{{ route('master.menus.index') }}" class="sidebar-link sub-link"><i
                        class="bi bi-cup-straw"></i><span>Menu & Resep</span></a>
            </div>
        @endif


        {{-- Mutasi Stok --}}
        <a href="#mutasiMenu" class="sidebar-link collapsed" data-bs-toggle="collapse">
            <i class="bi bi-arrow-left-right"></i>
            <span>Mutasi Stok</span>
            <i class="bi bi-chevron-down ms-auto small"></i>
        </a>
        <div class="collapse" id="mutasiMenu">
            <a data-base-href="{{ route('inventory.mutations.index') }}" href="{{ route('inventory.mutations.index') }}"
                class="sidebar-link sub-link"><i class="bi bi-list-ul"></i><span>Daftar Mutasi</span></a>
            <a data-base-href="{{ route('inventory.mutations.create') }}"
                href="{{ route('inventory.mutations.create') }}" class="sidebar-link sub-link"><i
                    class="bi bi-plus-circle"></i><span>Input Mutasi</span></a>
            <a data-base-href="{{ route('inventory.stocks.index') }}" href="{{ route('inventory.stocks.index') }}"
                class="sidebar-link sub-link"><i class="bi bi-clipboard-data"></i><span>Saldo Stok</span></a>
            <a data-base-href="{{ route('inventory.daily-ledger.index') }}"
                href="{{ route('inventory.daily-ledger.index') }}" class="sidebar-link sub-link"><i
                    class="bi bi-table"></i><span>Pencatatan Harian</span></a>
            <a data-base-href="{{ route('opname.opnames.index') }}" href="{{ route('opname.opnames.index') }}"
                class="sidebar-link sub-link"><i class="bi bi-clipboard-check"></i><span>Stok Opname</span></a>
        </div>

        {{-- Produksi & Waste --}}
        <a href="#prodWasteMenu" class="sidebar-link collapsed" data-bs-toggle="collapse">
            <i class="bi bi-fire"></i>
            <span>Produksi & Waste</span>
            <i class="bi bi-chevron-down ms-auto small"></i>
        </a>
        <div class="collapse" id="prodWasteMenu">
            <a data-base-href="{{ route('production.logs.index') }}" href="{{ route('production.logs.index') }}"
                class="sidebar-link sub-link"><i class="bi bi-gear"></i><span>Produksi</span></a>
            <a data-base-href="{{ route('waste.logs.index') }}" href="{{ route('waste.logs.index') }}"
                class="sidebar-link sub-link"><i class="bi bi-trash3"></i><span>Waste</span></a>
        </div>

        {{-- Penjualan & HPP --}}
        <a href="#hppMenu" class="sidebar-link collapsed" data-bs-toggle="collapse">
            <i class="bi bi-calculator"></i>
            <span>Penjualan & HPP</span>
            <i class="bi bi-chevron-down ms-auto small"></i>
        </a>
        <div class="collapse" id="hppMenu">
            <a data-base-href="{{ route('sales.monthly.index') }}" href="{{ route('sales.monthly.index') }}"
                class="sidebar-link sub-link"><i class="bi bi-cart3"></i><span>Penjualan</span></a>
            <a data-base-href="{{ route('sales.hpp.index') }}" href="{{ route('sales.hpp.index') }}"
                class="sidebar-link sub-link"><i class="bi bi-file-earmark-bar-graph"></i><span>Analisa HPP</span></a>
        </div>

        {{-- Laporan & Perencanaan --}}
        <a href="#laporanMenu" class="sidebar-link collapsed" data-bs-toggle="collapse">
            <i class="bi bi-grid-3x3-gap"></i>
            <span>Laporan & Perencanaan</span>
            <i class="bi bi-chevron-down ms-auto small"></i>
        </a>
        <div class="collapse" id="laporanMenu">
            <a href="{{ route('reports.ringkasan') }}" class="sidebar-link sub-link">
                <i class="bi bi-bar-chart-line"></i><span>Ringkasan Bisnis</span>
            </a>
            <a href="{{ route('reports.laporan.index') }}" class="sidebar-link sub-link">
                <i class="bi bi-file-earmark-text"></i><span>Detail Laporan</span>
            </a>
            <a data-base-href="{{ route('order-planning.index') }}" href="{{ route('order-planning.index') }}"
                class="sidebar-link sub-link"><i class="bi bi-cart-plus"></i><span>Rencana Order</span></a>
        </div>

        @if(auth()->user()->isSuperAdmin() || auth()->user()->isViewer())
            <a href="#sistemMenu" class="sidebar-link collapsed" data-bs-toggle="collapse">
                <i class="bi bi-shield-check"></i>
                <span>Sistem</span>
                <i class="bi bi-chevron-down ms-auto small"></i>
            </a>
            <div class="collapse" id="sistemMenu">
                <a href="{{ route('audit.index') }}" class="sidebar-link sub-link">
                    <i class="bi bi-journal-text"></i><span>Log Aktivitas</span>
                </a>
            </div>
        @endif

    </div>
</nav>