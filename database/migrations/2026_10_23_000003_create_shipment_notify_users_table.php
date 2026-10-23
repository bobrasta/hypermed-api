<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Shipments, 2026-10-23: people picked on the shipment to be notified of its
// creation and every status change, on top of the role-based list and the
// department manager. They can also open that shipment.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_notify_users', function (Blueprint $t) {
            $t->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->primary(['shipment_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_notify_users');
    }
};
