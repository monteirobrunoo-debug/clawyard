"""
Realistic PII fixtures for PartYard-style traffic.

Three classes of content, matching the three vectors Bruno flagged as the
sales blockers for regulated customers:

  - PT contract boilerplate (NIPC, NIF, IBAN, CC, phones, emails, names,
    addresses, dates of birth, passport numbers)
  - SAP export lines (customer IDs, material numbers, cost centres,
    purchase order refs — many of these LOOK like PII but are not)
  - Patent draft prose (inventor names, filing numbers, IPC codes,
    attorney emails, lab reference ids)

Every piece of sensitive text is annotated with a `PiiSpan` that the
audit harness then checks against the redactor output. Spans are
classified as:

  must_redact=True   → redactor is EXPECTED to hide this value
  must_redact=False  → redactor must PRESERVE this value (false-positive check)

This file is also the spec for what "PII coverage" means for us — if you
think of a new rule, add a fixture here before touching redactor.py.
"""
from __future__ import annotations

from dataclasses import dataclass


@dataclass
class PiiSpan:
    category: str  # e.g. "nif", "email", "name", "address", "sap_id"
    value: str  # literal substring in the fixture text
    must_redact: bool


@dataclass
class Fixture:
    name: str
    tag: str  # "contract" | "sap" | "patent"
    text: str
    spans: list[PiiSpan]


# ─── Helpers ───────────────────────────────────────────────────────────────

# Valid PT NIFs (pre-computed with mod-11). Used for "must redact" cases.
# Prefixes: 1,2,3 (singular), 5 (collective), 6 (public bodies),
# 8 (independent workers), 9 (NIPC collective).
VALID_NIF_1 = "234567899"  # individual (mod-11 check digit = 9)
VALID_NIF_2 = "515234567"  # collective (NIPC)
VALID_NIF_3 = "501442600"  # collective
VALID_NIF_4 = "123456789"  # individual
VALID_NIF_5 = "889900555"  # self-employed

# 9-digit codes that are NOT valid NIFs — prefix or checksum fails.
# These must be PRESERVED by the redactor (SAP doc numbers etc).
NOT_A_NIF_1 = "400123456"  # prefix 4 → invalid
NOT_A_NIF_2 = "700555000"  # prefix 7 → invalid
NOT_A_NIF_3 = "515234560"  # wrong check digit


# ─── Contract fixtures ─────────────────────────────────────────────────────

CONTRACT_1 = Fixture(
    name="ptcontract_services_agreement",
    tag="contract",
    text=(
        f"Contrato de Prestação de Serviços celebrado em 15 de Abril de 2026 entre:\n"
        f"PRIMEIRA OUTORGANTE — PartYard Technologies Lda, NIPC {VALID_NIF_2}, "
        f"com sede na Rua Alexandre Herculano 234, 4000-053 Porto, "
        f"neste acto representada por João Miguel Silva, portador do Cartão de "
        f"Cidadão 12345678 9 ZZ4, NIF {VALID_NIF_1}.\n"
        f"SEGUNDA OUTORGANTE — Contoso Portugal SA, NIPC {VALID_NIF_3}, "
        f"morada Avenida da Liberdade 110, 3º andar, 1250-143 Lisboa, "
        f"representada por Maria Alexandra Santos Pereira, data de nascimento "
        f"1984-07-12, passaporte CA123456, telemóvel +351 912 345 678, "
        f"email maria.pereira@contoso.pt.\n"
        f"Os pagamentos serão efectuados para IBAN PT50 0035 0000 00012345678 90 "
        f"(SWIFT CGDIPTPL). Em caso de litígio, contactar o departamento legal "
        f"através do +351 21 000 1122 ou legal@contoso.pt."
    ),
    spans=[
        PiiSpan("nif", VALID_NIF_2, True),
        PiiSpan("nif", VALID_NIF_1, True),
        PiiSpan("nif", VALID_NIF_3, True),
        PiiSpan("cc", "12345678 9 ZZ4", True),
        PiiSpan("email", "maria.pereira@contoso.pt", True),
        PiiSpan("email", "legal@contoso.pt", True),
        PiiSpan("phone", "+351 912 345 678", True),
        PiiSpan("phone", "+351 21 000 1122", True),
        PiiSpan("iban", "PT50 0035 0000 00012345678 90", True),
        # Known-gap categories — redactor currently does NOT cover these.
        # Keep them as must_redact=True to surface the gap honestly in the
        # report rather than burying it.
        PiiSpan("name", "João Miguel Silva", True),
        PiiSpan("name", "Maria Alexandra Santos Pereira", True),
        PiiSpan("address", "Rua Alexandre Herculano 234, 4000-053 Porto", True),
        PiiSpan("address", "Avenida da Liberdade 110, 3º andar, 1250-143 Lisboa", True),
        PiiSpan("dob", "1984-07-12", True),
        PiiSpan("passport", "CA123456", True),
    ],
)

# A contract that uses PT phone WITHOUT country prefix (common in domestic docs).
CONTRACT_2 = Fixture(
    name="ptcontract_nda_domestic_phone",
    tag="contract",
    text=(
        f"Acordo de Confidencialidade. Entre PartYard Lda (NIPC {VALID_NIF_2}) "
        f"e o cliente final, contactável através do número 912 345 678 "
        f"(alternativo 221 234 567) e do email compras@acme.pt. "
        f"Morada de facturação: Rua das Flores 42, 4050-262 Porto."
    ),
    spans=[
        PiiSpan("nif", VALID_NIF_2, True),
        PiiSpan("email", "compras@acme.pt", True),
        # Known gap: PT-domestic phone without + prefix.
        PiiSpan("phone", "912 345 678", True),
        PiiSpan("phone", "221 234 567", True),
        PiiSpan("address", "Rua das Flores 42, 4050-262 Porto", True),
    ],
)


# ─── SAP export fixtures ───────────────────────────────────────────────────
# The critical failure mode for SAP is FALSE POSITIVES — internal IDs get
# mangled and the agent's reply references "[NIF_REDACTED]" instead of the
# real document number. Track these carefully.

SAP_1 = Fixture(
    name="sap_vendor_master_export",
    tag="sap",
    text=(
        "Vendor master export 2026-04-24 08:17 UTC\n"
        f"Vendor: 0000123456  Name: Contoso PT    NIF: {VALID_NIF_2}\n"
        f"Doc number: {NOT_A_NIF_1}  PO: 4500789012  Material: 000000000000001234\n"
        f"Cost centre: CC-41020  GL: 62210000  Tax code: V1\n"
        f"Payment terms: NT30   Bank ref: {NOT_A_NIF_2}\n"
        f"Contact: lisa.becker@contoso.de  Phone: +49 89 55512345\n"
    ),
    spans=[
        PiiSpan("nif", VALID_NIF_2, True),
        PiiSpan("email", "lisa.becker@contoso.de", True),
        PiiSpan("phone", "+49 89 55512345", True),
        # These must NOT be redacted — they are SAP document/material refs.
        PiiSpan("sap_id", NOT_A_NIF_1, False),
        PiiSpan("sap_id", NOT_A_NIF_2, False),
        PiiSpan("sap_id", "0000123456", False),
        PiiSpan("sap_id", "4500789012", False),
        PiiSpan("sap_id", "000000000000001234", False),
        PiiSpan("sap_id", "CC-41020", False),
    ],
)

SAP_2 = Fixture(
    name="sap_purchase_order_line",
    tag="sap",
    text=(
        "PO 4500000123 line 00010:\n"
        f"Material 000000000010504321 qty 500 EA @ 12,50 EUR.\n"
        f"Delivery to plant 1010, storage loc 0001. Requester id {NOT_A_NIF_3} "
        f"(must be routed to approver email procurement.lead@partyard.eu)."
    ),
    spans=[
        PiiSpan("email", "procurement.lead@partyard.eu", True),
        # Check the FP rate on realistic-looking SAP numeric fields.
        PiiSpan("sap_id", "4500000123", False),
        PiiSpan("sap_id", "000000000010504321", False),
        PiiSpan("sap_id", NOT_A_NIF_3, False),
    ],
)


# ─── Patent draft fixtures ─────────────────────────────────────────────────

PATENT_1 = Fixture(
    name="patent_smartshield_draft",
    tag="patent",
    text=(
        "Patent draft — SmartShield UXS (provisional filing)\n"
        "Inventor: Bruno Silva Monteiro, Porto, Portugal. "
        "Co-inventor: António Gomes Fernandes.\n"
        "Attorney of record: patricia.almeida@patent-office.pt, "
        "phone +351 22 555 0000.\n"
        "Filing reference: EP24-112233 (EPO). US provisional: 63/987,654.\n"
        "Priority: 2024-11-15. IPC class: H04L 9/32.\n"
        "Excerpt from claim 1: 'A method for ... the secret key is "
        "api_key=sk-live-abcDEF12345xyz' — this is an inline example, "
        "should be scrubbed before any LLM sees it."
    ),
    spans=[
        PiiSpan("email", "patricia.almeida@patent-office.pt", True),
        PiiSpan("phone", "+351 22 555 0000", True),
        PiiSpan("secret", "api_key=sk-live-abcDEF12345xyz", True),
        # Known gaps — patent-specific identifiers.
        PiiSpan("name", "Bruno Silva Monteiro", True),
        PiiSpan("name", "António Gomes Fernandes", True),
        PiiSpan("patent_ref", "EP24-112233", True),
        PiiSpan("patent_ref", "63/987,654", True),
    ],
)

PATENT_2 = Fixture(
    name="patent_pem_leak",
    tag="patent",
    text=(
        "Appendix C — reference implementation signing key "
        "(REDACT BEFORE EXTERNAL REVIEW):\n"
        "-----BEGIN PRIVATE KEY-----\n"
        "MIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQC7VJTUt9Us8cKj\n"
        "MzEfYyjiWA4R4/M2bS1GB4t7NXp98C3SC6dVMvDuictGeurT8jNbvJZHtCSuYEvu\n"
        "NMoSfm76oqFvAp8Gy0iz5sxjZmSnXyCdPEovGhLa0VzMaQ8s+CLOyS56YyCFGeJZ\n"
        "-----END PRIVATE KEY-----\n"
        "Please redact before circulating. Token also in URL: "
        "https://reviewer:hunter2@review.patent.eu/doc/42."
    ),
    spans=[
        PiiSpan("pem", "-----BEGIN PRIVATE KEY-----", True),
        # Known gap: URL-embedded basic-auth credentials.
        PiiSpan("url_creds", "reviewer:hunter2@review.patent.eu", True),
    ],
)


# ─── Clean baseline — no PII ───────────────────────────────────────────────
# Used to confirm the redactor does not mangle neutral technical prose.

CLEAN_1 = Fixture(
    name="clean_product_description",
    tag="clean",
    text=(
        "SmartShield UXS is a rugged, field-deployable device family. "
        "The housing uses IP67-rated polycarbonate. Operating temperature "
        "range: -20 to +70 °C. Standard unit weighs approximately 1.8 kg. "
        "Power consumption: typically 12 W, peak 28 W at full telemetry load."
    ),
    spans=[
        # No should-redact spans. Any redaction here is a false positive.
        PiiSpan("clean_numeric", "-20 to +70", False),
        PiiSpan("clean_numeric", "1.8 kg", False),
        PiiSpan("clean_numeric", "12 W", False),
    ],
)


ALL_FIXTURES: list[Fixture] = [
    CONTRACT_1,
    CONTRACT_2,
    SAP_1,
    SAP_2,
    PATENT_1,
    PATENT_2,
    CLEAN_1,
]
