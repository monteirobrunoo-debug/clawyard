<?php

namespace Tests\Feature\Robotparts;

use App\Models\PartOrder;
use App\Models\User;
use App\Services\Robotparts\RobotBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Robot anatomy: blueprint catalogue + agent slot assignment + the
 * /robot page itself.
 */
class RobotBlueprintTest extends TestCase
{
    use RefreshDatabase;

    public function test_blueprint_has_at_least_10_slots(): void
    {
        $slots = RobotBlueprint::all();
        $this->assertGreaterThanOrEqual(10, count($slots),
            'a real robot anatomy needs more than a handful of slots');
    }

    public function test_every_slot_has_complete_metadata(): void
    {
        foreach (RobotBlueprint::all() as $key => $meta) {
            foreach (['emoji', 'label', 'purpose', 'typical_parts', 'owners'] as $field) {
                $this->assertArrayHasKey($field, $meta, "slot {$key} missing field '{$field}'");
                $this->assertNotEmpty($meta[$field], "slot {$key} field '{$field}' is empty");
            }
            $this->assertIsArray($meta['owners']);
            $this->assertGreaterThanOrEqual(1, count($meta['owners']),
                "slot {$key} must have at least 1 owning agent");
        }
    }

    public function test_slots_by_agent_returns_owned_slots(): void
    {
        $byAgent = RobotBlueprint::slotsByAgent();
        // engineer owns muscles + hands per the blueprint
        $this->assertArrayHasKey('engineer', $byAgent);
        $this->assertContains(RobotBlueprint::SLOT_MUSCLES, $byAgent['engineer']);
        $this->assertContains(RobotBlueprint::SLOT_HANDS,   $byAgent['engineer']);
    }

    public function test_next_slot_for_unfilled_agent_returns_primary(): void
    {
        // engineer has [muscles, hands]. Nothing filled — should get muscles.
        $next = RobotBlueprint::nextSlotFor('engineer', filledSlots: []);
        $this->assertSame(RobotBlueprint::SLOT_MUSCLES, $next);
    }

    public function test_next_slot_skips_already_filled(): void
    {
        // muscles already filled, engineer should fall back to hands.
        $next = RobotBlueprint::nextSlotFor('engineer', filledSlots: [RobotBlueprint::SLOT_MUSCLES]);
        $this->assertSame(RobotBlueprint::SLOT_HANDS, $next);
    }

    public function test_unowned_agent_returns_null(): void
    {
        // 'fakekey' is not in the blueprint owners.
        $next = RobotBlueprint::nextSlotFor('fakekey', filledSlots: []);
        $this->assertNull($next);
    }

    // ── /robot page ─────────────────────────────────────────────────────────

    public function test_robot_page_renders_for_authenticated_user(): void
    {
        $u = User::create([
            'name' => 'r', 'email' => 'r+'.uniqid().'@p.eu',
            'password' => 'x', 'role' => 'user', 'is_active' => true,
        ]);

        $this->actingAs($u)->get(route('robot.index'))
            ->assertOk()
            ->assertSee('Anatomia do robot');
    }

    public function test_robot_page_shows_filled_slot_with_part(): void
    {
        $u = User::create([
            'name' => 'r2', 'email' => 'r2+'.uniqid().'@p.eu',
            'password' => 'x', 'role' => 'user', 'is_active' => true,
        ]);

        // Engineer fills the muscles slot with a servo.
        PartOrder::create([
            'agent_key'  => 'engineer',
            'slot'       => RobotBlueprint::SLOT_MUSCLES,
            'name'       => 'MG90S Servo',
            'description'=> '9g micro servo for joint articulation',
            'cost_usd'   => 2.50,
            'status'     => PartOrder::STATUS_STL_READY,
        ]);

        $this->actingAs($u)->get(route('robot.index'))
            ->assertOk()
            ->assertSee('MG90S Servo')
            ->assertSee('slots preenchidos')
            ->assertSee('$2.50');
    }

    public function test_robot_page_lists_empty_slots_with_owners(): void
    {
        $u = User::create([
            'name' => 'r3', 'email' => 'r3+'.uniqid().'@p.eu',
            'password' => 'x', 'role' => 'user', 'is_active' => true,
        ]);

        $this->actingAs($u)->get(route('robot.index'))
            ->assertOk()
            ->assertSee('Slots em falta')
            ->assertSee('Cérebro / Compute', false);
    }
}
