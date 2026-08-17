<?php

// Terjemahan Indonesia untuk pesan validasi bawaan Laravel -- WAJIB ada karena APP_LOCALE dan
// APP_FALLBACK_LOCALE keduanya di-set ke 'id' (.env), sementara Laravel tidak membawa file
// bahasa Indonesia bawaan. TANPA file ini, Validator::errors() mengembalikan KUNCI mentah
// (mis. "validation.required") sebagai pesan -- bukan cuma jelek, tapi benar-benar tidak bisa
// dibaca pengguna sama sekali. Ini akar masalah untuk SELURUH form di sistem yang mengandalkan
// pesan default (tidak override messages() custom di FormRequest-nya).
return [

    /*
    |--------------------------------------------------------------------------
    | Baris Bahasa Validasi
    |--------------------------------------------------------------------------
    |
    | Baris berikut berisi pesan error default yang dipakai oleh kelas Validator.
    | Sebagian aturan punya beberapa versi seperti aturan ukuran (size). Silakan
    | sesuaikan tiap pesan ini sesuai kebutuhan aplikasi.
    |
    */

    'accepted' => ':attribute wajib disetujui.',
    'accepted_if' => ':attribute wajib disetujui apabila :other adalah :value.',
    'active_url' => ':attribute bukan URL yang valid.',
    'after' => ':attribute harus tanggal setelah :date.',
    'after_or_equal' => ':attribute harus tanggal setelah atau sama dengan :date.',
    'alpha' => ':attribute hanya boleh berisi huruf.',
    'alpha_dash' => ':attribute hanya boleh berisi huruf, angka, strip, dan garis bawah.',
    'alpha_num' => ':attribute hanya boleh berisi huruf dan angka.',
    'array' => ':attribute harus berupa array.',
    'ascii' => ':attribute hanya boleh berisi karakter alfanumerik dan simbol satu byte.',
    'before' => ':attribute harus tanggal sebelum :date.',
    'before_or_equal' => ':attribute harus tanggal sebelum atau sama dengan :date.',
    'between' => [
        'array' => ':attribute harus memiliki antara :min - :max item.',
        'file' => ':attribute harus antara :min - :max kilobita.',
        'numeric' => ':attribute harus antara :min - :max.',
        'string' => ':attribute harus antara :min - :max karakter.',
    ],
    'boolean' => ':attribute harus bernilai true atau false.',
    'can' => ':attribute berisi nilai yang tidak diizinkan.',
    'confirmed' => 'Konfirmasi :attribute tidak cocok.',
    'contains' => ':attribute kehilangan nilai yang diperlukan.',
    'current_password' => 'Password salah.',
    'date' => ':attribute bukan tanggal yang valid.',
    'date_equals' => ':attribute harus tanggal yang sama dengan :date.',
    'date_format' => ':attribute tidak cocok dengan format :format.',
    'decimal' => ':attribute harus memiliki :decimal desimal.',
    'declined' => ':attribute wajib ditolak.',
    'declined_if' => ':attribute wajib ditolak apabila :other adalah :value.',
    'different' => ':attribute dan :other harus berbeda.',
    'digits' => ':attribute harus :digits digit.',
    'digits_between' => ':attribute harus antara :min - :max digit.',
    'dimensions' => ':attribute memiliki dimensi gambar yang tidak valid.',
    'distinct' => ':attribute memiliki nilai yang duplikat.',
    'doesnt_end_with' => ':attribute tidak boleh berakhiran salah satu dari berikut: :values.',
    'doesnt_start_with' => ':attribute tidak boleh berawalan salah satu dari berikut: :values.',
    'email' => ':attribute harus berupa alamat email yang valid.',
    'ends_with' => ':attribute harus berakhiran salah satu dari berikut: :values.',
    'enum' => ':attribute yang dipilih tidak valid.',
    'exists' => ':attribute yang dipilih tidak valid.',
    'extensions' => ':attribute harus memiliki salah satu ekstensi berikut: :values.',
    'file' => ':attribute harus berupa file.',
    'filled' => ':attribute wajib diisi.',
    'gt' => [
        'array' => ':attribute harus memiliki lebih dari :value item.',
        'file' => ':attribute harus lebih besar dari :value kilobita.',
        'numeric' => ':attribute harus lebih besar dari :value.',
        'string' => ':attribute harus lebih besar dari :value karakter.',
    ],
    'gte' => [
        'array' => ':attribute harus memiliki :value item atau lebih.',
        'file' => ':attribute harus lebih besar atau sama dengan :value kilobita.',
        'numeric' => ':attribute harus lebih besar atau sama dengan :value.',
        'string' => ':attribute harus lebih besar atau sama dengan :value karakter.',
    ],
    'hex_color' => ':attribute harus berupa warna heksadesimal yang valid.',
    'image' => ':attribute harus berupa gambar.',
    'in' => ':attribute yang dipilih tidak valid.',
    'in_array' => ':attribute tidak ada di dalam :other.',
    'integer' => ':attribute harus berupa bilangan bulat.',
    'ip' => ':attribute harus berupa alamat IP yang valid.',
    'ipv4' => ':attribute harus berupa alamat IPv4 yang valid.',
    'ipv6' => ':attribute harus berupa alamat IPv6 yang valid.',
    'json' => ':attribute harus berupa string JSON yang valid.',
    'list' => ':attribute harus berupa daftar (list).',
    'lowercase' => ':attribute harus huruf kecil semua.',
    'lt' => [
        'array' => ':attribute harus memiliki kurang dari :value item.',
        'file' => ':attribute harus kurang dari :value kilobita.',
        'numeric' => ':attribute harus kurang dari :value.',
        'string' => ':attribute harus kurang dari :value karakter.',
    ],
    'lte' => [
        'array' => ':attribute tidak boleh memiliki lebih dari :value item.',
        'file' => ':attribute harus kurang atau sama dengan :value kilobita.',
        'numeric' => ':attribute harus kurang atau sama dengan :value.',
        'string' => ':attribute harus kurang atau sama dengan :value karakter.',
    ],
    'mac_address' => ':attribute harus berupa alamat MAC yang valid.',
    'max' => [
        'array' => ':attribute tidak boleh memiliki lebih dari :max item.',
        'file' => ':attribute tidak boleh lebih besar dari :max kilobita.',
        'numeric' => ':attribute tidak boleh lebih besar dari :max.',
        'string' => ':attribute tidak boleh lebih dari :max karakter.',
    ],
    'max_digits' => ':attribute tidak boleh memiliki lebih dari :max digit.',
    'mimes' => ':attribute harus berupa file berjenis: :values.',
    'mimetypes' => ':attribute harus berupa file berjenis: :values.',
    'min' => [
        'array' => ':attribute minimal harus memiliki :min item.',
        'file' => ':attribute minimal harus :min kilobita.',
        'numeric' => ':attribute minimal harus :min.',
        'string' => ':attribute minimal harus :min karakter.',
    ],
    'min_digits' => ':attribute minimal harus memiliki :min digit.',
    'missing' => ':attribute wajib tidak ada.',
    'missing_if' => ':attribute wajib tidak ada apabila :other adalah :value.',
    'missing_unless' => ':attribute wajib tidak ada kecuali :other adalah :value.',
    'missing_with' => ':attribute wajib tidak ada apabila :values ada.',
    'missing_with_all' => ':attribute wajib tidak ada apabila :values ada.',
    'multiple_of' => ':attribute harus kelipatan dari :value.',
    'not_in' => ':attribute yang dipilih tidak valid.',
    'not_regex' => 'Format :attribute tidak valid.',
    'numeric' => ':attribute harus berupa angka.',
    'password' => [
        'letters' => ':attribute harus mengandung minimal satu huruf.',
        'mixed' => ':attribute harus mengandung minimal satu huruf besar dan satu huruf kecil.',
        'numbers' => ':attribute harus mengandung minimal satu angka.',
        'symbols' => ':attribute harus mengandung minimal satu simbol.',
        'uncompromised' => ':attribute yang diberikan pernah muncul dalam kebocoran data. Silakan pilih :attribute yang lain.',
    ],
    'present' => ':attribute wajib ada.',
    'present_if' => ':attribute wajib ada apabila :other adalah :value.',
    'present_unless' => ':attribute wajib ada kecuali :other adalah :value.',
    'present_with' => ':attribute wajib ada apabila :values ada.',
    'present_with_all' => ':attribute wajib ada apabila :values ada.',
    'prohibited' => ':attribute dilarang.',
    'prohibited_if' => ':attribute dilarang apabila :other adalah :value.',
    'prohibited_unless' => ':attribute dilarang kecuali :other ada di dalam :values.',
    'prohibits' => ':attribute melarang :other untuk ada.',
    'regex' => 'Format :attribute tidak valid.',
    'required' => ':attribute wajib diisi.',
    'required_array_keys' => ':attribute wajib berisi entri untuk: :values.',
    'required_if' => ':attribute wajib diisi apabila :other adalah :value.',
    'required_if_accepted' => ':attribute wajib diisi apabila :other disetujui.',
    'required_if_declined' => ':attribute wajib diisi apabila :other ditolak.',
    'required_unless' => ':attribute wajib diisi kecuali :other ada di dalam :values.',
    'required_with' => ':attribute wajib diisi apabila :values ada.',
    'required_with_all' => ':attribute wajib diisi apabila :values ada.',
    'required_without' => ':attribute wajib diisi apabila :values tidak ada.',
    'required_without_all' => ':attribute wajib diisi apabila tidak ada satu pun dari :values yang ada.',
    'same' => ':attribute dan :other harus sama.',
    'size' => [
        'array' => ':attribute harus berisi :size item.',
        'file' => ':attribute harus berukuran :size kilobita.',
        'numeric' => ':attribute harus berukuran :size.',
        'string' => ':attribute harus berukuran :size karakter.',
    ],
    'starts_with' => ':attribute harus berawalan salah satu dari berikut: :values.',
    'string' => ':attribute harus berupa string.',
    'timezone' => ':attribute harus berupa zona waktu yang valid.',
    'unique' => ':attribute sudah digunakan.',
    'uploaded' => ':attribute gagal diunggah.',
    'uppercase' => ':attribute harus huruf besar semua.',
    'url' => ':attribute harus berupa URL yang valid.',
    'ulid' => ':attribute harus berupa ULID yang valid.',
    'uuid' => ':attribute harus berupa UUID yang valid.',

    /*
    |--------------------------------------------------------------------------
    | Baris Bahasa Validasi Kustom
    |--------------------------------------------------------------------------
    |
    | Di sini Anda bisa menentukan pesan validasi kustom untuk atribut dengan
    | konvensi "atribut.aturan" untuk menentukan baris pesan yang spesifik untuk
    | aturan atribut tertentu.
    |
    */

    'custom' => [],

    /*
    |--------------------------------------------------------------------------
    | Nama Atribut Kustom
    |--------------------------------------------------------------------------
    |
    | Baris berikut dipakai mengganti placeholder :attribute dengan nama yang
    | lebih ramah pembaca, mis. "E-Mail Address" jadi "Alamat E-Mail". Ini
    | membantu membuat pesan lebih ekspresif -- berlaku GLOBAL untuk seluruh
    | FormRequest di sistem, jadi field baru otomatis dapat nama yang layak
    | tanpa perlu override messages()/attributes() satu-satu per FormRequest.
    |
    */

    'attributes' => [
        'name' => 'nama',
        'nama' => 'nama',
        'email' => 'email',
        'password' => 'password',
        'password_confirmation' => 'konfirmasi password',
        'old_password' => 'password lama',
        'new_password' => 'password baru',
        'no_hp' => 'nomor HP',
        'no_wa' => 'nomor WhatsApp',
        'phone' => 'nomor telepon',
        'nik' => 'NIK',
        'nik_hash' => 'NIK',
        'no_bpjs' => 'nomor BPJS',
        'no_reg' => 'nomor registrasi',
        'gender' => 'jenis kelamin',
        'tgl_lahir' => 'tanggal lahir',
        'alamat' => 'alamat',
        'kel_desa' => 'kelurahan/desa',
        'kecamatan' => 'kecamatan',
        'kecamatan_id' => 'kecamatan',
        'desa_id' => 'kelurahan/desa',
        'puskesmas_id' => 'puskesmas',
        'kader_id' => 'kader',
        'tenaga_kesehatan_id' => 'tenaga kesehatan',
        'pj_id' => 'penanggung jawab',
        'role' => 'peran',
        'roles' => 'peran',
        'status_aktif' => 'status aktif',
        'title' => 'judul',
        'description' => 'deskripsi',
        'body' => 'isi',
        'type' => 'jenis',
        'urgency' => 'tingkat urgensi',
        'target_roles' => 'target peran',
        'icon' => 'ikon',
        'color' => 'warna',
        'image_url' => 'URL gambar',
        'button_label' => 'label tombol',
        'button_url' => 'tautan tombol',
        'reason' => 'alasan',
        'catatan' => 'catatan',
        'keluhan' => 'keluhan',
        'kondisi' => 'kondisi',
        'tindakan' => 'tindakan',
        'cara_rujukan' => 'cara rujukan',
        'rujukan_status' => 'status rujukan',
        'kepatuhan_obat' => 'kepatuhan obat',
        'sisa_obat' => 'sisa obat',
        'scheduled_date' => 'tanggal jadwal',
        'device_id' => 'ID perangkat',
        'device_name' => 'nama perangkat',
        'latitude' => 'lintang (latitude)',
        'longitude' => 'bujur (longitude)',
        'photo' => 'foto',
        'file' => 'berkas',
        'per_page' => 'jumlah per halaman',
        'is_valid' => 'status valid',
        'validation_note' => 'catatan validasi',
        'threshold_min' => 'ambang minimum',
        'threshold_max' => 'ambang maksimum',
        'operator' => 'operator',
        'parameter' => 'parameter',
        'level' => 'tingkat',
        'is_direct_classifier' => 'penentu langsung',
        'is_active' => 'status aktif',
    ],

];
