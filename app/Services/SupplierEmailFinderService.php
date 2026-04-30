<?php

namespace App\Services;

use App\Models\Supplier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Last-mile email finder for suppliers that already have a website
 * (most do — the enrichment cron filled 89% of the directory). The
 * primary enrichment relies on Tavily snippets, which rarely surface
 * emails for big corporate sites that hide them behind contact forms.
 *
 * Strategy (cheaper-first → expensive):
 *   1. Direct fetch of the supplier's homepage AND likely contact pages
 *      (/contact, /contact-us, /about, /sobre-nos, /pt/contactos, …),
 *      regex-grep for emails in the HTML.
 *   2. If nothing found, generate plausible candidates from the domain
 *      ("info@<domain>", "sales@<domain>", "<country-code>@<domain>"),
 *      then verify each via DNS MX + SMTP RCPT TO probe.
 *   3. (Optional, future) Fall back to Hunter.io free tier — 50 finds/
 *      month, gates behind HUNTER_API_KEY env.
 *
 * What we DON'T do:
 *   • Crawl deeply or scrape JavaScript-rendered pages — keeps this
 *     fast and respectful of the supplier's bandwidth.
 *   • Hammer ports for SMTP if the MX rejects RCPT — one probe and stop.
 *   • Assume an unverified candidate is real. Anything that didn't pass
 *     either path 1 or path 2 is left out.
 *
 * Findings flow:
 *   • First verified email becomes primary_email if column was empty.
 *   • Subsequent ones go into additional_emails (deduped via the
 *     Supplier::mergeEmail helper).
 *   • A summary is stamped in source_meta.last_email_finder for audit.
 */
class SupplierEmailFinderService
{
    /** Pages to probe (relative to the website root) — in order. */
    private const PROBE_PATHS = [
        '/',
        '/contact',
        '/contact-us',
        '/contacts',
        '/contacto',
        '/contactos',
        '/contact-nous',
        '/sobre-nos',
        '/about',
        '/about-us',
        '/quem-somos',
        '/empresa',
        '/team',
        '/legal',
        '/imprint',
        '/impressum',
    ];

    /** Local-parts to try as candidates when scraping yielded nothing. */
    private const COMMON_LOCALS = [
        'sales', 'info', 'contact', 'office', 'hello', 'commercial', 'export',
        'service', 'support', 'enquiries', 'enquiry', 'quote', 'rfq',
    ];

    /**
     * @return array{
     *   ok: bool,
     *   found: array<string>,   // verified emails added (could be 0)
     *   from: array<string>,    // 'page', 'mx_probe', or 'noop'
     *   reason?: string,
     * }
     */
    public function findFor(Supplier $supplier): array
    {
        if (empty($supplier->website)) {
            return ['ok' => false, 'found' => [], 'from' => [], 'reason' => 'no_website'];
        }

        $domain = $this->domainOf($supplier->website);
        if (!$domain) {
            return ['ok' => false, 'found' => [], 'from' => [], 'reason' => 'invalid_website'];
        }

        // 1) Scrape contact pages first — most reliable.
        $scraped = $this->scrapeContactEmails($supplier->website, $domain);

        $found = [];
        $sources = [];

        foreach ($scraped as $email) {
            if ($this->isUsable($email, $domain)) {
                $found[] = $email;
                $sources[] = 'page';
            }
        }

        // 2) If still empty, generate candidates from the domain
        // and verify via MX + SMTP RCPT probe.
        if (empty($found)) {
            foreach (self::COMMON_LOCALS as $local) {
                $candidate = $local . '@' . $domain;
                if ($this->verifyEmail($candidate)) {
                    $found[] = $candidate;
                    $sources[] = 'mx_probe';
                    break;   // one verified candidate is enough — manager can search for more later
                }
            }
        }

        if (empty($found)) {
            $this->stampMeta($supplier, [
                'attempted_at' => now()->toIso8601String(),
                'paths_probed' => count(self::PROBE_PATHS),
                'result'       => 'no_emails_found',
            ]);
            return ['ok' => false, 'found' => [], 'from' => [], 'reason' => 'no_emails_found'];
        }

        // Persist via the existing merge helper — first found becomes
        // primary if column was null, rest go to additional_emails.
        foreach ($found as $email) {
            $supplier->mergeEmail($email);
        }

        $this->stampMeta($supplier, [
            'attempted_at' => now()->toIso8601String(),
            'found'        => $found,
            'sources'      => $sources,
        ]);
        $supplier->save();

        return ['ok' => true, 'found' => $found, 'from' => $sources];
    }

    private function scrapeContactEmails(string $website, string $domain): array
    {
        $base = rtrim($website, '/');
        $emails = [];

        foreach (self::PROBE_PATHS as $path) {
            $url = $base . $path;
            try {
                $res = Http::timeout(8)
                    ->withOptions([
                        'verify'         => true,
                        'allow_redirects' => ['max' => 4, 'strict' => true],
                    ])
                    ->withHeaders([
                        // Polite UA so admins see who's poking.
                        'User-Agent' => 'Clawyard/1.0 supplier-email-finder (https://clawyard.partyard.eu)',
                        'Accept'     => 'text/html,application/xhtml+xml',
                    ])
                    ->get($url);
            } catch (\Throwable $e) {
                continue;
            }

            if (!$res->successful()) continue;
            $html = $res->body();
            if ($html === '') continue;

            // De-obfuscate "foo [at] bar [dot] com" → "foo@bar.com" so
            // the regex catches them. Common defence pattern on contact pages.
            $html = preg_replace('/\s*\[\s*at\s*\]\s*/i', '@', $html);
            $html = preg_replace('/\s*\(\s*at\s*\)\s*/i', '@', $html);
            $html = preg_replace('/\s+at\s+/', '@', $html);
            $html = preg_replace('/\s*\[\s*dot\s*\]\s*/i', '.', $html);
            $html = preg_replace('/\s*\(\s*dot\s*\)\s*/i', '.', $html);

            preg_match_all('/[a-zA-Z0-9._+\-]+@[a-zA-Z0-9-]+(?:\.[a-zA-Z0-9-]+)+/', $html, $m);
            foreach ($m[0] as $email) {
                $emails[] = mb_strtolower(trim($email));
            }

            // Stop early if we already collected enough on-domain hits.
            $domainHits = array_filter($emails, fn($e) => str_ends_with($e, '@' . $domain));
            if (count($domainHits) >= 3) break;
        }

        // Prefer same-domain emails, dedupe, cap at 5.
        $emails = array_values(array_unique($emails));
        usort($emails, function ($a, $b) use ($domain) {
            $aDomain = str_ends_with($a, '@' . $domain) ? 0 : 1;
            $bDomain = str_ends_with($b, '@' . $domain) ? 0 : 1;
            if ($aDomain !== $bDomain) return $aDomain <=> $bDomain;
            // Within the same group, prefer "sales/contact" over generic.
            $rank = fn($e) => preg_match('/^(sales|contact|info|export|commercial)/', $e) ? 0 : 1;
            return $rank($a) <=> $rank($b);
        });

        return array_slice($emails, 0, 5);
    }

    private function isUsable(string $email, string $domain): bool
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
        $local = explode('@', $email)[0] ?? '';

        // Reject obviously non-human / placeholder addresses.
        $bannedLocals = ['no-reply', 'noreply', 'donotreply', 'do-not-reply',
                         'mailer-daemon', 'postmaster', 'webmaster',
                         'example', 'test', 'admin', 'root',
                         'your.email', 'youremail', 'youraddress'];
        if (in_array(mb_strtolower($local), $bannedLocals, true)) return false;

        // Reject emails on free-mail providers (Wartsila not using @gmail).
        $emailDomain = explode('@', $email)[1] ?? '';
        $freeProviders = ['gmail.com', 'hotmail.com', 'yahoo.com', 'outlook.com',
                          'live.com', 'icloud.com', 'protonmail.com', 'aol.com'];
        if (in_array(mb_strtolower($emailDomain), $freeProviders, true)) return false;

        return true;
    }

    /**
     * Verify an email via DNS MX lookup + SMTP RCPT TO probe.
     * Conservative — many relays say "yes" to everything (catch-all).
     * We accept that risk: a catch-all means the address WILL deliver
     * to *someone* at that company, even if it bounces internally.
     */
    private function verifyEmail(string $email): bool
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
        [$local, $domain] = explode('@', $email, 2);
        if (!$domain) return false;

        // Step 1: MX record exists?
        $mxHosts = [];
        if (!@getmxrr($domain, $mxHosts) || empty($mxHosts)) return false;
        $mxHost = $mxHosts[0] ?? '';
        if ($mxHost === '') return false;

        // Step 2: Open a TCP connection to MX:25 and run the SMTP probe.
        // 6s timeout total; many corporate MX answer in <2s.
        $errno  = 0;
        $errstr = '';
        $sock = @fsockopen($mxHost, 25, $errno, $errstr, 6);
        if (!$sock) return false;

        $read = function () use ($sock) {
            stream_set_timeout($sock, 6);
            return (string) fgets($sock, 1024);
        };
        $write = function (string $cmd) use ($sock) { fwrite($sock, $cmd . "\r\n"); };

        try {
            $banner = $read();
            if (!str_starts_with($banner, '220')) return false;

            $write('HELO clawyard.partyard.eu');
            $resp = $read();
            if (!str_starts_with($resp, '250')) return false;

            $write('MAIL FROM:<probe@clawyard.partyard.eu>');
            $resp = $read();
            if (!str_starts_with($resp, '250')) return false;

            $write('RCPT TO:<' . $email . '>');
            $resp = $read();
            $accepted = str_starts_with($resp, '250');

            $write('QUIT');
            return $accepted;
        } catch (\Throwable) {
            return false;
        } finally {
            @fclose($sock);
        }
    }

    private function domainOf(string $website): ?string
    {
        $host = parse_url($website, PHP_URL_HOST);
        if (!$host) return null;
        $host = strtolower($host);
        if (str_starts_with($host, 'www.')) $host = substr($host, 4);
        return str_contains($host, '.') ? $host : null;
    }

    private function stampMeta(Supplier $supplier, array $entry): void
    {
        $meta = (array) ($supplier->source_meta ?? []);
        $meta['last_email_finder'] = $entry;
        $supplier->source_meta = $meta;
    }
}
