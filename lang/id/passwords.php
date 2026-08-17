<?php

// Sama alasan dengan lang/id/validation.php -- jaring pengaman untuk jalur reset password
// bawaan Laravel (Password broker), supaya tidak menampilkan kunci mentah "passwords.reset" dst.
return [
    'reset' => 'Password Anda telah berhasil direset.',
    'sent' => 'Kami telah mengirimkan tautan reset password ke email Anda.',
    'throttled' => 'Mohon tunggu sebelum mencoba lagi.',
    'token' => 'Token reset password ini tidak valid.',
    'user' => 'Kami tidak dapat menemukan pengguna dengan alamat email tersebut.',
];
