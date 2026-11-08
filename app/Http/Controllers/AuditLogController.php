<?php
namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditLogController extends Controller
{
    /**
     * Log yang boleh dilihat user yang sedang login.
     * - Super admin: semua log.
     * - Manager: log yang dibuat user di toko-toko yang dia pegang (termasuk dirinya).
     *   Tabel audit_logs tidak punya kolom toko, jadi dibatasi lewat PELAKUNYA.
     */
    private function logTerlihat()
    {
        $query = AuditLog::query();
        $user  = auth()->user();

        if (!$user->isSuperAdmin()) {
            $userIds = DB::table('store_user')
                ->whereIn('store_id', $user->accessibleStoreIds())
                ->pluck('user_id')
                ->push($user->id)
                ->unique()->values();
            $query->whereIn('user_id', $userIds);
        }
        return $query;
    }

    public function index(Request $request)
    {
        $query = $this->logTerlihat()->latest();

        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }
        if ($request->filled('model')) {
            $query->where('model', $request->model);
        }
        if ($request->filled('user')) {
            $query->where('user_name', 'like', '%' . $request->user . '%');
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }
        if ($request->filled('search')) {
            $query->where('description', 'like', '%' . $request->search . '%');
        }

        $logs = $query->paginate(50)->withQueryString();

        $models  = AuditLog::distinct()->pluck('model')->sort()->values();
        $actions = ['created', 'updated', 'deleted', 'confirmed', 'approved', 'rejected'];

        return view('audit.index', compact('logs', 'models', 'actions'));
    }

    public function show(AuditLog $auditLog)
    {
        abort_unless($this->logTerlihat()->whereKey($auditLog->id)->exists(), 403);
        return view('audit.show', ['log' => $auditLog]);
    }
}
