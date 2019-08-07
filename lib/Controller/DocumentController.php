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

use OCA\DocumentServer\Command\AuthCommand;
use OCA\DocumentServer\Command\IsSaveLock;
use OCA\DocumentServer\Command\SaveChangesCommand;
use OCA\DocumentServer\Document\DocumentStore;
use OCA\DocumentServer\Channel\ChannelFactory;
use OCP\AppFramework\Http\StreamResponse;
use OCP\IRequest;
use OCP\Security\ISecureRandom;
use function Sabre\HTTP\decodePathSegment;

class DocumentController extends SessionController {
	const INITIAL_RESPONSES = [
		'type' => 'license',
		'license' => [
			'type' => 3,
			'light' => false,
			'mode' => 0,
			'rights' => 1,
			'buildVersion' => '5.3.2',
			'buildNumber' => 20,
			'branding' => false,
			'customization' => false,
			'plugins' => false,
		],
	];

	const COMMAND_HANDLERS = [
		AuthCommand::class,
		IsSaveLock::class,
		SaveChangesCommand::class,
	];

	/** @var DocumentStore */
	private $documentStore;

	public function __construct(
		$appName,
		IRequest $request,
		ChannelFactory $sessionFactory,
		DocumentStore $documentStore,
		ISecureRandom $random
	) {
		parent::__construct($appName, $request, $sessionFactory, $random);

		$this->documentStore = $documentStore;
	}


	protected function getInitialResponses(): array {
		return self::INITIAL_RESPONSES;
	}

	protected function getCommandHandlerClasses(): array {
		return self::COMMAND_HANDLERS;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 */
	public function healthCheck() {
		return true;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function openDocument(int $docId, string $format, string $url) {
		$url = decodePathSegment($url);

		$file = $this->documentStore->getDocumentForEditor($docId, $url, $format);

		return new StreamResponse($file->read());
	}
}
