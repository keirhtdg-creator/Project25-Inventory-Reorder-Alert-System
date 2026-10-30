<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 40)->unique();
            $table->string('name');
            $table->string('unit', 30);
            $table->decimal('on_hand_quantity', 14, 3)->default(0);
            $table->decimal('reorder_threshold', 14, 3);
            $table->decimal('suggested_reorder_quantity', 14, 3);
            $table->boolean('is_approved')->default(true);
            $table->timestamps();
        });

        Schema::create('stock_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('inventory_items');
            $table->string('transaction_type', 20);
            $table->decimal('quantity_delta', 14, 3);
            $table->decimal('resulting_quantity', 14, 3);
            $table->string('reason')->nullable();
            $table->string('actor_label')->default('Demo user');
            $table->timestamps();
            $table->index(['item_id', 'created_at']);
        });

        Schema::create('inventory_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('inventory_items');
            $table->unsignedInteger('episode_number');
            $table->string('status', 20)->default('open');
            $table->timestamp('triggered_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['item_id', 'episode_number']);
        });

        Schema::create('reorder_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('inventory_items');
            $table->foreignId('source_alert_id')->unique()->constrained('inventory_alerts');
            $table->decimal('suggested_quantity', 14, 3);
            $table->decimal('received_quantity', 14, 3)->default(0);
            $table->string('status', 30)->default('pending_review');
            $table->string('review_note')->nullable();
            $table->string('resolution_note')->nullable();
            $table->timestamp('status_changed_at');
            $table->timestamps();
            $table->index(['status', 'status_changed_at']);
        });

        Schema::create('workflow_events', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->string('event_type', 80);
            $table->string('actor_label')->default('Demo user');
            $table->string('previous_state')->nullable();
            $table->string('current_state')->nullable();
            $table->json('details')->nullable();
            $table->string('correlation_id', 64)->nullable()->index();
            $table->timestamps();
            $table->index(['subject_type', 'subject_id', 'created_at']);
        });

        Schema::create('escalation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reorder_request_id')->constrained();
            $table->unsignedInteger('escalation_level')->default(1);
            $table->string('reason');
            $table->timestamp('escalated_at');
            $table->timestamps();
            $table->unique(['reorder_request_id', 'escalation_level']);
        });

        Schema::create('weekly_stock_summaries', function (Blueprint $table) {
            $table->id();
            $table->date('week_start');
            $table->date('week_end');
            $table->unsignedInteger('risk_item_count')->default(0);
            $table->timestamp('generated_at');
            $table->timestamps();
            $table->unique(['week_start', 'week_end']);
        });

        Schema::create('weekly_stock_summary_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('summary_id')->constrained('weekly_stock_summaries')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('inventory_items');
            $table->decimal('on_hand_quantity', 14, 3);
            $table->decimal('reorder_threshold', 14, 3);
            $table->string('reorder_status', 30)->nullable();
            $table->timestamps();
            $table->unique(['summary_id', 'item_id']);
        });

        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('automation_type', 40);
            $table->string('idempotency_key', 120)->unique();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->string('status', 20)->default('running');
            $table->text('last_error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('manual_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reorder_request_id')->nullable()->constrained();
            $table->foreignId('automation_run_id')->nullable()->constrained();
            $table->string('assigned_role', 40)->default('supervisor');
            $table->string('reason');
            $table->string('status', 20)->default('open');
            $table->text('resolution_note')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'assigned_role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_actions');
        Schema::dropIfExists('automation_runs');
        Schema::dropIfExists('weekly_stock_summary_items');
        Schema::dropIfExists('weekly_stock_summaries');
        Schema::dropIfExists('escalation_events');
        Schema::dropIfExists('workflow_events');
        Schema::dropIfExists('reorder_requests');
        Schema::dropIfExists('inventory_alerts');
        Schema::dropIfExists('stock_transactions');
        Schema::dropIfExists('inventory_items');
    }
};
