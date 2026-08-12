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

// Kadensi kunjungan berulang (revisi Bu Kadis) -- sekali sehari cukup, tidak clock-sensitive
// dalam hari seperti reminder H-1/hari-H di atas (lihat CareAssignmentCadenceService).
Schedule::command('produli:generate-care-visits')->dailyAt('03:00');
