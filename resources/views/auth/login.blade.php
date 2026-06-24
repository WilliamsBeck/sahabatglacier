<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Masuk — Glacier</title>
    <link rel="stylesheet" href="{{ asset('css/bootstrap-icons.css') }}">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap');

        :root {
            --teal-900: #00384a;
            --teal-800: #004f5f;
            --teal-700: #006275;
            --teal-500: #0891a8;
            --teal-300: #38c5d8;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            min-height: 100vh;
            display: flex;
            background: #eef4f5;
            overflow-x: hidden;
        }

        /* ───── LEFT PANEL ───────────────────────────────────────────── */
        .left {
            width: 46%;
            background:
                radial-gradient(circle at 80% 15%, rgba(56,197,216,.35) 0%, transparent 45%),
                linear-gradient(155deg, var(--teal-700) 0%, var(--teal-800) 55%, var(--teal-900) 100%);
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 56px 60px;
            overflow: hidden;
            color: #fff;
        }

        /* decorative shapes */
        .blob {
            position: absolute;
            border-radius: 50%;
            background: rgba(255,255,255,.06);
            border: 1px solid rgba(255,255,255,.08);
        }
        .blob.b1 { width: 420px; height: 420px; top: -150px; right: -130px; }
        .blob.b2 { width: 280px; height: 280px; bottom: -90px; left: -100px; background: rgba(56,197,216,.12); }
        .blob.b3 { width: 150px; height: 150px; bottom: 120px; right: 40px; }

        .left-top, .left-bottom { position: relative; z-index: 1; }

        .brand {
            display: inline-flex; align-items: center; gap: 10px;
            font-size: 1.25rem; font-weight: 800; letter-spacing: -.01em;
        }
        .brand .ico {
            width: 40px; height: 40px; border-radius: 12px;
            background: rgba(255,255,255,.14);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.3rem;
            backdrop-filter: blur(6px);
        }
        .brand .brand-logo {
            height: 44px; width: auto; display: block;
            object-fit: contain;
        }

        .hero { position: relative; z-index: 1; max-width: 380px; }
        .hero h1 {
            font-size: 2.3rem; font-weight: 800; line-height: 1.18;
            margin-bottom: 18px; letter-spacing: -.02em;
        }
        .hero p {
            font-size: .96rem; color: rgba(255,255,255,.82);
            line-height: 1.7;
        }

        .left-mid {
            flex: 1; display: flex; flex-direction: column;
            justify-content: center; align-items: flex-start;
        }
        .illus { width: 100%; max-width: 370px; margin: 0 auto 22px; }
        .illus svg { width: 100%; height: auto; display: block; }
        .float-anim { animation: floaty 3.4s ease-in-out infinite; transform-box: fill-box; transform-origin: center; }
        .float-anim.d1 { animation-delay: -1.1s; }
        .float-anim.d2 { animation-delay: -2.2s; }
        @keyframes floaty { 0%,100% { transform: translateY(-16px); } 50% { transform: translateY(16px); } }
        @media (prefers-reduced-motion: reduce) { .float-anim { animation: none; } }

        .feature-list {
            list-style: none; margin-top: 30px;
            display: flex; flex-direction: column; gap: 14px;
        }
        .feature-list li {
            display: flex; align-items: center; gap: 12px;
            font-size: .9rem; color: rgba(255,255,255,.9); font-weight: 500;
        }
        .feature-list .fi {
            width: 32px; height: 32px; border-radius: 9px; flex: none;
            background: rgba(255,255,255,.12);
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem; color: var(--teal-300);
        }

        .left-bottom { font-size: .8rem; color: rgba(255,255,255,.6); }

        /* ───── RIGHT PANEL ──────────────────────────────────────────── */
        .right {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 32px 24px;
        }

        /* ───── CARD ─────────────────────────────────────────────────── */
        .card {
            background: #ffffff;
            border-radius: 20px;
            padding: 44px 40px;
            width: 100%;
            max-width: 410px;
            box-shadow: 0 10px 40px rgba(0,98,117,.10), 0 2px 8px rgba(0,0,0,.04);
            border: 1px solid #e6eef0;
        }

        .card-greet {
            display: inline-flex; align-items: center; gap: 7px;
            font-size: .78rem; font-weight: 700; color: var(--teal-700);
            background: #e6f4f6; border-radius: 100px;
            padding: 5px 13px; margin-bottom: 18px;
        }
        .card-title {
            font-size: 1.65rem; font-weight: 800;
            color: #0f172a; margin-bottom: 5px; letter-spacing: -.01em;
        }
        .card-sub {
            font-size: .88rem; color: #94a3b8;
            margin-bottom: 28px;
        }

        /* ───── FORM ─────────────────────────────────────────────────── */
        label {
            display: block;
            font-size: .82rem; font-weight: 600; color: #334155;
            margin-bottom: 7px;
        }

        .input-wrap { position: relative; }
        .input-wrap .lead-ico {
            position: absolute; left: 13px; top: 50%; transform: translateY(-50%);
            color: #94a3b8; font-size: 1rem; pointer-events: none;
        }

        input[type="password"],
        input[type="text"] {
            width: 100%;
            background: #f7f9fa;
            border: 1.5px solid #e2e8f0;
            border-radius: 11px;
            padding: 12px 14px 12px 42px;
            font-family: inherit;
            font-size: .92rem;
            color: #0f172a;
            outline: none;
            transition: border-color .18s, box-shadow .18s, background .18s;
        }
        input::placeholder { color: #b6c1cc; }
        input:focus {
            border-color: var(--teal-700);
            box-shadow: 0 0 0 3.5px rgba(0,98,117,.13);
            background: #fff;
        }
        input:focus + .lead-ico,
        .input-wrap:focus-within .lead-ico { color: var(--teal-700); }
        input.is-invalid { border-color: #ef4444 !important; }

        .field { margin-bottom: 18px; }

        .pwd-wrap input { padding-right: 44px; }
        .eye-btn {
            position: absolute; right: 12px; top: 50%;
            transform: translateY(-50%);
            background: none; border: none; cursor: pointer;
            color: #94a3b8; font-size: 1rem; padding: 0;
            line-height: 1; transition: color .15s;
        }
        .eye-btn:hover { color: var(--teal-700); }

        /* remember */
        .remember-row {
            display: flex; align-items: center; gap: 9px;
            margin-bottom: 24px;
        }
        .remember-row input[type="checkbox"] {
            width: 17px; height: 17px;
            accent-color: var(--teal-700);
            border-radius: 4px; cursor: pointer;
            flex: none;
        }
        .remember-row label {
            font-size: .85rem; color: #64748b;
            margin: 0; cursor: pointer; font-weight: 500;
        }

        /* submit */
        .btn-login {
            width: 100%; padding: 13px;
            background: linear-gradient(135deg, var(--teal-700), var(--teal-800));
            color: #fff; border: none; border-radius: 12px;
            font-family: inherit; font-size: .95rem; font-weight: 700;
            cursor: pointer; letter-spacing: .01em;
            display: flex; align-items: center; justify-content: center; gap: 8px;
            transition: filter .18s, transform .1s, box-shadow .18s;
            box-shadow: 0 6px 18px rgba(0,98,117,.25);
        }
        .btn-login:hover  { filter: brightness(1.08); box-shadow: 0 8px 22px rgba(0,98,117,.32); }
        .btn-login:active { transform: scale(.985); }

        /* error */
        .err-box {
            display: flex; align-items: center; gap: 9px;
            background: #fef2f2; border: 1.5px solid #fecaca;
            border-radius: 10px; padding: 11px 13px;
            font-size: .84rem; color: #b91c1c;
            margin-bottom: 18px;
        }
        .invalid-msg {
            font-size: .78rem; color: #ef4444; margin-top: 5px;
        }

        .card-foot {
            margin-top: 26px; text-align: center;
            font-size: .8rem; color: #94a3b8;
        }

        /* ───── RESPONSIVE ───────────────────────────────────────────── */
        @media (max-width: 860px) {
            body { flex-direction: column; }
            .left { width: 100%; padding: 40px 32px; }
            .left-bottom { display: none; }
            .hero { max-width: none; }
            .hero h1 { font-size: 1.85rem; }
            .feature-list { display: none; }
            .blob.b1 { width: 240px; height: 240px; }
            .blob.b3 { display: none; }
            .right { padding: 36px 20px 56px; }
        }
    </style>
</head>
<body>

{{-- LEFT --}}
<div class="left">
    <span class="blob b1"></span>

    <div class="left-top">
        <div class="brand">
            @if(file_exists(public_path('images/logo.png')))
                <img src="{{ asset('images/logo.png') }}" alt="Glacier" class="brand-logo"> Glacier
            @else
                <span class="ico">❄️</span> Glacier
            @endif
        </div>
    </div>

    <div class="left-mid">
        <div class="illus">
            <svg viewBox="0 0 460 380" fill="none" xmlns="http://www.w3.org/2000/svg"
                 role="img" aria-label="Ilustrasi manajemen stok Glacier">
                <defs>
                    <filter id="cardShadow" x="-30%" y="-30%" width="160%" height="160%">
                        <feDropShadow dx="0" dy="8" stdDeviation="10" flood-color="#00232e" flood-opacity="0.28"/>
                    </filter>
                    <linearGradient id="barGrad" x1="0" y1="1" x2="0" y2="0">
                        <stop offset="0" stop-color="#006275"/>
                        <stop offset="1" stop-color="#38c5d8"/>
                    </linearGradient>
                </defs>

                {{-- bayangan lantai --}}
                <ellipse cx="222" cy="330" rx="150" ry="28" fill="#00232e" opacity=".25"/>

                {{-- ══ TUMPUKAN DUS ══ --}}
                <g>
                    {{-- dus bawah --}}
                    <polygon points="200,250 260,284 200,318 140,284" fill="#7fc4cf"/>
                    <polygon points="200,250 140,284 140,214 200,180" fill="#bfe4e8"/>
                    <polygon points="200,250 260,284 260,214 200,180" fill="#9ad3da"/>
                    <polygon points="200,180 260,214 200,248 140,214" fill="#ffffff"/>
                    {{-- flap & selotip dus bawah --}}
                    <line x1="200" y1="180" x2="200" y2="248" stroke="#9ad3da" stroke-width="2"/>
                    <line x1="140" y1="214" x2="260" y2="214" stroke="#9ad3da" stroke-width="2"/>
                    <line x1="200" y1="180" x2="200" y2="250" stroke="#006275" stroke-width="5" opacity=".55"/>

                    {{-- dus tengah --}}
                    <polygon points="200,190 242,214 200,238 158,214" fill="#7fc4cf"/>
                    <polygon points="200,190 158,214 158,158 200,134" fill="#bfe4e8"/>
                    <polygon points="200,190 242,214 242,158 200,134" fill="#9ad3da"/>
                    <polygon points="200,134 242,158 200,182 158,158" fill="#ffffff"/>
                    <line x1="200" y1="134" x2="200" y2="182" stroke="#9ad3da" stroke-width="2"/>
                    <line x1="158" y1="158" x2="242" y2="158" stroke="#9ad3da" stroke-width="2"/>

                    {{-- dus atas --}}
                    <polygon points="200,142 228,158 200,174 172,158" fill="#7fc4cf"/>
                    <polygon points="200,142 172,158 172,118 200,102" fill="#bfe4e8"/>
                    <polygon points="200,142 228,158 228,118 200,102" fill="#9ad3da"/>
                    <polygon points="200,102 228,118 200,134 172,118" fill="#ffffff"/>
                </g>

                {{-- ══ ES KRIM (sentuhan Glacier) di atas dus ══ --}}
                <g class="float-anim">
                    <polygon points="190,104 210,104 200,140" fill="#e9c9a0"/>
                    <line x1="194" y1="112" x2="204" y2="108" stroke="#cda978" stroke-width="1.5"/>
                    <line x1="196" y1="120" x2="206" y2="116" stroke="#cda978" stroke-width="1.5"/>
                    <line x1="198" y1="128" x2="204" y2="125" stroke="#cda978" stroke-width="1.5"/>
                    <circle cx="200" cy="92" r="17" fill="#38c5d8"/>
                    <circle cx="200" cy="78" r="12" fill="#5fd3e2"/>
                    <circle cx="195" cy="74" r="3.5" fill="#ffffff" opacity=".8"/>
                </g>

                {{-- ══ KARTU GRAFIK MENGAMBANG ══ --}}
                <g class="float-anim d1" filter="url(#cardShadow)">
                    <rect x="24" y="70" width="138" height="86" rx="16" fill="#ffffff"/>
                    <rect x="40" y="86" width="58" height="8" rx="4" fill="#cfe9ec"/>
                    <rect x="40" y="100" width="34" height="6" rx="3" fill="#e6eef0"/>
                    <rect x="40"  y="120" width="15" height="20" rx="3" fill="url(#barGrad)" opacity=".7"/>
                    <rect x="62"  y="110" width="15" height="30" rx="3" fill="url(#barGrad)" opacity=".82"/>
                    <rect x="84"  y="116" width="15" height="24" rx="3" fill="url(#barGrad)" opacity=".7"/>
                    <rect x="106" y="102" width="15" height="38" rx="3" fill="url(#barGrad)"/>
                    <rect x="128" y="112" width="15" height="28" rx="3" fill="url(#barGrad)" opacity=".82"/>
                </g>

                {{-- ══ BADGE CENTANG MENGAMBANG ══ --}}
                <g class="float-anim d2" filter="url(#cardShadow)">
                    <circle cx="374" cy="244" r="30" fill="#ffffff"/>
                    <circle cx="374" cy="244" r="30" fill="#38c5d8" opacity=".12"/>
                    <path d="M362 244 l8 8 l16 -17" stroke="#006275" stroke-width="5"
                          stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                </g>

                {{-- ══ ELEMEN DEKORATIF ══ --}}
                <circle cx="372" cy="96"  r="6" fill="#5fd3e2" opacity=".8"/>
                <circle cx="58"  cy="240" r="5" fill="#ffffff" opacity=".5"/>
                <circle cx="408" cy="300" r="4" fill="#ffffff" opacity=".45"/>
                <path d="M338 130 q10 -10 20 0" stroke="#5fd3e2" stroke-width="3" stroke-linecap="round" fill="none" opacity=".7"/>
            </svg>
        </div>

        <div class="hero">
            <h1>Kelola Stok<br>Lebih Cepat &amp; Akurat</h1>
            <p>Inventori bahan baku, perencanaan order, mutasi bahan baku, dan laporan HPP dalam satu sistem.</p>
        </div>
    </div>

    <div class="left-bottom">
        &copy; {{ date('Y') }} Glacier — Sistem Manajemen Inventori
    </div>
</div>

{{-- RIGHT --}}
<div class="right">
    <div class="card">

        <div class="card-title">Masuk ke Akun Anda</div>
        <div class="card-sub">Silakan masukkan username dan kata sandi Anda.</div>

        @if($errors->any())
        <div class="err-box">
            <i class="bi bi-exclamation-circle-fill"></i>
            {{ $errors->first() }}
        </div>
        @endif

        <form method="POST" action="{{ route('login') }}">
            @csrf

            <div class="field">
                <label for="username">Username</label>
                <div class="input-wrap">
                    <input type="text" id="username" name="username"
                           value="{{ old('username') }}"
                           placeholder="Masukkan username"
                           class="{{ $errors->has('username') ? 'is-invalid' : '' }}"
                           required autofocus autocomplete="username">
                    <i class="bi bi-person lead-ico"></i>
                </div>
                @error('username')
                    <div class="invalid-msg">{{ $message }}</div>
                @enderror
            </div>

            <div class="field">
                <label for="pwdField">Kata Sandi</label>
                <div class="input-wrap pwd-wrap">
                    <input type="password" id="pwdField" name="password"
                           placeholder="••••••••"
                           class="{{ $errors->has('password') ? 'is-invalid' : '' }}"
                           required autocomplete="current-password">
                    <i class="bi bi-lock lead-ico"></i>
                    <button type="button" class="eye-btn" onclick="togglePwd()" aria-label="Tampilkan kata sandi">
                        <i class="bi bi-eye" id="eyeIco"></i>
                    </button>
                </div>
                @error('password')
                    <div class="invalid-msg">{{ $message }}</div>
                @enderror
            </div>

            <div class="remember-row">
                <input type="checkbox" id="remember" name="remember">
                <label for="remember">Ingat saya di perangkat ini</label>
            </div>

            <button type="submit" class="btn-login">
                <i class="bi bi-box-arrow-in-right"></i> Masuk
            </button>

        </form>

        <div class="card-foot">
            Lupa kata sandi? Hubungi administrator Anda.
        </div>
    </div>
</div>

<script>
function togglePwd() {
    var f = document.getElementById('pwdField');
    var i = document.getElementById('eyeIco');
    f.type = (f.type === 'password') ? 'text' : 'password';
    i.className = (f.type === 'text') ? 'bi bi-eye-slash' : 'bi bi-eye';
}
</script>
</body>
</html>
