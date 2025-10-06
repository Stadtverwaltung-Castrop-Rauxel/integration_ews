<?php
/**
 * @copyright Copyright (c) 2023 Sebastian Krupinski <krupinski01@gmail.com>
 *
 * @author Sebastian Krupinski <krupinski01@gmail.com>
 *
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'AdminConfiguration#depositConfiguration', 'url' => '/admin-configuration', 'verb' => 'PUT'],
		['name' => 'PersonalConfiguration#ConnectAlternate', 'url' => '/connect-alternate', 'verb' => 'GET'],
		['name' => 'PersonalConfiguration#ConnectMS365', 'url' => '/connect-ms365', 'verb' => 'GET'],
		['name' => 'PersonalConfiguration#Disconnect', 'url' => '/disconnect', 'verb' => 'GET'],
		['name' => 'PersonalConfiguration#Harmonize', 'url' => '/harmonize', 'verb' => 'GET'],
		['name' => 'PersonalConfiguration#fetchLocalCollections', 'url' => '/fetch-local-collections', 'verb' => 'GET'],
		['name' => 'PersonalConfiguration#fetchRemoteCollections', 'url' => '/fetch-remote-collections', 'verb' => 'GET'],
		['name' => 'PersonalConfiguration#fetchCorrelations', 'url' => '/fetch-correlations', 'verb' => 'GET'],
		['name' => 'PersonalConfiguration#depositCorrelations', 'url' => '/deposit-correlations', 'verb' => 'PUT'],
		['name' => 'PersonalConfiguration#fetchPreferences', 'url' => '/fetch-preferences', 'verb' => 'GET'],
		['name' => 'PersonalConfiguration#depositPreferences', 'url' => '/deposit-preferences', 'verb' => 'PUT'],
	]
];
