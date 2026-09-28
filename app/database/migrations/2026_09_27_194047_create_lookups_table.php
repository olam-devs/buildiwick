<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One managed table for every dropdown list from the Excel's "Lists & Settings" tab:
     * transaction_type, main_category, subcategory, unit, payment_status, payment_method,
     * data_status, labour_status, construction_stage, project_status.
     * Subcategory rows set parent_code to their main_category's code (e.g. "materials").
     */
    public function up(): void
    {
        Schema::create('lookups', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('code');
            $table->string('label');
            $table->string('parent_type')->nullable();
            $table->string('parent_code')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['type', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lookups');
    }
};
