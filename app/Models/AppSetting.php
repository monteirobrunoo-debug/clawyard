<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Operator-editable settings store. Wraps key/value lookups in a
 * tiny cache layer so feature-flag checks are essentially free.
 *
 * Usage:
 *   AppSetting::get('feature.ticker.enabled', true)         // boolean default
 *   AppSetting::set('feature.ticker.enabled', false, $userId)
 *   AppSetting::all_grouped()                               // for the admin panel
 */
class AppSetting extends Model
{
    protected $fillable = ['key', 'value', 'category', 'description', 'updated_by_user_id'];

    /** Map of key → ['default' => mixed, 'type' => bool|int|string, 'category', 'description']. */
    public const KNOWN = [
        'feature.ticker.enabled' => [
            'default'     => true,
            'type'        => 'bool',
            'category'    => 'feature_flags',
            'description' => 'Faixa "Live ticker" no topo do dashboard.',
        ],
        'feature.activity_meter.enabled' => [
            'default'     => true,
            'type'        => 'bool',
            'category'    => 'feature_flags',
            'description' => 'Pill flutuante de pulso (canto inferior-direito).',
        ],
        'feature.presence.enabled' => [
            'default'     => true,
            'type'        => 'bool',
            'category'    => 'feature_flags',
            'description' => '"N pessoas aqui" e heartbeat /api/presence.',
        ],
        'feature.cmdk.enabled' => [
            'default'     => true,
            'type'        => 'bool',
            'category'    => 'feature_flags',
            'description' => 'Command palette com Cmd+K / Ctrl+K.',
        ],
        'feature.global_dropzone.enabled' => [
            'default'     => true,
            'type'        => 'bool',
            'category'    => 'feature_flags',
            'description' => 'Drag-drop universal de PDFs para hp-history.',
        ],
        'feature.confidential_mode_default' => [
            'default'     => false,
            'type'        => 'bool',
            'category'    => 'security',
            'description' => 'Concursos novos importados começam como confidenciais (LLM/web bloqueados).',
        ],
        'notification.lead_score_threshold' => [
            'default'     => 70,
            'type'        => 'int',
            'category'    => 'notifications',
            'description' => 'Score mínimo para um lead ser notificado nos digests.',
        ],
        'notification.tender_deadline_alert_hours' => [
            'default'     => 24,
            'type'        => 'int',
            'category'    => 'notifications',
            'description' => 'Horas antes do deadline para alerta automático.',
        ],
    ];

    public static function get(string $key, $default = null)
    {
        $cached = Cache::remember('app_setting:' . $key, 300, function () use ($key) {
            $row = static::where('key', $key)->first();
            return $row ? $row->value : '__MISS__';
        });
        if ($cached === '__MISS__') {
            $known = self::KNOWN[$key]['default'] ?? null;
            return $default !== null ? $default : $known;
        }
        return self::cast($key, $cached);
    }

    public static function set(string $key, $value, ?int $userId = null): void
    {
        $strVal = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        $meta = self::KNOWN[$key] ?? null;
        static::updateOrCreate(
            ['key' => $key],
            [
                'value'              => $strVal,
                'category'           => $meta['category']    ?? 'general',
                'description'        => $meta['description'] ?? null,
                'updated_by_user_id' => $userId,
            ],
        );
        Cache::forget('app_setting:' . $key);
    }

    /** Returns all known settings grouped by category, with current values. */
    public static function all_grouped(): array
    {
        $rows = static::all()->keyBy('key');
        $out = [];
        foreach (self::KNOWN as $key => $meta) {
            $cat = $meta['category'];
            $out[$cat] = $out[$cat] ?? [];
            $current = $rows->has($key) ? self::cast($key, $rows[$key]->value) : $meta['default'];
            $out[$cat][] = [
                'key'         => $key,
                'value'       => $current,
                'default'     => $meta['default'],
                'type'        => $meta['type'],
                'description' => $meta['description'],
                'updated_at'  => $rows->has($key) ? $rows[$key]->updated_at?->diffForHumans() : null,
            ];
        }
        return $out;
    }

    private static function cast(string $key, $raw)
    {
        $type = self::KNOWN[$key]['type'] ?? 'string';
        return match ($type) {
            'bool' => $raw === '1' || $raw === 1 || $raw === true || $raw === 'true',
            'int'  => (int) $raw,
            default => (string) $raw,
        };
    }
}
