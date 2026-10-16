<?php

use App\Models\ClientInvoice;
use App\Models\EstimateEntry;
use App\Models\Project;
use App\Support\Money;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $project = Project::query()->where('project_code', 'BWG-OYS-BW-2026-DEMO')->first();
        if (! $project) {
            return;
        }

        $invoice = ClientInvoice::query()->where('project_id', $project->id)
            ->where('invoice_code', 'INV-OYS-BW-DEMO-001')->first();
        if (! $invoice) {
            return;
        }

        $phaseIds = $project->phases()->whereIn('phase_order', [1, 2, 3, 4])->pluck('id');
        EstimateEntry::query()->where('project_estimate_id', $invoice->project_estimate_id)
            ->whereIn('project_phase_id', $phaseIds)->get()->each(function (EstimateEntry $entry) use ($invoice): void {
                $invoice->items()->updateOrCreate(['estimate_entry_id' => $entry->id], [
                    'project_phase_id' => $entry->project_phase_id,
                    'work_area' => $entry->work_area,
                    'work_section' => $entry->work_section,
                    'item_reference' => $entry->item_reference,
                    'main_category' => $entry->main_category,
                    'subcategory' => $entry->subcategory,
                    'item_description' => $entry->item_description,
                    'quantity' => $entry->quantity,
                    'unit' => $entry->unit,
                    'unit_price' => $entry->client_unit_cost,
                    'contract_amount' => $entry->line_total,
                    'claim_amount' => $entry->line_total,
                    'notes' => '100% claimed for completed phase.',
                ]);
            });
        $invoice->update(['notes' => 'Demo invoice containing completed Preliminaries, Demolition, Substructure and Superstructure quotation items.']);
        $invoice->payments()->where('payment_code', 'PAY-OYS-CLIENT-DEMO-001')->update([
            'amount' => Money::decimal('20000000')->__toString(),
            'reference' => 'BWK/OYS-BW/IP-01-RECEIPT',
            'notes' => 'Demo progress payment that keeps the project cash position positive while retaining an outstanding receivable.',
        ]);
    }

    public function down(): void
    {
        $project = Project::query()->where('project_code', 'BWG-OYS-BW-2026-DEMO')->first();
        $invoice = $project ? ClientInvoice::query()->where('project_id', $project->id)
            ->where('invoice_code', 'INV-OYS-BW-DEMO-001')->first() : null;
        if (! $invoice) {
            return;
        }

        $laterPhaseIds = $project->phases()->whereIn('phase_order', [3, 4])->pluck('id');
        $invoice->items()->whereIn('project_phase_id', $laterPhaseIds)->delete();
        $invoice->update(['notes' => 'Demo invoice containing completed Preliminaries and Demolition quotation items.']);
        $invoice->payments()->where('payment_code', 'PAY-OYS-CLIENT-DEMO-001')->update([
            'amount' => Money::decimal('4000000')->__toString(),
            'reference' => 'BWK/OYS-BW/IP-01-PART',
            'notes' => 'Demo part payment; remaining balance is visible in receivables.',
        ]);
    }
};
