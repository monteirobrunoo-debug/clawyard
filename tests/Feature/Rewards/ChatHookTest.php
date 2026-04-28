<?php

namespace Tests\Feature\Rewards;

use App\Http\Controllers\NvidiaController;
use App\Models\AgentMetric;
use App\Models\RewardEvent;
use App\Models\User;
use App\Models\UserPoints;
use App\Services\Rewards\BadgeCatalog;
use App\Services\Rewards\RewardRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * C5 — chat-engagement hook on NvidiaController::chat() and
 * chatStream(). The controller-level integration is tricky to
 * exercise in tests because chatStream() calls live Anthropic
 * downstream — so we test the wired helper directly via a
 * subclass that exposes it.
 *
 * What we want to lock in:
 *   • Calling recordChatEngagement creates ONE TYPE_AGENT_CHAT event.
 *   • Daily cap (10/day) clamps points but keeps recording.
 *   • Distinct agent_keys flow through to AGENT_FRIEND/POLYGLOT badges.
 */
class ChatHookTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create([
            'name'      => 'C-' . uniqid(),
            'email'     => 'c+' . uniqid() . '@partyard.eu',
            'password'  => 'x',
            'role'      => 'user',
            'is_active' => true,
        ]);
    }

    /**
     * Build a stripped-down controller instance that exposes
     * recordChatEngagement publicly. We DON'T newup the full
     * controller because it has heavy constructor dependencies
     * (RagService, AgentManager) we don't need for this hook test.
     */
    private function probe(): object
    {
        return new class extends NvidiaController {
            public function __construct() {}   // skip parent — no deps needed
            public function publicRecord(?int $userId, string $agentKey, string $sessionId): void
            {
                $this->recordChatEngagement($userId, $agentKey, $sessionId);
            }
        };
    }

    public function test_record_chat_engagement_creates_event(): void
    {
        $u = $this->user();
        $this->probe()->publicRecord($u->id, 'sales', 'sid-123');

        $this->assertSame(1, RewardEvent::where('user_id', $u->id)
            ->where('event_type', RewardEvent::TYPE_AGENT_CHAT)
            ->count());

        $event = RewardEvent::first();
        $this->assertSame('sales', $event->agent_key);
        $this->assertSame(1, $event->points);
        $this->assertSame('sid-123', $event->metadata['session_id'] ?? null);
    }

    public function test_no_op_when_user_id_is_null(): void
    {
        // Defensive: chat endpoints are auth-gated upstream so userId
        // shouldn't be null, but the helper handles it gracefully.
        $this->probe()->publicRecord(null, 'sales', 'sid-123');
        $this->assertSame(0, RewardEvent::count());
    }

    public function test_eleven_chats_in_a_day_clamp_to_10_points(): void
    {
        $u = $this->user();
        $probe = $this->probe();

        for ($i = 0; $i < 11; $i++) {
            $probe->publicRecord($u->id, 'sales', 'sid-' . $i);
        }

        // 11 events recorded, but only 10 points awarded.
        $this->assertSame(11, RewardEvent::where('user_id', $u->id)->count());
        $this->assertSame(10, UserPoints::find($u->id)->total_points,
            'cap is 10 chat events per day per user');
    }

    public function test_three_distinct_agents_unlock_agent_friend_badge(): void
    {
        $u = $this->user();
        $probe = $this->probe();

        $probe->publicRecord($u->id, 'sales',  'sid');
        $probe->publicRecord($u->id, 'vessel', 'sid');
        $probe->publicRecord($u->id, 'crm',    'sid');

        $owned = UserPoints::find($u->id)->badges ?? [];
        $this->assertContains(BadgeCatalog::AGENT_FRIEND, $owned,
            '3 distinct agents must auto-award AGENT_FRIEND via the recorder hook');
    }

    public function test_chat_event_does_not_bump_agent_metric(): void
    {
        // agent_chat events SHOULDN'T touch agent_metrics — those are
        // for swarm/lead-related performance signals, not chat volume.
        // (chat volume is its own user-side reward, not an agent KPI.)
        $u = $this->user();
        $this->probe()->publicRecord($u->id, 'sales', 'sid');

        // The recorder bumps agent_metric only for SWARM_RUN, SWARM_LEAD,
        // LEAD_WON, AGENT_THUMBS_*. AGENT_CHAT is intentionally excluded.
        $this->assertNull(AgentMetric::find('sales'),
            'AGENT_CHAT events must not create agent_metric rows');
    }
}
