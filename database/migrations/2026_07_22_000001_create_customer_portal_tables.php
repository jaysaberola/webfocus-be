<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customer_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('sales_transaction_id')->nullable()->constrained('sales_transactions')->nullOnDelete();
            $table->string('title', 160);
            $table->string('category', 80);
            $table->string('plan', 160);
            $table->string('status', 40)->default('Provisioning')->index();
            $table->string('renew_label', 40)->default('Renews');
            $table->timestamp('renew_at')->nullable();
            $table->string('renew_note', 255)->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
        });

        Schema::create('customer_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 180);
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->string('type', 40)->default('general')->index();
            $table->string('action_url', 255)->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'read_at']);
        });

        Schema::create('customer_support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->string('ticket_no', 40)->unique();
            $table->string('subject', 255);
            $table->string('status', 40)->default('Open')->index();
            $table->text('message')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_support_tickets');
        Schema::dropIfExists('customer_notifications');
        Schema::dropIfExists('customer_services');
    }
};
