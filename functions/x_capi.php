<?php
/**
 * X (Twitter) Ads Conversions API — server-side Purchase for the SECOND pixel (rgt8d).
 *
 * Reuses the env loader from includes/x_ads_config.php (lx_env_value / lx_load_dotenv_file)
 * and mirrors the sha256 / twclid / client-ip helper style of functions/x_ads_event.php.
 *
 * nv_x_capi_purchase(array $p): POST to measurement/conversions/<pixel_id> with the
 * `X-Pixel-Token` header. Non-blocking: flushes the response with fastcgi_finish_request()
 * (when available) BEFORE the HTTP call, CURLOPT_TIMEOUT 5. Errors are logged to
 * data/x_capi.log (NEVER the token). Returns [ok(bool), http_code(int), body(string)].
 *
 * ┌──────────────────────────────────────────────────────────────────────────────────────┐
 * │ NO PAYMENT PROVIDER EXISTS ON THIS SITE. api/billing_request.php only logs a "buy       │
 * │ request" (lead) + pings Telegram — there is no charge, no success page, no webhook.     │
 * │ Therefore this function MUST NOT be wired to billing_request.php (that is a lead, not a  │
 * │ sale) — doing so would send fake conversions to X.                                      │
 * │                                                                                          │
 * │ This function is the documented HOOK for a FUTURE real-payment handler. When a payment   │
 * │ provider is connected, call nv_x_capi_purchase() from the verified-payment path only     │
 * │ (webhook "payment succeeded" / server-rendered success page), passing the real order     │
 * │ value, currency, a unique conversion_id (= order id) and the buyer email.                │
 * └──────────────────────────────────────────────────────────────────────────────────────┘
 *
 * @param array $p {
 *     @var float|string value            Order value. Default 0.
 *     @var string       currency         ISO-4217, default 'USD'.
 *     @var string       conversion_id    Unique order id (used by X for dedup with the browser
 *                                         twq('event') call). Auto-generated if empty.
 *     @var string       email            Raw email -> sha256(hex) of trim+lower. Optional.
 *     @var string       hashed_email     Pre-hashed sha256 hex (used as-is). Optional.
 *     @var string       twclid           X click id. Optional; falls back to cookie lx_twclid / twclid.
 *     @var string       ip               Client IP. Optional; falls back to request headers.
 *     @var string       user_agent       Optional; falls back to $_SERVER['HTTP_USER_AGENT'].
 *     @var string       event_source_url Optional; falls back to current URL, then site root.
 *     @var bool         test             Marks the conversion as a test (keeps it out of real stats).
 * }
 * @return array{0:bool,1:int,2:string} [ok, http_code, body]
 */

if (!function_exists('nv_x_capi_log')) {
    function nv_x_capi_log(string $line): void
    {
        $file = dirname(__DIR__) . '/data/x_capi.log';
        @file_put_contents(
            $file,
            gmdate('c') . ' ' . substr(preg_replace('/\s+/', ' ', $line), 0, 600) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }
}

if (!function_exists('nv_x_capi_sha256')) {
    function nv_x_capi_sha256(string $value): string
    {
        $value = trim(strtolower($value));
        if ($value === '') {
            return '';
        }
        // Already a sha256 hex digest -> pass through (normalised to lower-case).
        if (preg_match('/^[a-f0-9]{64}$/i', $value)) {
            return strtolower($value);
        }
        return hash('sha256', $value);
    }
}

if (!function_exists('nv_x_capi_twclid')) {
    function nv_x_capi_twclid(string $value): string
    {
        $value = trim($value);
        return preg_match('/^[A-Za-z0-9_-]{5,512}$/', $value) ? $value : '';
    }
}

if (!function_exists('nv_x_capi_client_ip')) {
    function nv_x_capi_client_ip(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $h) {
            if (empty($_SERVER[$h])) {
                continue;
            }
            foreach (explode(',', (string)$_SERVER[$h]) as $ip) {
                $ip = trim($ip);
                if (strpos($ip, '::ffff:') === 0) {
                    $ip = substr($ip, strrpos($ip, ':') + 1);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '';
    }
}

if (!function_exists('nv_x_capi_purchase')) {
    function nv_x_capi_purchase(array $p): array
    {
        // Honour test-session opt-out, same as functions/x_ads_event.php.
        if (empty($p['test']) && !empty($_COOKIE['lx_notrack'])) {
            return [false, 0, 'skipped:notrack'];
        }

        // Load .env via the shared loader (defines lx_env_value + populates $_ENV).
        $configPath = dirname(__DIR__) . '/includes/x_ads_config.php';
        if (is_file($configPath)) {
            require_once $configPath;
        }
        if (!function_exists('lx_env_value')) {
            nv_x_capi_log('error no-lx_env_value');
            return [false, 0, 'error:no_env_loader'];
        }

        $pixelId  = lx_env_value('X_ADS_PIXEL_ID_2', 'rgt8d');
        $eventId  = lx_env_value('X_ADS_EVENT_ID_PURCHASE', 'tw-rgt8d-rgt8e');
        $token    = lx_env_value('X_ADS_PIXEL_TOKEN_2');
        $version  = preg_replace('/[^0-9]/', '', lx_env_value('X_ADS_API_VERSION', '12')) ?: '12';

        if (!preg_match('/^[A-Za-z0-9_-]{2,80}$/', (string)$pixelId) || trim((string)$token) === '') {
            nv_x_capi_log('skipped not_configured pixel=' . $pixelId);
            return [false, 0, 'skipped:not_configured'];
        }

        // ---- identifiers (owner spec: ONE object carrying every available signal) ----
        $twclid = nv_x_capi_twclid((string)($p['twclid'] ?? ''));
        if ($twclid === '') {
            $twclid = nv_x_capi_twclid((string)($_COOKIE['lx_twclid'] ?? ($_COOKIE['twclid'] ?? '')));
        }

        $hashedEmail = '';
        if (!empty($p['hashed_email'])) {
            $hashedEmail = nv_x_capi_sha256((string)$p['hashed_email']);
        } elseif (!empty($p['email'])) {
            $hashedEmail = nv_x_capi_sha256((string)$p['email']);
        }

        $ip = trim((string)($p['ip'] ?? '')) ?: nv_x_capi_client_ip();
        $ua = trim((string)($p['user_agent'] ?? '')) ?: trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));

        $identifier = [];
        if ($twclid !== '') {
            $identifier['twclid'] = $twclid;
        }
        if ($hashedEmail !== '') {
            $identifier['hashed_email'] = $hashedEmail;
        }
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
            $identifier['ip_address'] = $ip;
        }
        if ($ua !== '') {
            $identifier['user_agent'] = substr($ua, 0, 400);
        }

        // Need at least one real identifier.
        if (empty($identifier)) {
            nv_x_capi_log('skipped no_identifier');
            return [false, 0, 'skipped:no_identifier'];
        }

        $conversionId = trim((string)($p['conversion_id'] ?? ''));
        if ($conversionId === '') {
            $conversionId = 'nv_' . gmdate('YmdHis') . '_' . bin2hex(random_bytes(8));
        }

        $currency = strtoupper(trim((string)($p['currency'] ?? 'USD')));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            $currency = 'USD';
        }

        $esu = trim((string)($p['event_source_url'] ?? ''));
        if ($esu === '') {
            $host = (string)($_SERVER['HTTP_HOST'] ?? 'neuracreation.com');
            $esu  = !empty($_SERVER['REQUEST_URI'])
                ? 'https://' . $host . (string)$_SERVER['REQUEST_URI']
                : 'https://neuracreation.com/';
        }

        $conversion = [
            'conversion_time'  => gmdate('Y-m-d\TH:i:s.000\Z'),
            'event_id'         => (string)$eventId,
            'event_source_url' => $esu,
            'conversion_id'    => $conversionId,
            'value'            => number_format((float)($p['value'] ?? 0), 2, '.', ''),
            'price_currency'   => $currency,
            'number_items'     => 1,
            'identifiers'      => [$identifier],
        ];

        $payload = ['conversions' => [$conversion]];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            nv_x_capi_log('error json_encode cid=' . $conversionId);
            return [false, 0, 'error:json_encode'];
        }

        if (!function_exists('curl_init')) {
            nv_x_capi_log('error no_curl cid=' . $conversionId);
            return [false, 0, 'error:no_curl'];
        }

        $url = 'https://ads-api.x.com/' . $version . '/measurement/conversions/' . rawurlencode((string)$pixelId);

        // Non-blocking: flush the user response before the outbound call (FPM only; absent on CLI).
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_HTTPHEADER     => [
                'X-Pixel-Token: ' . trim((string)$token),
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);

        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        $ok = ($code >= 200 && $code < 300);
        $bodyOut = ($resp === false) ? ('curl_error:' . $err) : (string)$resp;

        // Log WITHOUT the token.
        nv_x_capi_log(
            ($p['test'] ?? false ? 'test ' : '') . 'cid=' . $conversionId . ' http=' . $code
            . ($twclid !== '' ? ' twclid' : '') . ($hashedEmail !== '' ? ' email' : '')
            . ' ' . ($ok ? 'ok' : 'FAIL') . ' ' . substr($bodyOut, 0, 300)
        );

        return [$ok, $code, $bodyOut];
    }
}

/**
 * Owner decision 2026-10-10: there is no payment provider, so Purchase (tw-rgt8d-rgt8e) fires on the
 * real desktop download. Called from api/desktop_download.php AFTER the file/302 was handed out.
 * conversion_id = the same 'dl-…' id the browser passed to twq('event', …) → X deduplicates the pair.
 * Skipped: HEAD, no/invalid cid (monitors and direct hits never carry one), self-declared bots,
 * no tracker cookie AND no Referer, tracking opt-out / no consent (same rules as Pinterest CAPI).
 */
if (!function_exists('nv_x_capi_download')) {
    function nv_x_capi_download($os, $cid): array
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return [false, 0, 'skipped:method'];
        $cid = (string)$cid;
        if (!preg_match('/^(dl|lk)-[a-z0-9-]{6,40}$/', $cid)) return [false, 0, 'skipped:no_cid'];
        $cid = 'dl-' . substr($cid, 3);   // the cabinets stamp 'lk-…'; browser sends the same 'dl-…'
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        if ($ua === '' || preg_match('/bot|crawl|spider|slurp|uptime|pingdom|headless|python-requests|curl\/|wget\//i', $ua)) return [false, 0, 'skipped:bot'];
        if (trim((string)($_COOKIE['lx_sid'] ?? '')) === '' && trim((string)($_SERVER['HTTP_REFERER'] ?? '')) === '') return [false, 0, 'skipped:no_session'];
        if (!empty($_COOKIE['lx_notrack']) || ($_COOKIE['lx_consent'] ?? '') === '0') return [false, 0, 'skipped:notrack'];
        if (!empty($_COOKIE['lx_eea']) && ($_COOKIE['lx_consent'] ?? '') !== '1') return [false, 0, 'skipped:consent'];
        $email = '';
        $tok = (string)($_COOKIE['lx_auth_token'] ?? '');
        if ($tok !== '' && preg_match('/^[A-Za-z0-9._-]{16,512}$/', $tok)) {
            try {
                require_once dirname(__DIR__) . '/functions/auth_db.php';
                $st = lx_auth_db()->prepare('SELECT email FROM users WHERE token = ? LIMIT 1');
                $st->execute([$tok]);
                $email = (string)($st->fetchColumn() ?: '');
            } catch (Throwable $e) { $email = ''; }
        }
        $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
        return nv_x_capi_purchase([
            'value' => 0, 'currency' => 'USD', 'conversion_id' => $cid, 'email' => $email,
            'event_source_url' => preg_match('#^https://(www\.)?neuracreation\.com/#', $ref) ? substr($ref, 0, 500) : '',
        ]);
    }
}
