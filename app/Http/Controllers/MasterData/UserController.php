<?php
namespace App\Http\Controllers\MasterData;
use App\Http\Controllers\Controller;
use App\Models\{User, Store, Mutation, Opname, WasteLog, ProductionLog, StockLedger};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index()
    {
        $users = User::with('stores')->latest()->paginate(20);
        return view('master.users.index', compact('users'));
    }
    public function create()
    {
        $stores = Store::where('is_active', true)->orderByRaw('CAST(store_code AS UNSIGNED)')->get();
        return view('master.users.form', compact('stores'));
    }
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'username' => 'required|string|alpha_dash|unique:users,username',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:8|confirmed',
            'role' => 'required|in:super_admin,admin_area',
        ]);
        $data['password'] = Hash::make($data['password']);
        $user = User::create($data);
        if ($request->store_ids) {
            $user->stores()->attach($request->store_ids, ['assigned_at' => now()]);
        }
        return redirect()->route('master.users.index')->with('success', 'User ditambahkan.');
    }
    public function edit(User $user)
    {
        $stores = Store::where('is_active', true)->orderByRaw('CAST(store_code AS UNSIGNED)')->get();
        $user->load('stores');
        $assignedStores = $user->stores->pluck('id')->toArray();
        return view('master.users.form', compact('user', 'stores', 'assignedStores'));
    }
    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'email' => 'required|email|unique:users,email,' . $user->id,
            'role' => 'required|in:super_admin,admin_area',
            'password' => 'nullable|min:8|confirmed',
        ]);
        if (empty($data['password']))
            unset($data['password']);
        else
            $data['password'] = Hash::make($data['password']);
        $user->update($data);
        return redirect()->route('master.users.index')->with('success', 'User diupdate.');
    }
    public function destroy(User $user)
    {
        if ($user->id === auth()->id()) {
            return back()->with('error', 'Tidak bisa menghapus akun sendiri.');
        }

        $hasData = Mutation::where('created_by', $user->id)->exists()
            || Opname::where('performed_by', $user->id)->exists()
            || WasteLog::where('recorded_by', $user->id)->exists()
            || ProductionLog::where('created_by', $user->id)->exists()
            || StockLedger::where('created_by', $user->id)->exists();

        if ($hasData) {
            return back()->with('error', 'User tidak bisa dihapus karena sudah memiliki data transaksi. Pertimbangkan untuk menonaktifkan user.');
        }

        $user->stores()->detach();
        $user->delete();
        return redirect()->route('master.users.index')->with('success', 'User berhasil dihapus.');
    }

    public function assignStore(Request $request, User $user)
    {
        $request->validate(['store_ids' => 'required|array', 'store_ids.*' => 'exists:stores,id']);
        $existing = $user->stores()->pluck('stores.id')->toArray();
        $toAttach = array_diff($request->store_ids, $existing);
        if ($toAttach) {
            $user->stores()->attach(array_fill_keys($toAttach, ['assigned_at' => now()]));
        }
        return back()->with('success', count($toAttach) . ' toko berhasil di-assign.');
    }
    public function revokeStore(User $user, Store $store)
    {
        $user->stores()->detach($store->id);
        return back()->with('success', 'Akses toko dicabut.');
    }


}
