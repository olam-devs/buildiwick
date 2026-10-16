<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignSupplierGroupRequest;
use App\Http\Requests\DecideSupplierGroupRequest;
use App\Http\Requests\MoveProcurementItemsRequest;
use App\Http\Requests\SaveSupplierGroupPricingRequest;
use App\Models\ExpenseRequest;
use App\Models\InternalBudgetEntry;
use App\Models\Lookup;
use App\Models\SupplierPayee;
use App\Models\SupplierRequestGroup;
use App\Services\SupplierRequestGroupService;
use Illuminate\Http\Request;

class ProcurementWorkflowController extends Controller
{
    public function show(Request $request, ExpenseRequest $expenseRequest)
    {
        $this->authorize('prepareCommercial', $expenseRequest);
        $batchCode = $expenseRequest->request_batch_code ?: $expenseRequest->request_code;
        $items = ExpenseRequest::query()->with(['phase', 'internalBudgetEntry', 'supplierRequestGroup.supplier'])
            ->where('project_id', $expenseRequest->project_id)
            ->where(fn ($query) => $query->where('request_batch_code', $batchCode)->orWhere('request_code', $batchCode))
            ->orderBy('item_description')->get();
        $groups = SupplierRequestGroup::query()->with(['supplier.paymentDestinations', 'paymentDestination', 'items.internalBudgetEntry'])
            ->where('project_id', $expenseRequest->project_id)->where('request_batch_code', $batchCode)->orderBy('id')->get();
        $budgetEntries = InternalBudgetEntry::query()->with('phase')
            ->whereHas('budget', fn ($query) => $query->where('project_id', $expenseRequest->project_id)->where('status', 'approved'))
            ->orderBy('item_description')->get();

        return view('pm.procurement.show', [
            'anchor' => $expenseRequest, 'project' => $expenseRequest->project, 'items' => $items, 'groups' => $groups,
            'budgetEntries' => $budgetEntries, 'phases' => $expenseRequest->project->phases()->orderBy('phase_order')->get(),
            'suppliers' => SupplierPayee::query()->where('is_active', true)->orderBy('name')->get(),
            'paymentMethods' => Lookup::where('type', 'payment_method')->where('is_active', true)->orderBy('sort_order')->get(),
            'step' => max(1, min(3, $request->integer('step', 1))),
        ]);
    }

    public function move(MoveProcurementItemsRequest $request, ExpenseRequest $expenseRequest, SupplierRequestGroupService $service)
    {
        $count = $service->move($expenseRequest, $request->validated(), $request->user());

        return back()->with('success', $count.' item(s) moved successfully.');
    }

    public function assign(AssignSupplierGroupRequest $request, ExpenseRequest $expenseRequest, SupplierRequestGroupService $service)
    {
        $group = $service->assign($expenseRequest, $request->validated(), $request->user());

        return redirect()->route('pm.procurement.show', [$expenseRequest, 'step' => 3])->with('success', $group->group_code.' created for '.$group->supplier->name.'.');
    }

    public function price(SaveSupplierGroupPricingRequest $request, SupplierRequestGroup $supplierRequestGroup, SupplierRequestGroupService $service)
    {
        $service->price($supplierRequestGroup, $request->validated(), $request->user(), $request->file('evidence'));

        return back()->with('success', $supplierRequestGroup->group_code.' pricing and payment details saved.');
    }

    public function decide(DecideSupplierGroupRequest $request, SupplierRequestGroup $supplierRequestGroup, SupplierRequestGroupService $service)
    {
        $anchor = $supplierRequestGroup->items()->firstOrFail();
        $decision = $request->validated('decision');
        $service->decide($supplierRequestGroup, $request->validated(), $request->user());

        return redirect()->route('pm.procurement.show', [$anchor, 'step' => $decision === 'approved' ? 3 : 2])
            ->with('success', $decision === 'approved' ? 'Supplier group approved and sent to Accounts.' : 'Items returned to Step 2.');
    }
}
