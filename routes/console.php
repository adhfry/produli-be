<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// Dipanggil harian, tapi produli:sync-silakes sendiri yang skip kalau run sukses
// terakhir belum 48 jam lalu (docs/planning/02 §3) — bukan cron interval hari.
Schedule::command('produli:sync-silakes')->dailyAt('02:00');

// twiceDaily (bukan literal "harian") supaya reminder H-1 (sore, 16:00) dan hari-H (pagi,
// 06:00) sama-sama terkirim dekat jam targetnya (docs/planning/02 §8) — kalau cuma sekali
// sehari, salah satu target selalu telat sampai ~14 jam ke run berikutnya. Jam di sini HARUS
// sinkron dengan config('produli.reminders.same_day_time'/'h_minus_1_time') di config/produli.php.
Schedule::command('produli:send-visit-reminders')->twiceDaily(6, 16);

// Ringkasan H-1 admin_puskesmas/pj_prolanis (permintaan user) -- SEKALI sehari (beda dari
// reminder kader di atas yang twiceDaily), jam sama dgn reminder H-1 (16:00) karena semantiknya
// "besok akan ada X kunjungan", cukup sekali, lihat docblock NotificationService::
// notifyPuskesmasUpcomingVisitsSummary().
Schedule::command('produli:notify-puskesmas-visit-summary')->dailyAt('16:00');

// Kadensi kunjungan berulang (revisi Bu Kadis) -- sekali sehari cukup, tidak clock-sensitive
// dalam hari seperti reminder H-1/hari-H di atas (lihat CareAssignmentCadenceService).
Schedule::command('produli:generate-care-visits')->dailyAt('03:00');

// Penjadwalan kegiatan Prolanis (permintaan user) -- generate DULUAN (04:00) baru kirim
// reminder (07:00, setelah reminder kunjungan kader jam 06:00 di atas selesai) supaya jadwal
// yang direminder sudah mutakhir dari data lab semalam.
Schedule::command('produli:generate-prolanis-schedules')->dailyAt('04:00');
Schedule::command('produli:notify-prolanis-schedule-reminders')->dailyAt('07:00');

// Modul "Kirim Data Prolanis ke Labkesda Sumenep" (Fase D) -- cek konfirmasi Labkesda tiap 5
// menit (BUKAN dailyAt seperti job di atas -- ini alur operasional SAME-DAY, puskesmas/kurir
// menunggu notifikasi "sampel dikonfirmasi" dalam hitungan menit, bukan besok).
// withoutOverlapping() -- run berikutnya bisa jatuh sebelum yang sebelumnya selesai kalau
// SiLAKES lambat merespons banyak batch sekaligus.
Schedule::command('produli:poll-prolanis-delivery-confirmation')->everyFiveMinutes()->withoutOverlapping();
