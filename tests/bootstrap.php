<?php

/**
 * -------------------------------------------------------------------------
 * advancedforms plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of advancedforms plugin.
 *
 * This plugin is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This plugin is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this plugin. If not, see <https://www.gnu.org/licenses/>.
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2018-2025 by Teclib'.
 * @license   GPLv3+ https://www.gnu.org/licenses/gpl-3.0.fr.html
 * @link      https://services.glpi-network.com
 * -------------------------------------------------------------------------
 */

$current_plugin_folder = basename(realpath(__DIR__ . '/../'));
chdir(__DIR__ . '/../../..');

require 'phpunit/bootstrap.php';

if (!Plugin::isPluginActive($current_plugin_folder)) {
    echo("Plugin $current_plugin_folder is not setup for tests" . PHP_EOL);
    echo("Run `make plugin-test-setup` to setup the plugin." . PHP_EOL);
    die();
}
