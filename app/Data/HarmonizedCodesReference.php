<?php

namespace App\Data;

/**
 * Harmonized System (HS) / Combined Nomenclature (CN) / TARIC reference.
 *
 * Structure:
 *   2 digits  → Capítulo (HS)
 *   4 digits  → Posição
 *   6 digits  → Subposição HS (padrão OMC/WCO — comum a 200+ países)
 *   8 digits  → CN (UE — Reg. 2658/87)
 *  10 digits  → TARIC (UE — medidas aduaneiras específicas)
 *  11+ dig.   → TARIC nacional (PT adiciona 2 dígitos para IVA/ICE)
 *
 * Fonte oficial UE:  https://data.europa.eu/data/datasets/eu-customs-tariff-taric
 * Consulta online:   https://ec.europa.eu/taxation_customs/dds2/taric/taric_consultation.jsp
 * Bulk / CIRCABC:    https://circabc.europa.eu/ui/group/0e5f18c2-4b2f-42e9-aed4-dfe50ae1263b
 *
 * Contém as 21 Secções do Sistema Harmonizado, todos os 99 capítulos
 * e os capítulos-chave para o universo PartYard (maquinaria, aviação,
 * náutica, metais, instrumentos de precisão, armas).
 */
final class HarmonizedCodesReference
{
    /**
     * The 21 HS Sections (WCO). Each section groups consecutive chapters.
     */
    public const SECTIONS = [
        'I'    => ['chapters' => '01-05', 'title' => 'Animais vivos e produtos do reino animal'],
        'II'   => ['chapters' => '06-14', 'title' => 'Produtos do reino vegetal'],
        'III'  => ['chapters' => '15',    'title' => 'Gorduras e óleos animais ou vegetais'],
        'IV'   => ['chapters' => '16-24', 'title' => 'Produtos das indústrias alimentares; bebidas; tabacos'],
        'V'    => ['chapters' => '25-27', 'title' => 'Produtos minerais'],
        'VI'   => ['chapters' => '28-38', 'title' => 'Produtos das indústrias químicas'],
        'VII'  => ['chapters' => '39-40', 'title' => 'Plásticos e borrachas e suas obras'],
        'VIII' => ['chapters' => '41-43', 'title' => 'Peles, couros, peles com pêlo; artigos de viagem'],
        'IX'   => ['chapters' => '44-46', 'title' => 'Madeira, carvão vegetal, cortiça, cestaria'],
        'X'    => ['chapters' => '47-49', 'title' => 'Pasta de madeira, papel, livros'],
        'XI'   => ['chapters' => '50-63', 'title' => 'Matérias têxteis e suas obras'],
        'XII'  => ['chapters' => '64-67', 'title' => 'Calçado, chapéus, guarda-chuvas, penas, flores artificiais'],
        'XIII' => ['chapters' => '68-70', 'title' => 'Pedra, gesso, cimento, cerâmica, vidro'],
        'XIV'  => ['chapters' => '71',    'title' => 'Pérolas, pedras preciosas, metais preciosos, moedas'],
        'XV'   => ['chapters' => '72-83', 'title' => 'Metais comuns e suas obras'],
        'XVI'  => ['chapters' => '84-85', 'title' => 'Máquinas, aparelhos mecânicos e eléctricos'],
        'XVII' => ['chapters' => '86-89', 'title' => 'Material de transporte (ferroviário, rodoviário, aéreo, naval)'],
        'XVIII'=> ['chapters' => '90-92', 'title' => 'Instrumentos de óptica, fotografia, medida, precisão, medicina; relógios; instrumentos musicais'],
        'XIX'  => ['chapters' => '93',    'title' => 'Armas e munições'],
        'XX'   => ['chapters' => '94-96', 'title' => 'Mercadorias e produtos diversos (mobiliário, brinquedos, manufacturas diversas)'],
        'XXI'  => ['chapters' => '97',    'title' => 'Objectos de arte, de colecção e antiguidades'],
    ];

    /**
     * All 99 HS chapters (2-digit level). Chapter 77 is reserved; 98-99 are
     * national/special at EU level.
     */
    public const CHAPTERS = [
        '01' => 'Animais vivos',
        '02' => 'Carnes e miudezas, comestíveis',
        '03' => 'Peixes e crustáceos, moluscos',
        '04' => 'Leite e lacticínios; ovos; mel',
        '05' => 'Outros produtos de origem animal',
        '06' => 'Plantas vivas e produtos de floricultura',
        '07' => 'Produtos hortícolas',
        '08' => 'Frutas; cascas de citrinos e de melões',
        '09' => 'Café, chá, mate e especiarias',
        '10' => 'Cereais',
        '11' => 'Produtos da indústria de moagem; malte; amidos; glúten',
        '12' => 'Sementes e frutos oleaginosos',
        '13' => 'Gomas, resinas e outros sucos vegetais',
        '14' => 'Matérias para entrançar e outros produtos vegetais',
        '15' => 'Gorduras e óleos animais ou vegetais; ceras',
        '16' => 'Preparações de carne, peixe, crustáceos',
        '17' => 'Açúcares e produtos de confeitaria',
        '18' => 'Cacau e suas preparações',
        '19' => 'Preparações à base de cereais, farinha, amido, leite',
        '20' => 'Preparações de produtos hortícolas, frutas',
        '21' => 'Preparações alimentícias diversas',
        '22' => 'Bebidas, líquidos alcoólicos e vinagres',
        '23' => 'Resíduos e desperdícios das indústrias alimentares; alimentos para animais',
        '24' => 'Tabaco e sucedâneos de tabaco manufacturados',
        '25' => 'Sal; enxofre; terras e pedras; gessos; cal; cimentos',
        '26' => 'Minérios, escórias e cinzas',
        '27' => 'Combustíveis minerais, óleos minerais, ceras',
        '28' => 'Produtos químicos inorgânicos',
        '29' => 'Produtos químicos orgânicos',
        '30' => 'Produtos farmacêuticos',
        '31' => 'Adubos/fertilizantes',
        '32' => 'Extractos tanantes e tintoriais; tintas; vernizes',
        '33' => 'Óleos essenciais e resinóides; perfumaria, cosmética',
        '34' => 'Sabões; ceras artificiais; velas',
        '35' => 'Matérias albuminóides; colas; enzimas',
        '36' => 'Pólvoras e explosivos; artigos pirotécnicos; fósforos',
        '37' => 'Produtos fotográficos ou cinematográficos',
        '38' => 'Produtos químicos diversos',
        '39' => 'Plásticos e suas obras',
        '40' => 'Borracha e suas obras',
        '41' => 'Peles (excepto peles com pêlo) e couros',
        '42' => 'Obras de couro; artigos de viagem, bolsas',
        '43' => 'Peles com pêlo, peles com pêlo artificiais',
        '44' => 'Madeira, carvão vegetal e obras de madeira',
        '45' => 'Cortiça e suas obras',
        '46' => 'Obras de espartaria ou cestaria',
        '47' => 'Pastas de madeira; papel ou cartão para reciclar',
        '48' => 'Papel e cartão; obras de papel',
        '49' => 'Livros, jornais, impressos; manuscritos',
        '50' => 'Seda',
        '51' => 'Lã, pêlos finos, crinas; tecidos',
        '52' => 'Algodão',
        '53' => 'Outras fibras têxteis vegetais; fios de papel',
        '54' => 'Filamentos sintéticos ou artificiais',
        '55' => 'Fibras sintéticas ou artificiais, descontínuas',
        '56' => 'Pastas (ouates), feltros, não-tecidos; cordéis, cordas',
        '57' => 'Tapetes e outros revestimentos têxteis',
        '58' => 'Tecidos especiais; bordados; tapeçarias',
        '59' => 'Tecidos impregnados, revestidos; artigos técnicos têxteis',
        '60' => 'Tecidos de malha',
        '61' => 'Vestuário de malha',
        '62' => 'Vestuário, excepto de malha',
        '63' => 'Outros artigos têxteis confeccionados; trapos',
        '64' => 'Calçado, polainas e artigos semelhantes',
        '65' => 'Chapéus e artigos de uso semelhante',
        '66' => 'Guarda-chuvas, bengalas, chicotes',
        '67' => 'Penas e penugem preparadas; flores artificiais; cabelo humano',
        '68' => 'Obras de pedra, gesso, cimento, amianto, mica',
        '69' => 'Produtos cerâmicos',
        '70' => 'Vidro e suas obras',
        '71' => 'Pérolas, pedras preciosas, metais preciosos; bijutaria; moedas',
        '72' => 'Ferro fundido, ferro e aço',
        '73' => 'Obras de ferro fundido, ferro e aço (parafusos, tubos, perfis)',
        '74' => 'Cobre e suas obras',
        '75' => 'Níquel e suas obras',
        '76' => 'Alumínio e suas obras',
        '77' => '(reservado pela OMA para uso futuro)',
        '78' => 'Chumbo e suas obras',
        '79' => 'Zinco e suas obras',
        '80' => 'Estanho e suas obras',
        '81' => 'Outros metais comuns (tungsténio, molibdénio, titânio, etc.)',
        '82' => 'Ferramentas, artefactos de cutelaria, colheres e garfos, de metais comuns',
        '83' => 'Obras diversas de metais comuns',
        '84' => 'Reactores nucleares, caldeiras, máquinas, aparelhos e instrumentos mecânicos',
        '85' => 'Máquinas, aparelhos e materiais eléctricos; sua partes; aparelhos de gravação/reprodução',
        '86' => 'Veículos e material para vias férreas ou semelhantes',
        '87' => 'Veículos automóveis, tractores, motociclos (excepto ferroviários)',
        '88' => 'Aeronaves e aparelhos espaciais, e suas partes',
        '89' => 'Embarcações e estruturas flutuantes',
        '90' => 'Instrumentos e aparelhos de óptica, fotografia, cinematografia, medida, controlo, precisão; medicinais e cirúrgicos',
        '91' => 'Relógios e aparelhos de relojoaria; suas partes',
        '92' => 'Instrumentos musicais; suas partes e acessórios',
        '93' => 'Armas e munições; suas partes e acessórios',
        '94' => 'Mobiliário; aparelhos de iluminação; construções pré-fabricadas',
        '95' => 'Brinquedos, jogos e artigos de desporto',
        '96' => 'Obras diversas',
        '97' => 'Objectos de arte, de colecção e antiguidades',
        '98' => '(UE — uso especial; tratamento aduaneiro específico)',
        '99' => '(UE — uso especial; ex.: mercadorias para fornecimento a bordo)',
    ];

    /**
     * Deep-dive on the chapters most relevant to the PartYard universe
     * (maquinaria, aeroespacial, naval, instrumentos, armas, metais).
     * Each entry lists common 4-digit posições with plain-language use cases.
     */
    public const PARTYARD_KEY_HEADINGS = [
        '84' => [
            'label' => 'Máquinas e aparelhos mecânicos',
            'headings' => [
                '8401' => 'Reactores nucleares; elementos combustíveis',
                '8407' => 'Motores de pistão, de ignição por faísca (gasolina, aviação ligeira)',
                '8408' => 'Motores diesel (marítimos incluídos)',
                '8409' => 'Partes para motores 8407/8408 (cabeçotes, pistões, camisas, êmbolos)',
                '8411' => 'Turborreactores, turbopropulsores, turbinas a gás — AVIAÇÃO',
                '8412' => 'Outros motores e máquinas motrizes (hidráulicos, pneumáticos)',
                '8413' => 'Bombas para líquidos',
                '8414' => 'Bombas de ar, compressores, ventiladores',
                '8419' => 'Permutadores de calor, esterilizadores, secadores',
                '8421' => 'Centrifugadores, filtros',
                '8431' => 'Partes para máquinas-ferramenta e de obras públicas',
                '8481' => 'Válvulas (de esfera, gaveta, retenção, reguladoras) — CRÍTICO marítimo/industrial',
                '8482' => 'Rolamentos (esferas, rolos) — CRÍTICO',
                '8483' => 'Veios de transmissão, engrenagens, redutores, embraiagens',
                '8484' => 'Juntas metaloplásticas (gaskets)',
                '8487' => 'Partes de máquinas sem ligações eléctricas n.e.',
            ],
        ],
        '85' => [
            'label' => 'Máquinas e aparelhos eléctricos',
            'headings' => [
                '8501' => 'Motores eléctricos e geradores',
                '8504' => 'Transformadores, conversores, UPS',
                '8511' => 'Aparelhos eléctricos de ignição/arranque para motores (velas, alternadores)',
                '8517' => 'Telefones, modems, routers, switches, comunicação digital',
                '8525' => 'Aparelhos de transmissão (radares, drones com câmara)',
                '8526' => 'Aparelhos de radiodetecção / radio-sondagem (RADAR, sonar)',
                '8527' => 'Receptores de radiodifusão',
                '8528' => 'Monitores, projectores, receptores de televisão',
                '8531' => 'Aparelhos eléctricos de sinalização acústica/visual (alarmes)',
                '8535' => 'Aparelhos de corte, protecção e ligação de circuitos > 1 kV',
                '8536' => 'Idem ≤ 1 kV (relés, contactores, fichas, tomadas)',
                '8537' => 'Quadros, painéis, armários de comando eléctrico',
                '8541' => 'Díodos, transístores, células fotovoltaicas',
                '8542' => 'Circuitos integrados (chips)',
                '8544' => 'Fios e cabos eléctricos, incluindo fibra óptica',
            ],
        ],
        '88' => [
            'label' => 'Aeronáutica e espaço (Collins Aerospace etc.)',
            'headings' => [
                '8802' => 'Aviões e helicópteros com motor',
                '8803' => 'Partes de veículos aéreos e espaciais (trem de aterragem, fuselagem)',
                '8805' => 'Aparelhos de lançamento e aterragem de aeronaves; simuladores de voo',
                '8806' => 'Aeronaves não tripuladas (drones)',
                '8807' => 'Partes de drones',
            ],
        ],
        '89' => [
            'label' => 'Náutica / marítimo (universo PartYard)',
            'headings' => [
                '8901' => 'Navios de passageiros, cruzeiros, ferries, cargueiros',
                '8902' => 'Barcos de pesca',
                '8903' => 'Iates e embarcações de recreio',
                '8904' => 'Rebocadores e barcos de empurrar',
                '8905' => 'Barcos-faróis, navios-guindaste, docas flutuantes',
                '8906' => 'Outras embarcações (incl. militares)',
                '8907' => 'Estruturas flutuantes (bóias, balsas, pontões)',
                '8908' => 'Embarcações para desmantelamento',
            ],
        ],
        '90' => [
            'label' => 'Instrumentos de precisão, óptica, medida, navegação',
            'headings' => [
                '9014' => 'Instrumentos de navegação (GPS, bússolas, sextantes)',
                '9015' => 'Instrumentos geodésicos, topográficos, hidrográficos, meteorológicos',
                '9025' => 'Densímetros, termómetros, barómetros, higrómetros',
                '9026' => 'Caudalímetros, manómetros, aparelhos de medição de fluidos',
                '9027' => 'Instrumentos de análise físico-química (espectrómetros)',
                '9028' => 'Contadores de gás, líquido, electricidade',
                '9030' => 'Osciloscópios, analisadores de espectro, equipamento eléctrico de ensaio',
                '9031' => 'Instrumentos de medida e controlo n.e. (laser, máquinas de medir 3D)',
                '9032' => 'Reguladores automáticos (PLC, termostatos)',
            ],
        ],
        '93' => [
            'label' => 'Armas e munições (MilDef / contexto defesa)',
            'headings' => [
                '9301' => 'Armas de guerra (excepto revólveres, pistolas, armas brancas)',
                '9302' => 'Revólveres e pistolas',
                '9303' => 'Outras armas de fogo',
                '9304' => 'Outras armas (ar comprimido, gás)',
                '9305' => 'Partes e acessórios de armas',
                '9306' => 'Munições e projécteis; suas partes',
                '9307' => 'Sabres, baionetas, lanças',
            ],
        ],
        '73' => [
            'label' => 'Obras de ferro/aço (parafusaria, tubagem, estrutura)',
            'headings' => [
                '7304' => 'Tubos sem costura (aço) — indústria petrolífera, naval',
                '7307' => 'Acessórios para tubagem (flanges, cotovelos, uniões)',
                '7318' => 'Parafusos, porcas, rebites, anilhas',
                '7320' => 'Molas de aço',
                '7326' => 'Outras obras de ferro/aço n.e.',
            ],
        ],
    ];

    /**
     * Measures carried by TARIC (10-digit level) beyond the base CN duty.
     */
    public const TARIC_MEASURES = [
        'Direito de importação convencional (Erga Omnes)',
        'Direito antidumping (ex.: aço da China)',
        'Direito de compensação (anti-subsídio)',
        'Contingente tarifário (quotas)',
        'Suspensão autónoma (redução temporária de direitos)',
        'Preferências pautais (SPG, EUR.1, FTA com Japão/Coreia/Canadá/UK)',
        'Restrições quantitativas / licenças',
        'Dupla utilização (bens civis e militares)',
        'Proibição ou vigilância (CITES, POPs, mercúrio)',
        'Medidas de salvaguarda (ex.: aço 2018-)',
    ];

    /**
     * Authoritative live-data endpoints.
     */
    public const LIVE_SOURCES = [
        'dataset'       => 'https://data.europa.eu/data/datasets/eu-customs-tariff-taric?locale=pt',
        'consultation'  => 'https://ec.europa.eu/taxation_customs/dds2/taric/taric_consultation.jsp?Lang=pt',
        'bulk_circabc'  => 'https://circabc.europa.eu/ui/group/0e5f18c2-4b2f-42e9-aed4-dfe50ae1263b',
        'vies'          => 'https://ec.europa.eu/taxation_customs/vies/',
        'wco'           => 'https://www.wcoomd.org/en/topics/nomenclature/instrument-and-tools/hs-nomenclature-2022-edition.aspx',
        'at_portugal'   => 'https://info-aduaneiro.portaldasfinancas.gov.pt/',
    ];

    /**
     * Returns the title of a 2-digit chapter. Returns null if unknown.
     */
    public static function chapter(string|int $chapter): ?string
    {
        $key = str_pad((string) $chapter, 2, '0', STR_PAD_LEFT);
        return self::CHAPTERS[$key] ?? null;
    }

    /**
     * Returns the TARIC Consultation URL pre-filled with a code.
     * Accepts 2/4/6/8/10-digit codes — TARIC will auto-pad.
     */
    public static function taricConsultationUrl(string $code, string $country = 'PT', string $lang = 'pt'): string
    {
        $clean = preg_replace('/\D/', '', $code);
        if ($clean === '') {
            return self::LIVE_SOURCES['consultation'];
        }
        // TARIC DDS2 accepts the code via the Goods parameter
        return sprintf(
            'https://ec.europa.eu/taxation_customs/dds2/taric/taric_consultation.jsp?Lang=%s&Taric=%s&Country=%s',
            strtolower($lang),
            $clean,
            strtoupper($country),
        );
    }

    /**
     * Finds the heading (4-digit posição) in PARTYARD_KEY_HEADINGS.
     * Accepts "8482", "84.82", "8482.10.00" — normalises to 4 digits.
     */
    public static function lookupHeading(string $code): ?string
    {
        $clean = preg_replace('/\D/', '', $code);
        if (strlen($clean) < 4) return null;
        $heading = substr($clean, 0, 4);
        $chapter = substr($clean, 0, 2);
        return self::PARTYARD_KEY_HEADINGS[$chapter]['headings'][$heading] ?? null;
    }
}
