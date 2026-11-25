<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name')->default('api');
            $table->string('token_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index('user_id');
        });

        Schema::create('opportunities', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->text('summary');
            $table->longText('description');
            $table->string('cover_image');
            $table->string('category');
            $table->string('model')->default('COLLECTIVE_PURCHASE');
            $table->string('participation_method')->default('AMOUNT');
            $table->string('status')->default('DRAFT');
            $table->foreignId('creator_id')->constrained('users');
            $table->string('creator_source')->default('PLATFORM');
            $table->string('supplier_name');
            $table->string('supplier_contact')->nullable();
            $table->boolean('supplier_verified')->default(false);
            $table->unsignedBigInteger('target_amount');
            $table->unsignedBigInteger('committed_amount')->default(0);
            $table->unsignedBigInteger('target_quantity')->nullable();
            $table->string('quantity_label')->nullable();
            $table->unsignedBigInteger('unit_price')->nullable();
            $table->unsignedBigInteger('total_units')->nullable();
            $table->unsignedBigInteger('units_committed')->default(0);
            $table->unsignedInteger('units_per_package')->nullable();
            $table->string('package_label')->nullable();
            $table->unsignedBigInteger('min_contribution');
            $table->unsignedBigInteger('max_contribution')->nullable();
            $table->unsignedInteger('min_units')->nullable();
            $table->unsignedInteger('max_units')->nullable();
            $table->unsignedInteger('max_participants')->nullable();
            $table->unsignedInteger('participant_count')->default(0);
            $table->timestamp('funding_deadline');
            $table->timestamp('expected_acquisition_date')->nullable();
            $table->timestamp('estimated_delivery_date')->nullable();
            $table->string('location');
            $table->unsignedInteger('fee_bps')->default(350);
            $table->unsignedInteger('policy_version')->default(1);
            $table->text('policy_terms')->nullable();
            $table->boolean('allows_partial_funding')->default(false);
            $table->boolean('allows_overfunding')->default(false);
            $table->boolean('allow_cancellation')->default(true);
            $table->boolean('waiting_list_enabled')->default(false);
            $table->boolean('allows_increases')->default(true);
            $table->unsignedInteger('reservation_minutes')->default(30);
            $table->string('funding_policy')->default('ALL_OR_NOTHING');
            $table->string('refund_policy')->default('FULL_BEFORE_ACQUISITION');
            $table->text('risk_notes');
            $table->text('acquisition_conditions');
            $table->string('legal_structure')->nullable();
            $table->string('regulatory_status')->default('NOT_REQUIRED');
            $table->text('regulatory_notes')->nullable();
            $table->string('ownership_type')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('funded_at')->nullable();
            $table->timestamp('acquired_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index('status');
            $table->index('category');
            $table->index('model');
        });

        Schema::create('opportunity_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opportunity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewer_id')->constrained('users');
            $table->string('decision');
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('opportunity_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opportunity_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('kind')->default('OTHER');
            $table->string('file_url');
            $table->boolean('verified')->default(false);
            $table->timestamp('uploaded_at')->nullable();
        });

        Schema::create('acquisition_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opportunity_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('file_url');
            $table->unsignedBigInteger('amount')->default(0);
            $table->string('vendor');
            $table->string('reference');
            $table->foreignId('verified_by_id')->nullable()->constrained('users');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('participations', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('opportunity_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->unsignedBigInteger('amount');
            $table->unsignedInteger('units')->default(0);
            $table->unsignedInteger('quantity')->default(0);
            $table->string('status')->default('PENDING');
            $table->string('idempotency_key')->unique();
            $table->unsignedInteger('entitlement_units')->default(0);
            $table->unsignedBigInteger('refunded_amount')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamps();
            $table->index(['user_id']);
            $table->index(['opportunity_id', 'status']);
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('opportunity_id')->nullable()->constrained();
            $table->foreignId('participation_id')->nullable()->constrained();
            $table->string('type');
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3)->default('NGN');
            $table->string('status')->default('PENDING');
            $table->string('provider')->default('SANDBOX');
            $table->string('provider_ref')->nullable();
            $table->string('idempotency_key')->unique();
            $table->text('metadata')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->index(['opportunity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('participations');
        Schema::dropIfExists('acquisition_evidence');
        Schema::dropIfExists('opportunity_documents');
        Schema::dropIfExists('opportunity_reviews');
        Schema::dropIfExists('opportunities');
        Schema::dropIfExists('api_tokens');
    }
};
