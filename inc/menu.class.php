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

class PluginLdaptoolsMenu extends CommonGLPI
{
    public static $rightname = 'config';

    public static function getTypeName($nb = 0): string
    {
        return __('LDAP Tools', 'ldaptools');
    }

    public static function getMenuName(): string
    {
        return __('LDAP Tools', 'ldaptools');
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
        return 'fas fa-network-wired';
    }

    public static function getMenuContent(): array|false
    {
        $menu = [];

        $base_dir = '/' . Plugin::getWebDir('ldaptools', false);

        if (PluginLdaptoolsMenu::canUpdate()) {
            $menu['title'] = self::getMenuName();
            $menu['page']  = "$base_dir/front/menu.php";
            $menu['icon']  = self::getIcon();

            $link_text = "<span class='d-none d-xxl-block'>" .
                      PluginLdaptoolsMenu::getTypeName(Session::getPluralNumber()) .
                      '</span>';
            $links = [
                "<i class='" . PluginLdaptoolsMenu::getIcon() . "'></i>$link_text"
               => PluginLdaptoolsMenu::getSearchURL(false),
            ];

            $menu['options']['test'] = [
                'title' => PluginLdaptoolsTest::getTypeName(Session::getPluralNumber()),
                'page'  => "$base_dir/front/test.php",
                'icon'  => PluginLdaptoolsTest::getIcon(),
                'links' => [],
            ];

            $menu['options']['log'] = [
                'title' => PluginLdaptoolsLog::getTypeName(Session::getPluralNumber()),
                'page'  => "$base_dir/front/log.php",
                'icon'  => PluginLdaptoolsLog::getIcon(),
                'links' => [],
            ];
        }

        if (count($menu)) {
            return $menu;
        }

        return false;
    }

    public static function showCentralPage(): void
    {
        $filepaths = [];
        if (Toolbox::canUseLdap()) {
            foreach (glob(PLUGIN_LDAPTOOLS_ROOT . '/inc/*') as $filepath) {
                if (preg_match("/inc.(.+)\.class\.php$/", $filepath, $matches)) {
                    $classname = 'PluginLdaptools' . ucfirst($matches[1]);
                    include_once($filepath);
                    $linkMethod = method_exists($classname, 'getPageLink') ? 'getPageLink' : (method_exists($classname, 'getLink') ? 'getLink' : null);
                    if ($linkMethod !== null) {
                        $filepaths[$filepath] = [
                            'link'    => $classname::$linkMethod(),
                            'name'    => $classname::getTypeName(),
                            'icon'    => method_exists($classname, 'getIcon') ? $classname::getIcon() : 'fas fa-cog',
                            'comment' => method_exists($classname, 'getComment')
                                ? $classname::getComment()
                                : __('No comments'),
                        ];
                    }
                }
            }
        }

        TemplateRenderer::getInstance()->display('@ldaptools/menu.html.twig', [
            'can_use'   => Toolbox::canUseLdap(),
            'filepaths' => $filepaths,
        ]);
    }
}
