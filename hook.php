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

/**
 * Plugin install process
 */
function plugin_ldaptools_install(): bool
{
    $migration = new Migration(PLUGIN_LDAPTOOLS_VERSION);

    // Parse inc directory
    foreach (glob(PLUGIN_LDAPTOOLS_ROOT . '/inc/*') as $filepath) {
        // Load *.class.php files and get the class name
        if (preg_match("/inc.(.+)\.class\.php$/", $filepath, $matches)) {
            $classname = 'PluginLdaptools' . ucfirst($matches[1]);
            include_once($filepath);
            // If the install method exists, load it
            if (method_exists($classname, 'install')) {
                $classname::install($migration);
            }
        }
    }

    $migration->executeMigration();

    return true;
}

/**
 * Plugin uninstall process
 */
function plugin_ldaptools_uninstall(): bool
{
    $migration = new Migration(PLUGIN_LDAPTOOLS_VERSION);

    // Parse inc directory
    foreach (glob(PLUGIN_LDAPTOOLS_ROOT . '/inc/*') as $filepath) {
        // Load *.class.php files and get the class name
        if (preg_match("/inc.(.+)\.class\.php$/", $filepath, $matches)) {
            $classname = 'PluginLdaptools' . ucfirst($matches[1]);
            include_once($filepath);
            // If the uninstall method exists, load it
            if (method_exists($classname, 'uninstall')) {
                $classname::uninstall($migration);
            }
        }
    }

    $migration->executeMigration();

    return true;
}
