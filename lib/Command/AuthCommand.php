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

namespace OCA\Documents\Command;

use OCP\IPC\IIPCChannel;
use OCP\IURLGenerator;
use function Sabre\HTTP\encodePathSegment;

class AuthCommand implements ICommandHandler {
	private $urlGenerator;

	public function __construct(IURLGenerator $urlGenerator) {
		$this->urlGenerator = $urlGenerator;
	}


	public function getType(): string {
		return 'auth';
	}

	public function handle(array $command, IIPCChannel $channel, CommandDispatcher $commandDispatcher): void {
		$channel->pushMessage(json_encode([
			'type' => 'authChange',
			'changes' => [],
		]));

		$user = $command['user'];

		$channel->pushMessage(json_encode([
			'type' => 'auth',
			'result' => 1,
			'sessionId' => 'foo',
			'sessionTimeConnect' => time(),
			'participants' => [
				'id' => $user['id'],
				'idOriginal' => $user['id'],
				'username' => $user['username'],
				'indexUser' => 1,
				'view' => true,
				'connectionId' => 'foo',
				'isCloseCoAuthoring' => false,
			],
			'locks' => [],
			'indexUser' => 1,
			'g_cAscSpellCheckUrl' => '/spellchecker',
			'buildVersion' => '5.0.0',
			'buildNumber' => 20,
			'licenseType' => 3,
			'settings' => [
				'spellcheckerUrl' => '/spellchecker',
				'reconnection' => [
					'attempts' => 50,
					'delay' => 2000,
				],
			],
		]));


		$openCmd = $command['openCmd'];
		$documentUrl = $openCmd['url'];
		$inputFormat = $openCmd['format'];

		$channel->pushMessage(json_encode([
			'type' => 'documentOpen',
			'data' => [
				'type' => 'open',
				'status' => 'ok',
				'data' => [
					// TODO
					'Editor.bin' => $this->urlGenerator->linkToRouteAbsolute(
						'documents.Document.openDocument', [
							'format' => $inputFormat,
							'url' => encodePathSegment($documentUrl)
						]
					)
				]
			]
		]));
	}
}
