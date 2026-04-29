<?php

namespace App\Services\Robotparts;

/**
 * The robot anatomy. Each slot has an owner agent (sometimes 2)
 * whose persona naturally aligns with the part's function. The
 * shop committee uses this map to BIAS the buyer's pick toward
 * its assigned slot — so vessel buys depth sensors (not random
 * ultrasonic modules), engineer buys actuators, finance buys
 * power systems, etc.
 *
 * Why declarative PHP file:
 *   • Editing the mapping is a single PR — no migration, no UI.
 *   • Clear ownership: each slot has 1-2 owners, not 24 agents
 *     all suggesting random parts.
 *   • Easy to read: this is the robot's "skeleton blueprint" in
 *     one place.
 *
 * The end goal: a real assembled robot the agents can inhabit.
 * Each slot, when filled, brings the robot closer to:
 *   • Moving (legs + actuators)
 *   • Seeing/hearing (cameras + microphones)
 *   • Communicating (speakers + comms modules)
 *   • Navigating (GPS + IMU + compass)
 *   • Self-maintaining (battery + BMS + charging)
 */
class RobotBlueprint
{
    public const SLOT_BRAIN          = 'brain';
    public const SLOT_EYES           = 'eyes';
    public const SLOT_EARS           = 'ears';
    public const SLOT_VOICE          = 'voice';
    public const SLOT_MUSCLES        = 'muscles';
    public const SLOT_HANDS          = 'hands';
    public const SLOT_LEGS           = 'legs';
    public const SLOT_HEART          = 'heart';
    public const SLOT_SKIN           = 'skin';
    public const SLOT_ANTENNA        = 'antenna';
    public const SLOT_COMPASS        = 'compass';
    public const SLOT_AMBIENT        = 'ambient_sensors';
    public const SLOT_SECURITY       = 'security';
    public const SLOT_PATENT         = 'patent_mech';
    public const SLOT_BRANDING       = 'branding';

    /**
     * Each slot's metadata: emoji + display label + Portuguese
     * description (purpose) + list of typical parts the agent should
     * search for + ordered list of owning agents (first = primary).
     *
     * @return array<string, array{
     *     emoji: string,
     *     label: string,
     *     purpose: string,
     *     typical_parts: string,
     *     owners: array<int, string>,
     * }>
     */
    public static function all(): array
    {
        return [
            self::SLOT_BRAIN => [
                'emoji' => '🧠',
                'label' => 'Cérebro / Compute',
                'purpose' => 'Onde correrá o software do robot — agentes embarcados, decisões em tempo real, conectividade ao swarm.',
                'typical_parts' => 'Raspberry Pi 4/5, ESP32-S3 dev board, Jetson Nano, Arduino Mega, microSD card, heatsink',
                'owners' => ['thinking', 'claude', 'briefing'],
            ],
            self::SLOT_EYES => [
                'emoji' => '👁️',
                'label' => 'Olhos / Vision',
                'purpose' => 'Câmaras para o robot ver o mundo — reconhecimento de objectos, navegação visual, leitura de QR codes.',
                'typical_parts' => 'OV5640 camera module, Pi Camera v3, USB webcam, IR night-vision camera, fish-eye lens',
                'owners' => ['research', 'document'],
            ],
            self::SLOT_EARS => [
                'emoji' => '👂',
                'label' => 'Ouvidos / Audio in',
                'purpose' => 'Microfones para o robot ouvir comandos, gravar áudio, detectar sons de alarme.',
                'typical_parts' => 'I2S MEMS microphone, USB lavalier mic, 4-mic array, MAX9814 amp',
                'owners' => ['email'],
            ],
            self::SLOT_VOICE => [
                'emoji' => '🗣️',
                'label' => 'Voz / Audio out',
                'purpose' => 'Altifalantes para o robot falar — anúncios de status, alertas, conversação com humanos via TTS.',
                'typical_parts' => 'Mini speaker 8Ω 3W, PAM8403 amplifier, audio jack, piezo buzzer',
                'owners' => ['sales', 'support'],
            ],
            self::SLOT_MUSCLES => [
                'emoji' => '💪',
                'label' => 'Músculos / Actuators',
                'purpose' => 'Servos e motores que dão movimento — articulações, cabeça giratória, braços que se elevam.',
                'typical_parts' => 'MG90S servo, SG90 micro servo, NEMA 17 stepper, DC gear motor 6V, servo bracket',
                'owners' => ['engineer', 'vessel'],
            ],
            self::SLOT_HANDS => [
                'emoji' => '🦾',
                'label' => 'Mãos / Manipuladores',
                'purpose' => 'Garras ou ferramentas no end-effector para agarrar, apontar, tocar.',
                'typical_parts' => 'Servo gripper, suction cup, magnetic pickup, soft silicone gripper, pen holder',
                'owners' => ['engineer', 'capitao'],
            ],
            self::SLOT_LEGS => [
                'emoji' => '🦵',
                'label' => 'Locomoção',
                'purpose' => 'Rodas, esteiras ou pernas que fazem o robot mover-se pelo mundo.',
                'typical_parts' => '60mm rubber wheel, omni wheel, tank tread set, mecanum wheel, BO motor + wheel',
                'owners' => ['shipping', 'capitao'],
            ],
            self::SLOT_HEART => [
                'emoji' => '🔋',
                'label' => 'Coração / Energia',
                'purpose' => 'Bateria, BMS e regulador — a "tesouraria" energética do robot. Sem isto, nada funciona.',
                'typical_parts' => '18650 Li-ion 3500mAh, LiPo 2S 5000mAh, BMS 3S, USB-C PD module, buck converter 5V',
                'owners' => ['finance', 'energy'],
            ],
            self::SLOT_SKIN => [
                'emoji' => '🛡️',
                'label' => 'Pele / Chassis',
                'purpose' => 'Estrutura externa que protege os componentes internos — placas, cantos, parafusos.',
                'typical_parts' => 'Aluminium L-bracket 20×20, M3 screw set, T-slot extrusion, ABS panel sheet, rubber feet',
                'owners' => ['mildef', 'aria'],
            ],
            self::SLOT_ANTENNA => [
                'emoji' => '📡',
                'label' => 'Antena / Comms',
                'purpose' => 'Módulos de comunicação — WiFi, Bluetooth, LoRa para o robot falar com o swarm e a internet.',
                'typical_parts' => 'ESP32 WiFi+BT module, HC-05 Bluetooth, LoRa SX1278 module, NRF24L01, GSM SIM800L',
                'owners' => ['crm', 'kyber'],
            ],
            self::SLOT_COMPASS => [
                'emoji' => '🧭',
                'label' => 'Bússola / Navegação',
                'purpose' => 'GPS + IMU para saber onde está e em que direcção aponta. Crítico para navegação autónoma.',
                'typical_parts' => 'NEO-6M GPS module, MPU-6050 IMU, BNO055 9-DOF, HMC5883L compass, GPS antenna',
                'owners' => ['capitao', 'vessel'],
            ],
            self::SLOT_AMBIENT => [
                'emoji' => '🌡️',
                'label' => 'Sensores ambiente',
                'purpose' => 'Temperatura, humidade, gases, qualidade do ar — consciência do meio onde está.',
                'typical_parts' => 'BME280 temp+humidity, MQ-2 gas sensor, SCD30 CO2, DHT22, ambient light sensor',
                'owners' => ['quantum', 'energy'],
            ],
            self::SLOT_SECURITY => [
                'emoji' => '🔐',
                'label' => 'Segurança / Acesso',
                'purpose' => 'RFID, biometria, leitor de impressão digital — controla quem pode interagir com o robot.',
                'typical_parts' => 'RC522 RFID reader, R307 fingerprint sensor, OLED PIN pad, NFC PN532',
                'owners' => ['aria', 'kyber'],
            ],
            self::SLOT_PATENT => [
                'emoji' => '💎',
                'label' => 'Mecanismo único',
                'purpose' => 'Componente patenteável ou inovador — algo distinto que justifique uma patente futura PartYard.',
                'typical_parts' => 'Custom hinge, novel coupling, magnetic levitation module, modular swap mechanism',
                'owners' => ['patent'],
            ],
            self::SLOT_BRANDING => [
                'emoji' => '🎯',
                'label' => 'Marcas / Branding',
                'purpose' => 'LEDs, OLED com logo PartYard, NFC tag com URL — identidade visual do robot.',
                'typical_parts' => 'WS2812B LED strip, 0.96" OLED display, NeoPixel ring, branded vinyl decal, NFC tag',
                'owners' => ['briefing'],
            ],
        ];
    }

    /**
     * Reverse map: agent_key → list of slots they own. An agent can
     * be primary on one slot AND a secondary on another (e.g. capitao
     * is primary on Hands but secondary on Compass).
     *
     * @return array<string, array<int, string>>
     */
    public static function slotsByAgent(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;

        $cache = [];
        foreach (self::all() as $slot => $meta) {
            foreach ($meta['owners'] as $agentKey) {
                $cache[$agentKey][] = $slot;
            }
        }
        return $cache;
    }

    /**
     * Pick the slot an agent should fill on its NEXT shop round.
     * Strategy: prefer slots the agent OWNS as primary (first in
     * owners list), then secondary. Among those, prefer slots that
     * are still unfilled (no STL_READY / PURCHASED order yet) so we
     * make progress toward a complete robot before duplicating slots.
     *
     * Returns null if the agent is not assigned to any slot.
     */
    public static function nextSlotFor(string $agentKey, array $filledSlots = []): ?string
    {
        $owned = self::slotsByAgent()[$agentKey] ?? [];
        if (empty($owned)) return null;

        // Prefer unfilled.
        foreach ($owned as $slot) {
            if (!in_array($slot, $filledSlots, true)) {
                return $slot;
            }
        }

        // All owned slots already filled — agent can buy a duplicate
        // (upgrade) of its primary slot.
        return $owned[0] ?? null;
    }

    /** Lookup metadata for a single slot. */
    public static function find(string $slot): ?array
    {
        return self::all()[$slot] ?? null;
    }

    /** All slot keys, in canonical display order. */
    public static function keys(): array
    {
        return array_keys(self::all());
    }
}
