<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\SavePaymentDestinationRequest;
use App\Http\Requests\SaveSupplierPayeeRequest;
use App\Models\Lookup;
use App\Models\SupplierPayee;
use Illuminate\Http\Request;

class SupplierPayeeController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', SupplierPayee::class);
        $suppliers = SupplierPayee::query()->when($request->filled('q'), fn ($query) => $query->where('name', 'like', '%'.$request->string('q').'%'))->orderBy('name')->get();

        return view('pm.suppliers.index', compact('suppliers'));
    }

    public function create(Request $request)
    {
        $this->authorize('create', SupplierPayee::class);

        return view('pm.suppliers.form', ['supplierPayee' => null]);
    }

    public function store(SaveSupplierPayeeRequest $request)
    {
        SupplierPayee::create($request->validated() + ['is_active' => $request->boolean('is_active', true)]);

        return redirect()->route('pm.suppliers.index')->with('success', 'Supplier/payee added.');
    }

    public function destinations(SupplierPayee $supplierPayee)
    {
        $this->authorize('update', $supplierPayee);

        return view('pm.suppliers.destinations', [
            'supplierPayee' => $supplierPayee,
            'destinations' => $supplierPayee->paymentDestinations()->where('is_active', true)->get(),
            'paymentMethods' => Lookup::ofType('payment_method')->get(),
        ]);
    }

    public function storeDestination(SavePaymentDestinationRequest $request, SupplierPayee $supplierPayee)
    {
        $supplierPayee->paymentDestinations()->create($request->validated() + ['is_active' => $request->boolean('is_active', true)]);

        return redirect()->route('pm.suppliers.destinations', $supplierPayee)->with('success', 'Payment destination added.');
    }
}