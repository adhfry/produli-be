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
        Schema::create('account_activations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // SHA-256 hex token mentah -- pola sama persis seperti refresh_tokens.token_hash,
            // token asli TIDAK PERNAH disimpan, cuma dikirim sekali lewat email aktivasi.
            $table->string('token_hash', 64)->unique();
            // Default 7 hari dihitung di AccountActivationService saat create (bukan DB default
            // statis -- sama pola seperti refresh_tokens.expires_at di AuthTokenService).
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            // Siapa yang mendaftarkan (PJ/admin_puskesmas/super_admin) -- audit trail.
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_activations');
    }
};
