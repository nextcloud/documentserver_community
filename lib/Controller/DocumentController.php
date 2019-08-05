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

namespace OCA\Documents\Controller;

use OCA\Documents\Command\AuthCommand;
use OCA\Documents\DocumentConverter;
use OCA\Documents\Session\SessionFactory;
use OCP\AppFramework\Http\StreamResponse;
use OCP\Files\NotFoundException;
use OCP\IRequest;
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
	];

	private $documentConverter;

	public function __construct(
		$appName,
		IRequest $request,
		SessionFactory $sessionFactory,
		DocumentConverter $documentConverter
	) {
		parent::__construct($appName, $request, $sessionFactory);

		$this->documentConverter = $documentConverter;
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
	 */
	public function openDocument(string $format, string $url) {
		$source = fopen(decodePathSegment($url), 'r');

		if (!$source) {
			throw new NotFoundException();
		}

		return new StreamResponse($this->documentConverter->convert($source, $format, 'bin'));
	}
}
