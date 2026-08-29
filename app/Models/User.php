<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\QueuedResetPasswordNotification;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'google_id',
        'puskesmas_id',
        // Cuma relevan utk staf (admin_puskesmas/pj_prolanis/super_admin) -- kader/tenaga_kesehatan
        // punya status_aktif sendiri di tabelnya masing-masing, kolom ini TIDAK dipakai untuk itu
        // (lihat StaffService::setActive()).
        'status_aktif',
        'no_hp',
        // Profil staf non-kader (admin_puskesmas/pj_prolanis) -- diisi saat onboarding step
        // "Lengkapi Profil", field SAMA dengan tabel kader tapi utk role yang tidak punya baris
        // kader (AuthController::completeOnboarding).
        'no_wa',
        'alamat',
        'gender',
        'tgl_lahir',
        'must_change_password',
        'avatar_path',
        'email_notifications_enabled',
        'onboarding_completed_at',
        'tos_accepted_at',
    ];

    /**
     * avatar_url dihitung SELALU (bukan dipilih per-request) supaya frontend tidak perlu tahu
     * kapan harus minta URL terpisah -- setiap kali User ter-serialize (raw model, BUKAN lewat
     * Resource -- /auth/me, login/refresh, upload/update profil semua balikin $user langsung)
     * otomatis ikut avatar_url. avatar_path sendiri (key S3/MinIO mentah) TETAP disembunyikan
     * dari kegunaan langsung di frontend (docs/planning/02 §5, bucket privat -- signed URL
     * bukan link publik permanen, sengaja short-lived).
     *
     * @var array<int, string>
     */
    protected $appends = ['avatar_url'];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        // 'roles'/'permissions' (relasi bawaan Spatie HasRoles) SENGAJA disembunyikan dari
        // serialisasi model -- kalau tidak, memanggil getRoleNames()/hasRole() di mana pun
        // (mis. AuthController) lazy-load & cache relasi itu ke instance model, lalu ikut
        // "bocor" sebagai object Role penuh (termasuk pivot model_type/model_id) begitu model
        // User yang SAMA ikut di-serialize di response yang sama. Ketahuan lewat dump JSON asli
        // saat verifikasi field 'roles' baru di AuthController. Tidak menghalangi getRoleNames()
        // dipakai di tempat lain (Resource tetap bisa akses relasi loaded via whenLoaded()).
        'roles',
        'permissions',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'tgl_lahir' => 'date',
            'status_aktif' => 'boolean',
            'must_change_password' => 'boolean',
            'email_notifications_enabled' => 'boolean',
            'onboarding_completed_at' => 'datetime',
            'tos_accepted_at' => 'datetime',
        ];
    }

    /**
     * Signed URL sementara (30 menit) ke avatar_path -- disk 's3' (produli.storage.visit_photos_disk)
     * privat, jadi TIDAK ADA link publik permanen. Frontend cukup pakai avatar_url apa adanya
     * di <img>, tidak perlu tahu proses signing atau minta ulang manual -- URL baru otomatis
     * ter-generate tiap kali User ini di-serialize lagi (mis. lain kali /auth/me dipanggil).
     */
    protected function avatarUrl(): Attribute
    {
        return Attribute::make(
            get: function () {
                if ($this->avatar_path === null) {
                    return null;
                }

                $disk = (string) config('produli.storage.visit_photos_disk', 's3');

                return Storage::disk($disk)->temporaryUrl($this->avatar_path, now()->addMinutes(30));
            },
        );
    }

    public function refreshTokens(): HasMany
    {
        return $this->hasMany(RefreshToken::class);
    }

    public function accountActivations(): HasMany
    {
        return $this->hasMany(AccountActivation::class);
    }

    public function puskesmas(): BelongsTo
    {
        return $this->belongsTo(Puskesmas::class);
    }

    public function kader(): HasOne
    {
        return $this->hasOne(Kader::class);
    }

    public function tenagaKesehatan(): HasOne
    {
        return $this->hasOne(TenagaKesehatan::class);
    }

    public function pengantarSampel(): HasOne
    {
        return $this->hasOne(PengantarSampel::class);
    }

    public function fcmTokens(): HasMany
    {
        return $this->hasMany(FcmToken::class);
    }

    /**
     * Kader yang disupervisi user ini sebagai PJ Prolanis (kader.pj_id -> users.id).
     */
    public function supervisedKader(): HasMany
    {
        return $this->hasMany(Kader::class, 'pj_id');
    }

    /**
     * Override bawaan CanResetPassword -- pakai versi ShouldQueue (QueuedResetPasswordNotification)
     * supaya POST /api/v1/auth/forgot-password tidak blocking nunggu SMTP selesai, konsisten
     * dengan AccountActivationMail yang juga queued.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new QueuedResetPasswordNotification($token));
    }
}
