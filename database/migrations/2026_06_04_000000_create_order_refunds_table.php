<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v2_order_refunds', function (Blueprint $table) {
            $table->id();
            $table->integer('order_id')->index();
            $table->integer('user_id')->index();
            $table->string('trade_no', 36)->index();
            $table->integer('amount')->comment('Refund amount in minor currency units');
            $table->string('method', 16)->comment('gateway or balance');
            $table->string('payment_plugin', 64)->nullable();
            $table->integer('payment_id')->nullable();
            $table->string('gateway_refund_id')->nullable();
            $table->string('status', 16)->default('succeeded');
            $table->boolean('revoked_access')->default(false);
            $table->integer('admin_id')->nullable();
            $table->text('note')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');
        });

        Schema::table('v2_order', function (Blueprint $table) {
            $table->unsignedTinyInteger('refund_status')->default(0)->after('status')
                ->comment('0 none, 1 partial, 2 full');
        });
    }

    public function down(): void
    {
        Schema::table('v2_order', function (Blueprint $table) {
            $table->dropColumn('refund_status');
        });

        Schema::dropIfExists('v2_order_refunds');
    }
};
