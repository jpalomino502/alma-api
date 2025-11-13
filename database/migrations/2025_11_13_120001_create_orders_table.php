<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->json('items');
            $table->integer('subtotal');
            $table->integer('taxes')->default(0);
            $table->integer('total');
            $table->string('status')->default('pending_payment');
            $table->string('epayco_ref')->nullable();
            $table->string('epayco_invoice')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};