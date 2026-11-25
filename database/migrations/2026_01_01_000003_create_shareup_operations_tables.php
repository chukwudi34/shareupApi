<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('distributions', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->string('type')->default('FINANCIAL');
            $table->string('status')->default('DRAFT');
            $table->foreignId('opportunity_id')->nullable()->constrained();
            $table->foreignId('asset_id')->nullable()->constrained();
            $table->string('title');
            $table->unsignedBigInteger('gross_amount')->default(0);
            $table->unsignedBigInteger('expense_amount')->default(0);
            $table->unsignedBigInteger('net_amount')->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users');
            $table->foreignId('approved_by_id')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();
            $table->index('status');
        });

        Schema::create('distribution_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('distribution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->unsignedInteger('units')->default(0);
            $table->unsignedInteger('quantity')->default(0);
            $table->unsignedBigInteger('amount')->default(0);
            $table->string('status')->default('PENDING');
            $table->string('provider_ref')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();
            $table->index('distribution_id');
        });

        Schema::create('fulfillments', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('distribution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('participation_id')->nullable()->constrained();
            $table->string('method')->default('PICKUP');
            $table->unsignedInteger('quantity_units')->default(0);
            $table->string('address')->nullable();
            $table->string('status')->default('PENDING');
            $table->string('tracking_ref')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->index('distribution_id');
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('opportunity_id')->constrained();
            $table->foreignId('participation_id')->nullable()->constrained();
            $table->foreignId('transaction_id')->nullable()->constrained();
            $table->unsignedBigInteger('amount');
            $table->string('reason');
            $table->text('detail')->nullable();
            $table->string('status')->default('REQUESTED');
            $table->foreignId('requested_by_id')->nullable()->constrained('users');
            $table->foreignId('approved_by_id')->nullable()->constrained('users');
            $table->string('provider_ref')->nullable();
            $table->text('evidence')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index('status');
        });

        Schema::create('supplier_payouts', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('opportunity_id')->constrained();
            $table->unsignedBigInteger('amount');
            $table->string('status')->default('AWAITING_APPROVAL');
            $table->foreignId('requested_by_id')->nullable()->constrained('users');
            $table->foreignId('approved_by_id')->nullable()->constrained('users');
            $table->string('provider_ref')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('entity_id');
            $table->string('summary');
            $table->unsignedBigInteger('amount')->nullable();
            $table->text('payload')->nullable();
            $table->string('status')->default('PENDING');
            $table->foreignId('requested_by_id')->constrained('users');
            $table->foreignId('approved_by_id')->nullable()->constrained('users');
            $table->text('reason')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index('status');
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users');
            $table->string('action');
            $table->string('entity');
            $table->string('entity_id');
            $table->string('reason')->nullable();
            $table->text('before')->nullable();
            $table->text('after')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['entity', 'entity_id']);
            $table->index('created_at');
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('body');
            $table->string('kind')->default('INFO');
            $table->string('href')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'read_at']);
        });

        Schema::create('waitlist_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opportunity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
            $table->unique(['opportunity_id', 'user_id']);
        });

        Schema::create('platform_configs', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value');
            $table->unsignedInteger('version')->default(1);
            $table->string('description')->nullable();
            $table->foreignId('updated_by_id')->nullable()->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_configs');
        Schema::dropIfExists('waitlist_entries');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('approvals');
        Schema::dropIfExists('supplier_payouts');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('fulfillments');
        Schema::dropIfExists('distribution_items');
        Schema::dropIfExists('distributions');
    }
};
