<?php

namespace Tests\Feature\Foundation;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * HTTPS detection behind a TLS-terminating reverse proxy (TRUSTED_PROXIES).
 */
class TrustedProxyTest extends TestCase
{
    private function probe(): void
    {
        Route::get('/_proxy-probe', fn (Request $request) => ['secure' => $request->isSecure(), 'ip' => $request->ip()]);
    }

    public function test_forwarded_headers_are_ignored_by_default()
    {
        $this->probe();

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
            ->get('/_proxy-probe', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.9'])
            ->assertJson(['secure' => false, 'ip' => '10.0.0.5']);
    }

    private function trust(string $proxies): void
    {
        putenv("TRUSTED_PROXIES={$proxies}");
        $this->refreshApplication();
        putenv('TRUSTED_PROXIES');
        $this->probe();
    }

    public function test_a_trusted_proxy_passes_https_and_the_client_ip()
    {
        $this->trust('10.0.0.5');

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
            ->get('/_proxy-probe', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.9'])
            ->assertJson(['secure' => true, 'ip' => '203.0.113.9']);
    }

    public function test_an_untrusted_source_cannot_spoof_https_or_its_ip()
    {
        // Separate test: the test client reuses the previous request's scheme for relative URLs.
        $this->trust('10.0.0.5');

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->get('/_proxy-probe', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.9'])
            ->assertJson(['secure' => false, 'ip' => '198.51.100.7']);
    }
}
