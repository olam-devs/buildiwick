<?php

/*
 * Product features switched on or off for the current rollout phase.
 *
 * The client starts with "recording" only: Director, PM and Accountant record what happens on site
 * (no approvals, no estimator, no site operator, no client billing). The workflow code stays in place
 * so later phases can switch areas back on without a rewrite. Tests run with every feature on
 * (see phpunit.xml) unless a test switches recording mode on itself.
 */
$recording = env('BUILDIWICK_MODE', 'recording') === 'recording';

return [
    'mode' => $recording ? 'recording' : 'full',

    'features' => [
        // Client invoices, client payments/receipts, receivables, cash balance and estimated client value.
        'client_billing' => ! $recording,
        // Estimator/QS role, client estimates and estimate approval.
        'estimates' => ! $recording,
        // Site Operator role and site need reports.
        'site_operator' => ! $recording,
        // PM request → approval → Accounts payment workflow and PM-submitted budgets awaiting Director approval.
        'approvals' => ! $recording,
    ],
];
