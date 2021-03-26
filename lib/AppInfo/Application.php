<?php
/**
 * @copyright Copyright (c) 2019 Robin Appelman <robin@icewind.nl>
 *
 * @license GNU AGPL version 3 or any later version
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

namespace OCA\DocumentServer\AppInfo;

use OCA\DocumentServer\IPC\DatabaseIPCFactory;
use OCA\DocumentServer\IPC\IIPCFactory;
use OCA\DocumentServer\IPC\IPCFactory;
use OCA\DocumentServer\IPC\MemcacheIPCFactory;
use OCA\DocumentServer\IPC\RedisIPCFactory;
use OCA\DocumentServer\JSSettingsHelper;
use OCA\DocumentServer\OnlyOffice\AutoConfig;
use OCA\DocumentServer\OnlyOffice\URLDecoder;
use OCA\Onlyoffice\AppConfig;
use OCA\Onlyoffice\Crypt;
use OCP\App\IAppManager;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\IAppContainer;
use OCP\Files\IRootFolder;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Share\IManager;
use OCP\Util;

class Application extends App implements IBootstrap {
	public function __construct(array $urlParams = []) {
		parent::__construct('documentserver_community', $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerService(IIPCFactory::class, function (IAppContainer $c) {
			$factory = new IPCFactory();
			$factory->registerBackend($c->get(DatabaseIPCFactory::class));
			$factory->registerBackend($c->get(MemcacheIPCFactory::class));
			$factory->registerBackend($c->get(RedisIPCFactory::class));

			return $factory;
		});

		$context->registerService(URLDecoder::class, function (IAppContainer $container) {
			$server = $container->getServer();
			$appConfig = new AppConfig('onlyoffice');
			$crypto = new Crypt($appConfig);

			return new URLDecoder(
				$crypto,
				$server->get(IUserSession::class),
				$server->get(IManager::class),
				$server->get(IRootFolder::class)
			);
		});

		$context->registerService(AutoConfig::class, function (IAppContainer $container) {
			$server = $container->getServer();
			$appConfig = new AppConfig('onlyoffice');

			return new AutoConfig(
				$server->get(IURLGenerator::class),
				$appConfig
			);
		});
	}

	public function boot(IBootContext $context): void {
		$context->injectFn(function (IAppManager $appManager) {
			if ($appManager->isEnabledForUser('onlyoffice')) {
				$this->getAutoConfig()->autoConfigIfNeeded();
				Util::connectHook('\OCP\Config', 'js', $this->getJSSettingsHelper(), 'extend');
			}
		});
	}

	private function getJSSettingsHelper(): JSSettingsHelper {
		return $this->getContainer()->get(JSSettingsHelper::class);
	}

	private function getAutoConfig(): AutoConfig {
		return $this->getContainer()->get(AutoConfig::class);
	}
}
