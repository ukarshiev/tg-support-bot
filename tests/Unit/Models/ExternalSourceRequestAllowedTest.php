<?php

namespace Tests\Unit\Models;

use App\Models\ExternalSource;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class ExternalSourceRequestAllowedTest extends TestCase
{
    // ── Empty allowlist ───────────────────────────────────────────────────────

    public function test_allows_any_request_when_allowlist_is_empty(): void
    {
        $source = new ExternalSource();
        $source->allowed_ips = null;

        $request = Request::create('https://anything.com/', 'GET');
        $request->server->set('REMOTE_ADDR', '1.2.3.4');

        $this->assertTrue($source->isRequestAllowed($request));
    }

    // ── IP entries ────────────────────────────────────────────────────────────

    public function test_allows_request_when_ip_matches(): void
    {
        $source = new ExternalSource();
        $source->allowed_ips = ['203.0.113.10'];

        $request = Request::create('https://example.com/', 'GET');
        $request->server->set('REMOTE_ADDR', '203.0.113.10');

        $this->assertTrue($source->isRequestAllowed($request));
    }

    public function test_rejects_request_when_ip_does_not_match(): void
    {
        $source = new ExternalSource();
        $source->allowed_ips = ['203.0.113.10'];

        $request = Request::create('https://example.com/', 'GET');
        $request->server->set('REMOTE_ADDR', '5.6.7.8');

        $this->assertFalse($source->isRequestAllowed($request));
    }

    // ── Exact domain entries ──────────────────────────────────────────────────

    public function test_allows_request_when_origin_host_matches_exact_domain(): void
    {
        $source = new ExternalSource();
        $source->allowed_ips = ['example.com'];

        $request = Request::create('https://example.com/widget', 'POST');
        $request->headers->set('Origin', 'https://example.com');

        $this->assertTrue($source->isRequestAllowed($request));
    }

    public function test_domain_match_is_case_insensitive(): void
    {
        $source = new ExternalSource();
        $source->allowed_ips = ['Example.COM'];

        $request = Request::create('https://example.com/', 'POST');
        $request->headers->set('Origin', 'https://EXAMPLE.com');

        $this->assertTrue($source->isRequestAllowed($request));
    }

    public function test_rejects_request_when_origin_host_differs(): void
    {
        $source = new ExternalSource();
        $source->allowed_ips = ['example.com'];

        $request = Request::create('https://other.com/', 'POST');
        $request->headers->set('Origin', 'https://other.com');

        $this->assertFalse($source->isRequestAllowed($request));
    }

    // ── Wildcard domain entries ───────────────────────────────────────────────

    public function test_wildcard_matches_one_subdomain_level(): void
    {
        $source = new ExternalSource();
        $source->allowed_ips = ['*.example.com'];

        $request = Request::create('https://foo.example.com/', 'POST');
        $request->headers->set('Origin', 'https://foo.example.com');

        $this->assertTrue($source->isRequestAllowed($request));
    }

    public function test_wildcard_does_not_match_base_domain(): void
    {
        $source = new ExternalSource();
        $source->allowed_ips = ['*.example.com'];

        $request = Request::create('https://example.com/', 'POST');
        $request->headers->set('Origin', 'https://example.com');

        $this->assertFalse($source->isRequestAllowed($request));
    }

    public function test_wildcard_does_not_match_two_subdomain_levels(): void
    {
        $source = new ExternalSource();
        $source->allowed_ips = ['*.example.com'];

        $request = Request::create('https://foo.bar.example.com/', 'POST');
        $request->headers->set('Origin', 'https://foo.bar.example.com');

        $this->assertFalse($source->isRequestAllowed($request));
    }

    // ── Referer fallback ──────────────────────────────────────────────────────

    public function test_falls_back_to_referer_when_origin_absent(): void
    {
        $source = new ExternalSource();
        $source->allowed_ips = ['example.com'];

        $request = Request::create('https://api.example.com/', 'POST');
        $request->headers->set('Referer', 'https://example.com/page');

        $this->assertTrue($source->isRequestAllowed($request));
    }

    // ── Subdomain does not match exact domain entry ───────────────────────────

    public function test_exact_domain_rejects_subdomain_in_origin(): void
    {
        $source = new ExternalSource();
        $source->allowed_ips = ['example.com'];

        $request = Request::create('https://sub.example.com/', 'POST');
        $request->headers->set('Origin', 'https://sub.example.com');

        $this->assertFalse($source->isRequestAllowed($request));
    }

    // ── Mixed list ────────────────────────────────────────────────────────────

    public function test_allows_when_ip_matches_in_mixed_list(): void
    {
        $source = new ExternalSource();
        $source->allowed_ips = ['example.com', '203.0.113.10'];

        $request = Request::create('https://other.com/', 'POST');
        $request->server->set('REMOTE_ADDR', '203.0.113.10');
        $request->headers->set('Origin', 'https://other.com');

        $this->assertTrue($source->isRequestAllowed($request));
    }

    public function test_rejects_when_nothing_matches_in_mixed_list(): void
    {
        $source = new ExternalSource();
        $source->allowed_ips = ['example.com', '203.0.113.10'];

        $request = Request::create('https://other.com/', 'POST');
        $request->server->set('REMOTE_ADDR', '5.6.7.8');
        $request->headers->set('Origin', 'https://other.com');

        $this->assertFalse($source->isRequestAllowed($request));
    }
}
