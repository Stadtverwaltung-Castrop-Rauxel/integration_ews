<?php
declare(strict_types=1);

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

namespace OCA\EWS\Settings;

use OCA\EWS\Integration\Microsoft365;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;

use OCA\EWS\AppInfo\Application;
use OCA\EWS\Service\ConfigurationService;

class PersonalSettings implements ISettings {

    /**
     * @psalm-mutation-free
     */
    public function __construct(private IInitialState $initialStateService,
                                private ConfigurationService $ConfigurationService,
                                private string $userId) {
    }

	/**
	 * @return TemplateResponse
	 */
	public function getForm(): TemplateResponse {

		// retrieve user configuration
		$configuration = $this->ConfigurationService->retrieveUser($this->userId);
		$configuration['system_ms365_authorization_uri'] = Microsoft365::constructAuthorizationUrl();
		$configuration['system_approved_account_servers'] = $this->ConfigurationService->getApprovedAccountServers();

		$this->initialStateService->provideInitialState('personal-configuration', $configuration);

		return new TemplateResponse(Application::APP_ID, 'personalSettings');
	}

	/**
	 * @psalm-pure
	 */
	public function getSection(): string {
		return 'connected-accounts';
	}

	/**
	 * @psalm-pure
	 */
	public function getPriority(): int {
		return 10;
	}
}
