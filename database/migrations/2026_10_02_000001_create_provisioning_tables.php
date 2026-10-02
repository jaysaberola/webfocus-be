<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provisioning_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_transaction_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('timeline', 20)->default('standard');
            $table->unsignedSmallInteger('duration_hours')->nullable();
            $table->unsignedTinyInteger('webdev_days')->nullable();
            $table->string('status', 30)->default('provisioning')->index();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('countdown_started_at')->nullable();
            $table->foreignId('countdown_started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('webdev_started_at')->nullable();
            $table->foreignId('webdev_started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('provisioning_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provisioning_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_service_id')->nullable()->constrained('customer_services')->nullOnDelete();
            $table->string('service_name');
            $table->string('service_kind', 20)->default('standard');
            $table->text('description');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedTinyInteger('checkpoint_hours')->default(12);
            $table->timestamp('due_at')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('provisioning_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provisioning_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 60);
            $table->text('summary');
            $table->json('changes')->nullable();
            $table->timestamps();
            $table->index(['sales_transaction_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioning_events');
        Schema::dropIfExists('provisioning_actions');
        Schema::dropIfExists('provisioning_runs');
    }
};
