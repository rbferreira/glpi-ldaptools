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
 * @copyright Copyright (C) 2021-2022 by Teclib'. 2026 enhancements by Rafael Ferreira.
 * @license   GPLv3 https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://github.com/pluginsglpi/ldaptools
 * -------------------------------------------------------------------------
 */

define('PLUGIN_LDAPTOOLS_VERSION', '1.0.1');

// Minimal GLPI version, inclusive
define('PLUGIN_LDAPTOOLS_MIN_GLPI', '11.0.0');
// Maximum GLPI version, exclusive
define('PLUGIN_LDAPTOOLS_MAX_GLPI', '11.0.99');

define('PLUGIN_LDAPTOOLS_ROOT', Plugin::getPhpDir('ldaptools'));

/**
 * Init hooks of the plugin.
 * REQUIRED
 */
function plugin_init_ldaptools(): void
{
    /** @var array $PLUGIN_HOOKS */
    global $PLUGIN_HOOKS;

    $plugin = new Plugin();

    $PLUGIN_HOOKS['csrf_compliant']['ldaptools'] = true;

    if (
        Session::getLoginUserID()
        && $plugin->isActivated('ldaptools')
        && Session::haveRight('config', UPDATE)
    ) {
        $PLUGIN_HOOKS['config_page']['ldaptools']         = 'front/menu.php';
        $PLUGIN_HOOKS['menu_toadd']['ldaptools']['tools'] = 'PluginLdaptoolsMenu';
    }
}

/**
 * Get the name and the version of the plugin
 * REQUIRED
 */
function plugin_version_ldaptools(): array
{
    return [
        'name'         => __('LDAP Tools', 'ldaptools'),
        'version'      => PLUGIN_LDAPTOOLS_VERSION,
        'author'       => 'teclib\' / Rafael Ferreira',
        'license'      => 'GPLv3',
        'homepage'     => 'https://github.com/pluginsglpi/ldaptools',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_LDAPTOOLS_MIN_GLPI,
                'max' => PLUGIN_LDAPTOOLS_MAX_GLPI,
            ],
        ],
    ];
}
