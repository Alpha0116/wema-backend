<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Merchants Table
        Schema::create('merchants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('channel_user_id')->unique(); // WhatsApp phone number / Meta ID
            $table->string('name');
            $table->string('language')->default('fr'); // fr, fon, etc.
            $table->timestamps();
        });

        // 2. Customers Table
        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->string('name');
            $table->json('aliases')->nullable(); // list of nicknames/aliases
            $table->integer('balance')->default(0); // in FCFA (positive = owes money, credit)
            $table->timestamps();
        });

        // 3. Products Table
        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->string('name');
            $table->json('aliases')->nullable(); // vocal variants/aliases
            $table->integer('unit_price')->default(0); // in FCFA
            $table->integer('stock')->default(0);
            $table->timestamps();
        });

        // 4. Messages Table
        Schema::create('messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->string('external_id')->nullable()->index(); // Meta WhatsApp message ID
            $table->string('audio_url')->nullable();
            $table->text('transcript')->nullable();
            $table->float('confidence')->nullable(); // Confidence score 0.00 to 1.00
            $table->enum('status', ['RECEIVED', 'TRANSCRIBED', 'PARSED', 'FAILED'])->default('RECEIVED');
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
        });

        // 5. Transactions Table
        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignUuid('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignUuid('message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->enum('type', ['SALE', 'PAYMENT', 'RESTOCK', 'EXPENSE'])->default('SALE');
            $table->integer('total_amount')->default(0);
            $table->integer('paid_amount')->default(0);
            $table->enum('status', ['PENDING', 'CONFIRMED', 'REJECTED'])->default('CONFIRMED');
            $table->timestamps();
        });

        // 6. Transaction Items Table
        Schema::create('transaction_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->foreignUuid('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->integer('quantity')->default(1);
            $table->integer('unit_price')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaction_items');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('products');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('merchants');
    }
};
