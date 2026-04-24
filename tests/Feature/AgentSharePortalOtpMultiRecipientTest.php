<?php

namespace Tests\Feature;

use App\Models\AgentShare;
use App\Models\AgentShareOtp;
use App\Models\User;
use App\Services\AgentShareAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Regression test for the multi-recipient portal OTP bug.
 *
 * User report (2026-04-24): "Problema quando se partilha user com mais
 * de um, o mail de confirmação não chega ao user para confirmar."
 *
 * Cause, found by audit: AgentShareAccessService::issuePortalOtp() was
 * comparing the inbound email against `client_email` only. Secondary
 * recipients added via `additional_emails` (comma-separated at share
 * creation time) were silently rejected, so they never got an OTP and
 * could never open the portal.
 *
 * Fix: validate against `$share->isAuthorisedEmail($email)`, which
 * already covers primary + additional_emails.
 *
 * This test locks that behaviour so a future refactor can't regress it.
 */
class AgentSharePortalOtpMultiRecipientTest extends TestCase
{
    use RefreshDatabase;

    private function makeBundledShare(User $owner, string $portalToken): AgentShare
    {
        return AgentShare::create([
            'token'             => AgentShare::generateToken(),
            'portal_token'      => $portalToken,
            'agent_key'         => 'sap',
            'client_name'       => 'Cliente Multi',
            'client_email'      => 'primary@example.com',
            'additional_emails' => ['secondary@example.com', 'third@example.com'],
            'is_active'         => true,
            'expires_at'        => now()->addDay(),
            'created_by'        => $owner->id,
        ]);
    }

    public function test_primary_recipient_gets_portal_otp_row(): void
    {
        $owner = User::factory()->create();
        $token = AgentShare::generateToken();
        $this->makeBundledShare($owner, $token);

        $svc = app(AgentShareAccessService::class);
        $ok  = $svc->issuePortalOtp($token, 'primary@example.com', 'browsersess-1', Request::create('/'));

        $this->assertTrue($ok);
        $this->assertSame(1, AgentShareOtp::where('email', 'primary@example.com')->count());
    }

    public function test_secondary_recipient_also_gets_portal_otp_row(): void
    {
        $owner = User::factory()->create();
        $token = AgentShare::generateToken();
        $this->makeBundledShare($owner, $token);

        $svc = app(AgentShareAccessService::class);
        $ok  = $svc->issuePortalOtp($token, 'secondary@example.com', 'browsersess-2', Request::create('/'));

        // Must NOT deny the secondary — this is the exact regression.
        $this->assertTrue($ok);
        $this->assertSame(1, AgentShareOtp::where('email', 'secondary@example.com')->count());
    }

    public function test_third_recipient_also_gets_portal_otp_row(): void
    {
        $owner = User::factory()->create();
        $token = AgentShare::generateToken();
        $this->makeBundledShare($owner, $token);

        $svc = app(AgentShareAccessService::class);
        $ok  = $svc->issuePortalOtp($token, 'third@example.com', 'browsersess-3', Request::create('/'));

        $this->assertTrue($ok);
        $this->assertSame(1, AgentShareOtp::where('email', 'third@example.com')->count());
    }

    public function test_unauthorised_email_is_still_rejected_silently(): void
    {
        $owner = User::factory()->create();
        $token = AgentShare::generateToken();
        $this->makeBundledShare($owner, $token);

        $svc = app(AgentShareAccessService::class);
        // Attacker tries an email that is not in the authorised set.
        $ok  = $svc->issuePortalOtp($token, 'attacker@example.com', 'browsersess-4', Request::create('/'));

        // Returns true (silent — anti-enumeration) but MUST NOT create an OTP row.
        $this->assertTrue($ok);
        $this->assertSame(0, AgentShareOtp::where('email', 'attacker@example.com')->count());
    }

    public function test_case_insensitive_match_on_primary(): void
    {
        $owner = User::factory()->create();
        $token = AgentShare::generateToken();
        $this->makeBundledShare($owner, $token);

        $svc = app(AgentShareAccessService::class);
        $ok  = $svc->issuePortalOtp($token, 'Primary@Example.COM', 'browsersess-5', Request::create('/'));

        $this->assertTrue($ok);
        $this->assertSame(1, AgentShareOtp::where('email', 'primary@example.com')->count());
    }
}
