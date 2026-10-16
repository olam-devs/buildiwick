<?php

namespace App\Services;

use App\Models\ClientInvoice;
use App\Models\ClientInvoiceItem;
use App\Models\EstimateEntry;
use App\Models\Project;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ClientInvoiceService
{
    public function __construct(private readonly DatabaseManager $database) {}

    public function createDraft(Project $project, array $data, User $actor): ClientInvoice
    {
        Gate::forUser($actor)->authorize('create', [ClientInvoice::class, $project]);

        return $this->database->transaction(function () use ($project, $data, $actor): ClientInvoice {
            $locked = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('create', [ClientInvoice::class, $locked]);
            if ((isset($data['project_id']) && (int) $data['project_id'] !== (int) $locked->id)
                || (isset($data['client_id']) && (int) $data['client_id'] !== (int) $locked->client_id)) {
                throw ValidationException::withMessages(['project_id' => 'Invoice project and client must match the route project.']);
            }
            if (! empty($data['project_estimate_id'])
                && ! $locked->estimates()->whereKey($data['project_estimate_id'])->exists()) {
                throw ValidationException::withMessages(['project_estimate_id' => 'The estimate must belong to this invoice project.']);
            }
            $items = $this->normalizedItems($data['items'], $locked);
            $invoice = $locked->invoices()->create([
                'invoice_code' => 'INV-TMP-'.Str::ulid(), 'reference' => $data['reference'] ?? null,
                'client_id' => $locked->client_id, 'project_estimate_id' => $data['project_estimate_id'] ?? null,
                'status' => 'draft', 'due_at' => $data['due_at'] ?? null, 'created_by' => $actor->id,
                'notes' => $data['notes'] ?? null,
            ]);
            $invoice->update(['invoice_code' => 'INV-'.str_pad((string) $invoice->id, 6, '0', STR_PAD_LEFT)]);
            $invoice->items()->createMany($items);

            return $invoice->fresh(['items', 'project', 'client']);
        });
    }

    public function issue(ClientInvoice $invoice, User $actor): ClientInvoice
    {
        return $this->database->transaction(function () use ($invoice, $actor): ClientInvoice {
            $locked = ClientInvoice::query()->with('items')->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $locked);
            if ($locked->status !== 'draft' || $locked->items->isEmpty() || Money::compare($locked->total_amount, '0') <= 0 || ! $locked->client_id || ! $locked->due_at) {
                throw ValidationException::withMessages(['invoice' => 'A draft invoice needs a client, due date, and at least one positive line before issue.']);
            }
            foreach ($locked->items->whereNotNull('estimate_entry_id') as $item) {
                $entry = EstimateEntry::query()->lockForUpdate()->findOrFail($item->estimate_entry_id);
                $previouslyIssued = $this->claimedAmount($entry->id, $locked->id, issuedOnly: true);
                if (Money::compare(Money::sum([$previouslyIssued, $item->claim_amount]), $entry->line_total) > 0) {
                    throw ValidationException::withMessages(['invoice' => 'A BOQ line has already been claimed by another issued invoice. Review the remaining amount.']);
                }
            }
            $locked->update(['status' => 'issued', 'issued_at' => today(), 'issued_by' => $actor->id]);

            return $locked->fresh(['items', 'project', 'client', 'issuedBy']);
        });
    }

    public function void(ClientInvoice $invoice, string $reason, User $actor): ClientInvoice
    {
        return $this->database->transaction(function () use ($invoice, $reason, $actor): ClientInvoice {
            $locked = ClientInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('void', $locked);
            if ($locked->status !== 'issued' || $locked->payments()->exists()) {
                throw ValidationException::withMessages(['invoice' => 'Only unpaid issued invoices can be voided.']);
            }
            $locked->update(['status' => 'void', 'voided_by' => $actor->id, 'voided_at' => now(), 'void_reason' => $reason]);

            return $locked->fresh();
        });
    }

    private function normalizedItems(array $items, Project $project): array
    {
        return collect($items)->filter(function (array $item): bool {
            return empty($item['estimate_entry_id']) || ! empty($item['selected']);
        })->map(function (array $item) use ($project): array {
            if (! empty($item['estimate_entry_id'])) {
                $entry = EstimateEntry::query()->with('estimate')->lockForUpdate()->findOrFail($item['estimate_entry_id']);
                if ((int) $entry->estimate->project_id !== (int) $project->id || $entry->estimate->status !== 'issued') {
                    throw ValidationException::withMessages(['items' => 'Invoices can only use lines from this project’s issued estimate.']);
                }
                $alreadyClaimed = $this->claimedAmount($entry->id);
                $remaining = Money::difference($entry->line_total, $alreadyClaimed);
                $claim = $item['claim_amount'] ?? $remaining;
                if (Money::compare($claim, '0') <= 0 || Money::compare($claim, $remaining) > 0) {
                    throw ValidationException::withMessages(['items' => 'A claim must be positive and cannot exceed the BOQ line’s remaining amount.']);
                }

                return [
                    'estimate_entry_id' => $entry->id, 'project_phase_id' => $entry->project_phase_id,
                    'work_area' => $entry->work_area, 'work_section' => $entry->work_section,
                    'item_reference' => $entry->item_reference, 'main_category' => $entry->main_category,
                    'subcategory' => $entry->subcategory, 'item_description' => $entry->item_description,
                    'quantity' => $entry->quantity, 'unit' => $entry->unit, 'unit_price' => $entry->client_unit_cost,
                    'contract_amount' => $entry->line_total, 'claim_amount' => $claim, 'notes' => $item['notes'] ?? null,
                ];
            }
            if (Money::compare($item['unit_price'], '0') <= 0 || ! $project->phases()->whereKey($item['project_phase_id'])->exists()) {
                throw ValidationException::withMessages(['items' => 'Invoice lines must belong to this project and have positive prices.']);
            }

            return ['project_phase_id' => $item['project_phase_id'], 'main_category' => $item['main_category'], 'subcategory' => $item['subcategory'] ?? null, 'item_description' => $item['item_description'], 'quantity' => $item['quantity'] ?? null, 'unit' => $item['unit'] ?? null, 'unit_price' => $item['unit_price'], 'notes' => $item['notes'] ?? null];
        })->all();
    }

    private function claimedAmount(int $entryId, ?int $excludeInvoiceId = null, bool $issuedOnly = false): string
    {
        $query = ClientInvoiceItem::query()->where('estimate_entry_id', $entryId)
            ->whereHas('invoice', function ($invoiceQuery) use ($excludeInvoiceId, $issuedOnly): void {
                $issuedOnly
                    ? $invoiceQuery->where('status', 'issued')
                    : $invoiceQuery->where('status', '!=', 'void');
                if ($excludeInvoiceId) {
                    $invoiceQuery->where('id', '!=', $excludeInvoiceId);
                }
            });

        return Money::sum($query->pluck('claim_amount'));
    }
}
