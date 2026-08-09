<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.frontend_url')"></x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
**{{ config('app.name') }}** &mdash; Sistem Pemantauan Kesehatan Prolanis

UPTD Laboratorium Kesehatan Daerah, Dinas Kesehatan Kabupaten Sumenep

Email ini dikirim otomatis, mohon tidak membalas ke alamat ini.
&copy; {{ date('Y') }} {{ config('app.name') }}. Seluruh hak cipta dilindungi.
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
