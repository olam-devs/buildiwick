<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_payments', function (Blueprint $table): void {
            $table->uuid('idempotency_key')->nullable()->unique();
        });

        Schema::table('client_payments', function (Blueprint $table): void {
            $table->uuid('idempotency_key')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('expense_payments', function (Blueprint $table): void {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });

        Schema::table('client_payments', function (Blueprint $table): void {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};