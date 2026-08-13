@component('mail::message')
# Password Akun Anda Telah Direset

Halo **{{ $recipientName }}**,

Password akun PRODULI Anda telah direset oleh administrator (**{{ $resetByName }}**). Berikut
adalah password baru Anda -- gunakan untuk login, lalu Anda akan diminta menggantinya dengan
password pilihan Anda sendiri.

@component('mail::panel')
Password Baru: **{{ $newPassword }}**
@endcomponent

Demi keamanan, jangan bagikan password ini ke siapa pun. Jika Anda tidak merasa meminta reset
password, segera hubungi administrator PRODULI.

Salam sehat,<br>
{{ config('app.name') }}
@endcomponent
