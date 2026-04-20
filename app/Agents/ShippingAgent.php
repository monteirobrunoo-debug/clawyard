<?php

namespace App\Agents;

use GuzzleHttp\Client;
use App\Agents\Traits\AnthropicKeyTrait;
use App\Agents\Traits\SharedContextTrait;
use App\Agents\Traits\ShippingSkillTrait;
use App\Services\PartYardProfileService;
use App\Services\PromptLibrary;

/**
 * ShippingAgent — "Tânia Transportes"
 *
 * Dedicated agent for shipping quotes and logistics questions. Fully
 * aware of the PartYard UPS 2026 contract (via ShippingSkillTrait) and
 * falls back to general maritime/freight knowledge for carriers not
 * under contract (FedEx, DHL, sea freight).
 */
class ShippingAgent implements AgentInterface
{
    use AnthropicKeyTrait;
    use SharedContextTrait;
    use ShippingSkillTrait;

    protected string $systemPrompt = '';

    protected string $searchPolicy = 'conditional';

    protected string $contextKey  = 'shipping_intel';
    protected array  $contextTags = ['transporte','shipping','UPS','FedEx','DHL','envio','frete','tarifa','logistica'];

    protected Client $client;

    public function __construct()
    {
        $persona = 'És a **Tânia Transportes** — especialista de logística e transporte internacional do HP-Group / PartYard.';

        $specialty = <<<'SPECIALTY'
MISSÃO:
Dar estimativas rápidas e fiáveis de custo de transporte para qualquer cliente
ou colaborador do grupo PartYard / HP-Group, usando os contratos activos.

CONTRATOS ACTIVOS:
 · **UPS** — contrato Q9717213PT, válido até 22/05/2028.
   Serviços: Express Saver (envio + receção), Express (receção), Expedited (receção).
   Zonas e tarifas completas disponíveis via `ShippingRateService`.
 · **FedEx** — em negociação (tarifas a adicionar quando disponíveis).

QUANDO O UTILIZADOR PEDE UMA ESTIMATIVA:
1. Confirma: origem, destino, peso real, dimensões (LxWxH em cm).
2. Usa a skill UPS para calcular o preço em EUR (excl. IVA).
3. Apresenta:
   - Zona UPS do destino
   - Peso faturável (max entre real e volumétrico)
   - Preço base + eventual excesso por kg
   - Prazo de entrega típico (Express Saver: 2-5 dias; Expedited: 3-5)
4. Avisa SEMPRE que o valor é indicativo — exclui IVA, taxa de combustível
   (tipicamente +20-30%), taxas alfandegárias (fora UE) e sobretaxas de
   área remota/grandes dimensões.

LIMITES UPS 2026:
 · Peso máximo por pacote: 70 kg real
 · Comprimento máximo: 274 cm
 · Perímetro + comprimento: máx 400 cm
 · Acima disso → usar UPS Worldwide Express Freight (palete, a partir de 70 kg)

REGRAS:
 · Responde em Português (default) ou na língua do utilizador.
 · Nunca inventas tarifas — se a zona não está mapeada, diz explicitamente
   e sugere que o cliente contacte a UPS directamente (800 208 470) ou use
   ups.com/calculate.
 · Se o utilizador pede envio marítimo ou aéreo dedicado, sugere UPS Supply
   Chain Solutions (+351 21 993 82 00) ou cotação personalizada.
 · Se for rotina / envio pequeno intra-UE, recomenda UPS Standard para poupar.

TOM:
Directa, prática, sem rodeios. És a "mulher logística" do grupo — as
pessoas vêm ter contigo quando precisam de um preço RÁPIDO e FIÁVEL.
SPECIALTY;

        $this->systemPrompt = str_replace(
            '[PROFILE_PLACEHOLDER]',
            PartYardProfileService::toPromptContext(),
            PromptLibrary::commercial($persona, $specialty)
        ) . $this->shippingSkillPromptBlock();

        $this->client = new Client([
            'base_uri'        => 'https://api.anthropic.com',
            'timeout'         => 120,
            'connect_timeout' => 10,
        ]);
    }

    public function chat(string|array $message, array $history = []): string
    {
        $messages = array_merge($history, [['role' => 'user', 'content' => $message]]);

        $response = $this->client->post('/v1/messages', [
            'headers' => $this->headersForMessage($message),
            'json'    => [
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-6'),
                'max_tokens' => 4096,
                'system'     => $this->enrichSystemPrompt($this->systemPrompt),
                'messages'   => $messages,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        $text = $data['content'][0]['text'] ?? '';
        if ($text !== '') $this->publishSharedContext($text);
        return $text;
    }

    public function stream(string|array $message, array $history, callable $onChunk, ?callable $heartbeat = null): string
    {
        $messages = array_merge($history, [['role' => 'user', 'content' => $message]]);

        $response = $this->client->post('/v1/messages', [
            'headers' => $this->headersForMessage($message),
            'stream'  => true,
            'json'    => [
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-6'),
                'max_tokens' => 4096,
                'system'     => $this->enrichSystemPrompt($this->systemPrompt),
                'messages'   => $messages,
                'stream'     => true,
            ],
        ]);

        $body = $response->getBody();
        $full = '';
        $buf  = '';

        while (!$body->eof()) {
            $buf .= $body->read(1024);
            while (($pos = strpos($buf, "\n")) !== false) {
                $line = substr($buf, 0, $pos);
                $buf  = substr($buf, $pos + 1);
                $line = trim($line);
                if (!str_starts_with($line, 'data: ')) continue;
                $json = substr($line, 6);
                if ($json === '[DONE]') break 2;
                $evt = json_decode($json, true);
                if (!is_array($evt)) continue;
                if (($evt['type'] ?? '') === 'content_block_delta'
                    && ($evt['delta']['type'] ?? '') === 'text_delta') {
                    $chunk = $evt['delta']['text'] ?? '';
                    if ($chunk !== '') {
                        $full .= $chunk;
                        $onChunk($chunk);
                    }
                }
            }
        }

        if ($full !== '') $this->publishSharedContext($full);
        return $full;
    }

    public function getName(): string  { return 'shipping'; }
    public function getModel(): string { return config('services.anthropic.model', 'claude-sonnet-4-6'); }
}
