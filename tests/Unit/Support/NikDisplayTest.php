<?php

namespace Tests\Unit\Support;

use App\Support\NikDisplay;
use PHPUnit\Framework\TestCase;

class NikDisplayTest extends TestCase
{
    public function test_nik_diawali_kode_wilayah_sumenep_ditampilkan_penuh(): void
    {
        $this->assertSame('3529012345670001', NikDisplay::resolve('3529012345670001'));
    }

    public function test_nik_tidak_diawali_kode_wilayah_sumenep_disamarkan(): void
    {
        $this->assertSame('Tidak Diketahui', NikDisplay::resolve('3510012345670001'));
    }

    public function test_nik_null_disamarkan(): void
    {
        $this->assertSame('Tidak Diketahui', NikDisplay::resolve(null));
    }
}
