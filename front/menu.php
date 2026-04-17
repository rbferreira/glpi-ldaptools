<?php

/**
 * -------------------------------------------------------------------------
 * ldaptools plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * @author    François Legastelois
 * @author    Rafael Ferreira (GLPI 11 adaptation + enhancements)
 * @license   GPLv3 https://www.gnu.org/licenses/gpl-3.0.html
 * -------------------------------------------------------------------------
 */

include(dirname(__DIR__, 3) . '/inc/includes.php');

Session::checkRight('config', UPDATE);

Html::header(
    __('LDAP Tools', 'ldaptools'),
    $_SERVER['PHP_SELF'],
    'tools',
    'PluginLdaptoolsMenu',
    'menu',
);

PluginLdaptoolsMenu::showCentralPage();

Html::footer();
