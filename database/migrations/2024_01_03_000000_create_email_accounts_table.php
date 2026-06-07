<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label');                          // e.g. "Work - info@medequip.tz"

            // IMAP (incoming)
            $table->string('imap_host');
            $table->unsignedSmallInteger('imap_port')->default(993);
            $table->enum('imap_encryption', ['ssl', 'tls', 'starttls', 'none'])->default('ssl');

            // SMTP (outgoing)
            $table->string('smtp_host');
            $table->unsignedSmallInteger('smtp_port')->default(465);
            $table->enum('smtp_encryption', ['ssl', 'tls', 'starttls', 'none'])->default('ssl');

            // Credentials (shared between IMAP & SMTP)
            $table->string('username');                       // usually the email address
            $table->text('password');                         // encrypted at model level
            $table->string('from_name')->nullable();
            $table->string('from_email');

            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('synced_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_account_id')->constrained()->cascadeOnDelete();
            $table->string('uid');                            // IMAP UID
            $table->string('message_id')->nullable();         // RFC Message-ID header
            $table->string('folder', 100)->default('INBOX');
            $table->string('subject')->nullable();
            $table->string('from_email');
            $table->string('from_name')->nullable();
            $table->json('to_addresses');                     // [{"email":"..","name":".."}]
            $table->json('cc_addresses')->nullable();
            $table->json('bcc_addresses')->nullable();
            $table->longText('body_html')->nullable();
            $table->longText('body_text')->nullable();
            $table->string('in_reply_to')->nullable();        // Message-ID this replies to
            $table->boolean('is_read')->default(false);
            $table->boolean('is_flagged')->default(false);
            $table->boolean('is_draft')->default(false);
            $table->boolean('has_attachments')->default(false);
            $table->timestamp('email_date')->nullable();      // Date from the email header
            $table->timestamps();

            $table->unique(['email_account_id', 'uid', 'folder']);
            $table->index(['email_account_id', 'folder', 'email_date']);
        });

        Schema::create('synced_email_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('synced_email_id')->constrained()->cascadeOnDelete();
            $table->string('filename');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);   // bytes
            $table->string('storage_path')->nullable();       // if downloaded locally
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('synced_email_attachments');
        Schema::dropIfExists('synced_emails');
        Schema::dropIfExists('email_accounts');
    }
};
