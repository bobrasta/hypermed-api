<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Letters on the Hypermed letterhead, each with the next reference number
// (HHL/2026/0001); the signatory's own signature goes on only when they sign
// it; the company stamp is optional per letter. Signature/stamp images are
// kept on the private disk, never served as public files.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('letters', function (Blueprint $t) {
            $t->id();
            $t->string('reference_number', 40)->unique();
            $t->date('letter_date');
            $t->text('recipient');
            $t->string('subject', 300);
            $t->longText('body');
            $t->foreignId('signatory_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('signatory_position', 120)->nullable();
            $t->timestamp('signed_at')->nullable();
            $t->boolean('include_stamp')->default(false);
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
        });
        if (! Schema::hasColumn('users', 'signature_path')) {
            Schema::table('users', fn (Blueprint $t) => $t->string('signature_path')->nullable());
        }
        DB::table('document_sequences')->updateOrInsert(['document_type' => 'letter'], [
            'label' => 'Letter', 'prefix' => 'HHL', 'format' => 'HHL/{Y}/{N}', 'digits' => 4, 'reset_yearly' => true,
            'next_number' => 1, 'year' => (int) now()->format('Y'), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('letters');
    }
};
