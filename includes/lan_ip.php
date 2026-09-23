<?php
/**
 * QID Management System - LAN IP Detection Helper
 *
 * Detects the real Wi-Fi / LAN IPv4 address(es) of this XAMPP machine.
 *
 * `gethostbyname(gethostname())` is unreliable on Windows: it often returns the
 * VirtualBox / VMware / Hyper-V virtual adapter address instead of the actual
 * Wi-Fi address, which makes the mobile app connect to an IP nothing listens on.
 * This helper enumerates every adapter, filters to private ranges and ranks the
 * candidates so the real Wi-Fi address always comes first.
 *
 * Used by api/discovery.php (mobile auto-detect) and settings.php (UI display).
 */

if (!function_exists('qid_is_private_ipv4')) {
    /**
     * True when $ip is a usable private LAN IPv4 (not loopback, not APIPA).
     */
    function qid_is_private_ipv4($ip)
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        $parts = array_map('intval', explode('.', $ip));

        if ($parts[0] === 127) return false;                       // loopback
        if ($parts[0] === 169 && $parts[1] === 254) return false;  // APIPA (no DHCP)
        if ($parts[0] === 0 || $parts[0] === 255) return false;

        if ($parts[0] === 192 && $parts[1] === 168) return true;
        if ($parts[0] === 10) return true;
        if ($parts[0] === 172 && $parts[1] >= 16 && $parts[1] <= 31) return true;

        return false;
    }
}

if (!function_exists('qid_ip_rank')) {
    /**
     * Lower rank = more likely to be the real Wi-Fi / LAN adapter.
     * Virtual-machine and Internet-Connection-Sharing subnets are pushed to the
     * bottom so they never win over a genuine Wi-Fi address.
     */
    function qid_ip_rank($ip)
    {
        $parts = array_map('intval', explode('.', $ip));

        // Known virtual / special-purpose subnets - always last.
        if ($parts[0] === 192 && $parts[1] === 168 && $parts[2] === 56)  return 90; // VirtualBox host-only
        if ($parts[0] === 192 && $parts[1] === 168 && $parts[2] === 137) return 91; // Windows ICS hotspot
        if ($parts[0] === 172 && $parts[1] >= 17 && $parts[1] <= 31)     return 92; // Docker / WSL bridges
        if ($parts[0] === 172 && $parts[1] === 16)                       return 80; // often VMware NAT

        // Real-world Wi-Fi / office LAN ordering.
        if ($parts[0] === 192 && $parts[1] === 168) return 10;
        if ($parts[0] === 10)                       return 20;

        return 50;
    }
}

if (!function_exists('qid_collect_lan_ips')) {
    /**
     * Returns every private LAN IPv4 of this machine, best candidate first.
     *
     * @return string[]
     */
    function qid_collect_lan_ips()
    {
        $found = array();

        // 1. Every address bound to this host's name (returns all adapters on Windows).
        $resolved = @gethostbynamel(gethostname());
        if (is_array($resolved)) {
            foreach ($resolved as $ip) {
                $found[$ip] = true;
            }
        }

        // 2. net_get_interfaces() when the build supports it (more complete).
        if (function_exists('net_get_interfaces')) {
            $interfaces = @net_get_interfaces();
            if (is_array($interfaces)) {
                foreach ($interfaces as $iface) {
                    if (empty($iface['unicast']) || !is_array($iface['unicast'])) {
                        continue;
                    }
                    foreach ($iface['unicast'] as $unicast) {
                        if (!empty($unicast['address'])) {
                            $found[$unicast['address']] = true;
                        }
                    }
                }
            }
        }

        // 3. The interface this very request arrived on - authoritative when the
        //    request came from the LAN rather than from localhost.
        if (!empty($_SERVER['SERVER_ADDR'])) {
            $found[$_SERVER['SERVER_ADDR']] = true;
        }

        $candidates = array();
        foreach (array_keys($found) as $ip) {
            if (qid_is_private_ipv4($ip)) {
                $candidates[] = $ip;
            }
        }

        // Stable sort: rank first, then numeric IP order.
        usort($candidates, function ($a, $b) {
            $ra = qid_ip_rank($a);
            $rb = qid_ip_rank($b);
            if ($ra !== $rb) {
                return $ra - $rb;
            }
            return strcmp(str_pad($a, 15, '0', STR_PAD_LEFT), str_pad($b, 15, '0', STR_PAD_LEFT));
        });

        return array_values(array_unique($candidates));
    }
}

if (!function_exists('qid_primary_lan_ip')) {
    /**
     * The single best LAN IPv4 to advertise to the mobile app.
     * Prefers the interface the current request arrived on when that request
     * did not come from localhost.
     */
    function qid_primary_lan_ip()
    {
        $server_addr = isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : '';
        if (qid_is_private_ipv4($server_addr)) {
            return $server_addr;
        }

        $ips = qid_collect_lan_ips();
        return !empty($ips) ? $ips[0] : '127.0.0.1';
    }
}

if (!function_exists('qid_base_path')) {
    /**
     * Web path this installation is served from, e.g. "/QID" (no trailing slash).
     * $strip_segment removes a trailing sub-folder such as "api".
     */
    function qid_base_path($strip_segment = null)
    {
        $script_name = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '';
        $dir = str_replace('\\', '/', dirname($script_name));
        $dir = '/' . trim($dir, '/');

        if ($strip_segment !== null) {
            $dir = preg_replace('#/' . preg_quote($strip_segment, '#') . '$#i', '', $dir);
        }

        if ($dir === '/' || $dir === '') {
            // Served from the web root - fall back to the real folder name.
            $folder = basename(dirname(__DIR__));
            return (strtolower($folder) === 'qid') ? '/' . $folder : '';
        }

        return rtrim($dir, '/');
    }
}

if (!function_exists('qid_server_id')) {
    /**
     * Stable fingerprint of this installation. Lets the mobile app recognise the
     * *same* server after its IP address changes on a different Wi-Fi network.
     */
    function qid_server_id()
    {
        $seed = gethostname() . '|' . dirname(__DIR__);
        return substr(hash('sha256', $seed), 0, 16);
    }
}

if (!function_exists('qid_server_port')) {
    /**
     * Port Apache is serving this request on.
     */
    function qid_server_port()
    {
        $port = isset($_SERVER['SERVER_PORT']) ? (int) $_SERVER['SERVER_PORT'] : 80;
        return $port > 0 ? $port : 80;
    }
}

if (!function_exists('qid_lan_urls')) {
    /**
     * Every URL the mobile app could use to reach this installation over the LAN,
     * best candidate first. e.g. ["http://192.168.0.158/QID"]
     *
     * @return string[]
     */
    function qid_lan_urls($strip_segment = null)
    {
        $base_path = qid_base_path($strip_segment);
        $port = qid_server_port();
        $suffix = ($port === 80) ? '' : ':' . $port;
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

        $urls = array();
        foreach (qid_collect_lan_ips() as $ip) {
            $urls[] = $scheme . '://' . $ip . $suffix . $base_path;
        }

        if (empty($urls)) {
            $urls[] = $scheme . '://localhost' . $suffix . $base_path;
        }

        return $urls;
    }
}
