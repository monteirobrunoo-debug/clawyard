<?php

namespace Database\Seeders;

use App\Models\Document;
use Illuminate\Database\Seeder;

class KnowledgeBaseSeeder extends Seeder
{
    public function run(): void
    {
        $documents = [

            // ─── PARTYARD / CLAWYARD COMPANY PROFILE ─────────────────────────────
            [
                'title'  => 'ClawYard / PartYard — Company Profile & Services',
                'source' => 'partyard',
                'content' => <<<TEXT
ClawYard (IT Partyard LDA) — Marine Spare Parts & Technical Services

HEADQUARTERS: Setúbal, Portugal (40 km south of Lisbon, 40 km north of Sines)
INTERNATIONAL OFFICES: USA, UK, Brazil, Norway
CERTIFICATIONS: ISO 9001:2015 | NCAGE P3527 (NATO supply) | AS:9120 (aerospace/defense)
PARTNERSHIPS: COGEMA (since 1959 — historic Iberian maritime supply), SKF Marine

BRANDS WE SPECIALISE IN (source: www.partyard.eu):
- MTU — high-performance marine and industrial engines (Series 2000, 4000, 8000, 396)
- Caterpillar (CAT) — marine propulsion and generator engines (C series, 3500 series)
- MAK — medium-speed marine diesel engines (M20, M25, M32, M43)
- Jenbacher — gas engines and cogeneration/power systems (J series)
- SKF — SternTube seals, shaft seals and marine bearings
- Schottel — propulsion systems: SRP (Rudder Propeller), STT (Transverse Thruster), STP (Pump Jet)

CORE SERVICES:
1. Marine Spare Parts — original and compatible parts for MTU, Caterpillar, MAK, Jenbacher, SKF, Schottel
2. Technical Services — engine overhauls, maintenance, inspections, field engineering
3. Emergency / Urgent Delivery — 24/7 response for vessels in distress or at port call
4. Defense & Naval Supply — NATO-certified (NCAGE P3527), supplies to military vessels and coast guard
5. Document Analysis — technical manuals, maintenance records, regulatory compliance
6. SAP Business One ERP — full business management integration

SPECIALISATIONS (parts):
- MTU: cylinder heads, pistons, liners, injectors, turbochargers, intercoolers, fuel pumps
- CAT: engine rebuild kits, fuel systems, cooling systems, generator components
- MAK: main engine spares, fuel injection equipment, turbocharger parts
- Jenbacher: gas engine parts, ignition systems, valve trains
- SKF: SternTube seals, lip seals, bearings, shaft alignment services
- Schottel: propeller blades, seal kits, gearbox components, thruster parts

COMPETITIVE ADVANTAGES:
- Located in Setúbal — 40 km from Lisbon, 40 km from Sines (Portugal's largest port)
- Military supply credentials (NCAGE P3527, AS:9120) — rare among regional competitors
- COGEMA partnership — deep historical Iberian maritime relationships since 1959
- H&P Group member — financial and logistics backing
- International offices: USA, UK, Brazil, Norway — global sourcing network
- 24-48h delivery for stock items, 5-7 days for sourced items
- Multi-language team: Portuguese, English, Spanish

TARGET CUSTOMERS:
- Ship owners (armadores) — bulk carriers, tankers, container vessels, ferries
- Shipping agents managing vessel port calls
- Ship managers and technical superintendents
- Vessel masters / chief engineers requiring urgent parts
- Port agents and marine surveyors
- Shipyards (repair and newbuild)
- Military / coast guard / naval procurement (NATO-approved)
TEXT,
            ],

            // ─── EUROPEAN PORTS — PORTUGUESE FOCUS ───────────────────────────────
            [
                'title'  => 'Portuguese Ports — Profile, Growth & Opportunities 2025-2026',
                'source' => 'market_intelligence',
                'content' => <<<TEXT
PORTUGUESE PORTS STRATEGIC ANALYSIS — 2025/2026

PORT OF SINES
Location: Alentejo coast, southwest Portugal — largest Portuguese port by tonnage
Authority: APS (Administração dos Portos de Sines e do Algarve)
Volume: Top 15 European container ports (16% container growth in 2024)
World Bank ranking: 3rd most efficient in Europe, 30th globally

Key developments:
- Phase III Terminal XXI expansion: €412M invested, quay extending to 1,950m, capacity rising from 2.7M to 4.1M TEU (PSA Sines, concession to 2049)
- Vasco da Gama New Terminal: €701M project, targeting 3M TEU/year
- Portugal–Mexico maritime corridor protocol (February 2026)
- Green hydrogen production at Galp Refinery (100 MW electrolysis)
- Sines Data Campus: 1.2 GW capacity by 2031 (transatlantic submarine cables)
- Total planned investment: €20 BILLION over next cycle
PartYard proximity: 40 km — best positioned supplier in the region.

PORT OF LISBON
Volume: 11.5M tonnes total cargo. Cargo up 3.4% in first 8 months of 2025.
Solid bulk surged 79.5%. YILPORT Liscont Terminal +25% TEU in Q1 2025.
Three new container services added November 2025.
Digital initiative with Port of Setúbal: IoT sensors reduced vessel turnaround 20%.
Strike impact: Nov–Dec 2025 reduced full-year cargo by 7.5%.
Top cruise port Southern Europe (World Luxury Awards 2025).

PORT OF LEIXÕES (Porto/Oporto)
Volume: 14.4M tonnes (2024). Cruise: 196,000 passengers (+32%).
National strategy "Ports 5+": €931M investment by 2035.
- New North Container Terminal: €430M
- Target: 20M tonnes (+35%), 1M TEU (+40%) by 2035
- EIB €60M loan for channel deepening and breakwater widening

PORT OF VIANA DO CASTELO
Authority: APDL (same as Leixões)
WestSEA Shipyard: 250,000 sqm, two dry docks, 700m berths, cranes to 180T.
Builds/repairs container ships, ferries, tankers, naval patrol vessels.
Windstar Cruises Star Seeker floated out November 2024.
Portuguese Navy modernization: UAV/ISR integration on OPVs.

PORT OF SETÚBAL (PartYard HQ location)
Type: Bulk (phosphates, paper pulp, steel), ro-ro, vehicle exports
Lisnave Mitrena: Portugal's largest and most capable dry-dock facility.
Digital partnership with Port of Lisbon (January 2026).

NATIONAL STRATEGY "PORTS 5+" (2025–2035):
Total investment: €4B+ across all Portuguese ports.
Priority: Sines (€701M new terminal + €412M expansion), Leixões (€931M).
Goal: Double Portugal's port capacity and position as Atlantic Hub for Europe.
TEXT,
            ],

            // ─── EUROPEAN PORTS — NORTH EUROPE ───────────────────────────────────
            [
                'title'  => 'North European Ports — Rotterdam, Antwerp, Hamburg 2025',
                'source' => 'market_intelligence',
                'content' => <<<TEXT
NORTH EUROPEAN PORTS — MARKET INTELLIGENCE 2025

PORT OF ROTTERDAM (Netherlands)
Volume: 428.4M tonnes (-1.7%), containers +3.1% to 14.2M TEU
Revenue: €940.4M (+6.6%), net profit €266M
Named European Leading Maritime City 2024 (DNV/Menon Economics)
Maasvlakte II expansion (€1B): +2M TEU capacity completing 2026
Maasvlakte III feasibility study launched January 2026
Congestion: waiting times rose during 2025 peak season.

Key competitors at Rotterdam:
- OMS Rotterdam: diesel engine spares (MAN B&W, Sulzer/Wärtsilä, MAK, Deutz, Daihatsu, Yanmar, Cummins, Caterpillar)
- RAMLAB: 3D-printed maritime spare parts on demand (pioneering additive manufacturing)
- Alternative Ship Supply (Vlaardingen): fast sourcing genuine parts for ARA area
- Van West-Holland: 100+ years, reconditioned 2- and 4-stroke engine spares
- SCR: auxiliary/main engine spares, mooring, ropes, pumps
- Wärtsilä, HD Hyundai Marine Solution, Rolls-Royce Marine, Alphatron Marine

PORT OF ANTWERP-BRUGES (Belgium)
Q1 2025: SURPASSED Rotterdam for first time ever (3.48M vs 3.38M TEU)
Full year: 13.6M TEU. Congestion: waiting times up 37% (32→44 hours) April–May 2025.
MSC removed two port calls; US overtook UK as largest trade partner.

Key competitors at Antwerp:
- EDR Antwerp Shipyard: Belgium's largest ship repair company (since 2011), spare parts distribution
- Flanders Ship Repair (FSR): 24/7 flying squads, all repair types, all spare parts
- Navitec NV: 24/7 emergency ship repairs (merged with GC Industries October 2025)
- Antwerp Marine Technics (AMT): floating dry dock 138×24m, ship repairs

PORT OF HAMBURG (Germany)
Best-performing major North European port in 2025: +3.6% H1, containers +9.3% to 4.2M TEU
Imports +11.6%, exports +6.9%. Ships >10,000 TEU calling: +51.6%.
Maersk rerouted services via Hamburg after exiting some Rotterdam routes.

Key competitors at Hamburg:
- TMS Hamburg (Heidgraben): spare parts trade, global sourcing network, 30+ years
- MAN Energy Solutions: OEM headquarters nearby
- Acelleron Turbocharging: turbochargers, spares, service

PORT OF BREMERHAVEN (Germany)
World's largest vehicle-handling port. Congestion: 77% surge in waiting times (worst in Northern Europe, 2025).

PORT OF FELIXSTOWE (UK) — UK's largest container port (~40% UK container traffic)
MSC Swan service rerouted from Antwerp to Felixstowe 2025.
Key supplier: Lunar Marine Trading (diesel engine components, compressor and turbo spares, 30+ years)

MARKET TRENDS NORTH EUROPE:
- Gemini Cooperation (Maersk+Hapag-Lloyd) launching 2025: major route restructuring
- Northern Range congestion creating vessel idle time = more maintenance/repair demand
- Green transition: LNG bunkering, ballast water treatment, emissions control systems = new parts demand
- Digital procurement: ShipServ, MESPAS transforming sourcing — digital presence critical
TEXT,
            ],

            // ─── EUROPEAN PORTS — MEDITERRANEAN ──────────────────────────────────
            [
                'title'  => 'Mediterranean Ports — Spain, Greece, Italy, France 2025',
                'source' => 'market_intelligence',
                'content' => <<<TEXT
MEDITERRANEAN PORTS — MARKET INTELLIGENCE 2025

PORT OF ALGECIRAS, SPAIN (Bay of Algeciras / Strait of Gibraltar)
Volume: ~9M TEU — Europe's busiest transshipment hub at Gibraltar entrance
Strategic position: gateway between Atlantic and Mediterranean

Key competitors:
- CROSSCOMAR S.A.: internationally managed ship repair; part of TECO Maritime Group; all repair types
- REMESA (founded 1989): engine repair/reconditioning by professional merchant marine engineers
- BMT Ship Repair & Maintenance: offices in Algeciras (Los Barrios, Cádiz), Valencia, Piraeus
- Spain Ship Supply: provisions and technical supply; 24/7

PORT OF VALENCIA, SPAIN
Volume: ~6M TEU — Spain's busiest container port
Key competitors: BMT Ship Repair, Spain Ship Supply, Talleres Navales Valencia

PORT OF BARCELONA, SPAIN
Volume: ~4M TEU — largest Mediterranean cruise port
Key competitors:
- RECMAR Marine Engine Spare Parts: international distributor, ISO 9001, Nadcap certified;
  compatible with all major marine engine brands; 2-year warranty; central logistics in Barcelona
- Spain Ship Supply: 24/7 coverage

PORT OF PIRAEUS, GREECE
Volume: 4–5M TEU (COSCO-managed terminals)
Cruise: record 863 ship calls, 1.85M passengers in 2025
Container growth: Pier I up 53% Q1 2025; Piers II/III down 14.7% January 2026
COSCO blacklisted by US Department of Defense January 2025 (alleged military links)
Repositioning as Asia–Mediterranean–Europe hub (Suez Canal proximity)

Key competitors:
- Piraeus Marine Services S.A. (PMS): spare parts (Europe, Japan, India, China), warehouses
  in Greece, India, Bangladesh, China; flying teams worldwide; ~$5M revenue 2025
- Oceantech Ltd: marine spare parts distributor, technical services
- Seascape Marine & Trading: ship engines, spare parts, repair, technical consultancy
- BMT Ship Repair: Piraeus office

PORT OF GENOA, ITALY
Volume: ~2.5M TEU — Italy's busiest port
GIN Group: Mariotti (luxury cruise) + San Giorgio del Porto (ship repair/conversion); 67,000 sqm

Key competitors:
- Spare Navi & Services S.r.l.: engine spare parts, large warehouse, workshop in ship repair area;
  cylinder heads, pistons, liners, valves, fuel pumps, turbochargers, charge air coolers
- Global Marine Supplies S.p.A. (GMS): technical stores, wire and mooring ropes
- Genova Marine Supply (since 1994): spare parts sourcing + technical intervention teams
- Canepa & Campi (founded 1901): life safety service; depots across Italy
- 79 companies in ship repair sector; ~4,000 direct employees

PORT OF MARSEILLE-FOS, FRANCE
Volume: ~1.5M TEU
Maersk and Hapag-Lloyd dropped direct calls February 2025 (now feeder only)
GIN Group acquired Chantier Naval de Marseille (Mediterranean's largest dry dock)
Key suppliers: Servaux Global Marine Services (national leader in technical supplies,
deck/engine/safety, life safety and firefighting)

PORT OF LE HAVRE, FRANCE
Volume: record 3.1M TEU in 2024 (+18.7%) — entered Europe's top 10
7 new world-largest gantry cranes installed 2025
10 x 24,000 TEU LNG CMA CGM ships to call from 2026
Key suppliers: Servaux Global Marine Services (Marseille + Le Havre offices)

MEDITERRANEAN OPPORTUNITIES FOR CLAWYARD:
- Algeciras: High transshipment volume, vessels always waiting = maintenance demand
- Piraeus: COSCO geopolitical uncertainty creates opening for Western suppliers
- Genoa: Fragmented local market, 79 companies = room for specialist entrant
- Barcelona: RECMAR is main rival — compete on price, speed, multi-brand
TEXT,
            ],

            // ─── COMPETITOR ANALYSIS ─────────────────────────────────────────────
            [
                'title'  => 'ClawYard Competitor Analysis — Marine Spare Parts Europe',
                'source' => 'competitive_intelligence',
                'content' => <<<TEXT
COMPETITOR ANALYSIS FOR CLAWYARD / PARTYARD — MARINE SPARE PARTS EUROPE

PARTYARD BRAND PORTFOLIO (source: www.partyard.eu):
- MTU — marine/industrial high-speed engines (our primary specialisation)
- Caterpillar (CAT) — marine propulsion and generator engines
- MAK — medium-speed marine diesels
- Jenbacher — gas engines and cogeneration
- SKF — SternTube seals and marine bearings
- Schottel — propulsion systems and thrusters

TIER 1: OEM GIANTS (they compete with us on MTU/CAT aftermarket)
- Wärtsilä (Finland): marine propulsion, engines, spare parts, service agreements
- Rolls-Royce Marine (UK): propulsion, thrusters, deck machinery — overlaps Schottel territory
- Caterpillar Marine dealer network: OEM parts but higher price and slower for non-critical
- MTU authorised dealers: OEM pricing, limited flexibility
- Schottel service centres: authorised but expensive, limited coverage in Portugal/Iberia

How to beat OEMs: faster delivery from Setúbal, competitive pricing on compatible parts,
personal service, multi-brand coverage (one call for MTU + Schottel + SKF)

TIER 2: GLOBAL DISTRIBUTION (biggest direct threat)
- Wilhelmsen Ship Services (Norway): LARGEST global ship services network; presence at ALL
  Portuguese ports; comprehensive catalogue. WEAKNESS: slow, bureaucratic, expensive, impersonal.
  HOW TO WIN: Response speed, local knowledge, relationship, price flexibility.

- ShipServ (UK): Digital marketplace connecting buyers and suppliers worldwide.
  OPPORTUNITY: Register ClawYard on ShipServ to capture digital procurement buyers.

TIER 3: REGIONAL COMPETITORS (port-by-port)
PORTUGAL:
- Iberia Marine Supplies (Aveiro): multi-port Portugal coverage (Sines, Lisbon, Leixões, Viana).
  Chandlery and provisions focus. WEAKNESS: No international offices, no defense credentials.
- REPFORN (Lisbon): safety equipment only — niche, not direct competitor across portfolio.
- Lisnave (Mitrena, Setúbal): integrated repair+parts — potential partner, not just competitor.

ANTWERP:
- Flanders Ship Repair: 24/7 all repair types, spare parts. Strong local. Not Portugal.
- EDR Antwerp: Belgium's largest ship repair; spare parts distribution.
- Navitec NV: Emergency repairs; merged Oct 2025 with GC Industries (growing).

ROTTERDAM:
- OMS Rotterdam: diesel engine spares, strong in MAN/Sulzer — same market as PartYard.
- Alternative Ship Supply: fast sourcing, ARA region — niche to Netherlands.
- RAMLAB: 3D-printed parts — future technology, watch closely.

ALGECIRAS/SPAIN:
- CROSSCOMAR (TECO Maritime Group): strong ship repair, international backing.
- RECMAR (Barcelona): ISO certified, all-brand marine engine parts, good warranty policy.
  DIRECT COMPETITOR. Differentiate: PartYard has defense credentials, faster delivery.

GREECE:
- Piraeus Marine Services (PMS): spare parts brokering, global sourcing network, flying teams.
  Similar model to PartYard. Revenue ~$5M. Monitor closely.

MARKET SIZE: ~$25 billion global marine spare parts market (2025), growing 4.5% CAGR to 2033.
Portugal is experiencing an investment boom (Ports 5+ strategy, €4B) — home market growing rapidly.

KEY DIFFERENTIATORS TO EMPHASISE IN SALES:
1. Military/defense credentials (NCAGE P3527) — rare, high-trust signal
2. ISO 9001:2015 certified
3. MTU + Caterpillar specialist (premium segments)
4. International offices: USA, UK, Brazil, Norway — global sourcing reach
5. COGEMA partnership: historical Iberian market relationships since 1959
6. SKF Marine partnership: bearings and power transmission
7. Located between Lisbon and Sines — fastest local delivery to Portugal's biggest ports
8. 24/7 emergency response capability
9. Multi-language: Portuguese, English, Spanish
TEXT,
            ],

            // ─── EMAIL TEMPLATES FOR MARITIME ────────────────────────────────────
            [
                'title'  => 'Maritime Email Templates & Key Contacts Strategy',
                'source' => 'sales_playbook',
                'content' => <<<TEXT
MARITIME EMAIL STRATEGY — CLAWYARD SALES PLAYBOOK

TARGET CONTACTS:
1. Ship Owners (Armadores): CEO, Fleet Manager, Technical Director
2. Shipping Agents: Commercial Director, Operations Manager
3. Ship Managers: Technical Superintendent, Chief Engineer (onboard contact)
4. Port Agents: Operations team, purchasing
5. Vessel Masters / Captains: Direct contact for emergency needs
6. Procurement Officers: Maritime procurement at large shipping companies

KEY SHIPPING COMPANIES TO TARGET:
- Maersk (Denmark) — world's largest container line
- MSC (Geneva) — container shipping
- CMA CGM (France) — container shipping
- Hapag-Lloyd (Germany)
- Evergreen Marine (Taiwan)
- COSCO Shipping (China)
- NYK Line (Japan)
- MOL (Japan)
- K Line (Japan)
- Stolt-Nielsen (tankers, Norway)
- Euronav (tankers, Belgium)
- Tsakos Group (tankers, Greece)
- Doris Group (bulk, France)
- Navios Maritime (Greece/Monaco)

SHIPPING AGENTS IN PORTUGUESE PORTS:
Lisbon/Sines: Agência Marítima Portline, ETE Maritime, Intermodal, Navalcargo, Wilson Sons
Leixões: APS Agência Porto, Transinsular, Samarino
Viana do Castelo: Agência Marítima do Noroeste

EMAIL SUBJECT LINE FORMULAS THAT WORK:
- "Marine Spare Parts — [Vessel Name/Type] — [Port] — [Your Company]"
- "Urgent: [Part Name] Available for Delivery to [Port] within 48h"
- "Quote: [Engine Brand] Parts for [Vessel Class] — ClawYard Portugal"
- "Technical Service Offer — Dry Dock / Port Call Support — [Port Name]"
- "Partnership Proposal — Marine Spare Parts Supply — [City/Port]"

COLD OUTREACH STRATEGY BY PORT:
- Algeciras: Focus on transshipment vessels (high volume, need fast turnaround parts)
- Piraeus: Target Greek shipowners (tankers, bulk — large fleets)
- Rotterdam/Antwerp: Position as Iberian/Atlantic hub alternative to ARA suppliers
- Genoa: Luxury cruise + ferry operators (specialized parts)
- Sines/Lisbon: Local advantage, emphasise speed and proximity

FOLLOW-UP SEQUENCE:
Day 1: Initial cold outreach email
Day 4: Follow-up if no reply (reference previous email)
Day 8: Value-add email (send market intelligence or price list)
Day 14: Final follow-up (close or archive)
TEXT,
            ],
        ];

        foreach ($documents as $doc) {
            $words  = explode(' ', $doc['content']);
            $chunks = array_chunk($words, 200);
            $chunkStrings = array_map(fn($c) => implode(' ', $c), $chunks);

            Document::updateOrCreate(
                ['title' => $doc['title']],
                [
                    'source'  => $doc['source'],
                    'content' => $doc['content'],
                    'summary' => mb_substr($doc['content'], 0, 500),
                    'chunks'  => $chunkStrings,
                ]
            );
        }

        $this->command->info('✅ Knowledge base loaded: ' . count($documents) . ' documents');
    }
}
