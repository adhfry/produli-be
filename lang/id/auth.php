<?php

// Sama alasan dengan lang/id/validation.php -- APP_LOCALE=id tanpa file bahasa ini akan
// menampilkan kunci mentah "auth.failed"/"auth.password" kalau ada jalur yang memicu pesan
// auth bawaan Laravel (Auth::attempt(), dst). AuthController PRODULI sendiri sudah pakai pesan
// custom untuk login utama, file ini jaring pengaman untuk jalur bawaan lain.
return [
    'failed' => 'Kredensial ini tidak cocok dengan data kami.',
    'password' => 'Password yang diberikan salah.',
    'throttle' => 'Terlalu banyak percobaan login. Silakan coba lagi dalam :seconds detik.',
];
