<?php
namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\{DailyEditRequest, Store};
use Carbon\Carbon;
use Illuminate\Http\Request;

class DailyEditRequestController extends Controller
{
    // ── User: kirim request perpanjangan edit ─────────────────────────────────
    public function store(Request $request)
    {
        $request->validate([
            'store_id'   => 'required|exists:stores,id',
            'month'      => 'required|integer|between:1,12',
            'year'       => 'required|integer|min:2020',
            'extra_days' => 'required|integer|min:1|max:30',
            'reason'     => 'required|string|max:500',
        ]);

        abort_unless(in_array($request->store_id, auth()->user()->accessibleStoreIds()), 403);

        // Cegah double submit: kalau masih ada yang pending untuk bulan yang sama
        $hasPending = DailyEditRequest::where('store_id', $request->store_id)
            ->where('request_month', $request->month)
            ->where('request_year', $request->year)
            ->where('status', 'pending')
            ->exists();

        if ($hasPending) {
            return back()->with('error', 'Sudah ada request yang sedang menunggu persetujuan untuk periode ini.');
        }

        DailyEditRequest::create([
            'store_id'      => $request->store_id,
            'request_month' => $request->month,
            'request_year'  => $request->year,
            'requested_by'  => auth()->id(),
            'reason'        => $request->reason,
            'extra_days'    => $request->extra_days,
            'status'        => 'pending',
        ]);

        return back()->with('success', 'Request perpanjangan edit berhasil dikirim. Menunggu persetujuan Super Admin.');
    }

    // CATATAN: controller ini belum punya route (tidak bisa diakses). Cek hak akses
    // tetap diselaraskan dengan empat jenis akun supaya aman bila kelak didaftarkan.

    // ── Super Admin / Manager: daftar request (manager: toko yang dia pegang) ──
    public function index(Request $request)
    {
        abort_unless(auth()->user()->isSuperAdminOrManager(), 403);

        $storeIds = auth()->user()->accessibleStoreIds();
        $query = DailyEditRequest::with(['store', 'requestedBy', 'reviewedBy'])
            ->whereIn('store_id', $storeIds)
            ->latest();

        if ($request->status) $query->where('status', $request->status);

        $requests      = $query->paginate(20);
        $pendingCount  = DailyEditRequest::where('status', 'pending')->whereIn('store_id', $storeIds)->count();

        return view('inventory.daily-edit-requests.index', compact('requests', 'pendingCount'));
    }

    // ── Super Admin / Manager: approve ────────────────────────────────────────
    public function approve(Request $request, DailyEditRequest $editRequest)
    {
        abort_unless(auth()->user()->isSuperAdminOrManager(), 403);
        abort_unless(in_array($editRequest->store_id, auth()->user()->accessibleStoreIds()), 403);
        abort_if($editRequest->status !== 'pending', 422, 'Request ini sudah diproses.');

        $request->validate([
            'admin_notes' => 'nullable|string|max:500',
        ]);

        // new_lock_until = lastEditDay normal + extra_days yang diminta
        $lastEditDay  = Carbon::create($editRequest->request_year, $editRequest->request_month, 1)
            ->addMonth()->addDays(6);
        $newLockUntil = $lastEditDay->addDays($editRequest->extra_days);

        $editRequest->update([
            'status'        => 'approved',
            'reviewed_by'   => auth()->id(),
            'reviewed_at'   => now(),
            'new_lock_until' => $newLockUntil->toDateString(),
            'admin_notes'   => $request->admin_notes,
        ]);

        return back()->with('success',
            "Request disetujui. {$editRequest->store->name} dapat edit data " .
            "{$editRequest->request_month}/{$editRequest->request_year} hingga " .
            $newLockUntil->isoFormat('D MMMM Y') . "."
        );
    }

    // ── Super Admin / Manager: reject ─────────────────────────────────────────
    public function reject(Request $request, DailyEditRequest $editRequest)
    {
        abort_unless(auth()->user()->isSuperAdminOrManager(), 403);
        abort_unless(in_array($editRequest->store_id, auth()->user()->accessibleStoreIds()), 403);
        abort_if($editRequest->status !== 'pending', 422, 'Request ini sudah diproses.');

        $request->validate([
            'admin_notes' => 'required|string|max:500',
        ]);

        $editRequest->update([
            'status'      => 'rejected',
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
            'admin_notes' => $request->admin_notes,
        ]);

        return back()->with('success', 'Request ditolak.');
    }
}
