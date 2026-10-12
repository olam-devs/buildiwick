<?php

namespace App\Services;

use App\Models\ExpenseRequest;
use App\Models\Lookup;
use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class SiteExpenseRequestService
{
    public const PHASES = [
        'preliminaries', 'substructure', 'superstructure', 'walling', 'roofing', 'finishing', 'external_works',
    ];

    public const CATEGORIES = [
        'materials' => 'Materials',
        'labour_subcontracts' => 'Labour & Subcontracts',
        'logistics_equipment' => 'Logistics & Equipment',
        'preliminaries_overheads' => 'Preliminaries & Site Overheads',
    ];

    public function __construct(private readonly DatabaseManager $database) {}

    public function activePhase(Project $project, bool $lock = false): ProjectPhase
    {
        // Restrict operational phases to the established seven stages, never `other_general`.
        $query = $project->phases()->where('status', 'active')->whereIn('stage_code', [
            'preliminaries', 'substructure', 'superstructure', 'walling', 'roofing', 'finishing', 'external_works',
        ]);
        if ($lock) {
            $query->lockForUpdate();
        }
        $phases = $query->get();
        if ($phases->count() !== 1) {
            throw ValidationException::withMessages([
                'project_phase_id' => $phases->isEmpty()
                    ? 'This project has no active construction phase configured. Ask an administrator to configure the current phase.'
                    : 'This project has multiple active construction phases. Ask an administrator to resolve the current phase before submitting a request.',
            ]);
        }

        return $phases->first();
    }

    public function create(Project $project, array $data, User $actor, ?UploadedFile $evidence = null): ExpenseRequest
    {
        Gate::forUser($actor)->authorize('create', [ExpenseRequest::class, $project]);
        $storedPath = null;

        try {
            return $this->database->transaction(function () use ($project, $data, $actor, $evidence, &$storedPath): ExpenseRequest {
                $lockedProject = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($actor)->authorize('create', [ExpenseRequest::class, $lockedProject]);
                $phase = $this->activePhase($lockedProject, lock: true);
                $this->assertCategoryPair($data);
                $evidencePath = $evidence?->store('expense-requests/'.$lockedProject->id, 'local');
                $storedPath = $evidencePath;

                $legacyAmount = array_key_exists('unit_cost', $data)
                    ? $this->legacyRequestedAmount($data)
                    : null;
                $request = $lockedProject->expenseRequests()->create([
                    'request_code' => 'REQ-TMP-'.Str::ulid(),
                    'project_phase_id' => $phase->id,
                    'labour_agreement_id' => $data['labour_agreement_id'] ?? null,
                    'request_type' => $data['request_type'] ?? 'item',
                    'main_category' => $data['main_category'],
                    'subcategory' => $data['subcategory'] ?? null,
                    'item_description' => $data['item_description'],
                    'supplier_fundi_client' => null,
                    'invoice_receipt_no' => null,
                    'quantity' => $this->normalizeQuantity($data['quantity'] ?? null),
                    'unit' => $data['unit'] ?? null,
                    'unit_cost' => array_key_exists('unit_cost', $data) ? $data['unit_cost'] : null,
                    'total_amount' => $legacyAmount,
                    'requested_amount' => $data['requested_amount'] ?? null,
                    'requested_at' => $data['requested_at'],
                    'submitted_at' => now(),
                    'status' => 'pending_approval',
                    'requested_by' => $actor->id,
                    'evidence_link' => $evidencePath,
                    'evidence_original_name' => $evidence?->getClientOriginalName(),
                    'notes' => $data['notes'] ?? null,
                ]);
                $request->update(['request_code' => 'REQ-'.str_pad((string) $request->id, 6, '0', STR_PAD_LEFT)]);

                return $request->fresh(['project', 'phase', 'requestedBy']);
            });
        } catch (Throwable $exception) {
            if ($storedPath) {
                Storage::disk('local')->delete($storedPath);
            }
            throw $exception;
        }
    }

    /** @return Collection<int, ExpenseRequest> */
    public function createBatch(Project $project, array $data, User $actor, ?UploadedFile $evidence = null): Collection
    {
        Gate::forUser($actor)->authorize('create', [ExpenseRequest::class, $project]);
        $storedPath = null;

        try {
            return $this->database->transaction(function () use ($project, $data, $actor, $evidence, &$storedPath): Collection {
                $lockedProject = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($actor)->authorize('create', [ExpenseRequest::class, $lockedProject]);
                $phase = $this->activePhase($lockedProject, lock: true);
                $storedPath = $evidence?->store('expense-requests/'.$lockedProject->id, 'local');
                $temporaryBatch = 'SRQ-TMP-'.Str::ulid();
                $created = collect();

                foreach (array_values($data['items']) as $index => $item) {
                    $requestType = $item['request_type'] ?? 'item';
                    $provisionalCategory = in_array($requestType, ['salary_wage'], true)
                        ? 'labour_subcontracts'
                        : 'unclassified';
                    $request = $lockedProject->expenseRequests()->create([
                        'request_code' => 'REQ-TMP-'.Str::ulid(),
                        'request_batch_code' => $temporaryBatch,
                        'request_line_number' => $index + 1,
                        'project_phase_id' => $phase->id,
                        'labour_agreement_id' => $item['labour_agreement_id'] ?? null,
                        'request_type' => $requestType,
                        'main_category' => $provisionalCategory,
                        'subcategory' => null,
                        'item_description' => $item['item_description'],
                        'quantity' => $this->normalizeQuantity($item['quantity'] ?? null),
                        'unit' => $item['unit'] ?? null,
                        'requested_amount' => $item['requested_amount'] ?? null,
                        'requested_at' => $data['requested_at'],
                        'submitted_at' => now(),
                        'status' => 'pending_approval',
                        'requested_by' => $actor->id,
                        'evidence_link' => $storedPath,
                        'evidence_original_name' => $evidence?->getClientOriginalName(),
                        'notes' => $item['notes'] ?? null,
                    ]);
                    $request->update(['request_code' => 'REQ-'.str_pad((string) $request->id, 6, '0', STR_PAD_LEFT)]);
                    $created->push($request);
                }

                $batchCode = 'SRQ-'.str_pad((string) $created->first()->id, 6, '0', STR_PAD_LEFT);
                ExpenseRequest::query()->whereIn('id', $created->pluck('id'))->update(['request_batch_code' => $batchCode]);

                return ExpenseRequest::query()->whereIn('id', $created->pluck('id'))
                    ->with(['project', 'phase', 'requestedBy'])->orderBy('request_line_number')->get();
            });
        } catch (Throwable $exception) {
            if ($storedPath) {
                Storage::disk('local')->delete($storedPath);
            }
            throw $exception;
        }
    }

    public function update(ExpenseRequest $expenseRequest, array $data, User $actor, ?UploadedFile $evidence = null): ExpenseRequest
    {
        $storedPath = null;
        $oldPath = $expenseRequest->evidence_link;

        try {
            $updated = $this->database->transaction(function () use ($expenseRequest, $data, $actor, $evidence, &$storedPath): ExpenseRequest {
                $request = ExpenseRequest::query()->whereKey($expenseRequest->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($actor)->authorize('update', $request);
                $this->assertCategoryPair($data);
                $newPath = $evidence?->store('expense-requests/'.$request->project_id, 'local');
                $storedPath = $newPath;

                $request->update([
                    'labour_agreement_id' => $data['labour_agreement_id'] ?? null,
                    'request_type' => $data['request_type'] ?? $request->request_type ?? 'item',
                    'main_category' => $data['main_category'],
                    'subcategory' => $data['subcategory'] ?? null,
                    'item_description' => $data['item_description'],
                    'quantity' => $this->normalizeQuantity($data['quantity'] ?? null),
                    'unit' => $data['unit'] ?? null,
                    'requested_amount' => $data['requested_amount'] ?? null,
                    ...($this->legacyCommercialData($data)),
                    'requested_at' => $data['requested_at'],
                    'notes' => $data['notes'] ?? null,
                    ...($evidence ? [
                        'evidence_link' => $newPath,
                        'evidence_original_name' => $evidence->getClientOriginalName(),
                    ] : []),
                ]);

                return $request->fresh(['project', 'phase', 'requestedBy']);
            });

            if ($evidence && $oldPath && $oldPath !== $storedPath) {
                Storage::disk('local')->delete($oldPath);
            }

            return $updated;
        } catch (Throwable $exception) {
            if ($storedPath) {
                Storage::disk('local')->delete($storedPath);
            }
            throw $exception;
        }
    }

    public function cancel(ExpenseRequest $expenseRequest, User $actor): ExpenseRequest
    {
        return $this->database->transaction(function () use ($expenseRequest, $actor): ExpenseRequest {
            $request = ExpenseRequest::query()->whereKey($expenseRequest->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('cancel', $request);
            $request->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $actor->id]);

            return $request->fresh();
        });
    }

    /** @return array<string, mixed> */
    public function projectSummary(Project $project): array
    {
        $requests = $project->expenseRequests()->with('payments')->where('status', '!=', 'cancelled')->get();
        $priced = $requests->filter(fn (ExpenseRequest $request) => $request->commercial_total !== null || $request->total_amount !== null);
        $requested = Money::sum($priced->map(fn (ExpenseRequest $request) => $request->commercial_total ?? $request->total_amount));
        $pending = $requests->where('status', 'pending_approval');
        $approved = $requests->where('status', 'approved');
        $rejected = $requests->where('status', 'rejected');
        $pendingAmount = Money::sum($pending->filter(fn (ExpenseRequest $request) => $request->commercial_total !== null || $request->total_amount !== null)->map(fn (ExpenseRequest $request) => $request->commercial_total ?? $request->total_amount));
        $approvedAmount = Money::sum($approved->map(fn (ExpenseRequest $request) => $request->approved_payable_amount));
        $rejectedAmount = Money::sum($rejected->filter(fn (ExpenseRequest $request) => $request->commercial_total !== null || $request->total_amount !== null)->map(fn (ExpenseRequest $request) => $request->commercial_total ?? $request->total_amount));
        $paid = Money::sum($requests->flatMap(fn (ExpenseRequest $request) => $request->payments->pluck('amount')));
        $approvedOutstanding = Money::sum($approved->map(fn (ExpenseRequest $request) => $request->approved_outstanding_amount));
        $categories = [];

        foreach (self::CATEGORIES as $code => $label) {
            $group = $requests->where('main_category', $code);
            $categories[$code] = [
                'label' => $label,
                'requests' => $group->count(),
                'pending' => $group->where('status', 'pending_approval')->count(),
                'approved' => $group->where('status', 'approved')->count(),
                'rejected' => $group->where('status', 'rejected')->count(),
                'requested' => Money::sum($group->filter(fn (ExpenseRequest $request) => $request->commercial_total !== null || $request->total_amount !== null)->map(fn (ExpenseRequest $request) => $request->commercial_total ?? $request->total_amount)),
                'approved_amount' => Money::sum($group->where('status', 'approved')->map(fn (ExpenseRequest $request) => $request->approved_payable_amount)),
                'approved' => Money::sum($group->where('status', 'approved')->map(fn (ExpenseRequest $request) => $request->approved_payable_amount)),
                'paid' => Money::sum($group->flatMap(fn (ExpenseRequest $request) => $request->payments->pluck('amount'))),
            ];
        }

        return [
            'total_requests' => $requests->count(),
            'requested' => $requested,
            'pending_count' => $pending->count(),
            'pending_amount' => $pendingAmount,
            'pending_director_count' => 0,
            'approved_count' => $approved->count(),
            'approved_amount' => $approvedAmount,
            'rejected_count' => $rejected->count(),
            'rejected_amount' => $rejectedAmount,
            'paid' => $paid,
            'approved_outstanding' => $approvedOutstanding,
            'approved_awaiting_count' => $approved->filter(fn (ExpenseRequest $request) => Money::compare($request->approved_outstanding_amount, '0') > 0)->count(),
            'approved_awaiting_amount' => $approvedOutstanding,
            'awaiting_pricing_count' => $requests->filter(fn (ExpenseRequest $request) => $request->commercial_total === null && $request->status === 'pending_approval')->count(),
            'categories' => $categories,
        ];
    }

    private function normalizeQuantity(mixed $quantity): ?string
    {
        return $quantity === null || $quantity === '' ? null : (string) $quantity;
    }

    private function legacyRequestedAmount(array $data): string
    {
        $amount = Money::product($data['unit_cost'], $this->normalizeQuantity($data['quantity'] ?? null) ?? '1');
        if (Money::compare($amount, '0') <= 0) {
            throw ValidationException::withMessages(['unit_cost' => 'The calculated requested amount must be greater than zero.']);
        }

        return $amount;
    }

    private function legacyCommercialData(array $data): array
    {
        if (! array_key_exists('unit_cost', $data)) {
            return [];
        }

        return ['unit_cost' => $data['unit_cost'], 'total_amount' => $this->legacyRequestedAmount($data)];
    }

    private function assertCategoryPair(array $data): void
    {
        if (empty($data['subcategory'])) {
            return;
        }

        $valid = Lookup::where('type', 'subcategory')
            ->where('code', $data['subcategory'])
            ->where('parent_code', $data['main_category'])
            ->where('is_active', true)
            ->exists();
        if (! $valid) {
            throw ValidationException::withMessages(['subcategory' => 'Choose a subcategory belonging to the selected category.']);
        }
    }
}
