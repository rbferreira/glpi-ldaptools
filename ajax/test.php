<?php

/**
 * -------------------------------------------------------------------------
 * ldaptools plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of ldaptools.
 *
 * ldaptools is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * ldaptools is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with ldaptools. If not, see <http://www.gnu.org/licenses/>.
 * -------------------------------------------------------------------------
 * @author    François Legastelois
 * @author    Rafael Ferreira (GLPI 11 adaptation + enhancements)
 * @license   GPLv3 https://www.gnu.org/licenses/gpl-3.0.html
 * -------------------------------------------------------------------------
 */

header('Content-Type: text/html; charset=UTF-8');
Html::header_nocache();

Session::checkRight('config', UPDATE);


$authldaps_id          = intval($_GET['authldaps_id'] ?? 0);
$authldapreplicates_id = intval($_GET['authldapreplicates_id'] ?? 0);
$is_replicat           = $authldapreplicates_id !== 0;
$search_limit          = intval($_GET['limit'] ?? 10);
if ($search_limit <= 0) {
    $search_limit = 0; // 0 = unlimited
}
// Some LDAP servers (e.g. Google Workspace) ignore client-side sizelimit
// and always return the full directory. Use a generous timeout to avoid 504s.
$search_timelimit = 120;

if (empty($authldaps_id)) {
    http_response_code(400);
    Toolbox::logInfo("[ldaptools] Missing parameter 'authldaps_id'.");
    die;
}

$AuthLDAP          = new AuthLDAP();
$authreplicat_ldap = new AuthLdapReplicate();

if (!$AuthLDAP->can($authldaps_id, READ)) {
    http_response_code(403);
    Toolbox::logInfo('[ldaptools] Missing rights to read AuthLDAP data.');
    die;
}

$AuthLDAP->getFromDB($authldaps_id);
$hostname    = $AuthLDAP->getField('host');
$port_num    = intval($AuthLDAP->getField('port'));
$server_name = $AuthLDAP->getField('name');

if ($is_replicat) {
    $authreplicat_ldap->getFromDB($authldapreplicates_id);
    $hostname    = $authreplicat_ldap->getField('host');
    $port_num    = intval($authreplicat_ldap->getField('port'));
    $server_name = $authreplicat_ldap->getField('name');
}

$username    = $AuthLDAP->getField('rootdn');
$password    = (new GLPIKey())->decrypt($AuthLDAP->getField('rootdn_passwd'));
$base_dn     = $AuthLDAP->getField('basedn');
$login_field = $AuthLDAP->getField('login_field');
$use_tls     = $AuthLDAP->getField('use_tls');
$filter      = Html::entity_decode_deep($AuthLDAP->getField('condition'));
$search      = '(cn=*)';

$use_bind = true;
if ($AuthLDAP->isField('use_bind')) {
    $use_bind = $AuthLDAP->getField('use_bind');
}

$tls_certfile = null;
if ($AuthLDAP->isField('tls_certfile')) {
    $tls_certfile = $AuthLDAP->getField('tls_certfile');
}

$tls_keyfile = null;
if ($AuthLDAP->isField('tls_keyfile')) {
    $tls_keyfile = $AuthLDAP->getField('tls_keyfile');
}

// -----------------------------------------------------------------------
// Enhanced diagnostic state
// -----------------------------------------------------------------------
$test_start    = microtime(true);
$next          = false;
$ldap          = null;
$count_entries = 0;
$results       = [];
$errors        = [];

// Log data accumulator
$log_data = [
    'authldaps_id'          => $authldaps_id,
    'authldapreplicates_id' => $authldapreplicates_id,
    'server_name'           => $server_name,
    'hostname'              => $hostname,
    'port'                  => $port_num,
    'is_replica'            => $is_replicat ? 1 : 0,
];

// -----------------------------------------------------------------------
// Helper: format milliseconds
// -----------------------------------------------------------------------
function fmt_ms(float $ms): string
{
    if ($ms < 1) {
        return '<1ms';
    }
    return number_format($ms, 1) . 'ms';
}

function status_span(string $status, int $id, string $content, string $tooltip = ''): string
{
    $colors = [
        'ok'      => '#22c55e',
        'error'   => '#ef4444',
        'warn'    => '#f59e0b',
        'skip'    => '#94a3b8',
    ];
    $color = $colors[$status] ?? '#94a3b8';
    $html  = '<span style="color: ' . $color . ';">';
    $html .= $content;
    if ($tooltip !== '') {
        ob_start();
        Html::showToolTip($tooltip);
        $html .= ob_get_clean();
    }
    $html .= '</span>';
    return $html;
}

// -----------------------------------------------------------------------
// Extract clean hostname for DNS/TCP tests
// -----------------------------------------------------------------------
if (preg_match("/(ldap:\/\/|ldaps:\/\/)(.*)/", $hostname, $matches)) {
    $host = $matches[2][0] ?? $matches[2];
    // Handle case where host still has the full match
    $host = rtrim($matches[2], '/');
} else {
    $host = $hostname;
}
// Remove trailing port if present in host string
$host = preg_replace('/:\d+$/', '', $host);

echo '<tr id="ldap_test_' . $authldaps_id . '_' . $authldapreplicates_id . '">';

// -- Server name column --
if ($is_replicat) {
    echo "<td><i class='ti ti-copy mr-2 m-2 pl-4'></i>" . htmlspecialchars($server_name) . '</td>';
} else {
    echo "<td><i class='ti ti-crown mr-2 m-2'></i>" . $AuthLDAP->getLink() . '</td>';
}

// ===================================================================
// TEST 1: DNS Resolution (NEW)
// ===================================================================
echo '<td>';
$dns_start = microtime(true);
$dns_ok    = false;
$dns_addrs = [];

if (filter_var($host, FILTER_VALIDATE_IP)) {
    // It's already an IP, skip DNS
    $dns_ok    = true;
    $dns_addrs = [$host];
    $dns_ms    = 0.0;
    $log_data['test_dns']       = 'skip';
    $log_data['dns_time_ms']    = 0;
    $log_data['dns_addresses']  = $host;
    echo status_span('ok', $authldaps_id, '<i class="far fa-thumbs-up mr-2 m-2"></i>' . htmlspecialchars($host) . ' (IP)', __('Direct IP address, DNS resolution not needed.', 'ldaptools'));
} else {
    $dns_records = @dns_get_record($host, DNS_A | DNS_AAAA);
    $dns_ms = (microtime(true) - $dns_start) * 1000;

    if ($dns_records && count($dns_records) > 0) {
        foreach ($dns_records as $record) {
            if (isset($record['ip'])) {
                $dns_addrs[] = $record['ip'];
            }
            if (isset($record['ipv6'])) {
                $dns_addrs[] = $record['ipv6'];
            }
        }
    }

    if (count($dns_addrs) > 0) {
        $dns_ok = true;
        $log_data['test_dns']      = 'ok';
        $log_data['dns_time_ms']   = round($dns_ms, 2);
        $log_data['dns_addresses'] = implode(', ', $dns_addrs);
        $tooltip  = '<b>' . __('DNS Resolution', 'ldaptools') . '</b><br/>';
        $tooltip .= __('Addresses:', 'ldaptools') . ' ' . implode(', ', $dns_addrs) . '<br/>';
        $tooltip .= __('Time:', 'ldaptools') . ' ' . fmt_ms($dns_ms);
        echo status_span('ok', $authldaps_id, '<i class="far fa-thumbs-up mr-2 m-2"></i>' . htmlspecialchars($host) . ' <small>(' . fmt_ms($dns_ms) . ')</small>', $tooltip);
    } else {
        $log_data['test_dns']    = 'error';
        $log_data['dns_time_ms'] = round($dns_ms, 2);
        $errors[] = "DNS resolution failed for '$host'";
        $tooltip  = __('Could not resolve hostname. Check DNS configuration or use an IP address.', 'ldaptools');
        echo status_span('error', $authldaps_id, '<i class="far fa-thumbs-down mr-2 m-2"></i>' . htmlspecialchars($host), $tooltip);
    }
}
$next = $dns_ok;
echo '</td>';

// ===================================================================
// TEST 2: TCP Connectivity
// ===================================================================
echo '<td>';
if ($next) {
    $tcp_start = microtime(true);
    $tcp_conn  = @fsockopen($host, $port_num, $errno, $errstr, 5);
    $tcp_ms    = (microtime(true) - $tcp_start) * 1000;

    if ($tcp_conn) {
        fclose($tcp_conn);
        $log_data['test_tcp']    = 'ok';
        $log_data['tcp_time_ms'] = round($tcp_ms, 2);
        $tooltip  = '<b>' . __('TCP Connection', 'ldaptools') . '</b><br/>';
        $tooltip .= __('Host:', 'ldaptools') . ' ' . $host . ':' . $port_num . '<br/>';
        $tooltip .= __('Time:', 'ldaptools') . ' ' . fmt_ms($tcp_ms);
        echo status_span('ok', $authldaps_id, '<i class="far fa-thumbs-up mr-2 m-2"></i>TCP/' . $port_num . ' <small>(' . fmt_ms($tcp_ms) . ')</small>', $tooltip);
        $next = true;
    } else {
        $log_data['test_tcp']    = 'error';
        $log_data['tcp_time_ms'] = round($tcp_ms, 2);
        $errors[] = "TCP connection failed: $errstr ($errno)";
        $tooltip  = '<b>' . __('TCP Connection Failed', 'ldaptools') . '</b><br/>';
        $tooltip .= __('Error:', 'ldaptools') . " $errstr ($errno)<br/>";
        $tooltip .= __('Time:', 'ldaptools') . ' ' . fmt_ms($tcp_ms);
        echo status_span('error', $authldaps_id, '<i class="far fa-thumbs-down mr-2 m-2"></i>TCP/' . $port_num . '<br/><small>' . htmlspecialchars($errstr) . " ($errno)</small>", $tooltip);
        $next = false;
    }
} else {
    $log_data['test_tcp'] = 'skip';
    echo status_span('skip', $authldaps_id, '<i class="far fa-hand-point-left mr-2 m-2"></i>' . __('Fix previous!', 'ldaptools'));
    $next = false;
}
echo '</td>';

// ===================================================================
// TEST 3: BaseDN Validation
// ===================================================================
echo '<td>';
if ($next) {
    if (empty($base_dn)) {
        $log_data['test_basedn'] = 'error';
        $errors[] = 'BaseDN is empty';
        echo status_span('error', $authldaps_id, '<i class="far fa-thumbs-down mr-2 m-2"></i>' . __('BaseDN should not be empty!', 'ldaptools'));
        $next = false;
    } else {
        $log_data['test_basedn'] = 'ok';
        echo status_span('ok', $authldaps_id, '<i class="far fa-thumbs-up mr-2 m-2"></i><small>' . htmlspecialchars($base_dn) . '</small>');
        $next = true;
    }
} else {
    $log_data['test_basedn'] = 'skip';
    echo status_span('skip', $authldaps_id, '<i class="far fa-hand-point-left mr-2 m-2"></i>' . __('Fix previous!', 'ldaptools'));
    $next = false;
}
echo '</td>';

// ===================================================================
// TEST 4: LDAP URI Connection
// ===================================================================
echo '<td>';
if ($next) {
    $conn_start = microtime(true);
    $ldap_uri = (str_starts_with($hostname, 'ldap://') || str_starts_with($hostname, 'ldaps://'))
        ? $hostname
        : "ldap://{$hostname}:{$port_num}";
    $ldap = @ldap_connect($ldap_uri);
    $conn_ms = (microtime(true) - $conn_start) * 1000;

    if ($ldap) {
        ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($ldap, LDAP_OPT_NETWORK_TIMEOUT, 10);
        ldap_set_option($ldap, LDAP_OPT_TIMELIMIT, $search_timelimit);
        if ($search_limit > 0) {
            ldap_set_option($ldap, LDAP_OPT_SIZELIMIT, $search_limit);
        }

        if (!empty($tls_certfile) && file_exists($tls_certfile)) {
            ldap_set_option(null, LDAP_OPT_X_TLS_CERTFILE, $tls_certfile);
        }
        if (!empty($tls_keyfile) && file_exists($tls_keyfile)) {
            ldap_set_option(null, LDAP_OPT_X_TLS_KEYFILE, $tls_keyfile);
        }

        $log_data['test_connect']    = 'ok';
        $log_data['connect_time_ms'] = round($conn_ms, 2);

        // StartTLS if configured
        if ($use_tls) {
            $tls_start = microtime(true);
            $tls_ok = @ldap_start_tls($ldap);
            $tls_ms = (microtime(true) - $tls_start) * 1000;

            $log_data['tls_time_ms'] = round($tls_ms, 2);

            if ($tls_ok) {
                $log_data['test_starttls'] = 'ok';
                $tooltip  = '<b>' . __('LDAP Connection + StartTLS', 'ldaptools') . '</b><br/>';
                $tooltip .= __('Connect time:', 'ldaptools') . ' ' . fmt_ms($conn_ms) . '<br/>';
                $tooltip .= __('TLS handshake:', 'ldaptools') . ' ' . fmt_ms($tls_ms);
                echo status_span('ok', $authldaps_id, '<i class="far fa-thumbs-up mr-2 m-2"></i><i class="fas fa-lock ml-1" style="color:#22c55e;font-size:0.8em;"></i> <small>(' . fmt_ms($conn_ms + $tls_ms) . ')</small>', $tooltip);
            } else {
                $log_data['test_starttls'] = 'warn';
                $errors[] = 'StartTLS failed: ' . ldap_error($ldap);
                $tooltip  = '<b>' . __('StartTLS Warning', 'ldaptools') . '</b><br/>';
                $tooltip .= __('Connection succeeded but StartTLS failed.', 'ldaptools') . '<br/>';
                $tooltip .= __('Error:', 'ldaptools') . ' ' . ldap_error($ldap);
                echo status_span('warn', $authldaps_id, '<i class="far fa-thumbs-up mr-2 m-2"></i><i class="fas fa-lock-open ml-1" style="color:#f59e0b;font-size:0.8em;"></i> <small>(' . fmt_ms($conn_ms) . ')</small>', $tooltip);
            }
        } else {
            $log_data['test_starttls'] = 'skip';
            $tooltip  = '<b>' . __('LDAP Connection', 'ldaptools') . '</b><br/>';
            $tooltip .= __('Connect time:', 'ldaptools') . ' ' . fmt_ms($conn_ms) . '<br/>';
            $tooltip .= __('TLS:', 'ldaptools') . ' ' . __('Not configured', 'ldaptools');
            echo status_span('ok', $authldaps_id, '<i class="far fa-thumbs-up mr-2 m-2"></i> <small>(' . fmt_ms($conn_ms) . ')</small>', $tooltip);
        }
        $next = true;
    } else {
        $log_data['test_connect'] = 'error';
        $errors[] = 'ldap_connect() failed';
        echo status_span('error', $authldaps_id, '<i class="far fa-thumbs-down mr-2 m-2"></i>' . __('Connection failed', 'ldaptools'));
        $next = false;
    }
} else {
    $log_data['test_connect'] = 'skip';
    echo status_span('skip', $authldaps_id, '<i class="far fa-hand-point-left mr-2 m-2"></i>' . __('Fix previous!', 'ldaptools'));
    $next = false;
}
echo '</td>';

// ===================================================================
// TEST 5: Bind Authentication
// ===================================================================
echo '<td>';
if ($next && $use_bind) {
    $bind_start  = microtime(true);
    $bind_result = @ldap_bind($ldap, $username, $password);
    $bind_ms     = (microtime(true) - $bind_start) * 1000;

    if (!$bind_result) {
        $errno_ldap = ldap_errno($ldap);
        $log_data['test_bind']    = 'error';
        $log_data['bind_time_ms'] = round($bind_ms, 2);
        $errors[] = 'Bind failed: ' . ldap_err2str($errno_ldap) . " (errno: $errno_ldap)";

        $tooltip  = '<b>' . __('Bind Failed', 'ldaptools') . '</b><br/>';
        $tooltip .= sprintf(__('Error number: %s', 'ldaptools'), $errno_ldap) . '<br/>';
        $tooltip .= sprintf(__('Error message: %s', 'ldaptools'), ldap_err2str($errno_ldap)) . '<br/>';
        $tooltip .= __('RootDN:', 'ldaptools') . ' ' . htmlspecialchars($username) . '<br/>';
        $tooltip .= __('Time:', 'ldaptools') . ' ' . fmt_ms($bind_ms);
        echo status_span('error', $authldaps_id, '<i class="far fa-thumbs-down mr-2 m-2"></i>' . __('Could not bind', 'ldaptools') . ' <small>(' . fmt_ms($bind_ms) . ')</small>', $tooltip);
        $next = false;
    } else {
        $log_data['test_bind']    = 'ok';
        $log_data['bind_time_ms'] = round($bind_ms, 2);

        // Try to get server info after successful bind
        $server_info = [];
        $info_read = @ldap_read($ldap, '', '(objectclass=*)', ['*', '+']);
        if ($info_read) {
            $info_entries = @ldap_get_entries($ldap, $info_read);
            if ($info_entries && $info_entries['count'] > 0) {
                $entry = $info_entries[0];
                foreach (['vendorname', 'vendorversion', 'supportedldapversion', 'namingcontexts', 'subschemasubentry', 'supportedsaslmechanisms', 'defaultnamingcontext', 'currenttime', 'dsservicename'] as $attr) {
                    if (isset($entry[$attr])) {
                        if (is_array($entry[$attr]) && isset($entry[$attr]['count'])) {
                            $vals = [];
                            for ($i = 0; $i < $entry[$attr]['count']; $i++) {
                                $vals[] = $entry[$attr][$i];
                            }
                            $server_info[$attr] = implode(', ', $vals);
                        } else {
                            $server_info[$attr] = $entry[$attr];
                        }
                    }
                }
            }
        }
        if (!empty($server_info)) {
            $log_data['server_info'] = json_encode($server_info, JSON_UNESCAPED_UNICODE);
        }

        $tooltip  = '<b>' . __('Bind Successful', 'ldaptools') . '</b><br/>';
        $tooltip .= __('RootDN:', 'ldaptools') . ' ' . htmlspecialchars($username) . '<br/>';
        $tooltip .= __('Time:', 'ldaptools') . ' ' . fmt_ms($bind_ms);
        if (!empty($server_info)) {
            $tooltip .= '<br/><br/><b>' . __('Server Info', 'ldaptools') . '</b><br/>';
            foreach ($server_info as $k => $v) {
                $tooltip .= htmlspecialchars($k) . ': ' . htmlspecialchars($v) . '<br/>';
            }
        }
        echo status_span('ok', $authldaps_id, '<i class="far fa-thumbs-up mr-2 m-2"></i> <small>(' . fmt_ms($bind_ms) . ')</small>', $tooltip);
        $next = true;
    }
} elseif (!$use_bind) {
    $log_data['test_bind'] = 'skip';
    $tooltip = __("Bind user/password authentication is disabled. Your LDAP server allows anonymous requests or authenticates with a key. If intentional, this is fine. If subsequent tests fail, review this setting.", 'ldaptools');
    echo status_span('warn', $authldaps_id, '<i class="fas fa-lock-open mr-2 m-2"></i>' . __('Disabled', 'ldaptools'), $tooltip);
} else {
    $log_data['test_bind'] = 'skip';
    echo status_span('skip', $authldaps_id, '<i class="far fa-hand-point-left mr-2 m-2"></i>' . __('Fix previous!', 'ldaptools'));
    $next = false;
}
echo '</td>';

// ===================================================================
// TEST 6: Generic Search (cn=*)
// ===================================================================
echo '<td>';
if ($next) {
    $search_start = microtime(true);
    $results = @ldap_search($ldap, $base_dn, $search, [], 0, $search_limit, $search_timelimit);
    $search_ms = (microtime(true) - $search_start) * 1000;

    if (!$results) {
        $errno_ldap = ldap_errno($ldap);
        $log_data['test_search']    = 'error';
        $log_data['search_time_ms'] = round($search_ms, 2);
        $errors[] = "Search failed ($search): " . ldap_err2str($errno_ldap);

        $tooltip  = sprintf(__('Error number: %s', 'ldaptools'), $errno_ldap) . '<br/>';
        $tooltip .= sprintf(__('Error message: %s', 'ldaptools'), ldap_err2str($errno_ldap));
        echo status_span('error', $authldaps_id, '<i class="far fa-thumbs-down mr-2 m-2"></i>' . sprintf(__('Search error: %s', 'ldaptools'), htmlspecialchars($search)) . ' <small>(' . fmt_ms($search_ms) . ')</small>', $tooltip);
        $next = false;
    } else {
        $server_count = ldap_count_entries($ldap, $results);
        $count_entries = $server_count;
        $limit_ignored = ($search_limit > 0 && $server_count > $search_limit);
        if ($limit_ignored) {
            $count_entries = $search_limit; // client-side trim
        }
        $log_data['test_search']    = ($count_entries > 0) ? 'ok' : 'error';
        $log_data['search_time_ms'] = round($search_ms, 2);
        $log_data['search_count']   = $count_entries;

        if ($count_entries > 0) {
            $display_count = $limit_ignored
                ? $count_entries . '/' . $server_count
                : (string) $count_entries;
            $tooltip  = '<b>' . __('Generic Search', 'ldaptools') . '</b><br/>';
            $tooltip .= __('Filter:', 'ldaptools') . ' ' . htmlspecialchars($search) . '<br/>';
            $tooltip .= __('Server returned:', 'ldaptools') . ' ' . $server_count . '<br/>';
            if ($limit_ignored) {
                $tooltip .= '<i class="fas fa-info-circle"></i> ' . __('Server ignored sizelimit — showing client-side trim', 'ldaptools') . '<br/>';
            }
            $tooltip .= __('Displaying:', 'ldaptools') . ' ' . $count_entries . '<br/>';
            $tooltip .= __('Time:', 'ldaptools') . ' ' . fmt_ms($search_ms) . '<br/>';
            $firstEntry = ldap_first_entry($ldap, $results);
            if ($firstEntry) {
                $tooltip .= '<br/><b>' . __('First entry', 'ldaptools') . '</b><br/>' . htmlspecialchars(ldap_get_dn($ldap, $firstEntry));
            }
            $status = $limit_ignored ? 'warn' : 'ok';
            echo status_span($status, $authldaps_id, '<i class="far fa-thumbs-up mr-2 m-2"></i>' . $display_count . ' ' . __('entries', 'ldaptools') . ' <small>(' . fmt_ms($search_ms) . ')</small>', $tooltip);
            $next = true;
        } else {
            echo status_span('error', $authldaps_id, '<i class="far fa-thumbs-down mr-2 m-2"></i>' . sprintf(__('No entry found: %s', 'ldaptools'), htmlspecialchars($search)));
            $next = false;
        }
    }
} else {
    $log_data['test_search'] = 'skip';
    echo status_span('skip', $authldaps_id, '<i class="far fa-hand-point-left mr-2 m-2"></i>' . __('Fix previous!', 'ldaptools'));
    $next = false;
}
echo '</td>';

// ===================================================================
// TEST 7: Filtered Search (user-configured filter)
// ===================================================================
echo '<td>';
if ($next) {
    if (empty($filter)) {
        $filter = $search;
    }

    $filter_start = microtime(true);
    $results = @ldap_search($ldap, $base_dn, $filter, [], 0, $search_limit, $search_timelimit);
    $filter_ms = (microtime(true) - $filter_start) * 1000;

    if (!$results) {
        $errno_ldap = ldap_errno($ldap);
        $log_data['test_filter']    = 'error';
        $log_data['filter_time_ms'] = round($filter_ms, 2);
        $errors[] = "Filter search failed ($filter): " . ldap_err2str($errno_ldap);

        $tooltip  = sprintf(__('Error number: %s', 'ldaptools'), $errno_ldap) . '<br/>';
        $tooltip .= sprintf(__('Error message: %s', 'ldaptools'), ldap_err2str($errno_ldap));
        echo status_span('error', $authldaps_id, '<i class="far fa-thumbs-down mr-2 m-2"></i>' . sprintf(__('Filter error: %s', 'ldaptools'), htmlspecialchars($filter)) . ' <small>(' . fmt_ms($filter_ms) . ')</small>', $tooltip);
        $next = false;
    } else {
        $server_filter_count = ldap_count_entries($ldap, $results);
        $filter_count = $server_filter_count;
        $filter_limit_ignored = ($search_limit > 0 && $server_filter_count > $search_limit);
        if ($filter_limit_ignored) {
            $filter_count = $search_limit;
        }
        $log_data['test_filter']    = ($filter_count > 0) ? 'ok' : 'error';
        $log_data['filter_time_ms'] = round($filter_ms, 2);
        $log_data['filter_count']   = $filter_count;

        if ($filter_count > 0) {
            $display_filter = $filter_limit_ignored
                ? $filter_count . '/' . $server_filter_count
                : (string) $filter_count;
            $tooltip  = '<b>' . __('Filtered Search', 'ldaptools') . '</b><br/>';
            $tooltip .= __('Filter:', 'ldaptools') . ' ' . htmlspecialchars($filter) . '<br/>';
            $tooltip .= __('Server returned:', 'ldaptools') . ' ' . $server_filter_count . '<br/>';
            if ($filter_limit_ignored) {
                $tooltip .= '<i class="fas fa-info-circle"></i> ' . __('Server ignored sizelimit — showing client-side trim', 'ldaptools') . '<br/>';
            }
            $tooltip .= __('Displaying:', 'ldaptools') . ' ' . $filter_count . '<br/>';
            $tooltip .= __('Time:', 'ldaptools') . ' ' . fmt_ms($filter_ms);
            if ($firstEntry = ldap_first_entry($ldap, $results)) {
                $tooltip .= '<br/><br/><b>' . __('First entry', 'ldaptools') . '</b><br/>' . htmlspecialchars(ldap_get_dn($ldap, $firstEntry));
            }
            $status = $filter_limit_ignored ? 'warn' : 'ok';
            echo status_span($status, $authldaps_id, '<i class="far fa-thumbs-up mr-2 m-2"></i>' . sprintf(__('%s entries', 'ldaptools'), $display_filter) . ' <small>(' . fmt_ms($filter_ms) . ')</small>', $tooltip);
            $next = true;
        } else {
            echo status_span('error', $authldaps_id, '<i class="far fa-thumbs-down mr-2 m-2"></i>' . sprintf(__('No entry found: %s', 'ldaptools'), htmlspecialchars($filter)));
            $next = false;
        }
    }
} else {
    $log_data['test_filter'] = 'skip';
    echo status_span('skip', $authldaps_id, '<i class="far fa-hand-point-left mr-2 m-2"></i>' . __('Fix previous!', 'ldaptools'));
    $next = false;
}
echo '</td>';

// ===================================================================
// TEST 8: Attributes Discovery
// ===================================================================
echo '<td>';
if ($next) {
    $attrs = false;
    if ($first = ldap_first_entry($ldap, $results)) {
        $attrs = ldap_get_attributes($ldap, $first);
    }
    if (!$attrs) {
        $errno_ldap = ldap_errno($ldap);
        $log_data['test_attributes'] = 'error';
        $errors[] = 'Get attributes failed: ' . ldap_err2str($errno_ldap);

        $tooltip  = sprintf(__('Error number: %s', 'ldaptools'), $errno_ldap) . '<br/>';
        $tooltip .= sprintf(__('Error message: %s', 'ldaptools'), ldap_err2str($errno_ldap));
        echo status_span('error', $authldaps_id, '<i class="far fa-thumbs-down mr-2 m-2"></i>' . __('Get attributes error', 'ldaptools'), $tooltip);
    } else {
        $log_data['test_attributes'] = 'ok';
        $attr_list = [];
        for ($i = 0; $i < $attrs['count']; $i++) {
            $attr_list[] = $attrs[$i];
        }
        $log_data['attributes_list'] = implode(', ', $attr_list);

        $tooltip  = '<b>' . __('Available attributes', 'ldaptools') . ' (' . count($attr_list) . ')</b><br/>';
        $tooltip .= implode(', ', array_map('htmlspecialchars', $attr_list));
        echo status_span('ok', $authldaps_id, '<i class="far fa-thumbs-up mr-2 m-2"></i>' . count($attr_list) . ' attrs', $tooltip);
    }
} else {
    $log_data['test_attributes'] = 'skip';
    echo status_span('skip', $authldaps_id, '<i class="far fa-hand-point-left mr-2 m-2"></i>' . __('Fix previous!', 'ldaptools'));
}
echo '</td>';

// ===================================================================
// Total time & overall status
// ===================================================================
$total_ms = (microtime(true) - $test_start) * 1000;
$log_data['total_time_ms'] = round($total_ms, 2);

// Determine overall status
$statuses = ['test_dns', 'test_tcp', 'test_basedn', 'test_connect', 'test_bind', 'test_search', 'test_filter', 'test_attributes'];
$has_error = false;
$has_warn  = false;
foreach ($statuses as $s) {
    $val = $log_data[$s] ?? 'skip';
    if ($val === 'error') {
        $has_error = true;
    }
    if ($val === 'warn') {
        $has_warn = true;
    }
}
// Flag as 'slow' warning if total time exceeds 30 seconds
if ($total_ms > 30000) {
    $has_warn = true;
    $errors[] = sprintf('Test took %.1fs — may cause proxy timeouts. Reduce "Max entries" limit.', $total_ms / 1000);
}
$log_data['overall_status'] = $has_error ? 'error' : ($has_warn ? 'warn' : 'ok');
$log_data['error_details']  = !empty($errors) ? implode("\n", $errors) : null;

// -- Total time column --
echo '<td>';
$status_icon = match ($log_data['overall_status']) {
    'ok'    => '<i class="fas fa-check-circle" style="color:#22c55e;"></i>',
    'warn'  => '<i class="fas fa-exclamation-triangle" style="color:#f59e0b;"></i>',
    default => '<i class="fas fa-times-circle" style="color:#ef4444;"></i>',
};
echo $status_icon . ' <small>' . fmt_ms($total_ms) . '</small>';
echo '</td>';

echo '</tr>';

// ===================================================================
// Save to log table
// ===================================================================
if (class_exists('PluginLdaptoolsLog')) {
    PluginLdaptoolsLog::saveTestResult($log_data);
}

// Close LDAP connection
if ($ldap) {
    @ldap_unbind($ldap);
}

// GLPI log entry
$log_msg = sprintf(
    '[ldaptools] Test completed: server=%s host=%s:%d status=%s total=%sms',
    $server_name,
    $host,
    $port_num,
    $log_data['overall_status'],
    fmt_ms($total_ms),
);
if ($log_data['overall_status'] === 'error') {
    Toolbox::logInfo($log_msg . ' errors=' . ($log_data['error_details'] ?? ''));
} else {
    Toolbox::logDebug($log_msg);
}
