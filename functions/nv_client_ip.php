<?php
/**
 * nv_client_ip() — the visitor's real IP (2026-10-10, X Ads pass).
 * nginx now runs `real_ip_header CF-Connecting-IP` + `set_real_ip_from <Cloudflare ranges>` for neuracreation.com
 * (extension/neuracreation.com/cf_realip.conf), so REMOTE_ADDR already IS the visitor IP for requests that came
 * through Cloudflare, and stays the peer IP for direct-to-origin requests (where a CF-Connecting-IP header could be
 * forged). The CF header is trusted only when the TCP peer is itself a Cloudflare edge, i.e. real_ip was not applied
 * (nginx rolled back / other vhost). Same value as the old "CF header first" readers for all CF traffic, so ip
 * hashes stay identical.
 */
if (!function_exists('nv_client_ip')) {

function nv_cf_ranges(): array {
    return ['173.245.48.0/20','103.21.244.0/22','103.22.200.0/22','103.31.4.0/22','141.101.64.0/18','108.162.192.0/18',
            '190.93.240.0/20','188.114.96.0/20','197.234.240.0/22','198.41.128.0/17','162.158.0.0/15','104.16.0.0/13',
            '104.24.0.0/14','172.64.0.0/13','131.0.72.0/22',
            '2400:cb00::/32','2606:4700::/32','2803:f800::/32','2405:b500::/32','2405:8100::/32','2a06:98c0::/29','2c0f:f248::/32'];
}

function nv_ip_in_cidr(string $ip, string $cidr): bool {
    list($net, $bits) = explode('/', $cidr, 2);
    $a = @inet_pton($ip); $b = @inet_pton($net);
    if ($a === false || $b === false || strlen($a) !== strlen($b)) return false;
    $bits = (int)$bits; $bytes = intdiv($bits, 8); $rem = $bits % 8;
    if ($bytes && substr($a, 0, $bytes) !== substr($b, 0, $bytes)) return false;
    if ($rem) { $m = (0xFF << (8 - $rem)) & 0xFF; if ((ord($a[$bytes]) & $m) !== (ord($b[$bytes]) & $m)) return false; }
    return true;
}

function nv_ip_is_cloudflare(string $ip): bool {
    foreach (nv_cf_ranges() as $c) { if (nv_ip_in_cidr($ip, $c)) return true; }
    return false;
}

function nv_client_ip(): string {
    $ra = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    $cf = trim(explode(',', (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''))[0]);
    if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP) && ($ra === '' || nv_ip_is_cloudflare($ra))) return $cf;
    if ($ra !== '' && filter_var($ra, FILTER_VALIDATE_IP)) return $ra;
    return '0.0.0.0';
}

}
