<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Profil Saya & Pengaturan, semua role (docs/planning/02 §17) -- BUKAN pengganti
 * KaderService::updateOwnProfile() (itu tetap khusus field kader: no_wa/alamat/gender/
 * tgl_lahir), ini untuk field yang berlaku semua role.
 */
class ProfileService
{
    /**
     * Reuse disk S3/MinIO yang SAMA dengan foto kunjungan (produli.storage.visit_photos_disk)
     * -- bukan disk terpisah, sesuai instruksi "reuse config yang sudah ada" (docs/planning/02
     * §17). Folder 'profile/' (taksonomi bucket, docs/planning/02 §5).
     */
    public function updateAvatar(User $user, UploadedFile $file): User
    {
        $disk = (string) config('produli.storage.visit_photos_disk', 's3');
        $extension = $file->getClientOriginalExtension() ?: 'jpg';
        $key = 'profile/'.$user->id.'/'.Str::uuid().'.'.$extension;

        $success = Storage::disk($disk)->put($key, file_get_contents($file->getRealPath()));

        if (! $success) {
            throw new RuntimeException("Gagal upload avatar ke disk '{$disk}'.");
        }

        $oldAvatarPath = $user->avatar_path;

        $user->update(['avatar_path' => $key]);

        // Hapus avatar lama supaya tidak menumpuk file yatim di bucket -- dilakukan SETELAH
        // avatar baru berhasil tersimpan (kalau upload baru gagal, avatar lama tidak boleh
        // ikut hilang).
        if ($oldAvatarPath !== null) {
            Storage::disk($disk)->delete($oldAvatarPath);
        }

        return $user->fresh();
    }

    /**
     * Field umum semua role (docs/planning/02 §17): preferensi (email_notifications_enabled)
     * DAN data diri dasar (name/no_hp). SENGAJA tidak termasuk email/puskesmas_id/roles --
     * identitas resmi & penugasan, dikunci dari self-service (lihat UpdateProfileRequest).
     *
     * @param  array{email_notifications_enabled?: bool, name?: string, no_hp?: ?string}  $data
     */
    public function updatePreferences(User $user, array $data): User
    {
        $user->update(Arr::only($data, ['email_notifications_enabled', 'name', 'no_hp']));

        return $user->fresh();
    }

    /**
     * Onboarding step "Lengkapi Profil" utk STAF non-kader (admin_puskesmas/pj_prolanis) --
     * field SAMA dengan KaderService::updateOwnProfile() (no_wa/alamat/gender/tgl_lahir), tapi
     * tersimpan di users karena staf tidak punya baris kader (docs/planning/02 §14 lanjutan).
     *
     * @param  array{no_wa?: ?string, alamat?: ?string, gender?: ?string, tgl_lahir?: ?string}  $data
     */
    public function updateStaffProfile(User $user, array $data): User
    {
        $user->update(Arr::only($data, ['no_wa', 'alamat', 'gender', 'tgl_lahir']));

        return $user->fresh();
    }

    /**
     * Onboarding First-Login (docs/planning/02 §14) -- selalu set kedua timestamp bersamaan,
     * memanggil endpoint ini SUDAH berarti user mengonfirmasi/menyelesaikan alur onboarding.
     */
    public function completeOnboarding(User $user): User
    {
        $user->update([
            'onboarding_completed_at' => now(),
            'tos_accepted_at' => now(),
        ]);

        return $user->fresh();
    }
}
