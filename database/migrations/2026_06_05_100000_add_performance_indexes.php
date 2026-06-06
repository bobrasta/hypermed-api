<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── hospitals ────────────────────────────────────────────────────────
        Schema::table('hospitals', function (Blueprint $table) {
            $table->index('type');
            $table->index('region');
            $table->index('zone');
            $table->index('revenue_monthly');
        });

        // ── machines ─────────────────────────────────────────────────────────
        // PostgreSQL does NOT auto-index FK columns — every JOIN/WHERE is a seq scan without these
        Schema::table('machines', function (Blueprint $table) {
            $table->index('hospital_id');
            $table->index('status');
            $table->index('type');
            $table->index('warranty_expiry');
        });

        // ── service_tickets ──────────────────────────────────────────────────
        Schema::table('service_tickets', function (Blueprint $table) {
            $table->index('machine_id');
            $table->index('hospital_id');
            $table->index('assigned_to');
            $table->index('status');
            $table->index('created_at');
        });

        // ── invoices ─────────────────────────────────────────────────────────
        Schema::table('invoices', function (Blueprint $table) {
            $table->index('hospital_id');
            $table->index('machine_id');
            $table->index('status');
            $table->index('issue_date');
        });

        // ── sales_leads ──────────────────────────────────────────────────────
        Schema::table('sales_leads', function (Blueprint $table) {
            $table->index('hospital_id');
            $table->index('assigned_to');
            $table->index('stage');
        });

        // ── contacts ─────────────────────────────────────────────────────────
        Schema::table('contacts', function (Blueprint $table) {
            $table->index('hospital_id');
        });

        // ── contact_tags ─────────────────────────────────────────────────────
        Schema::table('contact_tags', function (Blueprint $table) {
            $table->index('tag');
        });

        // ── notifications ─────────────────────────────────────────────────────
        // Composite covers both the per-user list query and the readAll update
        Schema::table('notifications', function (Blueprint $table) {
            $table->index(['user_id', 'is_read']);
        });
    }

    public function down(): void
    {
        Schema::table('hospitals', function (Blueprint $table) {
            $table->dropIndex(['type']);
            $table->dropIndex(['region']);
            $table->dropIndex(['zone']);
            $table->dropIndex(['revenue_monthly']);
        });

        Schema::table('machines', function (Blueprint $table) {
            $table->dropIndex(['hospital_id']);
            $table->dropIndex(['status']);
            $table->dropIndex(['type']);
            $table->dropIndex(['warranty_expiry']);
        });

        Schema::table('service_tickets', function (Blueprint $table) {
            $table->dropIndex(['machine_id']);
            $table->dropIndex(['hospital_id']);
            $table->dropIndex(['assigned_to']);
            $table->dropIndex(['status']);
            $table->dropIndex(['created_at']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['hospital_id']);
            $table->dropIndex(['machine_id']);
            $table->dropIndex(['status']);
            $table->dropIndex(['issue_date']);
        });

        Schema::table('sales_leads', function (Blueprint $table) {
            $table->dropIndex(['hospital_id']);
            $table->dropIndex(['assigned_to']);
            $table->dropIndex(['stage']);
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex(['hospital_id']);
        });

        Schema::table('contact_tags', function (Blueprint $table) {
            $table->dropIndex(['tag']);
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'is_read']);
        });
    }
};
