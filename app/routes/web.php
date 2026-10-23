<?php

use App\Http\Controllers\Web\AccountantController;
use App\Http\Controllers\Web\AdminUserController;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\DirectorController;
use App\Http\Controllers\Web\EntryController;
use App\Http\Controllers\Web\EstimatorController;
use App\Http\Controllers\Web\OnboardingRecordsController;
use App\Http\Controllers\Web\ProcurementWorkflowController;
use App\Http\Controllers\Web\ProjectManagerController;
use App\Http\Controllers\Web\ProjectSetupController;
use App\Http\Controllers\Web\SiteOperatorController;
use App\Http\Controllers\Web\SupplierPayeeController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => auth()->user()?->hasRole('estimator_qs')
    ? redirect()->route('estimator.projects.index')
    : (auth()->user()?->hasRole('site_operator')
        ? redirect()->route('site.index')
        : (auth()->user()?->hasRole('project_manager') ? redirect()->route('pm.projects.index') : ((auth()->user()?->hasRole('accounts') || auth()->user()?->hasRole('accountant')) ? redirect()->route('accountant.index') : (auth()->user()?->hasRole('director') ? redirect()->route('director.index') : redirect('/projects'))))));

Route::get('/login', [AuthController::class, 'show'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/logout', [AuthController::class, 'logout']);

Route::middleware(['auth', 'active-account'])->group(function () {
    Route::get('/director', [DirectorController::class, 'index'])->name('director.index');
    Route::get('/director/exports/projects.xlsx', [DirectorController::class, 'exportPortfolio'])->name('director.portfolio.export');
    Route::get('/director/exports/summary.xlsx', [DirectorController::class, 'exportSummary'])->name('director.summary.export');
    Route::get('/director/exports/transactions.xlsx', [DirectorController::class, 'exportTransactions'])->name('director.transactions.export');
    Route::get('/director/projects/{project}/budgets/{budget}', [DirectorController::class, 'budget'])->name('director.budgets.show');
    Route::get('/director/projects/{project}', [DirectorController::class, 'project'])->name('director.projects.show');
    Route::post('/director/projects/{project}/budgets/{budget}/decision', [DirectorController::class, 'decideBudget'])->name('director.budgets.decision');
    Route::get('/director/receivables', [DirectorController::class, 'receivables'])->name('director.receivables.index');
    Route::get('/director/payables', [DirectorController::class, 'payables'])->name('director.payables.index');
    Route::get('/director/transactions', [DirectorController::class, 'transactions'])->name('director.transactions.index');
    Route::get('/director/request-approvals', [DirectorController::class, 'requests'])->name('director.requests.index');

    Route::get('/estimator/projects', [EstimatorController::class, 'index'])->name('estimator.projects.index');
    Route::get('/estimator/projects/{project}', [EstimatorController::class, 'show'])->name('estimator.projects.show');
    Route::get('/estimator/projects/{project}/estimates/{estimate}.xlsx', [EstimatorController::class, 'export'])->name('estimator.estimates.export');
    Route::post('/estimator/projects/{project}/estimates', [EstimatorController::class, 'startEstimate'])->name('estimator.estimates.start');
    Route::post('/estimator/projects/{project}/estimates/{estimate}/edit', [EstimatorController::class, 'startEstimateEdit'])->name('estimator.estimates.edit');
    Route::post('/estimator/projects/{project}/phases', [EstimatorController::class, 'storePhase'])->name('estimator.phases.store');
    Route::put('/estimator/projects/{project}/phases/{phase}', [EstimatorController::class, 'updatePhase'])->name('estimator.phases.update');
    Route::delete('/estimator/projects/{project}/phases/{phase}', [EstimatorController::class, 'deletePhase'])->name('estimator.phases.delete');
    Route::post('/estimator/projects/{project}/phases-reset', [EstimatorController::class, 'resetPhases'])->name('estimator.phases.reset');
    Route::get('/estimator/projects/{project}/phases/{phase}/entries/create', [EstimatorController::class, 'createEntry'])->name('estimator.entries.create');
    Route::post('/estimator/projects/{project}/phases/{phase}/entries', [EstimatorController::class, 'storeEntry'])->name('estimator.entries.store');
    Route::get('/estimator/projects/{project}/phases/{phase}/entries/{entry}/edit', [EstimatorController::class, 'editEntry'])->name('estimator.entries.edit');
    Route::put('/estimator/projects/{project}/phases/{phase}/entries/{entry}', [EstimatorController::class, 'updateEntry'])->name('estimator.entries.update');
    Route::delete('/estimator/projects/{project}/phases/{phase}/entries/{entry}', [EstimatorController::class, 'deleteEntry'])->name('estimator.entries.delete');
    Route::post('/estimator/projects/{project}/estimates/{estimate}/finalize', [EstimatorController::class, 'finalize'])->name('estimator.estimates.finalize');

    Route::get('/site', [SiteOperatorController::class, 'index'])->name('site.index');
    Route::get('/site/requests', [SiteOperatorController::class, 'requests'])->name('site.requests.index');
    Route::get('/site/projects/{project}', [SiteOperatorController::class, 'project'])->name('site.projects.show');
    Route::get('/site/projects/{project}/requests/create', [SiteOperatorController::class, 'create'])->name('site.requests.create');
    Route::post('/site/projects/{project}/requests', [SiteOperatorController::class, 'store'])->name('site.requests.store');
    Route::get('/site/projects/{project}/requests/{expenseRequest}', [SiteOperatorController::class, 'show'])->name('site.requests.show');
    Route::get('/site/projects/{project}/requests/{expenseRequest}/edit', [SiteOperatorController::class, 'edit'])->name('site.requests.edit');
    Route::put('/site/projects/{project}/requests/{expenseRequest}', [SiteOperatorController::class, 'update'])->name('site.requests.update');
    Route::post('/site/projects/{project}/requests/{expenseRequest}/cancel', [SiteOperatorController::class, 'cancel'])->name('site.requests.cancel');
    Route::get('/site/projects/{project}/requests/{expenseRequest}/evidence', [SiteOperatorController::class, 'evidence'])->name('site.requests.evidence');

    Route::get('/pm/projects', [ProjectManagerController::class, 'index'])->name('pm.projects.index');
    Route::get('/pm/site-requests', [ProjectManagerController::class, 'requests'])->name('pm.requests.index');
    Route::get('/pm/site-requests.xlsx', [ProjectManagerController::class, 'exportRequests'])->name('pm.requests.export');
    Route::get('/pm/labour-agreements', [ProjectManagerController::class, 'labourAgreements'])->name('pm.labour.index');
    Route::get('/pm/labour-agreements.xlsx', [ProjectManagerController::class, 'exportLabourAgreements'])->name('pm.labour.export');
    Route::get('/pm/projects/{project}/labour-agreements/create', [ProjectManagerController::class, 'createLabourAgreement'])->name('pm.labour.create');
    Route::post('/pm/projects/{project}/labour-agreements', [ProjectManagerController::class, 'storeLabourAgreement'])->name('pm.labour.store');
    Route::get('/pm/projects/{project}/requests/create', [ProjectManagerController::class, 'createRequest'])->name('pm.requests.create');
    Route::post('/pm/projects/{project}/requests', [ProjectManagerController::class, 'storeRequest'])->name('pm.requests.store');
    Route::get('/pm/site-requests/{expenseRequest}/commercial', [ProjectManagerController::class, 'editCommercial'])->name('pm.requests.commercial.edit');
    Route::put('/pm/site-requests/{expenseRequest}/classification', [ProjectManagerController::class, 'classifyRequest'])->name('pm.requests.classification.update');
    Route::put('/pm/site-requests/{expenseRequest}/commercial', [ProjectManagerController::class, 'updateCommercial'])->name('pm.requests.commercial.update');
    Route::post('/pm/site-requests/{expenseRequest}/decision', [ProjectManagerController::class, 'decide'])->name('pm.requests.decision');
    Route::put('/pm/request-batches/{expenseRequest}/commercial', [ProjectManagerController::class, 'updateBatchCommercial'])->name('pm.request-batches.commercial.update');
    Route::post('/pm/request-batches/{expenseRequest}/decision', [ProjectManagerController::class, 'decideBatch'])->name('pm.request-batches.decision');
    Route::get('/pm/request-batches/{expenseRequest}/procurement', [ProcurementWorkflowController::class, 'show'])->name('pm.procurement.show');
    Route::put('/pm/request-batches/{expenseRequest}/procurement/move', [ProcurementWorkflowController::class, 'move'])->name('pm.procurement.move');
    Route::post('/pm/request-batches/{expenseRequest}/supplier-groups', [ProcurementWorkflowController::class, 'assign'])->name('pm.procurement.groups.store');
    Route::put('/pm/supplier-groups/{supplierRequestGroup}/pricing', [ProcurementWorkflowController::class, 'price'])->name('pm.procurement.groups.price');
    Route::post('/pm/supplier-groups/{supplierRequestGroup}/decision', [ProcurementWorkflowController::class, 'decide'])->name('pm.procurement.groups.decision');
    Route::get('/pm/site-requests/{expenseRequest}/commercial-evidence', [ProjectManagerController::class, 'commercialEvidence'])->name('pm.requests.commercial.evidence');
    Route::get('/pm/suppliers', [SupplierPayeeController::class, 'index'])->name('pm.suppliers.index');
    Route::get('/pm/suppliers/create', [SupplierPayeeController::class, 'create'])->name('pm.suppliers.create');
    Route::post('/pm/suppliers', [SupplierPayeeController::class, 'store'])->name('pm.suppliers.store');
    Route::get('/pm/suppliers/{supplierPayee}/destinations', [SupplierPayeeController::class, 'destinations'])->name('pm.suppliers.destinations');
    Route::post('/pm/suppliers/{supplierPayee}/destinations', [SupplierPayeeController::class, 'storeDestination'])->name('pm.suppliers.destinations.store');

    Route::get('/accountant', [AccountantController::class, 'index'])->name('accountant.index');
    Route::get('/accountant/requests', [AccountantController::class, 'requests'])->name('accountant.requests.index');
    Route::get('/accountant/projects', [AccountantController::class, 'projects'])->name('accountant.projects.index');
    Route::get('/accountant/requests/{expenseRequest}', [AccountantController::class, 'show'])->name('accountant.requests.show');
    Route::post('/accountant/requests/{expenseRequest}/payments', [AccountantController::class, 'record'])->name('accountant.payments.store');
    Route::get('/accountant/payments/{expensePayment}/evidence', [AccountantController::class, 'evidence'])->name('accountant.payments.evidence');
    Route::get('/accountant/requests/{expenseRequest}/site-evidence', [AccountantController::class, 'siteEvidence'])->name('accountant.requests.site-evidence');
    Route::get('/accountant/projects/{project}', [AccountantController::class, 'project'])->name('accountant.projects.show');
    Route::get('/accountant/invoices', [AccountantController::class, 'invoices'])->name('accountant.invoices.index');
    Route::get('/accountant/projects/{project}/invoices/create', [AccountantController::class, 'createInvoice'])->name('accountant.invoices.create');
    Route::post('/accountant/projects/{project}/invoices', [AccountantController::class, 'storeInvoice'])->name('accountant.invoices.store');
    Route::get('/accountant/invoices/{clientInvoice}.xlsx', [AccountantController::class, 'exportInvoice'])->name('accountant.invoices.export');
    Route::get('/accountant/invoices/{clientInvoice}', [AccountantController::class, 'invoice'])->name('accountant.invoices.show');
    Route::post('/accountant/invoices/{clientInvoice}/issue', [AccountantController::class, 'issueInvoice'])->name('accountant.invoices.issue');
    Route::post('/accountant/invoices/{clientInvoice}/void', [AccountantController::class, 'voidInvoice'])->name('accountant.invoices.void');
    Route::post('/accountant/invoices/{clientInvoice}/payments', [AccountantController::class, 'clientPayment'])->name('accountant.client-payments.store');
    Route::get('/accountant/client-payments/{clientPayment}/evidence', [AccountantController::class, 'clientPaymentEvidence'])->name('accountant.client-payments.evidence');
    Route::get('/accountant/transactions', [AccountantController::class, 'transactions'])->name('accountant.transactions.index');
    Route::get('/accountant/transactions/export', [AccountantController::class, 'exportTransactions'])->name('accountant.transactions.export');
    Route::get('/accountant/projects/{project}/transactions', [AccountantController::class, 'projectTransactions'])->name('accountant.projects.transactions');
    Route::get('/accountant/projects/{project}/transactions/export', [AccountantController::class, 'exportProjectTransactions'])->name('accountant.projects.transactions.export');
    Route::get('/accountant/receivables', [AccountantController::class, 'receivables'])->name('accountant.receivables.index');
    Route::get('/accountant/receivables/export', [AccountantController::class, 'exportReceivables'])->name('accountant.receivables.export');
    Route::get('/accountant/payables', [AccountantController::class, 'payables'])->name('accountant.payables.index');
    Route::get('/accountant/payables/export', [AccountantController::class, 'exportPayables'])->name('accountant.payables.export');
    Route::get('/accountant/projects/{project}/statement', [AccountantController::class, 'statement'])->name('accountant.projects.statement');
    Route::get('/accountant/projects/{project}/opening-records', [OnboardingRecordsController::class, 'index'])->name('accountant.onboarding.index');
    Route::get('/accountant/projects/{project}/opening-records/template.xlsx', [OnboardingRecordsController::class, 'template'])->name('accountant.onboarding.template');
    Route::post('/accountant/projects/{project}/opening-records', [OnboardingRecordsController::class, 'store'])->name('accountant.onboarding.store');
    Route::get('/accountant/projects/{project}/opening-records/{onboardingImport}/file', [OnboardingRecordsController::class, 'download'])->name('accountant.onboarding.download');
    Route::post('/accountant/projects/{project}/opening-records/{onboardingImport}/undo', [OnboardingRecordsController::class, 'undo'])->name('accountant.onboarding.undo');
    Route::get('/pm/projects/{project}', [ProjectManagerController::class, 'show'])->name('pm.projects.show');
    Route::post('/pm/projects/{project}/budgets', [ProjectManagerController::class, 'startBudget'])->name('pm.budgets.start');
    Route::get('/pm/projects/{project}/budgets/{budget}', [ProjectManagerController::class, 'showBudget'])->whereNumber('budget')->name('pm.budgets.show');
    Route::post('/pm/projects/{project}/budgets/{budget}/edit', [ProjectManagerController::class, 'startBudgetEdit'])->name('pm.budgets.edit');
    Route::post('/pm/projects/{project}/budgets/{budget}/finalize', [ProjectManagerController::class, 'finalize'])->name('pm.budgets.finalize');
    Route::get('/pm/projects/{project}/budgets/{budget}.xlsx', [ProjectManagerController::class, 'exportBudget'])->name('pm.budgets.export');
    Route::get('/pm/projects/{project}/phases/{phase}/budget-entries/create', [ProjectManagerController::class, 'createEntry'])->name('pm.budget-entries.create');
    Route::post('/pm/projects/{project}/phases/{phase}/budget-entries', [ProjectManagerController::class, 'storeEntry'])->name('pm.budget-entries.store');
    Route::get('/pm/projects/{project}/phases/{phase}/budget-entries/{entry}/edit', [ProjectManagerController::class, 'editEntry'])->name('pm.budget-entries.edit');
    Route::put('/pm/projects/{project}/phases/{phase}/budget-entries/{entry}', [ProjectManagerController::class, 'updateEntry'])->name('pm.budget-entries.update');
    Route::delete('/pm/projects/{project}/phases/{phase}/budget-entries/{entry}', [ProjectManagerController::class, 'deleteEntry'])->name('pm.budget-entries.delete');

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

    Route::get('/projects', [DashboardController::class, 'index'])->name('dashboard.index');
    Route::get('/projects/{project}', [DashboardController::class, 'show'])->name('dashboard.show');

    Route::post('/entries/transactions', [EntryController::class, 'storeTransaction']);
    Route::post('/entries/labour-agreements', [EntryController::class, 'storeAgreement']);
});
