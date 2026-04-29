<?php

namespace App\Models;

use App\Services\AgentCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Per-agent wallet. ONE row per agent_key, lazily created the first
 * time an agent earns or spends.
 *
 * Read paths:
 *   • /agents/{key} performance card — show balance + lifetime earned
 *   • Shop committee cron — only invites agents with balance > $X
 *
 * Write paths:
 *   • WalletCreditService::credit() — daily cron credits earnings
 *   • PartOrderService::charge()    — debits when agent buys a part
 *   • Both go through the model methods below so totals stay in sync
 */
class AgentWallet extends Model
{
    protected $table = 'agent_wallets';
    protected $primaryKey = 'agent_key';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'agent_key',
        'balance_usd',
        'lifetime_earned_usd',
        'lifetime_spent_usd',
        'last_credit_at',
        'last_credit_basis',
    ];

    protected function casts(): array
    {
        return [
            'balance_usd'         => 'decimal:4',
            'lifetime_earned_usd' => 'decimal:4',
            'lifetime_spent_usd'  => 'decimal:4',
            'last_credit_at'      => 'datetime',
            'last_credit_basis'   => 'array',
        ];
    }

    /** Display metadata from the catalogue (name, emoji, role). */
    public function meta(): ?array
    {
        return AgentCatalog::find($this->agent_key);
    }

    /** All purchase decisions this agent has made. */
    public function orders(): HasMany
    {
        return $this->hasMany(PartOrder::class, 'agent_key', 'agent_key');
    }

    /**
     * Credit (positive) or debit (negative) the wallet. Updates both
     * balance + lifetime counter atomically. Returns the new balance.
     *
     * Caller is responsible for transaction wrapping when bundling
     * multiple writes (e.g. wallet debit + part_order insert).
     */
    public function adjust(float $amount): float
    {
        if ($amount > 0) {
            $this->balance_usd         = (float) $this->balance_usd + $amount;
            $this->lifetime_earned_usd = (float) $this->lifetime_earned_usd + $amount;
        } elseif ($amount < 0) {
            // Debit — never goes below zero. Caller must check
            // balance BEFORE attempting to spend (canAfford()).
            $abs = abs($amount);
            $this->balance_usd        = max(0, (float) $this->balance_usd - $abs);
            $this->lifetime_spent_usd = (float) $this->lifetime_spent_usd + $abs;
        }
        $this->save();
        return (float) $this->balance_usd;
    }

    /** Cheap pre-check before debiting to avoid pointless transactions. */
    public function canAfford(float $amount): bool
    {
        return (float) $this->balance_usd >= $amount;
    }

    /**
     * Lazy-create then return the wallet for the given agent_key.
     * Used by the credit cron + the shop committee.
     */
    public static function forAgent(string $agentKey): self
    {
        return self::firstOrCreate(['agent_key' => $agentKey]);
    }
}
