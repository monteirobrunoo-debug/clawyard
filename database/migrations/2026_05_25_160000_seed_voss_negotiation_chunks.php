<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Seed Chris Voss "Never Split the Difference" core tactics into
 * technical_book_chunks. Permite Marta, Marco, Daniel, etc. citar
 * técnicas de negociação real (FBI hostage negotiation training)
 * quando lidam com clientes/fornecedores.
 *
 * Pedido: #53 in_progress há semanas — "Adicionar Voss à knowledge
 * base dos agentes". Sem o EPUB original disponível, criamos seed
 * curado dos 16 conceitos centrais do livro.
 *
 * Domain: 'negotiation' — separado de soldadura/naval para que
 * pesquisas técnicas não puxem Voss e vice-versa.
 *
 * Idempotente: usa book_key='voss-nstd-core' e re-corre apaga + insere.
 */
return new class extends Migration {
    public function up(): void
    {
        try {
            if (!Schema::hasTable('technical_book_chunks')) {
                return;
            }

            // Clean previous seed (idempotent)
            DB::table('technical_book_chunks')
                ->where('book_key', 'voss-nstd-core')
                ->delete();

            $bookKey   = 'voss-nstd-core';
            $bookTitle = 'Never Split the Difference (Chris Voss) — Core Tactics';
            $domain    = 'negotiation';

            $chunks = [
                [
                    'page' => 1,
                    'keywords' => ['tactical empathy', 'empathy', 'rapport'],
                    'content' => "TACTICAL EMPATHY — A pedra angular de Voss. NÃO é simpatia, é o reconhecimento explícito do estado emocional da outra parte e o uso desse reconhecimento para construir confiança. Demonstrar que entendes o ponto de vista deles (mesmo sem concordar) abre a porta a uma cooperação que pressão argumentativa nunca consegue. Tactical empathy = compreensão emocional + influência estratégica."
                ],
                [
                    'page' => 2,
                    'keywords' => ['mirroring', 'espelhar', 'last 3 words'],
                    'content' => "MIRRORING — Repete as últimas 1-3 palavras (ou as 1-3 mais importantes) da outra pessoa, em tom de pergunta. Custa zero esforço e desencadeia uma resposta automática de expansão. Exemplo: cliente: 'O preço está alto.' Tu: 'Está alto?' — ele continua a explicar. Espelhar é a forma mais simples de fazer alguém falar mais sem parecer interrogatório."
                ],
                [
                    'page' => 3,
                    'keywords' => ['labeling', 'parece-me', 'soa como'],
                    'content' => "LABELING — Nomear a emoção/posição da outra parte abertamente: 'Parece-me que estás preocupado com o prazo de entrega...', 'Soa como se houvesse uma restrição orçamental por trás disto...'. Nunca uses 'Eu acho' ou 'Eu sinto' (centra-te neles). Usa 'Parece...', 'Soa...'. Labels desactivam negativas e amplificam positivas. Identifica e nomeia antes que se acumule emoção."
                ],
                [
                    'page' => 4,
                    'keywords' => ['calibrated questions', 'how', 'what', 'como', 'porquê não'],
                    'content' => "CALIBRATED QUESTIONS — Perguntas abertas começadas com 'Como' ou 'O quê' que dão a ilusão de controlo à outra parte mas guiam-na para a tua direcção. Exemplos: 'Como posso fazer isso?', 'O que é mais importante para vocês neste prazo?', 'Como vamos resolver este problema?'. Evita 'Porquê' (acusatório) excepto 'Porque não experimentamos X?' (convida a re-considerar)."
                ],
                [
                    'page' => 5,
                    'keywords' => ['accusation audit', 'antecipar negativos'],
                    'content' => "ACCUSATION AUDIT — Antecipa e neutraliza todas as acusações negativas que o outro lado poderia fazer ANTES de as fazerem. Lista as 5-6 piores coisas que poderiam pensar de ti/da tua proposta e di-las primeiro. 'Vai parecer que estou a tentar ganhar tempo. Vai parecer que o preço é alto. Vai parecer que mudei de ideias.' — depois disso, ele já não pode usar essas armas. Desarme emocional preventivo."
                ],
                [
                    'page' => 6,
                    'keywords' => ['no', 'start of negotiation', 'começo'],
                    'content' => "NO IS THE START — 'Sim' compromete; 'Não' protege. Quando o outro diz 'Não', ele sente controlo, e só agora a negociação real começa. Pede 'Nãos' explicitamente: 'É uma má ideia se conversássemos amanhã às 14h?' — um 'Não' aqui dá-lhe segurança, deixa-o relaxar e expôr o verdadeiro problema. Evita perseguir 'Sim' (contraproducente — ele fica defensivo)."
                ],
                [
                    'page' => 7,
                    'keywords' => ['thats right', 'tem razão', 'breakthrough'],
                    'content' => "'THAT'S RIGHT' vs 'YOU'RE RIGHT' — 'Tens razão' é dispensa: ele quer que pares de falar. 'É verdade' / 'É isso mesmo' (que vem como reacção a um sumário bem feito) é breakthrough: significa que o ele se sente entendido. Trabalha para conseguir 'That's right' resumindo o ponto de vista dele tão bem que ele só pode confirmar. Esse é o momento em que muda o tom da negociação."
                ],
                [
                    'page' => 8,
                    'keywords' => ['black swan', 'unknown unknowns'],
                    'content' => "BLACK SWANS — As 3 informações desconhecidas que mudariam tudo na negociação se as soubesses. Procura activamente: pergunta o que não está dito, observa o que omite, faz reuniões cara-a-cara (revelam mais). Razões reais nunca são as primeiras: o cliente diz 'preço' mas a real é 'incerteza interna na empresa dele'. Pergunta 'Como vais explicar isto ao teu chefe?' para descobrir."
                ],
                [
                    'page' => 9,
                    'keywords' => ['anchoring', 'extreme anchor', 'âncora'],
                    'content' => "EXTREME ANCHORING — A primeira oferta puxa todas as posteriores na direcção dela (anchoring effect). Se TIVERES de abrir, abre com âncora muito agressiva (não obscena) e justifica. Se forem ELES a abrir, usa labeling para desactivar: 'Parece que tem um número muito específico em mente — como chegou a esse valor?' antes de fazer contra-oferta. Nunca contra-ataques com número — pergunta sobre o processo."
                ],
                [
                    'page' => 10,
                    'keywords' => ['bracketing', 'price bracket', 'gama'],
                    'content' => "BRACKETING — Quando tiveres de dar um número, dá uma GAMA cujo limite inferior já é o teu target. Ex: queres €100k, oferece '€100k a €150k para um projecto deste tipo'. A outra parte tende a fixar-se no meio (€125k) ou no superior. Bracketing usa o efeito de comparação automática do cérebro a teu favor. Funciona melhor quando o referencial é confuso ou novo."
                ],
                [
                    'page' => 11,
                    'keywords' => ['7-38-55 rule', 'tone', 'body language'],
                    'content' => "7-38-55 RULE (Mehrabian) — Em comunicação emocional, 7% é palavras, 38% é tom de voz, 55% é linguagem corporal. Numa negociação telefónica/email, perdes 55% — compensa com tom calibrado e palavras precisas. Em videochamada, presta atenção ao corpo deles. Reúne presencial quando o stake é alto: ler micro-expressões revela Black Swans."
                ],
                [
                    'page' => 12,
                    'keywords' => ['late night fm dj', 'voice', 'voz'],
                    'content' => "LATE-NIGHT FM DJ VOICE — Tom calmo, profundo, desacelerado, com leve inflexão descendente no fim. Sinaliza ao cérebro deles 'tenho tudo sob controlo', desactiva amígdala (ameaça). Usa em momentos de tensão. NÃO uses voz aguda/alta — actívica modo defesa. A segunda voz mais eficaz: 'positiva/playful' (sorrir genuinamente enquanto falas) — para conversas amistosas e construir rapport inicial."
                ],
                [
                    'page' => 13,
                    'keywords' => ['loss aversion', 'aversão à perda', 'framing'],
                    'content' => "LOSS AVERSION FRAMING — Pessoas reagem 2-3× mais ao medo de perder algo do que à possibilidade de ganhar o equivalente. Frame propostas em termos de PERDA evitada, não ganho. 'Se não decidirmos hoje, perdes o desconto X' em vez de 'Se decidires hoje, ganhas X'. 'Esta capacidade vai ficar reservada para outro cliente.' Funciona porque o cérebro reptiliano protege o status quo."
                ],
                [
                    'page' => 14,
                    'keywords' => ['how am I supposed to do that', 'forced empathy'],
                    'content' => "'HOW AM I SUPPOSED TO DO THAT?' — Quando recebes uma exigência impossível, em vez de dizer 'Não posso', pergunta: 'Como é que esperas que eu faça isso?'. Força a outra parte a entrar nos teus constrangimentos. Em vez de discutir o NÃO, transferes o problema para eles e fazem o trabalho de procurar soluções dentro dos teus limites. Calibrated question disfarçada de pedido de ajuda."
                ],
                [
                    'page' => 15,
                    'keywords' => ['batna', 'walk away', 'alternativas'],
                    'content' => "BATNA + WALK-AWAY POINT — Sabe a tua melhor alternativa antes de entrar (Best Alternative To Negotiated Agreement). Define o teu walk-away point e respeita-o. Voss diverge ligeiramente de Fisher/Ury: o objectivo NÃO é só não ficar abaixo do BATNA, é descobrir 'Black Swans' que mudam o quadro inteiro. Mas BATNA sólido dá-te tranquilidade emocional — sem ela, vais ceder."
                ],
                [
                    'page' => 16,
                    'keywords' => ['deadline', 'time pressure', '65-95 rule'],
                    'content' => "DEADLINE PRESSURE (65/95) — 65% das concessões acontecem nos últimos 5% do tempo, e 95% nos últimos 20%. Usa deadlines a teu favor: define-os tu, mantém-nos firmes. Quando ELES impõem deadline, lembra-te que deadlines são quase sempre soft (eles também querem fechar). Pergunta 'O que acontece se passarmos esse prazo?' — frequentemente revela que o deadline é arbitrário."
                ],
                [
                    'page' => 17,
                    'keywords' => ['silence', 'effective pause', 'silêncio'],
                    'content' => "EFFECTIVE PAUSE — Depois de fazer mirror ou label, FICA EM SILÊNCIO 4+ segundos. Maior parte das pessoas não tolera silêncio e enche-o com mais informação (incluindo a que não pretendia dar). Negociadores amadores falam para preencher; profissionais usam silêncio como ferramenta. A informação que sai depois de um silêncio prolongado é frequentemente a mais valiosa da reunião."
                ],
                [
                    'page' => 18,
                    'keywords' => ['compromise', 'never split the difference', 'meio termo'],
                    'content' => "NUNCA DIVIDIR A DIFERENÇA — Compromise é frequentemente solução preguiçosa onde ambas as partes ficam mal: vestir sapato preto + castanho não dá par. Em vez de ceder ao meio termo, faz mais labeling, mais calibrated questions, descobre o que está REALMENTE por trás da posição deles. Quase sempre há uma solução melhor que satisfaz ambos sem ninguém ceder no que importa. Compromiso = falência de criatividade."
                ],
            ];

            $now = now();
            $inserted = 0;
            foreach ($chunks as $c) {
                DB::table('technical_book_chunks')->insert([
                    'book_key'   => $bookKey,
                    'book_title' => $bookTitle,
                    'domain'     => $domain,
                    'page_no'    => $c['page'],
                    'content'    => $c['content'],
                    'keywords'   => json_encode($c['keywords']),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $inserted++;
            }

            Log::info("Voss seed: inserted {$inserted} negotiation chunks");
        } catch (\Throwable $e) {
            Log::warning('seed_voss_negotiation_chunks failed (non-fatal): ' . $e->getMessage());
        }
    }

    public function down(): void
    {
        try {
            if (Schema::hasTable('technical_book_chunks')) {
                DB::table('technical_book_chunks')
                    ->where('book_key', 'voss-nstd-core')
                    ->delete();
            }
        } catch (\Throwable) { /* idempotent */ }
    }
};
