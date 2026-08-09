@component('mail::message')
# 🔐 Permintaan Reset Password

Halo **{{ $userName }}**,

Kami menerima permintaan reset password untuk akun Anda di KOPIPU Smart. Berikut adalah rincian permintaan tersebut:

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 24px 0; border-top: 1px solid #e2e8f0; border-bottom: 1px solid #e2e8f0;">
<tr>
<td style="padding: 16px 0; border-bottom: 1px solid #f1f5f9; color: #64748b; font-size: 14px; width: 40%; vertical-align: middle;">
<span style="display: inline-block; background-color: #f0fcfb; border-radius: 6px; padding: 6px; margin-right: 8px; vertical-align: middle;">⏳</span>
<strong style="vertical-align: middle; color: #334155;">Masa Berlaku Link</strong>
</td>
<td style="padding: 16px 0; border-bottom: 1px solid #f1f5f9; color: #003b5c; font-size: 14px; font-weight: 600; text-align: right; vertical-align: middle;">
{{ $expireMinutes }} Menit
</td>
</tr>
<tr>
<td style="padding: 16px 0; color: #64748b; font-size: 14px; width: 40%; vertical-align: middle;">
<span style="display: inline-block; background-color: #f0fcfb; border-radius: 6px; padding: 6px; margin-right: 8px; vertical-align: middle;">🛡️</span>
<strong style="vertical-align: middle; color: #334155;">Keamanan Akun</strong>
</td>
<td style="padding: 16px 0; color: #003b5c; font-size: 14px; font-weight: 600; text-align: right; vertical-align: middle;">
Rahasia & Terenkripsi
</td>
</tr>
</table>

Silakan klik tombol di bawah ini untuk mengatur ulang password Anda. Jika Anda **tidak meminta** reset password, abaikan saja email ini (akun Anda tetap aman).

@component('mail::button', ['url' => $resetUrl, 'color' => 'primary'])
Reset Password Sekarang
@endcomponent

Terima kasih atas perhatian Anda.

Salam sehat,<br>
**Tim {{ config('app.name') }}**
@endcomponent
