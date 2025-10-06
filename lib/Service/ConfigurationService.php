<?php
//declare(strict_types=1);

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

namespace OCA\EWS\Service;

use OCP\Exceptions\AppConfigException;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

use OCP\IConfig;
use OCP\Security\ICrypto;
use OCP\IUserManager;
use OCP\App\IAppManager;

use OCA\EWS\AppInfo\Application;

/**
 * TODO: Migrate string constants to an enum for
 */
class ConfigurationService {

	const ProviderAlternate = 'A';
	const ProviderMS365 = 'MS365';

	/**
	 * Default System Configuration
	 *
	 * @var array
	 * */
	private const _SYSTEM = [
		'transport_verification' => '1',
		'transport_log' => '0',
		'transport_log_path' => '/tmp',
		'harmonization_mode' => 'P',
		'harmonization_thread_duration' => '3600',
		'harmonization_thread_pause' => '15',
		'ms365_tenant_id' => '',
		'ms365_application_id' => '',
		'ms365_application_secret' => '',
		'approved_account_servers' => []
	];

	private const _SYSTEM_ARRAY = [
		'approved_account_servers' => 1,
	];

	/**
	 * Default System Secure Parameters
	 *
	 * @var array
	 * */
	private const _SYSTEM_SECURE = [
		'ms365_tenant_id' => 1,
		'ms365_application_id' => 1,
		'ms365_application_secret' => 1,
	];

	/**
	 * Default User Configuration
	 *
	 * @var array
	 * */
	private const _USER = [
		'account_provider' => 'A',
		'account_id' => '',
		'account_name' => '',
		'account_server' => '',
		'account_bauth_id' => '',
		'account_bauth_secret' => '',
		'account_protocol' => 'Exchange2007',
		'account_connected' => '0',
		'account_harmonization_state' => '0',
		'account_harmonization_start' => '0',
		'account_harmonization_end' => '0',
		'account_harmonization_tid' => '0',
		'account_harmonization_thb' => '0',
		'account_oauth_access' => '',
		'account_oauth_expiry' => '0',
		'account_oauth_refresh' => '',
		'contacts_harmonize' => '5',
		'contacts_prevalence' => 'R',
		'contacts_presentation' => '',
		'events_harmonize' => '5',
		'events_prevalence' => 'R',
		'events_timezone' => '',
		'events_attachment_path' => '/Calendar',
		'tasks_harmonize' => '5',
		'tasks_prevalence' => 'R',
		'tasks_attachment_path' => '/Tasks',
	];

	/**
	 * Default User Secure Parameters
	 *
	 * @var array
	 * */
	private const _USER_SECURE = [
		'account_bauth_secret' => 1,
		'account_oauth_access' => 1,
		'account_oauth_refresh' => 1,
	];

	/** @var LoggerInterface */
	private $_logger;

	/** @var IConfig */
	private $_config;

	/** @var IAppConfig */
	private $_appConfig;

	/** @var ICrypto */
	private $_crypto;

	/** @var IUserManager */
	private $_usermanager;

	/** @var IAppManager */
	private $_appmanager;


	public function __construct(LoggerInterface $logger, IConfig $config, IAppConfig $appConfig, ICrypto $crypto, IUserManager $userManager, IAppManager $appManager) {
		$this->_logger = $logger;
		$this->_config = $config;
		$this->_crypto = $crypto;
		$this->_usermanager = $userManager;
		$this->_appmanager = $appManager;
		$this->_appConfig = $appConfig;
	}

	/**
	 * Retrieves account provider
	 *
	 * @param string $uid nextcloud user id
	 *
	 * @return string acount provider id
	 * @since Release 1.0.0
	 *
	 */
	public function retrieveProvider(string $uid): string {

		// retrieve and return account provider
		return $this->retrieveUserValue($uid, 'account_provider');

	}

	/**
	 * Deposit accout provider
	 *
	 * @param string $uid nextcloud user id
	 * @param string $id account provider id
	 *
	 * @return void
	 * @since Release 1.0.0
	 *
	 */
	public function depositProvider(string $uid, string $id): void {

		// deposit account provider
		$this->depositUserValue($uid, 'account_provider', $id);

	}

	/**
	 * Retrieves collection of system configuration parameters
	 *
	 * @param string $uid nextcloud user id
	 * @param array $keys collection of configuration parameter keys
	 *
	 * @return array of key/value pairs, of configuration parameter
	 * @since Release 1.0.0
	 *
	 */
	public function retrieveUser(string $uid, ?array $keys = null): array {

		// define parameters place holder
		$parameters = [];
		// evaluate if we are looking for specific parameters

		if (!isset($keys) || count($keys) == 0) {
			// retrieve all user configuration keys
			$keys = array_keys(self::_USER);
			// retrieve all user configuration values
			foreach ($keys as $entry) {
				$parameters[$entry] = $this->retrieveUserValue($uid, $entry);
			}
			// retrieve system parameters
			$parameters['system_timezone'] = date_default_timezone_get();
			$parameters['system_contacts'] = $this->isContactsAppAvailable($uid);
			$parameters['system_events'] = $this->isCalendarAppAvailable($uid);
			$parameters['system_tasks'] = $this->isTasksAppAvailable($uid);
			$parameters['user_id'] = $uid;
			// user default time zone
			$v = $this->_config->getUserValue($uid, 'core', 'timezone');
			if (!empty($v)) {
				$parameters['user_timezone'] = $v;
			}
			// user events attachment path
			$v = $this->_config->getUserValue($uid, 'dav', 'attachmentsFolder');
			if (!empty($v)) {
				$parameters['events_attachment_path'] = $v;
			}
		} else {
			// retrieve specific user configuration values
			foreach ($keys as $entry) {
				$parameters[$entry] = $this->retrieveUserValue($uid, $entry);
			}
		}
		// remove account secret
		if (isset($parameters['account_secret'])) {
			$parameters['account_secret'] = null;
		}
		// return configuration parameters
		return $parameters;

	}

	/**
	 * Deposit collection of system configuration parameters
	 *
	 * @param string $uid nextcloud user id
	 * @param array $parameters collection of key/value pairs, of parameters
	 *
	 * @return void
	 * @throws AppConfigException
	 * @since Release 1.0.0
	 *
	 */
	public function depositUser($uid, array $parameters): void {

		$approvedServers = $this->getApprovedAccountServers();

		// deposit system configuration parameters
		foreach ($parameters as $key => $value) {
			if ($key == 'account_server' && !empty($approvedServers) && !in_array($value, $approvedServers)) {
				$msg = "App configuration for \"$key\" is invalid.";
				$this->_logger->warning($msg, ['app' => Application::APP_ID]);
				throw new AppConfigException($msg);
			}
			$this->depositUserValue($uid, $key, $value);
		}

	}

	/**
	 * Destroy collection of system configuration parameters
	 *
	 * @param string $uid nextcloud user id
	 * @param array $keys collection of configuration parameter keys
	 *
	 * @return void
	 * @since Release 1.0.0
	 *
	 */
	public function destroyUser(string $uid, ?array $keys = null): void {

		// evaluate if we are looking for specific parameters
		if (!isset($keys) || count($keys) == 0) {
			$keys = $this->_config->getUserKeys($uid, Application::APP_ID);
		}
		// destroy system configuration parameter
		foreach ($keys as $entry) {
			$this->destroyUserValue($uid, $entry);
		}

	}

	/**
	 * Retrieves single system configuration parameter
	 *
	 * @param string $uid nextcloud user id
	 * @param string $key configuration parameter key
	 *
	 * @return string configuration parameter value
	 * @since Release 1.0.0
	 *
	 */
	public function retrieveUserValue(string $uid, string $key): string {

		// retrieve configured parameter value
		$value = $this->_config->getUserValue($uid, Application::APP_ID, $key);
		// evaluate if value was returned
		if ($value != '') {
			// evaluate if parameter is on the secure list and is not empty
			if (isset(self::_USER_SECURE[$key]) && !empty($value)) {
				try {
					$value = $this->_crypto->decrypt($value);
				} catch (\Throwable $th) {
					// Do nothing just return the original value
				}
			}
			// return configuration parameter value
			return $value;
		} else {
			// return default system configuration value
			return self::_USER[$key];
		}

	}

	/**
	 * Deposit single system configuration parameter
	 *
	 * @param string $uid nextcloud user id
	 * @param string $key configuration parameter key
	 * @param string $value configuration parameter value
	 *
	 * @return void
	 * @since Release 1.0.0
	 *
	 */
	public function depositUserValue(string $uid, string $key, string $value): void {

		// trim whitespace
		$value = trim($value);
		// evaluate if parameter is on the secure list
		if (isset(self::_USER_SECURE[$key]) && !empty($value)) {
			$value = $this->_crypto->encrypt($value);
		}
		// deposit user configuration parameter value
		$this->_config->setUserValue($uid, Application::APP_ID, $key, $value);

	}

	/**
	 * Destroy single user configuration parameter
	 *
	 * @param string $uid nextcloud user id
	 * @param string $key configuration parameter keys
	 *
	 * @return void
	 * @since Release 1.0.0
	 *
	 */
	public function destroyUserValue(string $uid, string $key): void {

		// destroy user configuration parameter
		$this->_config->deleteUserValue($uid, Application::APP_ID, $key);

	}

	/**
	 * Retrieves collection of system configuration parameters
	 *
	 * @param array $keys collection of configuration parameter keys
	 *
	 * @return array of key/value pairs, of configuration parameter
	 * @since Release 1.0.0
	 *
	 */
	public function retrieveSystem(?array $keys = null): array {

		// evaluate if we are looking for specific parameters
		if (!isset($keys) || count($keys) == 0) {
			$keys = array_keys(self::_SYSTEM);
		}
		// retrieve system configuration values
		$parameters = [];
		foreach ($keys as $entry) {
			if (array_key_exists($entry, self::_SYSTEM_ARRAY)) {
				$parameters[$entry] = $this->retrieveSystemArray($entry);
			} else {
				$parameters[$entry] = $this->retrieveSystemValue($entry);
			}
		}
		// return configuration parameters
		return $parameters;

	}

	/**
	 * Deposit collection of system configuration parameters
	 *
	 * @param array $parameters collection of key/value pairs, of parameters
	 *
	 * @return void
	 * @since Release 1.0.0
	 *
	 */
	public function depositSystem(array $parameters): void {

		// deposit system configuration parameters
		foreach ($parameters as $key => $value) {
			if (array_key_exists($key, self::_SYSTEM_ARRAY) && is_array($value)) {
				$this->depositSystemArray($key, $value);
			} else {
				$this->depositSystemValue($key, $value);
			}
		}

	}


	/**
	 * Destroy collection of system configuration parameters
	 *
	 * @param array $keys collection of configuration parameter keys
	 *
	 * @return void
	 * @since Release 1.0.0
	 *
	 */
	public function destroySystem(?array $keys = null): void {

		// evaluate if we are looking for specific parameters
		if (!isset($keys) || count($keys) == 0) {
			$keys = $this->_appConfig->getKeys(Application::APP_ID);
		}
		// destroy system configuration parameter
		foreach ($keys as $entry) {
			$this->destroySystemValue($entry);
		}

	}

	/**
	 * Retrieves single system configuration parameter
	 *
	 * @param string $key configuration parameter key
	 *
	 * @return string configuration parameter value
	 * @since Release 1.0.0
	 *
	 */
	public function retrieveSystemValue(string $key): string {

		// retrieve configured parameter value
		$value = $this->_appConfig->getValueString(Application::APP_ID, $key);
		// evaluate if value was returned
		if ($value != '') {
			// evaluate if parameter is on the secure list and is not empty
			if (isset(self::_SYSTEM_SECURE[$key]) && !empty($value)) {
				try {
					$value = $this->_crypto->decrypt($value);
				} catch (\Throwable $th) {
					// Do nothing just return the original value
				}
			}
			// return configuration parameter value
			return $value;
		} else {
			// return default system configuration value
			return self::_SYSTEM[$key];
		}

	}

	/**
	 * Retrieves single system configuration parameter which is an array
	 *
	 * @param string $key configuration parameter key
	 *
	 * @return string configuration parameter value
	 */
	public function retrieveSystemArray(string $key): array {

		// retrieve configured parameter value
		$value = $this->_appConfig->getValueArray(Application::APP_ID, $key);
		// evaluate if value was returned
		if ($value == '') {
			// return default system configuration value
			return self::_SYSTEM[$key];
		} else {
			// return configuration parameter value
			return $value;
		}

	}

	/**
	 * Deposit single system configuration parameter
	 *
	 * @param string $key configuration parameter key
	 * @param string $value configuration parameter value
	 *
	 * @return void
	 * @since Release 1.0.0
	 *
	 */
	public function depositSystemValue(string $key, string $value): void {

		// trim whitespace
		$value = trim($value);
		// evaluate if parameter is on the secure list
		if (isset(self::_SYSTEM_SECURE[$key]) && !empty($value)) {
			$value = $this->_crypto->encrypt($value);
		}
		// deposit system configuration parameter value
		$this->_appConfig->setValueString(Application::APP_ID, $key, $value);
	}

	/**
	 * Deposit single system configuration parameter
	 *
	 * @param string $key configuration parameter key
	 * @param array $value configuration parameter value
	 *
	 * @return void
	 * @since Release 1.0.0
	 *
	 */
	public function depositSystemArray(string $key, array $value): void {
		// deposit system configuration parameter value
		$this->_appConfig->setValueArray(Application::APP_ID, $key, $value);
	}

	/**
	 * Destroy single system configuration parameter
	 *
	 * @return void
	 * @since Release 1.0.0
	 *
	 */
	public function destroySystemValue(string $key): void {

		// destroy system configuration parameter
		$this->_appConfig->deleteKey(Application::APP_ID, $key);

	}

	/**
	 * Converts key/value paired attribute array to object properties
	 *
	 * @param string $parameters collection of key/value paired attributes
	 *
	 * @return \OCA\EWS\Objects\ConfigurationObject
	 * @since Release 1.0.0
	 *
	 */
	public function toUserConfigurationObject(array $parameters): \OCA\EWS\Objects\ConfigurationObject {

		// construct configuration object
		$o = new \OCA\EWS\Objects\ConfigurationObject();

		foreach ($parameters as $key => $value) {
			switch ($key) {
				case 'system_timezone':
					if (!empty($value)) {
						$tz = @timezone_open($value);
						if ($tz instanceof \DateTimeZone) {
							$o->SystemTimeZone = $tz;
						}
					}
					unset($tz);
					break;
				case 'user_id':
					$o->UserId = $value;
					break;
				case 'user_timezone':
					if (!empty($value)) {
						$tz = @timezone_open($value);
						if ($tz instanceof \DateTimeZone) {
							$o->UserTimeZone = $tz;
						}
					}
					unset($tz);
					break;
				case 'contacts_harmonize':
					$o->ContactsHarmonize = $value;
					break;
				case 'contacts_prevalence':
					$o->ContactsPrevalence = $value;
					break;
				case 'contacts_presentation':
					$o->ContactsPresentation = $value;
					break;
				case 'events_harmonize':
					$o->EventsHarmonize = $value;
					break;
				case 'events_prevalence':
					$o->EventsPrevalence = $value;
					break;
				case 'events_timezone':
					if (!empty($value)) {
						$tz = @timezone_open($value);
						if ($tz instanceof \DateTimeZone) {
							$o->EventsTimezone = $tz;
						}
					}
					unset($tz);
					break;
				case 'events_attachment_path':
					$o->EventsAttachmentPath = $value;
					break;
				case 'tasks_harmonize':
					$o->TasksHarmonize = $value;
					break;
				case 'tasks_prevalence':
					$o->TasksPrevalence = $value;
					break;
				case 'tasks_attachment_path':
					$o->TasksAttachmentPath = $value;
					break;
				case 'account_provider':
					$o->AccountProvider = $value;
					break;
				case 'account_server':
					$o->AccountServer = $value;
					break;
				case 'account_id':
					$o->AccountId = $value;
					break;
				case 'account_protocol':
					$o->AccountProtocol = $value;
					break;
				case 'account_connected':
					$o->AccountConnected = $value;
					break;
			}
		}
		// return configuration object
		return $o;

	}

	/**
	 * Gets approved account servers
	 *
	 * @return string[] approved account servers
	 * @since Release 1.0.0
	 *
	 */
	public function getApprovedAccountServers(): array {

		// retrieve approved account servers
		$approvedAccountServers = $this->retrieveSystemArray('approved_account_servers');
		// return approved account servers or default
		if (!empty($approvedAccountServers)) {
			return $approvedAccountServers;
		} else {
			return self::_SYSTEM['approved_account_servers'];
		}

	}

	/**
	 * Sets approved account servers
	 *
	 * @param string[] $approvedAccountServers approved account servers
	 *
	 * @return void
	 * @since Release 1.0.0
	 *
	 */
	public function setApprovedAccountServers(array $approvedAccountServers): void {

		// set approved account servers
		$this->depositSystemArray('approved_account_servers', $approvedAccountServers);

	}

	/**
	 * Gets harmonization mode
	 *
	 * @return string harmonization mode (default P - passive)
	 * @since Release 1.0.0
	 *
	 */
	public function getHarmonizationMode(): string {

		// retrieve harmonization mode
		$mode = $this->retrieveSystemValue('harmonization_mode');
		// return harmonization mode or default
		if (!empty($mode)) {
			return $mode;
		} else {
			return self::_SYSTEM['harmonization_mode'];
		}

	}

	/**
	 * Sets harmonization mode
	 *
	 * @param string $mode harmonization mode (A - Active / P - Passive)
	 *
	 * @return void
	 * @since Release 1.0.0
	 *
	 */
	public function setHarmonizationMode(string $mode): void {

		// set harmonization mode
		$this->depositSystemValue('harmonization_mode', $mode);

	}


	/**
	 * Gets harmonization state
	 *
	 * @param string $uid nextcloud user id
	 *
	 * @return bool
	 * @since Release 1.0.0
	 *
	 */
	public function getHarmonizationState(string $uid): bool {

		// retrieve state
		return (bool)$this->retrieveUserValue($uid, 'account_harmonization_state');

	}

	/**
	 * Sets harmonization state
	 *
	 * @param string $uid nextcloud user id
	 * @param bool $state harmonization state (true/false)
	 *
	 * @return void
	 * @since Release 1.0.0
	 *
	 */
	public function setHarmonizationState(string $uid, bool $state): void {

		// deposit state
		$this->depositUserValue($uid, 'account_harmonization_state', $state);

	}


	/**
	 * Gets harmonization start
	 *
	 * @param string $uid nextcloud user id
	 *
	 * @return int
	 * @since Release 1.0.0
	 *
	 */
	public function getHarmonizationStart(string $uid): int {

		// return time stamp
		return (int)$this->retrieveUserValue($uid, 'account_harmonization_start');

	}

	/**
	 * Sets harmonization start
	 *
	 * @param string $uid nextcloud user id
	 *
	 * @return void
	 * @since Release 1.0.0
	 *
	 */
	public function setHarmonizationStart(string $uid): void {

		// deposit time stamp
		$this->depositUserValue($uid, 'account_harmonization_start', time());

	}

	/**
	 * Gets harmonization end
	 *
	 * @param string $uid nextcloud user id
	 *
	 * @return int
	 * @since Release 1.0.0
	 *
	 */
	public function getHarmonizationEnd(string $uid): int {

		// return time stamp
		return (int)$this->retrieveUserValue($uid, 'account_harmonization_end');

	}

	/**
	 * Sets harmonization end
	 *
	 * @param string $uid nextcloud user id
	 *
	 * @return void
	 * @since Release 1.0.0
	 *
	 */
	public function setHarmonizationEnd(string $uid): void {

		// deposit time stamp
		$this->depositUserValue($uid, 'account_harmonization_end', time());

	}

	/**
	 * Gets harmonization heart beat
	 *
	 * @param string $uid nextcloud user id
	 *
	 * @return int
	 * @since Release 1.0.0
	 *
	 */
	public function getHarmonizationHeartBeat(string $uid): int {

		// return time stamp
		return (int)$this->retrieveUserValue($uid, 'account_harmonization_hb');

	}

	/**
	 * Sets harmonization heart beat
	 *
	 * @param string $uid nextcloud user id
	 *
	 * @return void
	 * @since Release 1.0.0
	 *
	 */
	public function setHarmonizationHeartBeat(string $uid): void {

		// deposit time stamp
		$this->depositUserValue($uid, 'account_harmonization_hb', time());

	}

	/**
	 * Gets harmonization thread run duration interval
	 *
	 * @return string harmonization thread run duration interval (default 3600 seconds)
	 * @since Release 1.0.0
	 *
	 */
	public function getHarmonizationThreadDuration(): int {

		// retrieve value
		$interval = $this->retrieveSystemValue('harmonization_thread_duration');

		// return value or default
		if (is_numeric($interval)) {
			return intval($interval);
		} else {
			return intval(self::_SYSTEM['harmonization_thread_duration']);
		}

	}

	/**
	 * Sets harmonization thread pause interval
	 *
	 * @param string $interval harmonization thread pause interval in seconds
	 *
	 * @return void
	 * @since Release 1.0.0
	 *
	 */
	public function setHarmonizationThreadDuration(int $interval): void {

		// set value
		$this->depositSystemValue('harmonization_thread_duration', $interval);

	}

	/**
	 * Gets harmonization thread pause interval
	 *
	 * @return string harmonization thread pause interval (default 5 seconds)
	 * @since Release 1.0.0
	 *
	 */
	public function getHarmonizationThreadPause(): int {

		// retrieve value
		$interval = $this->retrieveSystemValue('harmonization_thread_pause');

		// return value or default
		if (is_numeric($interval)) {
			return intval($interval);
		} else {
			return intval(self::_SYSTEM['harmonization_thread_pause']);
		}

	}

	/**
	 * Sets harmonization thread pause interval
	 *
	 * @param string $interval harmonization thread pause interval in seconds
	 *
	 * @return void
	 * @since Release 1.0.0
	 *
	 */
	public function setHarmonizationThreadPause(int $interval): void {

		// set value
		$this->depositSystemValue('harmonization_thread_pause', $interval);

	}

	/**
	 * Gets harmonization thread id
	 *
	 * @param string $uid nextcloud user id
	 *
	 * @return string|null thread id if exists | null if does not exist
	 * @since Release 1.0.0
	 *
	 */
	public function getHarmonizationThreadId(string $uid): int {

		// retrieve thread id
		$tid = $this->retrieveUserValue($uid, 'account_harmonization_tid');
		// return thread id
		if (is_numeric($tid)) {
			return intval($tid);
		} else {
			return 0;
		}

	}

	/**
	 * Sets harmonization thread id
	 *
	 * @param string $uid nextcloud user id
	 * @param string $tid thread id
	 *
	 * @return void
	 * @since Release 1.0.0
	 *
	 */
	public function setHarmonizationThreadId(string $uid, int $tid): void {

		// update harmonization thread id
		$this->depositUserValue($uid, 'account_harmonization_tid', (string)$tid);

	}

	/**
	 * Gets harmonization thread heart beat
	 *
	 * @param string $uid nextcloud user id
	 *
	 * @return int thread heart beat time stamp if exists | null if does not exist
	 * @since Release 1.0.0
	 *
	 */
	public function getHarmonizationThreadHeartBeat(string $uid): int {

		// retrieve thread heart beat
		$thb = $this->retrieveUserValue($uid, 'account_harmonization_thb');
		// return thread heart beat
		if (is_numeric($thb)) {
			return (int)$thb;
		} else {
			return 0;
		}

	}

	/**
	 * Sets harmonization thread heart beat
	 *
	 * @param string $uid nextcloud user id
	 * @param int $thb thread heart beat time stamp
	 *
	 * @return void
	 * @since Release 1.0.0
	 *
	 */
	public function setHarmonizationThreadHeartBeat(string $uid, int $thb): void {

		// update harmonization thread id
		$this->depositUserValue($uid, 'account_harmonization_thb', $thb);

	}

	/**
	 * retrieve contacts app status
	 *
	 * @return bool
	 * @since Release 1.0.0
	 *
	 */
	public function isMailAppAvailable(string $uid): bool {

		$user = $this->_usermanager->get($uid);
		return $this->_appmanager->isEnabledForUser('mail', $user);

	}

	/**
	 * retrieve contacts app status
	 *
	 * @return bool
	 * @since Release 1.0.0
	 *
	 */
	public function isContactsAppAvailable(string $uid): bool {

		$user = $this->_usermanager->get($uid);
		return $this->_appmanager->isEnabledForUser('contacts', $user);

	}

	/**
	 * retrieve calendar app status
	 *
	 * @return bool
	 * @since Release 1.0.0
	 *
	 */
	public function isCalendarAppAvailable(string $uid): bool {

		$user = $this->_usermanager->get($uid);
		return $this->_appmanager->isEnabledForUser('calendar', $user);

	}

	/**
	 * retrieve task app status
	 *
	 * @return bool
	 * @since Release 1.0.0
	 *
	 */
	public function isTasksAppAvailable(string $uid): bool {

		$user = $this->_usermanager->get($uid);
		return $this->_appmanager->isEnabledForUser('tasks', $user);

	}

	/**
	 * retrieve account status
	 *
	 * @return bool
	 * @since Release 1.0.0
	 *
	 */
	public function isAccountConnected(string $uid): bool {

		// retrieve account status
		return filter_var($this->retrieveUserValue($uid, 'account_connected'), FILTER_VALIDATE_BOOLEAN);

	}

	/**
	 * encrypt string
	 *
	 * @return string
	 * @since Release 1.0.0
	 *
	 */
	public function encrypt(string $value): string {

		return $this->_crypto->encrypt($value);

	}

	/**
	 * decrypt string
	 *
	 * @return string
	 * @since Release 1.0.0
	 *
	 */
	public function decrypt(string $value): string {

		return $this->_crypto->decrypt($value);

	}
}
