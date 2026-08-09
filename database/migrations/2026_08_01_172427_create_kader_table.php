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
        Schema::create('kader', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            // FK ke users.id (BUKAN kader.id) — PJ Prolanis murni supervisor (tidak pernah
            // kunjungan sendiri) tidak akan punya baris di tabel kader ini, jadi self-referencing
            // FK tidak punya target valid untuknya. PJ yang KEBETULAN merangkap kader tetap bisa
            // direpresentasikan (pj_id = user_id PJ tsb, terlepas apakah PJ itu juga punya baris kader).
            $table->foreignId('pj_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('puskesmas_id')->constrained('puskesmas')->restrictOnDelete();
            $table->boolean('status_aktif')->default(true);
            $table->string('no_hp', 20)->nullable();
            $table->string('no_wa', 20)->nullable();
            $table->text('alamat')->nullable();
            $table->string('gender', 1)->nullable();
            $table->date('tgl_lahir')->nullable();
            $table->timestamps();

            // email SENGAJA tidak diduplikasi di sini — sudah ada di users.email (relasi user_id).
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kader');
    }
};
