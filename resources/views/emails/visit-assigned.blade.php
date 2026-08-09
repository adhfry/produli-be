@component('mail::message')
# Tugas Kunjungan Baru

Halo **{{ $recipientName }}**,

Anda telah ditugaskan untuk melakukan kunjungan pasien Prolanis. Berikut adalah rincian tugas Anda:

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 24px 0; border-top: 1px solid #e2e8f0; border-bottom: 1px solid #e2e8f0;">
<tr>
<td style="padding: 16px 0; border-bottom: 1px solid #f1f5f9; color: #64748b; font-size: 14px; width: 40%; vertical-align: middle;">
<span style="display: inline-block; background-color: #f0fcfb; border-radius: 6px; padding: 6px; margin-right: 8px; vertical-align: middle;">📅</span>
<strong style="vertical-align: middle; color: #334155;">Jadwal Kunjungan</strong>
</td>
<td style="padding: 16px 0; border-bottom: 1px solid #f1f5f9; color: #003b5c; font-size: 14px; font-weight: 600; text-align: right; vertical-align: middle;">
{{ $scheduledDate }}
</td>
</tr>
<tr>
<td style="padding: 16px 0; color: #64748b; font-size: 14px; width: 40%; vertical-align: middle;">
<span style="display: inline-block; background-color: #f0fcfb; border-radius: 6px; padding: 6px; margin-right: 8px; vertical-align: middle;">👥</span>
<strong style="vertical-align: middle; color: #334155;">Jumlah Pasien</strong>
</td>
<td style="padding: 16px 0; color: #003b5c; font-size: 14px; font-weight: 600; text-align: right; vertical-align: middle;">
{{ $taskCount }} Pasien
</td>
</tr>
</table>

Silakan buka aplikasi KOPIPU Smart untuk melihat detail lokasi pasien dan panduan navigasi dari titik Anda.

@component('mail::button', ['url' => rtrim((string) config('app.frontend_url'), '/').'/app/tugas', 'color' => 'primary'])
Buka Aplikasi Sekarang
@endcomponent

Terima kasih atas dedikasi Anda dalam mendukung kesehatan masyarakat.

Salam sehat,<br>
**Tim {{ config('app.name') }}**
@endcomponent
