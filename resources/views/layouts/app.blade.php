<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') — Glacier</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('css/bootstrap-icons.css') }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
    @stack('styles')
</head>

<body>

    @include('layouts.sidebar')

    <div class="main-content" id="mainContent">

        {{-- TOPBAR --}}
        <nav class="topbar d-flex align-items-center justify-content-between px-4">
            <div class="d-flex align-items-center gap-3">
                <button class="btn btn-link text-dark p-0" id="sidebarToggle">
                    <i class="bi bi-list fs-4"></i>
                </button>

                {{-- ── Pilih Toko Aktif ── --}}
                <div class="d-flex align-items-center gap-2" id="storePickerWrap">
                    <i class="bi bi-shop text-muted" style="font-size:.95rem"></i>
                    <select id="sidebarStorePicker"
                        class="form-select form-select-sm border-0 bg-transparent fw-semibold"
                        style="min-width:140px;max-width:200px;cursor:pointer;font-size:.85rem;box-shadow:none">
                        <option value="">Semua Toko</option>
                        @foreach(auth()->user()->accessibleStores() as $s)
                            <option value="{{ $s->id }}">{{ $s->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="d-flex align-items-center gap-3">
                {{-- Bell icon notifications --}}
                <div class="dropdown" id="notifDropdown">
                    <button class="btn btn-link text-dark p-0 position-relative" data-bs-toggle="dropdown" id="bellBtn"
                        aria-expanded="false" style="text-decoration:none">
                        <i class="bi bi-bell fs-5"></i>
                        <span id="notifBadge"
                            class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
                            style="font-size:.55rem;display:none">0</span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end shadow border-0 p-0"
                        style="width:340px;max-height:460px;overflow:hidden;border-radius:12px" id="notifPanel">
                        <div class="d-flex align-items-center justify-content-between px-3 py-2 border-bottom">
                            <span class="fw-semibold small">Notifikasi</span>
                            <button class="btn btn-link btn-sm p-0 text-muted text-decoration-none" id="markAllReadBtn"
                                style="font-size:.75rem">Tandai semua dibaca</button>
                        </div>
                        <div id="notifList" style="max-height:380px;overflow-y:auto">
                            <div class="text-center text-muted py-4 small" id="notifEmpty">Tidak ada notifikasi baru
                            </div>
                        </div>
                    </div>
                </div>

                <div class="dropdown">
                    <button
                        class="btn btn-link text-dark dropdown-toggle d-flex align-items-center gap-2 p-0 text-decoration-none"
                        data-bs-toggle="dropdown">
                        <div class="avatar-sm">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</div>
                        <span class="fw-semibold small d-none d-md-block">{{ auth()->user()->name }}</span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0">
                        <li class="px-3 py-2">
                            <div class="fw-semibold small">{{ auth()->user()->name }}</div>
                            <div class="text-muted" style="font-size:11px">{{ auth()->user()->email }}</div>
                            <span class="badge bg-primary mt-1">
                                {{ auth()->user()->isSuperAdmin() ? 'Super Admin' : 'Admin Area' }}
                            </span>
                        </li>
                        <li>
                            <hr class="dropdown-divider">
                        </li>
                        <li>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button class="dropdown-item text-danger">
                                    <i class="bi bi-box-arrow-right me-2"></i>Logout
                                </button>
                            </form>
                        </li>
                    </ul>
                </div>
            </div> {{-- end right group --}}
        </nav>

        {{-- FLASH MESSAGES --}}
        <div class="px-4 pt-3">
            @if(session('success'))
                <div
                    class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 js-auto-dismiss">
                    <i class="bi bi-check-circle-fill"></i>
                    <span>{{ session('success') }}</span>
                    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
                </div>
            @endif
            @if(session('error'))
                <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2 js-auto-dismiss">
                    <i class="bi bi-exclamation-circle-fill"></i>
                    <span>{{ session('error') }}</span>
                    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
                </div>
            @endif
            @if($errors->any())
                <div class="alert alert-danger alert-dismissible fade show js-auto-dismiss">
                    <strong><i class="bi bi-exclamation-triangle me-1"></i>Ada kesalahan input:</strong>
                    <ul class="mb-0 mt-2 ps-3">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif
        </div>

        <script>
            // Auto-dismiss flash alerts setelah 5 detik
            document.addEventListener('DOMContentLoaded', () => {
                document.querySelectorAll('.js-auto-dismiss').forEach(el => {
                    setTimeout(() => {
                        const inst = bootstrap.Alert.getOrCreateInstance(el);
                        inst.close();
                    }, 5000);
                });
            });

            // ══════════════════════════════════════════════════════════════════════
            // GLOBAL NUMBER FORMATTER (titik per 3 angka, format Indonesia)
            // ══════════════════════════════════════════════════════════════════════
            window.NumberFmt = {
                // Format angka jadi "1.000.000"
                format(num, decimals = 0) {
                    if (num === null || num === undefined || num === '') return '';
                    const n = Number(String(num).replace(/\./g, '').replace(',', '.'));
                    if (isNaN(n)) return '';
                    return n.toLocaleString('id-ID', {
                        minimumFractionDigits: decimals,
                        maximumFractionDigits: decimals
                    });
                },
                // Ambil angka asli dari string ber-titik: "1.000.000" → 1000000
                parse(str) {
                    if (str === null || str === undefined) return 0;
                    return Number(String(str).replace(/\./g, '').replace(',', '.')) || 0;
                },
                // Drop-in pengganti parseFloat() yang aman untuk input ber-titik
                val(el) {
                    if (!el) return 0;
                    return this.parse(el.value);
                }
            };
            // Alias singkat
            window.parseNum = (v) => NumberFmt.parse(v);

            // Auto-apply ke semua input dengan class .num-fmt (event delegation)
            // Cara pakai: <input type="text" class="num-fmt" name="harga">
            // Berlaku juga untuk input yang ditambah dinamis via JS
            (function () {
                const applyFormat = (el) => {
                    if (!el.classList.contains('num-fmt')) return;
                    const caret = el.selectionStart;
                    const oldLen = el.value.length;
                    el.value = NumberFmt.format(NumberFmt.parse(el.value));
                    const newLen = el.value.length;
                    try { el.setSelectionRange(caret + (newLen - oldLen), caret + (newLen - oldLen)); } catch (e) { }
                };

                // Format saat user mengetik (delegasi ke document)
                document.addEventListener('input', (e) => {
                    if (e.target.matches('input.num-fmt')) applyFormat(e.target);
                });

                // Format nilai awal saat halaman load
                document.addEventListener('DOMContentLoaded', () => {
                    document.querySelectorAll('input.num-fmt').forEach(el => {
                        el.setAttribute('inputmode', 'numeric');
                        if (el.value) el.value = NumberFmt.format(NumberFmt.parse(el.value));
                    });
                });

                // Format nilai saat node baru ditambah (MutationObserver) — utk row dinamis
                const observer = new MutationObserver((mutations) => {
                    mutations.forEach(m => m.addedNodes.forEach(node => {
                        if (node.nodeType !== 1) return;
                        node.querySelectorAll?.('input.num-fmt').forEach(el => {
                            el.setAttribute('inputmode', 'numeric');
                            if (el.value) el.value = NumberFmt.format(NumberFmt.parse(el.value));
                        });
                    }));
                });
                document.addEventListener('DOMContentLoaded', () => {
                    observer.observe(document.body, { childList: true, subtree: true });
                });

                // Sebelum submit form, strip dots — server terima angka bersih
                document.addEventListener('submit', (e) => {
                    const form = e.target;
                    if (!(form instanceof HTMLFormElement)) return;
                    form.querySelectorAll('input.num-fmt').forEach(el => {
                        el.value = NumberFmt.parse(el.value);
                    });
                }, true); // capture phase, jalan sebelum handler lain
            })();
        </script>

        {{-- PAGE CONTENT --}}
        <div class="page-content px-4 pb-5">
            @yield('content')
        </div>
    </div>

    <script src="{{ asset('js/jquery.min.js') }}"></script>
    <script src="{{ asset('js/bootstrap.bundle.min.js') }}"></script>

    @include('layouts.partials.ui-dialog')
    <script>
        // Toggle sidebar
        // Sidebar — desktop: collapse jadi ikon. Mobile (≤768px): drawer geser + backdrop.
        (function () {
            var mq = window.matchMedia('(max-width: 768px)');
            var isMobile = function () { return mq.matches; };

            function closeDrawer() {
                $('#sidebar').removeClass('mobile-open');
                $('.sidebar-backdrop').remove();
            }
            function openDrawer() {
                $('#sidebar').removeClass('collapsed').addClass('mobile-open');
                if (!$('.sidebar-backdrop').length) {
                    $('<div class="sidebar-backdrop"></div>').appendTo('body').on('click', closeDrawer);
                }
            }
            // Pastikan mode sesuai lebar layar (mis. saat rotate / resize)
            function syncMode() {
                if (isMobile()) {
                    $('#sidebar').removeClass('collapsed');   // di drawer, menu tampil penuh
                    $('#mainContent').removeClass('expanded'); // konten selalu lebar penuh
                } else {
                    closeDrawer();
                }
            }

            $('#sidebarToggle').on('click', function () {
                if (isMobile()) {
                    $('#sidebar').hasClass('mobile-open') ? closeDrawer() : openDrawer();
                } else {
                    $('#sidebar').toggleClass('collapsed');
                    $('#mainContent').toggleClass('expanded');
                }
            });

            // Tutup drawer setelah memilih menu (bukan tombol grup dropdown)
            $(document).on('click', '#sidebar a.sidebar-link', function () {
                var href = $(this).attr('href');
                if (isMobile() && href && href !== '#' && !$(this).attr('data-bs-toggle')) closeDrawer();
            });

            mq.addEventListener ? mq.addEventListener('change', syncMode) : mq.addListener(syncMode);
            syncMode();
        })();

        // Active menu highlight + biarkan grup dropdown yang aktif tetap terbuka
        $(document).ready(function () {
            var currentPath = window.location.pathname.replace(/\/+$/, '') || '/';
            var bestMatch = null, bestLen = -1, dashboardLink = null;

            // Cari link yang paling cocok dgn URL saat ini (path terpanjang menang)
            $('.sidebar-link[href]').each(function () {
                var href = $(this).attr('href');
                if (!href || href.charAt(0) === '#') return;
                var linkPath;
                try { linkPath = new URL(href, window.location.origin).pathname; }
                catch (e) { return; }
                linkPath = linkPath.replace(/\/+$/, '') || '/';
                if (linkPath === '/') { dashboardLink = this; return; }
                if (currentPath === linkPath || currentPath.startsWith(linkPath + '/')) {
                    if (linkPath.length > bestLen) { bestLen = linkPath.length; bestMatch = this; }
                }
            });

            if (bestMatch) {
                var $grp = $(bestMatch).addClass('active').closest('.collapse');
                if ($grp.length) {
                    $grp.addClass('show');
                    $grp.prev('.sidebar-link')
                        .removeClass('collapsed')
                        .addClass('active')
                        .attr('aria-expanded', 'true');
                }
            } else if (currentPath === '/' && dashboardLink) {
                $(dashboardLink).addClass('active');
            }
        });
    </script>

    {{-- Action-menu (kebab) portal: pindahkan dropdown ke body saat dibuka agar
         tidak ke-clip oleh overflow .table-responsive, lalu kembalikan saat ditutup. --}}
    <script>
        (function () {
            document.addEventListener('show.bs.dropdown', function (e) {
                var menuEl = e.target.closest('.action-menu');
                if (!menuEl) return;
                var menu = menuEl.querySelector('.dropdown-menu');
                if (!menu) return;
                menu._origParent = menuEl;
                document.body.appendChild(menu);
                // Netralkan positioning Popper/Bootstrap, pakai fixed manual
                menu.style.position = 'fixed';
                menu.style.inset = 'auto';
                menu.style.margin = '0';
                menu.style.transform = 'none';
                menu.style.zIndex = '2000';
                var r = menuEl.getBoundingClientRect();
                requestAnimationFrame(function () {
                    var w = menu.offsetWidth, h = menu.offsetHeight;
                    var left = r.right - w;                 // rata kanan dengan tombol
                    if (left < 8) left = 8;
                    var top = r.bottom + 4;
                    if (top + h > window.innerHeight - 8) top = r.top - h - 4; // buka ke atas bila mepet
                    menu.style.left = left + 'px';
                    menu.style.top = Math.max(8, top) + 'px';
                });
            });
            document.addEventListener('hide.bs.dropdown', function (e) {
                var menuEl = e.target.closest('.action-menu');
                if (!menuEl) return;
                var menu = menuEl.querySelector('.dropdown-menu') ||
                           document.querySelector('body > .dropdown-menu');
                if (menu && menu._origParent) {
                    menu.removeAttribute('style');
                    menu._origParent.appendChild(menu);
                    menu._origParent = null;
                }
            });
        })();
    </script>
    @stack('scripts')
    <script>
        // ── Store Picker ─────────────────────────────────────────────────────────────
        (function () {
            const KEY = 'sb_store_id';
            const picker = document.getElementById('sidebarStorePicker');
            if (!picker) return;

            // Sync dari URL → picker, atau ambil dari localStorage
            const urlStore = new URLSearchParams(window.location.search).get('store_id') || '';
            const saved = urlStore || localStorage.getItem(KEY) || '';
            picker.value = saved;
            if (urlStore) localStorage.setItem(KEY, urlStore);

            function applyStore() {
                const sid = picker.value;
                document.querySelectorAll('[data-base-href]').forEach(el => {
                    const base = el.getAttribute('data-base-href');
                    el.href = sid
                        ? base + (base.includes('?') ? '&' : '?') + 'store_id=' + sid
                        : base;
                });
            }

            picker.addEventListener('change', function () {
                localStorage.setItem(KEY, this.value);
                const url = new URL(window.location.href);
                if (this.value) {
                    url.searchParams.set('store_id', this.value);
                } else {
                    url.searchParams.delete('store_id');
                }
                window.location.href = url.toString();
            });

            applyStore();
        })();
    </script>
    <script>
        // ── In-app Notifications ─────────────────────────────────────────────────────
        (function () {
            const badge = document.getElementById('notifBadge');
            const list = document.getElementById('notifList');
            const empty = document.getElementById('notifEmpty');
            const markAllBtn = document.getElementById('markAllReadBtn');
            const bellBtn = document.getElementById('bellBtn');
            const CSRF = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

            function levelColor(level) {
                return level === 'critical' ? '#ef4444' : '#f59e0b';
            }

            function renderNotifs(data) {
                badge.textContent = data.count;
                badge.style.display = data.count > 0 ? '' : 'none';

                if (data.items.length === 0) {
                    list.innerHTML = '';
                    empty.style.display = '';
                    return;
                }
                empty.style.display = 'none';

                list.innerHTML = data.items.map(n => `
            <div class="notif-item px-3 py-2 border-bottom" data-id="${n.id}"
                 style="cursor:pointer;background:#fff;transition:background .15s"
                 onmouseenter="this.style.background='#f8fafc'" onmouseleave="this.style.background='#fff'">
              <div class="d-flex justify-content-between align-items-start">
                <div class="fw-semibold small">${n.store_name}</div>
                <span class="text-muted" style="font-size:.7rem">${n.time}</span>
              </div>
              <div class="small text-muted mt-1">${n.message}</div>
              ${n.items.slice(0, 3).map(i => `
                <div class="d-flex align-items-center gap-1 mt-1" style="font-size:.72rem">
                  <span style="width:6px;height:6px;border-radius:50%;background:${levelColor(i.level)};display:inline-block;flex-shrink:0"></span>
                  <span>${i.name}</span>
                  <span class="text-muted ms-auto">${i.dos} hari</span>
                </div>`).join('')}
            </div>
        `).join('');

                // Click → mark as read
                list.querySelectorAll('.notif-item').forEach(el => {
                    el.addEventListener('click', () => markRead(el.dataset.id));
                });
            }

            function fetchNotifs() {
                fetch('{{ route("notifications.index") }}')
                    .then(r => r.json()).then(renderNotifs).catch(() => { });
            }

            function markRead(id) {
                fetch('{{ route("notifications.mark-read") }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
                    body: JSON.stringify({ id }),
                }).then(fetchNotifs);
            }

            markAllBtn?.addEventListener('click', () => {
                fetch('{{ route("notifications.mark-read") }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
                    body: JSON.stringify({}),
                }).then(fetchNotifs);
            });

            // Trigger low-stock notification generation on every page load (once per day)
            const TODAY = new Date().toISOString().slice(0, 10);
            const LSK = 'notif_generated_' + TODAY;
            if (!sessionStorage.getItem(LSK)) {
                fetch('{{ route("notifications.generate-low-stock") }}', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': CSRF },
                }).then(() => { sessionStorage.setItem(LSK, '1'); fetchNotifs(); });
            } else {
                fetchNotifs();
            }

            // Refresh every 5 minutes
            setInterval(fetchNotifs, 5 * 60 * 1000);
        })();
    </script>
</body>

</html>