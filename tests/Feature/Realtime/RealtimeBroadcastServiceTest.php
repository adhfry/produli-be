<?php

namespace Tests\Feature\Realtime;

use App\Services\Realtime\RealtimeBroadcastService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class RealtimeBroadcastServiceTest extends TestCase
{
    use RefreshDatabase;

    private RealtimeBroadcastService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(RealtimeBroadcastService::class);
        config([
            'produli.realtime.base_url' => 'http://fake-wss.test',
            'produli.realtime.broadcast_secret' => 'test-broadcast-secret',
        ]);
    }

    public function test_post_ke_internal_broadcast_dengan_header_secret_dan_body_benar(): void
    {
        Http::fake(['fake-wss.test/*' => Http::response(['status' => 'ok'], 200)]);

        $this->service->broadcast('puskesmas:5', 'visit_report.submitted', ['assignment_id' => 42]);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === 'http://fake-wss.test/internal/broadcast'
                && $request->method() === 'POST'
                && $request->hasHeader('x-internal-secret', 'test-broadcast-secret')
                && $body['topic'] === 'puskesmas:5'
                && $body['event'] === 'visit_report.submitted'
                && $body['payload'] === ['assignment_id' => 42];
        });
    }

    public function test_throw_kalau_response_gagal(): void
    {
        Http::fake(['fake-wss.test/*' => Http::response('', 500)]);

        $this->expectException(RuntimeException::class);
        $this->service->broadcast('puskesmas:5', 'visit_report.submitted');
    }

    public function test_throw_kalau_config_belum_diisi(): void
    {
        config(['produli.realtime.base_url' => '']);

        $this->expectException(RuntimeException::class);
        $this->service->broadcast('puskesmas:5', 'visit_report.submitted');
    }
}
