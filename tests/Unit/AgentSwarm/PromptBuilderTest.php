<?php

namespace Tests\Unit\AgentSwarm;

use App\Services\AgentSwarm\PromptBuilder;
use Tests\TestCase;

/**
 * Pin the prompt-construction contract — both the per-agent system
 * prompts AND the synthesis JSON parsing. The Runner depends on
 * parseSynthesis() being robust against the typical model misbehaviours
 * (markdown fences, trailing prose, malformed JSON).
 */
class PromptBuilderTest extends TestCase
{
    public function test_system_prompt_includes_agent_name_and_role(): void
    {
        $pb = new PromptBuilder();
        $sys = $pb->systemFor('sales');

        $this->assertStringContainsString('Marco Sales', $sys,
            'system prompt must seed the agent persona from AgentCatalog');
        $this->assertStringContainsString('200 words', $sys,
            'word-count constraint must be on the wire');
        $this->assertStringNotContainsString('JSON', $sys,
            'non-synthesis agents must NOT see the JSON contract');
    }

    public function test_synthesis_system_prompt_includes_json_contract(): void
    {
        $pb = new PromptBuilder();
        $sys = $pb->systemFor('sales', isSynthesis: true);

        $this->assertStringContainsString('SYNTHESIS MODE', $sys);
        $this->assertStringContainsString('"leads"',         $sys);
        $this->assertStringContainsString('"score"',         $sys);
        $this->assertStringContainsString('STRICT JSON',     $sys);
    }

    public function test_unknown_agent_falls_back_to_a_generic_persona(): void
    {
        $pb = new PromptBuilder();
        $sys = $pb->systemFor('not-a-real-agent-key');

        // Doesn't crash, produces SOMETHING usable.
        $this->assertStringContainsString('Domain analyst', $sys);
        $this->assertStringContainsString('Not-a-real-agent-key Agent', $sys);
    }

    public function test_user_message_serialises_signal_and_prior_context(): void
    {
        $pb = new PromptBuilder();
        $msg = $pb->userFor(
            agentKey: 'crm',
            signal:   ['title' => 'NSPA RFQ', 'reference' => 'NSPA-001'],
            priorContext: ['research' => ['agent' => 'research', 'text' => 'market analysis']],
        );

        $this->assertStringContainsString('SIGNAL:',               $msg);
        $this->assertStringContainsString('NSPA RFQ',              $msg);
        $this->assertStringContainsString('PRIOR_AGENT_OUTPUTS:',  $msg);
        $this->assertStringContainsString('market analysis',       $msg);
    }

    public function test_first_agent_in_chain_sees_no_prior_context(): void
    {
        $pb = new PromptBuilder();
        $msg = $pb->userFor('research', ['title' => 'X'], priorContext: []);

        $this->assertStringContainsString('(none — you are the first agent', $msg);
    }

    public function test_synthesis_user_drops_signal_and_synthesis_keys_from_context(): void
    {
        // The 'signal' key is added separately, the '_synthesis' key
        // is the slot for the synth output itself — neither should
        // leak into the CHAIN_OUTPUTS the synth sees.
        $pb = new PromptBuilder();
        $msg = $pb->synthesisUserFor(
            signal: ['title' => 'sig'],
            priorContext: [
                'signal'     => ['title' => 'sig'],   // would be a self-reference
                'research'   => ['text' => 'OK_RESEARCH'],
                '_synthesis' => ['leaked' => true],
            ],
        );

        $this->assertStringContainsString('OK_RESEARCH',  $msg);
        $this->assertStringNotContainsString('"leaked":', $msg);
    }

    public function test_parse_synthesis_extracts_clean_json(): void
    {
        $pb = new PromptBuilder();
        $text = '{"leads":[{"title":"T1","summary":"S1","score":75}]}';
        $out = $pb->parseSynthesis($text);

        $this->assertArrayNotHasKey('parse_error', $out);
        $this->assertCount(1, $out['leads']);
        $this->assertSame('T1', $out['leads'][0]['title']);
        $this->assertSame(75,   $out['leads'][0]['score']);
        $this->assertNull($out['leads'][0]['customer_hint']);
    }

    public function test_parse_synthesis_strips_markdown_fence(): void
    {
        $pb = new PromptBuilder();
        $text = "```json\n{\"leads\":[{\"title\":\"T\",\"summary\":\"S\",\"score\":50}]}\n```";
        $out = $pb->parseSynthesis($text);

        $this->assertArrayNotHasKey('parse_error', $out);
        $this->assertSame('T', $out['leads'][0]['title']);
    }

    public function test_parse_synthesis_tolerates_trailing_prose(): void
    {
        $pb = new PromptBuilder();
        // Some models sneak in commentary AFTER the JSON despite instructions.
        $text = '{"leads":[{"title":"T","summary":"S","score":40}]}'
              . "\n\nLet me know if you need any clarification!";
        $out = $pb->parseSynthesis($text);

        $this->assertArrayNotHasKey('parse_error', $out);
        $this->assertSame(40, $out['leads'][0]['score']);
    }

    public function test_parse_synthesis_returns_error_for_non_json(): void
    {
        $pb = new PromptBuilder();
        $out = $pb->parseSynthesis('this is not json at all');

        $this->assertSame('no_json_object_found', $out['parse_error']);
        $this->assertSame([], $out['leads']);
    }

    public function test_parse_synthesis_returns_error_for_malformed_json(): void
    {
        $pb = new PromptBuilder();
        // Looks like JSON but isn't valid — broken comma.
        $out = $pb->parseSynthesis('{"leads":[{"title":"T",}]}');

        $this->assertSame('json_decode_failed', $out['parse_error']);
        $this->assertSame([], $out['leads']);
    }

    public function test_parse_synthesis_normalises_missing_fields(): void
    {
        $pb = new PromptBuilder();
        // Lead with only title — others must default sensibly.
        $out = $pb->parseSynthesis('{"leads":[{"title":"Just title"}]}');

        $this->assertCount(1, $out['leads']);
        $this->assertSame('Just title', $out['leads'][0]['title']);
        $this->assertSame('',           $out['leads'][0]['summary']);
        $this->assertSame(0,            $out['leads'][0]['score']);
    }

    public function test_parse_synthesis_handles_empty_leads_list(): void
    {
        $pb = new PromptBuilder();
        $out = $pb->parseSynthesis('{"leads":[]}');

        $this->assertArrayNotHasKey('parse_error', $out);
        $this->assertSame([], $out['leads']);
    }
}
