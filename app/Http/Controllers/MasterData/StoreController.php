<?php
namespace App\Http\Controllers\MasterData;
use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\Request;

class StoreController extends Controller
{
    public function index(Request $request)
    {
        $query = Store::query();
        if ($request->search) $query->where(function($q) use ($request) {
            $q->where('name','like',"%{$request->search}%")->orWhere('store_code','like',"%{$request->search}%");
        });
        if ($request->area) $query->where('area', $request->area);
        if ($request->status !== null && $request->status !== '') $query->where('is_active', $request->status);
        $stores = $query->orderBy('store_code')->paginate(20);
        $areas  = Store::distinct()->pluck('area');
        return view('master.stores.index', compact('stores','areas'));
    }
    public function create() { return view('master.stores.form'); }
    public function store(Request $request)
    {
        $data = $request->validate([
            'store_code'     => 'required|string|unique:stores,store_code',
            'name'           => 'required|string',
            'area'           => 'required|string',
        ]);
        $data['is_active']  = $request->has('is_active');
        Store::create($data);
        return redirect()->route('master.stores.index')->with('success','Toko berhasil ditambahkan.');
    }
    public function show(Store $store) { return view('master.stores.show', compact('store')); }
    public function edit(Store $store) { return view('master.stores.form', compact('store')); }
    public function update(Request $request, Store $store)
    {
        $data = $request->validate([
            'store_code'     => 'required|string|unique:stores,store_code,'.$store->id,
            'name'           => 'required|string',
            'area'           => 'required|string',
        ]);
        $data['is_active']  = $request->has('is_active');
        $store->update($data);
        return redirect()->route('master.stores.index')->with('success','Toko berhasil diupdate.');
    }
    public function destroy(Store $store)
    {
        $hasData = $store->opnames()->exists()
            || $store->wasteLogs()->exists()
            || $store->productionLogs()->exists()
            || $store->mutations()->exists()
            || \App\Models\Mutation::where('source_store_id', $store->id)->exists()
            || \App\Models\StockLedger::where('store_id', $store->id)->exists();

        if ($hasData) {
            return back()->with('error', 'Toko tidak bisa dihapus karena sudah memiliki data transaksi. Nonaktifkan toko jika tidak ingin digunakan.');
        }

        $store->delete();
        return back()->with('success', 'Toko berhasil dihapus.');
    }
}
