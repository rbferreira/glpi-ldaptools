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

use Glpi\Application\View\TemplateRenderer;

class PluginLdaptoolsTest extends CommonGLPI
{
    public static $rightname = 'config';

    public static function getTypeName($nb = 0): string
    {
        return __('LDAP Test', 'ldaptools');
    }

    public static function getMenuName(): string
    {
        return __('LDAP Test', 'ldaptools');
    }

    public static function getLink(): string
    {
        return 'test.php';
    }

    public static function getComment(): string
    {
        return __('Performs diagnostic tests on all LDAP directories declared in GLPI, with detailed timing, DNS resolution, TLS certificate info, and structured logging.', 'ldaptools');
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
        return 'fas fa-bug';
    }

    public static function showResult(): void
    {
        $ldaps_map = array_map(function (array $ldap_master): array {
            return [
                'master'   => $ldap_master,
                'replicat' => AuthLDAP::getAllReplicateForAMaster($ldap_master['id']),
            ];
        }, AuthLDAP::getLdapServers());

        TemplateRenderer::getInstance()->display('@ldaptools/test.html.twig', [
            'ldap'         => self::class,
            'ldap_servers' => $ldaps_map,
            'plugin_dir'   => Plugin::getWebDir('ldaptools'),
        ]);
    }
}
