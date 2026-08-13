# Font Carlito (kop laporan PDF)

Kop laporan PDF (`resources/views/pdf/patients-export.blade.php`) memakai font
**Carlito** — pengganti open-source resmi untuk Calibri (metrik lebar huruf identik,
lisensi SIL Open Font License, bebas dipakai/didistribusikan). Calibri asli TIDAK
dipakai di sini karena berlisensi Microsoft — tidak aman untuk di-embed permanen dan
didistribusikan lewat PDF yang dihasilkan sistem ini (lihat diskusi terkait, jawaban
user: "Pakai Carlito, bukan Calibri asli").

**File TTF-nya BELUM ada di folder ini** — agent tidak bisa membuat file font biner.
Taruh 4 file berikut di sini sebelum deploy (nama file HARUS persis, dirujuk oleh
`@font-face` di blade view):

```
Carlito-Regular.ttf
Carlito-Bold.ttf
Carlito-Italic.ttf
Carlito-BoldItalic.ttf
```

## Cara mendapatkan file-nya

**Opsi 1 — Google Fonts (paling gampang, di komputer mana pun):**
Buka https://fonts.google.com/specimen/Carlito, unduh family lengkap, ambil 4 file
`.ttf` di atas dari hasil unduhan, taruh di folder ini.

**Opsi 2 — VPS (Debian/Ubuntu), langsung dari paket sistem:**
```bash
sudo apt install fonts-crosextra-carlito
cp /usr/share/fonts/truetype/crosextra-carlito/Carlito-Regular.ttf resources/fonts/carlito/
cp /usr/share/fonts/truetype/crosextra-carlito/Carlito-Bold.ttf resources/fonts/carlito/
cp /usr/share/fonts/truetype/crosextra-carlito/Carlito-Italic.ttf resources/fonts/carlito/
cp /usr/share/fonts/truetype/crosextra-carlito/Carlito-BoldItalic.ttf resources/fonts/carlito/
```

## Fallback kalau file belum ada

`@font-face` di blade sengaja diikuti fallback `'DejaVu Sans', sans-serif` --
kalau ke-4 file ini belum ada, dompdf otomatis pakai DejaVu Sans untuk kop laporan
(TIDAK error/PDF tetap ter-generate normal), cuma font-nya belum Carlito sampai
file-nya ditaruh di sini.
