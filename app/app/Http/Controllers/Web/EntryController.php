<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLabourAgreementRequest;
use App\Http\Requests\StoreTransactionRequest;
use App\Models\LabourAgreement;
use App\Models\Project;
use App\Models\Transaction;
use App\Services\TransactionRecorder;
use Illuminate\Http\Request;

class EntryController extends Controller
{
    public function storeTransaction(StoreTransactionRequest $request, TransactionRecorder $recorder)
    {
        $data = $request->validated();
        $project = Project::findOrFail($data['project_id']);
        $this->authorize('create', [Transaction::class, $project]);

        [$transaction, $alreadySynced] = $recorder->record(
            $data, $project, $request->user(), $request->file('evidence')
        );

        return redirect('/projects/'.$project->id)->with(
            'success',
            $alreadySynced
                ? 'Already recorded — nothing duplicated.'
                : 'Saved '.$transaction->transaction_code.' · '.number_format($transaction->total_amount).' TSh.'
        );
    }

    public function storeAgreement(StoreLabourAgreementRequest $request)
    {
        $data = $request->validated();
        $project = Project::findOrFail($data['project_id']);
        $this->authorize('create', [LabourAgreement::class, $project]);

        $n = LabourAgreement::count() + 1;

        $agreement = LabourAgreement::create($data + [
            'agreement_code' => 'AGR-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'status' => 'draft',
            'created_by' => $request->user()->id,
        ]);

        return redirect('/projects/'.$project->id)->with(
            'success',
            'Agreement '.$agreement->agreement_code.' recorded for '.$agreement->fundi_subcontractor.'.'
        );
    }
}
