<?php

namespace App\Services\Rewards;

/**
 * Static catalogue of every badge a user can earn. The catalogue lives
 * here (not in the DB) so adding a new badge is one PR — declarative,
 * versioned, no admin UI needed.
 *
 * Why a separate catalogue instead of just using the badge keys
 * directly:
 *   • Display: emoji + label + Portuguese description for /rewards/me
 *   • Tier: badges grouped by theme (engagement / sales / agents) for
 *     UI clustering when a user has 20+ badges
 *   • Sort order: catalogue order is the canonical render order so
 *     Bronze → Silver → Gold variants stack visually
 *
 * Evaluation lives in BadgeEvaluator — it reads this catalogue but
 * the conditions themselves are coded over there to keep this file
 * pure data.
 */
class BadgeCatalog
{
    // Engagement — "you showed up"
    public const FIRST_STEPS    = 'first_steps';
    public const DAILY_GRINDER  = 'daily_grinder';      // 7-day streak
    public const STREAK_MASTER  = 'streak_master';      // 30-day streak

    // Levels — automatic at threshold cross
    public const LEVEL_JUNIOR     = 'level_junior';     // 100 pts
    public const LEVEL_SENIOR     = 'level_senior';     // 1k pts
    public const LEVEL_SPECIALIST = 'level_specialist'; // 5k pts
    public const LEVEL_MASTER     = 'level_master';     // 20k pts
    public const LEVEL_LEGEND     = 'level_legend';     // 50k pts

    // Sales loop — "you closed"
    public const LEAD_SNIFFER  = 'lead_sniffer';        // first lead reviewed
    public const CLOSER        = 'closer';              // first lead won
    public const DEAL_MACHINE  = 'deal_machine';        // 5 leads won
    public const WHALE_HUNTER  = 'whale_hunter';        // won a high-score lead (≥ 80)

    // Agent loop — "you used the AI"
    public const AGENT_FRIEND     = 'agent_friend';     // 3 distinct agents
    public const AGENT_POLYGLOT   = 'agent_polyglot';   // 10 distinct agents
    public const FEEDBACK_GIVER   = 'feedback_giver';   // 10 thumbs total

    // Imports — "you fed the machine"
    public const IMPORT_CHAMPION = 'import_champion';   // imported 5 tenders

    /**
     * Display metadata. Tier is one of: 'engagement', 'level',
     * 'sales', 'agents', 'imports' — used by the UI to group badges
     * into sections.
     *
     * @return array<string, array{emoji:string,label:string,description:string,tier:string}>
     */
    public static function all(): array
    {
        return [
            self::FIRST_STEPS    => ['emoji' => '🌱', 'label' => 'Primeiro passo',  'description' => 'Ganhaste o teu primeiro reward — bem-vindo!',                       'tier' => 'engagement'],
            self::DAILY_GRINDER  => ['emoji' => '🔥', 'label' => 'Daily grinder',   'description' => '7 dias consecutivos com atividade.',                                'tier' => 'engagement'],
            self::STREAK_MASTER  => ['emoji' => '⚡', 'label' => 'Streak master',    'description' => '30 dias consecutivos — a sério mesmo.',                              'tier' => 'engagement'],

            self::LEVEL_JUNIOR     => ['emoji' => '🥉', 'label' => 'Junior',          'description' => 'Atingiste 100 pontos.',     'tier' => 'level'],
            self::LEVEL_SENIOR     => ['emoji' => '🥈', 'label' => 'Senior',          'description' => 'Atingiste 1.000 pontos.',   'tier' => 'level'],
            self::LEVEL_SPECIALIST => ['emoji' => '🥇', 'label' => 'Specialist',      'description' => 'Atingiste 5.000 pontos.',   'tier' => 'level'],
            self::LEVEL_MASTER     => ['emoji' => '🏆', 'label' => 'Master',          'description' => 'Atingiste 20.000 pontos.',  'tier' => 'level'],
            self::LEVEL_LEGEND     => ['emoji' => '👑', 'label' => 'Legend',          'description' => 'Atingiste 50.000 pontos. A casa toda agradece.', 'tier' => 'level'],

            self::LEAD_SNIFFER  => ['emoji' => '🔍', 'label' => 'Lead sniffer',  'description' => 'Reviste o teu primeiro lead.',                       'tier' => 'sales'],
            self::CLOSER        => ['emoji' => '💼', 'label' => 'Closer',        'description' => 'Fechaste o teu primeiro deal.',                       'tier' => 'sales'],
            self::DEAL_MACHINE  => ['emoji' => '🚀', 'label' => 'Deal machine',  'description' => '5 deals fechados — and counting.',                    'tier' => 'sales'],
            self::WHALE_HUNTER  => ['emoji' => '🐋', 'label' => 'Whale hunter',  'description' => 'Fechaste um lead com score ≥ 80.',                    'tier' => 'sales'],

            self::AGENT_FRIEND     => ['emoji' => '🤝', 'label' => 'Agent friend',    'description' => 'Usaste 3 agentes diferentes.',                  'tier' => 'agents'],
            self::AGENT_POLYGLOT   => ['emoji' => '🌐', 'label' => 'Agent polyglot',  'description' => '10 agentes diferentes — versátil.',             'tier' => 'agents'],
            self::FEEDBACK_GIVER   => ['emoji' => '💬', 'label' => 'Feedback giver',  'description' => '10 thumbs (👍 ou 👎) — treinas o sistema.',     'tier' => 'agents'],

            self::IMPORT_CHAMPION => ['emoji' => '📥', 'label' => 'Import champion', 'description' => 'Importaste 5 ficheiros de concursos.',           'tier' => 'imports'],
        ];
    }

    /** Display metadata for a single badge key. NULL for unknown keys. */
    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /**
     * All badge keys, in catalogue order. The UI uses this to render
     * a "checklist" view (earned + locked, all visible).
     *
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * Group badges by tier for the UI. Returns:
     *   tier => [ key => meta, … ]
     *
     * @return array<string, array<string, array>>
     */
    public static function byTier(): array
    {
        $grouped = [];
        foreach (self::all() as $key => $meta) {
            $grouped[$meta['tier']][$key] = $meta;
        }
        return $grouped;
    }
}
