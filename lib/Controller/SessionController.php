<?php declare(strict_types=1);
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

namespace OCA\DocumentServer\Controller;

use OCA\DocumentServer\XHRCommand\CommandDispatcher;
use OCA\DocumentServer\Channel\ChannelFactory;
use OCA\DocumentServer\XHRResponse;
use OCP\AppFramework\Controller;
use OCP\IRequest;
use OCP\Security\ISecureRandom;

abstract class SessionController extends Controller {
	private $sessionFactory;
	private $random;

	public function __construct(
		$appName,
		IRequest $request,
		ChannelFactory $sessionFactory,
		ISecureRandom $random
	) {
		parent::__construct($appName, $request);

		$this->sessionFactory = $sessionFactory;
		$this->random = $random;
	}

	abstract protected function getInitialResponses(): array;

	abstract protected function getCommandHandlerClasses(): array;

	protected function getIdleHandlerClasses(): array {
		return [];
	}

	protected function getCommandHandlers(): array {
		return array_map(function (string $class) {
			return \OC::$server->query($class);
		}, $this->getCommandHandlerClasses());
	}

	protected function getIdleHandlers(): array {
		return array_map(function (string $class) {
			return \OC::$server->query($class);
		}, $this->getIdleHandlerClasses());
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 */
	public function info(?string $version, string $documentId) {
		return [
			'websocket' => false,
			'origins' => ['*:*'],
			'cookie_needed' => false,
			'entropy' => (int)$this->random->generate(10, ISecureRandom::CHAR_DIGITS),
		];
	}

	private function getCommandDispatcher() {
		$dispatcher = new CommandDispatcher();
		foreach ($this->getCommandHandlers() as $commandHandler) {
			$dispatcher->addHandler($commandHandler);
		}
		foreach ($this->getIdleHandlers() as $idleHandler) {
			$dispatcher->addIdleHandler($idleHandler);
		}
		return $dispatcher;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 */
	public function xhr(?string $version, string $documentId, string $serverId, string $sessionId) {
		$session = $this->sessionFactory->getSession($sessionId, $documentId, $this->getCommandDispatcher(), $this->getInitialResponses());
		list($type, $data) = $session->getResponse($sessionId);

		return new XHRResponse($type, $data);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 */
	public function xhrSend(?string $version, string $documentId, string $serverId, string $sessionId) {
		$commands = json_decode(file_get_contents('php://input'));
		$session = $this->sessionFactory->getSession($sessionId, $documentId, $this->getCommandDispatcher());
		foreach ($commands as $encodedCommand) {
			$command = json_decode($encodedCommand, true);
			$session->handleCommand($command, (int)$documentId, $sessionId);
		}
	}
}
