# Hybrid model routing — sensitivity-tiered LLM backends

## The problem we're solving

Every regulated-customer conversation ends with the same question:
"how do we know this prompt doesn't end up training someone else's
model?". The honest answer for a pure-Anthropic stack is a series of
contract clauses. For customers where contracts are not enough
(defence, patent drafting, specific health-data cases), the answer
has to be **the prompt never left our infrastructure in the first
place**.

But we don't want to concede quality on every prompt for the
fraction of traffic that needs this. Anthropic is visibly better than
any self-hostable model today; routing "did you ship my order yet?"
to a local Llama 8B would be gratuitous.

So we tier.

```
                            ┌──────────────────┐
                            │ SensitivityClassifier │
                            └─────────┬────────┘
                                      │
                     ┌────────────────┼────────────────┐
                     │                │                │
                 low / medium        high           (force_tier)
                     │                │                │
                     ▼                ▼                ▼
             Claude via proxy    local model     same as above
             (redacted, signed,  (inside VPC,    — used by tests
              audited)            never leaves)
```

Everything already in production (PiiRedactor, FastAPI redactor,
split-VM HMAC) continues to apply to the low/medium path. The local
path is additive — turning it on does not change what Claude sees
for the traffic that still goes there.

## Component map

| Piece | Path | Role |
|---|---|---|
| Classifier | `app/Support/SensitivityClassifier.php` | Scores a payload, returns `low` / `medium` / `high`. Deterministic, side-effect-free. |
| Routing trait | `app/Agents/Traits/ModelRoutingTrait.php` | Agents mix this in instead of calling Guzzle directly. Picks backend, converts payload shape. |
| Config | `config/services.php` → `hybrid` | Toggle + endpoint + model name. |
| Local model | ollama on the proxy VM (or a separate GPU box) | Serves OpenAI-compatible `/v1/chat/completions`. |

## Classifier tiers

Signals combined (per `SensitivityClassifier::classify`):

1. **PII density** — number of `PiiRedactor` matches per 100 words.
2. **Keyword triggers** — curated PT+EN terms with per-term weights
   (patent draft, classified, ITAR, confidencial, pre-IPO, dados
   clínicos, …).

The higher signal wins, so a single strong trigger isn't washed out
by a long low-density prompt. Both signals are visible in the log
line (`hybrid.classify`) so you can tune thresholds against real
traffic without touching code.

Default thresholds:

| Tier | PII density | Keyword score |
|---|---|---|
| `low` | < 1 / 100 words | < 0.20 |
| `medium` | ≥ 1 / 100 words | ≥ 0.20 |
| `high` | ≥ 3 / 100 words | ≥ 0.45 |

Tunable in code; exposing them as env vars is a 10-line change when
we need it.

## Wiring a local model on the proxy VM

**Note**: the proxy VM in the split-VM topology already has a 1 vCPU
/ 1 GB shape. A Llama 8B quant needs 8–10 GB RAM. You have three
choices:

1. **Upsize the proxy VM** to `s-4vcpu-16gb` (≈48 €/month). No extra
   network hops. Simplest.
2. **Dedicated local-model VM** in the same VPC, reachable over the
   same HMAC-gated channel. Lets you pick GPU.
3. **Shared inference VM** if multiple customers each need their
   own quantised model — multi-tenant with strict directory
   separation.

### Minimal ollama install (option 1)

```bash
# on proxy VM, as root
curl -fsSL https://ollama.com/install.sh | sh
systemctl enable --now ollama

# pull a Llama-class model
sudo -u ollama ollama pull llama3.1:8b-instruct-q5_K_M
```

Ollama listens on `127.0.0.1:11434` by default. Its
`/v1/chat/completions` endpoint is OpenAI-compatible, which is what
`ModelRoutingTrait::callLocalModel` targets.

In the **app VM**'s `.env`:

```
HYBRID_ROUTING_ENABLED=true
HYBRID_LOCAL_ENDPOINT=http://10.114.0.B:11434/v1/chat/completions
HYBRID_LOCAL_MODEL=llama3.1:8b-instruct-q5_K_M
```

The ollama HTTP port must be reachable from the app VM — add a
firewall rule that mirrors the existing `10.114.0.A/32` → 443 one,
but for 11434.

Security note: ollama does not natively authenticate. Either:
- Put it behind the same nginx vhost that already enforces HMAC
  (add a `location = /v1/chat/completions { proxy_pass
  http://127.0.0.1:11434; }` to `llm-proxy-internal.conf`), OR
- Ensure only the app VM's private IP can reach port 11434 via
  DO firewall.

Prefer the nginx route — the HMAC layer is the security story we
already sell customers.

## Migration path for agents

`ModelRoutingTrait` drops into an agent like this:

```php
use App\Agents\Traits\ModelRoutingTrait;

class PatentAgent extends ClaudeAgent
{
    use ModelRoutingTrait;

    public function analyse(string $draft): string
    {
        $out = $this->routedChat(
            messages: [['role' => 'user', 'content' => $draft]],
            system:   $this->systemPrompt,
            opts:     ['max_tokens' => 2048],
        );
        // $out['content']  → model text
        // $out['backend']  → "claude" | "local:llama3.1:8b-instruct-q5_K_M"
        // $out['tier']     → "low" | "medium" | "high"
        return $out['content'];
    }
}
```

Agents that don't need routing (e.g. Shipping, Aria, Kyber) stay
on the plain `anthropicGuzzleClient()` path. Roll this out agent by
agent, starting with Patent, Cyber, MilDef, and anything
customer-facing on regulated accounts.

## Observability

Every `routedChat` call emits one `hybrid.classify` log entry with:

```json
{
  "tier": "high",
  "score": 0.72,
  "signals": {
    "words": 412,
    "pii_hits": 8,
    "pii_per_100_words": 1.94,
    "keyword_score": 0.55,
    "keywords_matched": ["patent draft", "confidencial"]
  },
  "routed": "high"
}
```

That line is your traffic-mix dashboard. Pipe it into Kibana / Grafana
Loki / plain `grep` until a pattern shows up. When you're ready to
flip `HYBRID_ROUTING_ENABLED=true`, you already know what fraction of
traffic will shift.

## What this does NOT do

- It does not make Claude stop seeing any prompt. Low and medium
  tiers still go to Claude as today.
- It does not choose a specific local model. Llama 3.1 8B is a
  sensible default; Mistral Nemo 12B, Qwen 2.5, or a fine-tuned
  variant will all work as long as they speak the OpenAI chat
  format.
- It does not catch prompts the classifier rates "low" but that a
  human would rate "high". That residual risk is why the roadmap
  also includes a NER pass and a keyword-list expansion every time
  we onboard a new regulated vertical.
- It does not run today. The code is in place; flipping `enabled=true`
  without a running local model will hard-fail by design (we refuse
  to fall back to Claude — that would defeat the purpose).

## Rollout checklist

1. Provision the split-VM topology (`doc/vm-separation.md`). Local
   model runs on the proxy VM or a neighbour in the same VPC.
2. Install ollama + pull a Llama 8B quant. Measure wall-clock latency
   on a representative prompt (~200 tokens in, ~400 out).
3. Turn on `HYBRID_ROUTING_ENABLED=true` with `force_tier=low` in
   tests to confirm Claude still works, then with `force_tier=high`
   to confirm the local path works, then remove the force.
4. Migrate PatentAgent, CyberAgent, MilDefAgent to `routedChat`.
   Watch the `hybrid.classify` log for unexpected tiers.
5. Broaden to remaining agents only after a week of clean traffic.

## References

- `app/Support/SensitivityClassifier.php` — scoring rules
- `app/Agents/Traits/ModelRoutingTrait.php` — backend selection
- `config/services.php` → `hybrid` block
- `doc/vm-separation.md` — the infrastructure this sits on
- `doc/security-one-pager.md` — the customer-facing claim set
