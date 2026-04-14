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
 * @author    Rafael Ferreira
 * @license   GPLv3 https://www.gnu.org/licenses/gpl-3.0.html
 * -------------------------------------------------------------------------
 */

use Glpi\Application\View\TemplateRenderer;

class PluginLdaptoolsLog extends CommonDBTM
{
    public static $rightname = 'config';

    // Table name
    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_ldaptools_logs';
    }

    public static function getTypeName($nb = 0): string
    {
        return _n('LDAP Test Log', 'LDAP Test Logs', $nb, 'ldaptools');
    }

    public static function getMenuName(): string
    {
        return __('Test Logs', 'ldaptools');
    }

    public static function getPageLink(): string
    {
        return 'log.php';
    }

    public static function getComment(): string
    {
        return __('View historical LDAP test results with detailed diagnostics and timing information.', 'ldaptools');
    }

    public static function canView(): bool
    {
        return Config::canView();
    }

    public static function canUpdate(): bool
    {
        return Config::canUpdate();
    }

    public static function getIcon(): string
    {
        return 'fas fa-clipboard-list';
    }

    /**
     * Install the log table
     */
    public static function install(Migration $migration): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $table = self::getTable();

        if (!$DB->tableExists($table)) {
            $migration->displayMessage("Installing $table");

            $DB->doQuery("CREATE TABLE `$table` (
                `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `date_test`             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `users_id`              INT UNSIGNED NOT NULL DEFAULT 0,
                `authldaps_id`          INT UNSIGNED NOT NULL DEFAULT 0,
                `authldapreplicates_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `server_name`           VARCHAR(255) DEFAULT NULL,
                `hostname`              VARCHAR(255) DEFAULT NULL,
                `port`                  INT UNSIGNED DEFAULT NULL,
                `is_replica`            TINYINT(1) NOT NULL DEFAULT 0,
                `test_dns`              VARCHAR(20) DEFAULT NULL,
                `dns_time_ms`           FLOAT DEFAULT NULL,
                `dns_addresses`         TEXT DEFAULT NULL,
                `test_tcp`              VARCHAR(20) DEFAULT NULL,
                `tcp_time_ms`           FLOAT DEFAULT NULL,
                `test_basedn`           VARCHAR(20) DEFAULT NULL,
                `test_connect`          VARCHAR(20) DEFAULT NULL,
                `connect_time_ms`       FLOAT DEFAULT NULL,
                `test_starttls`         VARCHAR(20) DEFAULT NULL,
                `tls_time_ms`           FLOAT DEFAULT NULL,
                `tls_protocol`          VARCHAR(50) DEFAULT NULL,
                `tls_cipher`            VARCHAR(100) DEFAULT NULL,
                `test_bind`             VARCHAR(20) DEFAULT NULL,
                `bind_time_ms`          FLOAT DEFAULT NULL,
                `test_search`           VARCHAR(20) DEFAULT NULL,
                `search_time_ms`        FLOAT DEFAULT NULL,
                `search_count`          INT UNSIGNED DEFAULT NULL,
                `test_filter`           VARCHAR(20) DEFAULT NULL,
                `filter_time_ms`        FLOAT DEFAULT NULL,
                `filter_count`          INT UNSIGNED DEFAULT NULL,
                `test_attributes`       VARCHAR(20) DEFAULT NULL,
                `attributes_list`       TEXT DEFAULT NULL,
                `total_time_ms`         FLOAT DEFAULT NULL,
                `overall_status`        VARCHAR(20) DEFAULT NULL,
                `error_details`         TEXT DEFAULT NULL,
                `server_info`           TEXT DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `date_test` (`date_test`),
                KEY `authldaps_id` (`authldaps_id`),
                KEY `users_id` (`users_id`),
                KEY `overall_status` (`overall_status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
    }

    /**
     * Uninstall the log table
     */
    public static function uninstall(Migration $migration): void
    {
        $table = self::getTable();
        $migration->displayMessage("Uninstalling $table");
        $migration->dropTable($table);
    }

    /**
     * Save a test result to the log via direct insert
     * (CommonDBTM::add() filters unknown fields, so we use doQuery)
     */
    public static function saveTestResult(array $data): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        $data['users_id'] = Session::getLoginUserID() ?: 0;
        $data['date_test'] = date('Y-m-d H:i:s');

        // Only keep columns that exist in our table
        $valid_columns = [
            'date_test', 'users_id', 'authldaps_id', 'authldapreplicates_id',
            'server_name', 'hostname', 'port', 'is_replica',
            'test_dns', 'dns_time_ms', 'dns_addresses',
            'test_tcp', 'tcp_time_ms',
            'test_basedn',
            'test_connect', 'connect_time_ms',
            'test_starttls', 'tls_time_ms', 'tls_protocol', 'tls_cipher',
            'test_bind', 'bind_time_ms',
            'test_search', 'search_time_ms', 'search_count',
            'test_filter', 'filter_time_ms', 'filter_count',
            'test_attributes', 'attributes_list',
            'total_time_ms', 'overall_status', 'error_details', 'server_info',
        ];

        $filtered = [];
        foreach ($valid_columns as $col) {
            if (array_key_exists($col, $data)) {
                $filtered[$col] = $data[$col];
            }
        }

        $result = $DB->insert(self::getTable(), $filtered);
        return (bool) $result;
    }

    /**
     * Get recent test logs
     */
    public static function getRecentLogs(int $limit = 100, int $authldaps_id = 0): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $criteria = [
            'FROM'    => self::getTable(),
            'ORDER'   => 'date_test DESC',
            'LIMIT'   => $limit,
        ];

        if ($authldaps_id > 0) {
            $criteria['WHERE'] = ['authldaps_id' => $authldaps_id];
        }

        $results = [];
        $iterator = $DB->request($criteria);
        foreach ($iterator as $row) {
            $results[] = $row;
        }

        return $results;
    }

    /**
     * Purge logs older than N days
     */
    public static function purgeLogs(int $days = 90): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $DB->delete(self::getTable(), [
            'date_test' => ['<', date('Y-m-d H:i:s', strtotime("-{$days} days"))],
        ]);

        return $DB->affectedRows();
    }

    /**
     * Show the log viewer page
     */
    public static function showLogs(): void
    {
        $authldaps_id = intval($_GET['authldaps_id'] ?? 0);
        $logs = self::getRecentLogs(200, $authldaps_id);

        // Get server names for the filter dropdown
        $servers = AuthLDAP::getLdapServers();

        TemplateRenderer::getInstance()->display('@ldaptools/log.html.twig', [
            'logs'            => $logs,
            'servers'         => $servers,
            'authldaps_id'    => $authldaps_id,
            'plugin_dir'      => Plugin::getWebDir('ldaptools'),
        ]);
    }
}
