@component('mail::message')
# Aktivasi Akun PRODULI

Halo **{{ $recipientName }}**,

Akun Anda sudah didaftarkan sebagai bagian dari tim pemantauan kesehatan Prolanis di PRODULI.
Klik tombol di bawah untuk mengaktifkan akun dan mendapatkan kata sandi Anda.

@component('mail::button', ['url' => $activationUrl, 'color' => 'primary'])
Aktivasi Akun Sekarang
@endcomponent

@component('mail::panel')
Tautan aktivasi ini berlaku selama **7 hari**. Jika Anda tidak merasa didaftarkan oleh admin
PRODULI, abaikan email ini -- akun tidak akan aktif tanpa konfirmasi Anda.
@endcomponent

Terima kasih telah menjadi bagian dari layanan kesehatan masyarakat yang lebih proaktif.

Salam sehat,<br>
{{ config('app.name') }}
@endcomponent
