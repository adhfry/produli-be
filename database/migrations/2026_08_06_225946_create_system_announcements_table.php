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
        Schema::create('system_announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title', 150);
            $table->text('description');
            $table->enum('type', ['info', 'success', 'warning'])->default('info');
            // Nullable + nullOnDelete (pola sama seperti validated_by/assigned_by dkk) --
            // pengumuman tetap ada sebagai jejak meski akun super_admin yang memostingnya
            // kemudian dihapus.
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_announcements');
    }
};
