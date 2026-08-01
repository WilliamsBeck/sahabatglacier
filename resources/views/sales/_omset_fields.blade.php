{{--
    Tiga field omset yang dipakai bersama di halaman Input, Edit, dan Pratinjau Impor:
        Omset Bruto + Selisih TikTok = Total Omset

    Total dihitung di layar (JS) dan dihitung ulang di server saat simpan,
    jadi angkanya tidak bisa meleset walau JS gagal jalan.

    Parameter:
      $gross    nilai awal omset bruto  (boleh null)
      $tiktok   nilai awal selisih tiktok (boleh null)
      $colClass class kolom bootstrap tiap field (default: setumpuk penuh)
      $wajib    true = omset bruto wajib diisi (halaman Input)
--}}
@php
    $colClass = $colClass ?? 'col-12';
    $gross    = $gross  ?? null;
    $tiktok   = $tiktok ?? null;
    $wajib    = $wajib ?? false;
@endphp

<div class="{{ $colClass }}">
    <label class="form-label fw-semibold">Omset Bruto @if($wajib)<span class="text-danger">*</span>@endif</label>
    <div class="input-group">
        <span class="input-group-text">Rp</span>
        <input type="text" name="gross_revenue" class="form-control text-end num-fmt js-omset-gross"
               value="{{ $gross === null || $gross === '' ? '' : (int) round($gross) }}"
               placeholder="0" @if($wajib) required @endif>
    </div>
</div>

<div class="{{ $colClass }}">
    <label class="form-label fw-semibold">Selisih TikTok</label>
    <div class="input-group">
        <span class="input-group-text">Rp</span>
        {{-- Tanpa class .num-fmt: formatter global mengubah "-" jadi 0 saat baru diketik.
             Field ini punya formatter sendiri di bawah supaya nilai minus bisa diketik. --}}
        <input type="text" name="tiktok_diff" class="form-control text-end js-omset-tiktok"
               value="{{ $tiktok === null || $tiktok === '' ? '' : (int) round($tiktok) }}"
               inputmode="numeric" placeholder="0">
    </div>
    <div class="form-text">Boleh minus, contoh: -250.000</div>
</div>

<div class="{{ $colClass }}">
    <label class="form-label fw-semibold">Total Omset</label>
    <div class="input-group">
        <span class="input-group-text">Rp</span>
        <input type="text" class="form-control text-end fw-bold bg-light js-omset-total"
               value="" readonly tabindex="-1">
    </div>
</div>

@once
@push('scripts')
<script>
(function () {
    // Format ribuan yang mempertahankan tanda minus, termasuk saat "-" baru diketik
    function fmtMinus(str) {
        var s   = String(str == null ? '' : str).trim();
        var neg = s.charAt(0) === '-';
        var d   = s.replace(/[^0-9]/g, '');
        if (d === '') return neg ? '-' : '';
        return (neg ? '-' : '') + Number(d).toLocaleString('id-ID');
    }
    function angka(str) {
        var s   = String(str == null ? '' : str).trim();
        var neg = s.charAt(0) === '-';
        var d   = s.replace(/[^0-9]/g, '');
        if (d === '') return 0;
        return (neg ? -1 : 1) * parseInt(d, 10);
    }

    function hitungTotal() {
        var g = document.querySelector('.js-omset-gross');
        var t = document.querySelector('.js-omset-tiktok');
        var o = document.querySelector('.js-omset-total');
        if (!o) return;
        o.value = (angka(g ? g.value : 0) + angka(t ? t.value : 0)).toLocaleString('id-ID');
    }

    function pasang() {
        var t = document.querySelector('.js-omset-tiktok');
        if (t && t.value) t.value = fmtMinus(t.value);
        hitungTotal();
    }

    document.addEventListener('input', function (e) {
        if (e.target.matches('.js-omset-tiktok')) {
            var caret = e.target.selectionStart, oldLen = e.target.value.length;
            e.target.value = fmtMinus(e.target.value);
            var d = e.target.value.length - oldLen;
            try { e.target.setSelectionRange(caret + d, caret + d); } catch (err) {}
        }
        if (e.target.matches('.js-omset-gross, .js-omset-tiktok')) hitungTotal();
    });

    // Lucuti titik sebelum submit — server juga membersihkan lagi sebagai jaring pengaman
    document.addEventListener('submit', function (e) {
        var t = e.target.querySelector ? e.target.querySelector('.js-omset-tiktok') : null;
        if (t) t.value = angka(t.value);
    }, true);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', pasang);
    } else { pasang(); }
})();
</script>
@endpush
@endonce
