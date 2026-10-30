<?php

use App\Http\Controllers\Web\AccountantController;
use App\Http\Controllers\Web\AdminUserController;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\DirectorController;
use App\Http\Controllers\Web\DirectorStrategyController;
use App\Http\Controllers\Web\EntryController;
use App\Http\Controllers\Web\EstimatorController;
use App\Http\Controllers\Web\LedgerController;
use App\Http\Controllers\Web\OnboardingRecordsController;
use App\Http\Controllers\Web\ProcurementWorkflowController;
use App\Http\Controllers\Web\ProjectManagerController;
use App\Http\Controllers\Web\ProjectSetupController;
use App\Http\Controllers\Web\SiteOperatorController;
use App\Http\Controllers\Web\SupplierPayeeController;
use App\Support\Features;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (Features::recording() && auth()->check()) {
        $route = AuthController::landingRoute(auth()->user()->role?->name);

        return $route ? redirect()->route($route) : redirect()->route('login');
    }

    return auth()->user()?->hasRole('estimator_qs')
    ? redirect()->route('estimator.projects.index')
    : (auth()->user()?->hasRole('site_operator')
        ? redirect()->route('site.index')
        : (auth()->user()?->hasRole('project_manager') ? redirect()->route('pm.projects.index') : ((auth()->user()?->hasRole('accounts') || auth()->user()?->hasRole('accountant')) ? redirect()->route('accountant.index') : (auth()->user()?->hasRole('director') ? redirect()->route('director.index') : redirect('/projects')))));
});

Route::get('/login', [AuthController::class, 'show'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/logout', [AuthController::class, 'logout']);

Route::middleware(['auth', 'active-account'])->group(function () {

    // Recording-mode project workspace (Director, PM, Accounts).
    Route::get('/ledger', [LedgerController::class, 'index'])->name('ledger.index');
    Route::get('/ledger/to-confirm', [LedgerController::class, 'toConfirm'])->name('ledger.to-confirm');
    Route::get('/ledger/export', [LedgerController::class, 'exportAll'])->name('ledger.export');
    Route::get('/ledger/projects/{project}', [LedgerController::class, 'overview'])->name('ledger.overview');
    Route::get('/ledger/projects/{project}/expenses', [LedgerController::class, 'expenses'])->name('ledger.expenses');
    Route::get('/ledger/projects/{project}/expenses.xlsx', [LedgerController::class, 'export'])->name('ledger.expenses.export');
    Route::get('/ledger/projects/{project}/expenses/create', [LedgerController::class, 'create'])->name('ledger.expenses.create');
    Route::post('/ledger/projects/{project}/expenses', [LedgerController::class, 'store'])->name('ledger.expenses.store');
    Route::get('/ledger/projects/{project}/expenses/{line}/edit', [LedgerController::class, 'edit'])->name('ledger.expenses.edit');
    Route::put('/ledger/projects/{project}/expenses/{line}', [LedgerController::class, 'update'])->name('ledger.expenses.update');
    Route::delete('/ledger/projects/{project}/expenses/{line}', [LedgerController::class, 'destroy'])->name('ledger.expenses.destroy');
    Route::post('/ledger/projects/{project}/expenses/bulk', [LedgerController::class, 'bulk'])->name('ledger.expenses.bulk');
    Route::post('/ledger/projects/{project}/expenses/{line}/confirm', [LedgerController::class, 'confirm'])->name('ledger.expenses.confirm');
    Route::post('/ledger/projects/{project}/expenses/{line}/deny', [LedgerController::class, 'deny'])->name('ledger.expenses.deny');
    Route::post('/ledger/projects/{project}/expenses/{line}/restore', [LedgerController::class, 'restore'])->name('ledger.expenses.restore');
    Route::get('/ledger/projects/{project}/structure', [LedgerController::class, 'structure'])->name('ledger.structure');
    Route::post('/ledger/projects/{project}/phases', [LedgerController::class, 'storePhase'])->name('ledger.phases.store');
    Route::put('/ledger/projects/{project}/phases/{phase}', [LedgerController::class, 'renamePhase'])->name('ledger.phases.rename');
    Route::post('/ledger/projects/{project}/phases/{phase}/move', [LedgerController::class, 'movePhase'])->name('ledger.phases.move');
    Route::delete('/ledger/projects/{project}/phases/{phase}', [LedgerController::class, 'destroyPhase'])->name('ledger.phases.destroy');
    Route::post('/ledger/projects/{project}/budget', [LedgerController::class, 'saveBudget'])->name('ledger.budget.save');
    Route::get('/ledger/projects/{project}/workers', [LedgerController::class, 'workers'])->name('ledger.workers');
    Route::post('/ledger/projects/{project}/workers', [LedgerController::class, 'storeWorker'])->name('ledger.workers.store');
    Route::put('/ledger/projects/{project}/workers/{worker}', [LedgerController::class, 'updateWorker'])->name('ledger.workers.update');
    Route::get('/director', [DirectorController::class, 'index'])->name('director.index');
    Route::get('/director/exports/projects.xlsx', [DirectorController::class, 'exportPortfolio'])->middleware('feature:client_billing')->name('director.portfolio.export');
    Route::get('/director/exports/summary.xlsx', [DirectorController::class, 'exportSummary'])->middleware('feature:client_billing')->name('director.summary.export');
    Route::get('/director/exports/transactions.xlsx', [DirectorController::class, 'exportTransactions'])->middleware('feature:client_billing')->name('director.transactions.export');
    Route::get('/director/projects/{project}/budgets/{budget}', [DirectorController::class, 'budget'])->middleware('feature:approvals')->name('director.budgets.show');
    Route::get('/director/projects/{project}', [DirectorController::class, 'project'])->name('director.projects.show');
    Route::post('/director/projects/{project}/budgets/{budget}/decision', [DirectorController::class, 'decideBudget'])->middleware('feature:approvals')->name('director.budgets.decision');
    Route::get('/director/receivables', [DirectorController::class, 'receivables'])->middleware('feature:client_billing')->name('director.receivables.index');
    Route::get('/director/payables', [DirectorController::class, 'payables'])->middleware('feature:approvals')->name('director.payables.index');
    Route::get('/director/transactions', [DirectorController::class, 'transactions'])->middleware('feature:client_billing')->name('director.transactions.index');
    Route::get('/director/kpis', [DirectorStrategyController::class, 'kpis'])->name('director.kpis.index');
    Route::post('/director/kpis', [DirectorStrategyController::class, 'storeKpi'])->name('director.kpis.store');
    Route::delete('/director/kpis/{target}', [DirectorStrategyController::class, 'destroyKpi'])->name('director.kpis.destroy');
    Route::post('/director/kpis/{target}/visibility', [DirectorStrategyController::class, 'toggleKpiVisibility'])->name('director.kpis.visibility');
    Route::get('/director/warning-rules', [DirectorStrategyController::class, 'warningRules'])->name('director.warning-rules.index');
    Route::post('/director/warning-rules', [DirectorStrategyController::class, 'saveWarningRules'])->name('director.warning-rules.save');
    Route::get('/director/projects/{project}/warnings', [DirectorStrategyController::class, 'project'])->name('director.projects.warnings');
    Route::post('/director/projects/{project}/kpis', [DirectorStrategyController::class, 'storeProjectKpi'])->name('director.projects.kpis.store');
    Route::post('/director/projects/{project}/warning-rules', [DirectorStrategyController::class, 'saveProjectWarningRules'])->name('director.projects.warning-rules.save');
    Route::delete('/director/projects/{project}/warning-rules', [DirectorStrategyController::class, 'resetProjectWarningRules'])->name('director.projects.warning-rules.reset');
    Route::get('/director/request-approvals', [DirectorController::class, 'requests'])->middleware('feature:approvals')->name('director.requests.index');

    Route::get('/estimator/projects', [EstimatorController::class, 'index'])->middleware('feature:estimates')->name('estimator.projects.index');
    Route::get('/estimator/projects/{project}', [EstimatorController::class, 'show'])->middleware('feature:estimates')->name('estimator.projects.show');
    Route::get('/estimator/projects/{project}/estimates/{estimate}.xlsx', [EstimatorController::class, 'export'])->middleware('feature:estimates')->name('estimator.estimates.export');
    Route::post('/estimator/projects/{project}/estimates', [EstimatorController::class, 'startEstimate'])->middleware('feature:estimates')->name('estimator.estimates.start');
    Route::post('/estimator/projects/{project}/estimates/{estimate}/edit', [EstimatorController::class, 'startEstimateEdit'])->middleware('feature:estimates')->name('estimator.estimates.edit');
    Route::post('/estimator/projects/{project}/phases', [EstimatorController::class, 'storePhase'])->middleware('feature:estimates')->name('estimator.phases.store');
    Route::put('/estimator/projects/{project}/phases/{phase}', [EstimatorController::class, 'updatePhase'])->middleware('feature:estimates')->name('estimator.phases.update');
    Route::delete('/estimator/projects/{project}/phases/{phase}', [EstimatorController::class, 'deletePhase'])->middleware('feature:estimates')->name('estimator.phases.delete');
    Route::post('/estimator/projects/{project}/phases-reset', [EstimatorController::class, 'resetPhases'])->middleware('feature:estimates')->name('estimator.phases.reset');
    Route::get('/estimator/projects/{project}/phases/{phase}/entries/create', [EstimatorController::class, 'createEntry'])->middleware('feature:estimates')->name('estimator.entries.create');
    Route::post('/estimator/projects/{project}/phases/{phase}/entries', [EstimatorController::class, 'storeEntry'])->middleware('feature:estimates')->name('estimator.entries.store');
    Route::get('/estimator/projects/{project}/phases/{phase}/entries/{entry}/edit', [EstimatorController::class, 'editEntry'])->middleware('feature:estimates')->name('estimator.entries.edit');
    Route::put('/estimator/projects/{project}/phases/{phase}/entries/{entry}', [EstimatorController::class, 'updateEntry'])->middleware('feature:estimates')->name('estimator.entries.update');
    Route::delete('/estimator/projects/{project}/phases/{phase}/entries/{entry}', [EstimatorController::class, 'deleteEntry'])->middleware('feature:estimates')->name('estimator.entries.delete');
    Route::post('/estimator/projects/{project}/estimates/{estimate}/finalize', [EstimatorController::class, 'finalize'])->middleware('feature:estimates')->name('estimator.estimates.finalize');
    Route::post('/estimator/projects/{project}/estimates/{estimate}/submit', [EstimatorController::class, 'submit'])->middleware('feature:estimates')->name('estimator.estimates.submit');
    Route::post('/estimator/projects/{project}/estimates/{estimate}/return', [EstimatorController::class, 'returnToEstimator'])->middleware('feature:estimates')->name('estimator.estimates.return');
    Route::get('/estimator/projects/{project}/estimate-template.xlsx', [EstimatorController::class, 'template'])->middleware('feature:estimates')->name('estimator.estimates.template');
    Route::post('/estimator/projects/{project}/estimate-upload', [EstimatorController::class, 'upload'])->middleware('feature:estimates')->name('estimator.estimates.upload');

    Route::get('/site', [SiteOperatorController::class, 'index'])->middleware('feature:site_operator')->name('site.index');
    Route::get('/site/requests', [SiteOperatorController::class, 'requests'])->middleware('feature:site_operator')->name('site.requests.index');
    Route::get('/site/projects/{project}', [SiteOperatorController::class, 'project'])->middleware('feature:site_operator')->name('site.projects.show');
    Route::get('/site/projects/{project}/requests/create', [SiteOperatorController::class, 'create'])->middleware('feature:site_operator')->name('site.requests.create');
    Route::post('/site/projects/{project}/requests', [SiteOperatorController::class, 'store'])->middleware('feature:site_operator')->name('site.requests.store');
    Route::get('/site/projects/{project}/requests/{expenseRequest}', [SiteOperatorController::class, 'show'])->middleware('feature:site_operator')->name('site.requests.show');
    Route::get('/site/projects/{project}/requests/{expenseRequest}/edit', [SiteOperatorController::class, 'edit'])->middleware('feature:site_operator')->name('site.requests.edit');
    Route::put('/site/projects/{project}/requests/{expenseRequest}', [SiteOperatorController::class, 'update'])->middleware('feature:site_operator')->name('site.requests.update');
    Route::post('/site/projects/{project}/requests/{expenseRequest}/cancel', [SiteOperatorController::class, 'cancel'])->middleware('feature:site_operator')->name('site.requests.cancel');
    Route::get('/site/projects/{project}/requests/{expenseRequest}/evidence', [SiteOperatorController::class, 'evidence'])->middleware('feature:site_operator')->name('site.requests.evidence');

    Route::get('/pm/projects', [ProjectManagerController::class, 'index'])->middleware('feature:approvals')->name('pm.projects.index');
    Route::get('/pm/site-requests', [ProjectManagerController::class, 'requests'])->middleware('feature:approvals')->name('pm.requests.index');
    Route::get('/pm/site-requests.xlsx', [ProjectManagerController::class, 'exportRequests'])->middleware('feature:approvals')->name('pm.requests.export');
    Route::get('/pm/labour-agreements', [ProjectManagerController::class, 'labourAgreements'])->middleware('feature:approvals')->name('pm.labour.index');
    Route::get('/pm/labour-agreements.xlsx', [ProjectManagerController::class, 'exportLabourAgreements'])->middleware('feature:approvals')->name('pm.labour.export');
    Route::get('/pm/projects/{project}/labour-agreements/create', [ProjectManagerController::class, 'createLabourAgreement'])->middleware('feature:approvals')->name('pm.labour.create');
    Route::post('/pm/projects/{project}/labour-agreements', [ProjectManagerController::class, 'storeLabourAgreement'])->middleware('feature:approvals')->name('pm.labour.store');
    Route::get('/pm/projects/{project}/requests/create', [ProjectManagerController::class, 'createRequest'])->middleware('feature:approvals')->name('pm.requests.create');
    Route::post('/pm/projects/{project}/requests', [ProjectManagerController::class, 'storeRequest'])->middleware('feature:approvals')->name('pm.requests.store');
    Route::get('/pm/site-requests/{expenseRequest}/commercial', [ProjectManagerController::class, 'editCommercial'])->middleware('feature:approvals')->name('pm.requests.commercial.edit');
    Route::put('/pm/site-requests/{expenseRequest}/classification', [ProjectManagerController::class, 'classifyRequest'])->middleware('feature:approvals')->name('pm.requests.classification.update');
    Route::put('/pm/site-requests/{expenseRequest}/commercial', [ProjectManagerController::class, 'updateCommercial'])->middleware('feature:approvals')->name('pm.requests.commercial.update');
    Route::post('/pm/site-requests/{expenseRequest}/decision', [ProjectManagerController::class, 'decide'])->middleware('feature:approvals')->name('pm.requests.decision');
    Route::put('/pm/request-batches/{expenseRequest}/commercial', [ProjectManagerController::class, 'updateBatchCommercial'])->middleware('feature:approvals')->name('pm.request-batches.commercial.update');
    Route::post('/pm/request-batches/{expenseRequest}/decision', [ProjectManagerController::class, 'decideBatch'])->middleware('feature:approvals')->name('pm.request-batches.decision');
    Route::get('/pm/request-batches/{expenseRequest}/procurement', [ProcurementWorkflowController::class, 'show'])->middleware('feature:approvals')->name('pm.procurement.show');
    Route::put('/pm/request-batches/{expenseRequest}/procurement/move', [ProcurementWorkflowController::class, 'move'])->middleware('feature:approvals')->name('pm.procurement.move');
    Route::post('/pm/request-batches/{expenseRequest}/supplier-groups', [ProcurementWorkflowController::class, 'assign'])->middleware('feature:approvals')->name('pm.procurement.groups.store');
    Route::put('/pm/supplier-groups/{supplierRequestGroup}/pricing', [ProcurementWorkflowController::class, 'price'])->middleware('feature:approvals')->name('pm.procurement.groups.price');
    Route::post('/pm/supplier-groups/{supplierRequestGroup}/decision', [ProcurementWorkflowController::class, 'decide'])->middleware('feature:approvals')->name('pm.procurement.groups.decision');
    Route::get('/pm/site-requests/{expenseRequest}/commercial-evidence', [ProjectManagerController::class, 'commercialEvidence'])->middleware('feature:approvals')->name('pm.requests.commercial.evidence');
    Route::get('/pm/suppliers', [SupplierPayeeController::class, 'index'])->middleware('feature:approvals')->name('pm.suppliers.index');
    Route::get('/pm/suppliers/create', [SupplierPayeeController::class, 'create'])->middleware('feature:approvals')->name('pm.suppliers.create');
    Route::post('/pm/suppliers', [SupplierPayeeController::class, 'store'])->middleware('feature:approvals')->name('pm.suppliers.store');
    Route::get('/pm/suppliers/{supplierPayee}/destinations', [SupplierPayeeController::class, 'destinations'])->middleware('feature:approvals')->name('pm.suppliers.destinations');
    Route::post('/pm/suppliers/{supplierPayee}/destinations', [SupplierPayeeController::class, 'storeDestination'])->middleware('feature:approvals')->name('pm.suppliers.destinations.store');

    Route::get('/accountant', [AccountantController::class, 'index'])->middleware('feature:approvals')->name('accountant.index');
    Route::get('/accountant/requests', [AccountantController::class, 'requests'])->middleware('feature:approvals')->name('accountant.requests.index');
    Route::get('/accountant/projects', [AccountantController::class, 'projects'])->middleware('feature:approvals')->name('accountant.projects.index');
    Route::get('/accountant/requests/{expenseRequest}', [AccountantController::class, 'show'])->middleware('feature:approvals')->name('accountant.requests.show');
    Route::post('/accountant/requests/{expenseRequest}/payments', [AccountantController::class, 'record'])->middleware('feature:approvals')->name('accountant.payments.store');
    Route::get('/accountant/payments/{expensePayment}/evidence', [AccountantController::class, 'evidence'])->middleware('feature:approvals')->name('accountant.payments.evidence');
    Route::get('/accountant/requests/{expenseRequest}/site-evidence', [AccountantController::class, 'siteEvidence'])->middleware('feature:approvals')->name('accountant.requests.site-evidence');
    Route::get('/accountant/projects/{project}', [AccountantController::class, 'project'])->middleware('feature:approvals')->name('accountant.projects.show');
    Route::get('/accountant/invoices', [AccountantController::class, 'invoices'])->middleware('feature:client_billing')->name('accountant.invoices.index');
    Route::get('/accountant/projects/{project}/invoices/create', [AccountantController::class, 'createInvoice'])->middleware('feature:client_billing')->name('accountant.invoices.create');
    Route::post('/accountant/projects/{project}/invoices', [AccountantController::class, 'storeInvoice'])->middleware('feature:client_billing')->name('accountant.invoices.store');
    Route::get('/accountant/invoices/{clientInvoice}.xlsx', [AccountantController::class, 'exportInvoice'])->middleware('feature:client_billing')->name('accountant.invoices.export');
    Route::get('/accountant/invoices/{clientInvoice}', [AccountantController::class, 'invoice'])->middleware('feature:client_billing')->name('accountant.invoices.show');
    Route::post('/accountant/invoices/{clientInvoice}/issue', [AccountantController::class, 'issueInvoice'])->middleware('feature:client_billing')->name('accountant.invoices.issue');
    Route::post('/accountant/invoices/{clientInvoice}/void', [AccountantController::class, 'voidInvoice'])->middleware('feature:client_billing')->name('accountant.invoices.void');
    Route::post('/accountant/invoices/{clientInvoice}/payments', [AccountantController::class, 'clientPayment'])->middleware('feature:client_billing')->name('accountant.client-payments.store');
    Route::get('/accountant/client-payments/{clientPayment}/evidence', [AccountantController::class, 'clientPaymentEvidence'])->middleware('feature:client_billing')->name('accountant.client-payments.evidence');
    Route::get('/accountant/transactions', [AccountantController::class, 'transactions'])->middleware('feature:client_billing')->name('accountant.transactions.index');
    Route::get('/accountant/transactions/export', [AccountantController::class, 'exportTransactions'])->middleware('feature:client_billing')->name('accountant.transactions.export');
    Route::get('/accountant/projects/{project}/transactions', [AccountantController::class, 'projectTransactions'])->middleware('feature:client_billing')->name('accountant.projects.transactions');
    Route::get('/accountant/projects/{project}/transactions/export', [AccountantController::class, 'exportProjectTransactions'])->middleware('feature:client_billing')->name('accountant.projects.transactions.export');
    Route::get('/accountant/receivables', [AccountantController::class, 'receivables'])->middleware('feature:client_billing')->name('accountant.receivables.index');
    Route::get('/accountant/receivables/export', [AccountantController::class, 'exportReceivables'])->middleware('feature:client_billing')->name('accountant.receivables.export');
    Route::get('/accountant/payables', [AccountantController::class, 'payables'])->middleware('feature:approvals')->name('accountant.payables.index');
    Route::get('/accountant/payables/export', [AccountantController::class, 'exportPayables'])->middleware('feature:approvals')->name('accountant.payables.export');
    Route::get('/accountant/projects/{project}/statement', [AccountantController::class, 'statement'])->middleware('feature:client_billing')->name('accountant.projects.statement');
    Route::get('/accountant/projects/{project}/opening-records', [OnboardingRecordsController::class, 'index'])->middleware('feature:client_billing')->name('accountant.onboarding.index');
    Route::get('/accountant/projects/{project}/opening-records/template.xlsx', [OnboardingRecordsController::class, 'template'])->middleware('feature:client_billing')->name('accountant.onboarding.template');
    Route::post('/accountant/projects/{project}/opening-records', [OnboardingRecordsController::class, 'store'])->middleware('feature:client_billing')->name('accountant.onboarding.store');
    Route::get('/accountant/projects/{project}/opening-records/{onboardingImport}/file', [OnboardingRecordsController::class, 'download'])->middleware('feature:client_billing')->name('accountant.onboarding.download');
    Route::post('/accountant/projects/{project}/opening-records/{onboardingImport}/undo', [OnboardingRecordsController::class, 'undo'])->middleware('feature:client_billing')->name('accountant.onboarding.undo');
    Route::get('/pm/projects/{project}', [ProjectManagerController::class, 'show'])->middleware('feature:approvals')->name('pm.projects.show');
    Route::post('/pm/projects/{project}/budgets', [ProjectManagerController::class, 'startBudget'])->middleware('feature:approvals')->name('pm.budgets.start');
    Route::get('/pm/projects/{project}/budgets/{budget}', [ProjectManagerController::class, 'showBudget'])->whereNumber('budget')->middleware('feature:approvals')->name('pm.budgets.show');
    Route::post('/pm/projects/{project}/budgets/{budget}/edit', [ProjectManagerController::class, 'startBudgetEdit'])->middleware('feature:approvals')->name('pm.budgets.edit');
    Route::post('/pm/projects/{project}/budgets/{budget}/finalize', [ProjectManagerController::class, 'finalize'])->middleware('feature:approvals')->name('pm.budgets.finalize');
    Route::get('/pm/projects/{project}/budgets/{budget}.xlsx', [ProjectManagerController::class, 'exportBudget'])->middleware('feature:approvals')->name('pm.budgets.export');
    Route::get('/pm/projects/{project}/phases/{phase}/budget-entries/create', [ProjectManagerController::class, 'createEntry'])->middleware('feature:approvals')->name('pm.budget-entries.create');
    Route::post('/pm/projects/{project}/phases/{phase}/budget-entries', [ProjectManagerController::class, 'storeEntry'])->middleware('feature:approvals')->name('pm.budget-entries.store');
    Route::get('/pm/projects/{project}/phases/{phase}/budget-entries/{entry}/edit', [ProjectManagerController::class, 'editEntry'])->middleware('feature:approvals')->name('pm.budget-entries.edit');
    Route::put('/pm/projects/{project}/phases/{phase}/budget-entries/{entry}', [ProjectManagerController::class, 'updateEntry'])->middleware('feature:approvals')->name('pm.budget-entries.update');
    Route::delete('/pm/projects/{project}/phases/{phase}/budget-entries/{entry}', [ProjectManagerController::class, 'deleteEntry'])->middleware('feature:approvals')->name('pm.budget-entries.delete');

    Route::get('/admin/projects', [ProjectSetupController::class, 'index'])->name('admin.projects.index');
    Route::get('/admin/projects/create', [ProjectSetupController::class, 'create'])->name('admin.projects.create');
    Route::post('/admin/projects', [ProjectSetupController::class, 'store'])->name('admin.projects.store');
    Route::get('/admin/projects/{project}/edit', [ProjectSetupController::class, 'edit'])->name('admin.projects.edit');
    Route::put('/admin/projects/{project}', [ProjectSetupController::class, 'update'])->name('admin.projects.update');
    Route::get('/admin/projects/{project}/contracts/{document}', [ProjectSetupController::class, 'downloadContract'])->name('admin.projects.contracts.download');
    Route::get('/admin/projects/{project}', [ProjectSetupController::class, 'show'])->name('admin.projects.show');
    Route::resource('/admin/users', AdminUserController::class)
        ->except(['show', 'destroy'])
        ->names('admin.users');

    Route::get('/projects', [DashboardController::class, 'index'])->middleware('feature:approvals')->name('dashboard.index');
    Route::get('/projects/{project}', [DashboardController::class, 'show'])->middleware('feature:approvals')->name('dashboard.show');

    Route::post('/entries/transactions', [EntryController::class, 'storeTransaction'])->middleware('feature:approvals');
    Route::post('/entries/labour-agreements', [EntryController::class, 'storeAgreement'])->middleware('feature:approvals');
});
