<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('type');
            $table->string('description')->nullable();
            $table->boolean('is_system')->default(true);
            $table->timestamps();
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->string('kind');
            $table->string('description');
            $table->foreignId('opportunity_id')->nullable()->constrained();
            $table->foreignId('posted_by_id')->nullable()->constrained('users');
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->index('opportunity_id');
        });

        Schema::create('ledger_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ledger_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ledger_account_id')->constrained();
            $table->string('direction');
            $table->unsignedBigInteger('amount');
            $table->timestamps();
            $table->index('ledger_account_id');
        });

        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('opportunity_id')->nullable()->constrained();
            $table->string('name');
            $table->string('category');
            $table->text('description');
            $table->string('cover_image');
            $table->unsignedBigInteger('acquisition_price');
            $table->unsignedBigInteger('current_valuation')->nullable();
            $table->string('valuation_source')->nullable();
            $table->timestamp('valuation_at')->nullable();
            $table->timestamp('acquisition_date')->nullable();
            $table->string('location');
            $table->string('legal_structure');
            $table->string('custodian');
            $table->string('status')->default('PROPOSED');
            $table->unsignedInteger('total_units')->default(0);
            $table->text('documents_note')->nullable();
            $table->timestamps();
            $table->index('status');
        });

        Schema::create('ownership_interests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('participation_id')->nullable()->unique()->constrained();
            $table->unsignedInteger('units');
            $table->unsignedBigInteger('cost_basis');
            $table->string('ownership_type');
            $table->timestamps();
            $table->index('asset_id');
            $table->index('user_id');
        });

        Schema::create('asset_incomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained();
            $table->string('source');
            $table->string('category');
            $table->unsignedBigInteger('amount');
            $table->boolean('verified')->default(false);
            $table->timestamp('received_at')->nullable();
            $table->foreignId('recorded_by_id')->nullable()->constrained('users');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('asset_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained();
            $table->string('category');
            $table->string('description');
            $table->unsignedBigInteger('amount');
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('approved_by_id')->nullable()->constrained('users');
            $table->string('receipt_url')->nullable();
            $table->timestamps();
        });

        Schema::create('asset_valuations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained();
            $table->unsignedBigInteger('value');
            $table->string('source');
            $table->boolean('verified')->default(false);
            $table->timestamps();
        });

        Schema::create('asset_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained();
            $table->string('title');
            $table->string('kind')->default('UPDATE');
            $table->text('body');
            $table->string('file_url')->nullable();
            $table->boolean('verified')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opportunity_id')->nullable()->constrained();
            $table->foreignId('asset_id')->nullable()->constrained();
            $table->string('name');
            $table->string('sku')->unique();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('reserved')->default(0);
            $table->string('unit_label');
            $table->string('location');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('asset_reports');
        Schema::dropIfExists('asset_valuations');
        Schema::dropIfExists('asset_expenses');
        Schema::dropIfExists('asset_incomes');
        Schema::dropIfExists('ownership_interests');
        Schema::dropIfExists('assets');
        Schema::dropIfExists('ledger_lines');
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('ledger_accounts');
    }
};
