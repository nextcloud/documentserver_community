<?php

declare(strict_types=1);
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

use OCA\DocumentServer\Document\DocumentConversionException;
use OCA\DocumentServer\Document\DocumentStore;
use OCA\DocumentServer\Document\PasswordRequiredException;
use OCA\DocumentServer\OnlyOffice\URLDecoder;
use OCA\DocumentServer\JSONResponse;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use OCP\IURLGenerator;

class ConvertController extends Controller {
	private $documentStore;
	private $urlDecoder;
	private $urlGenerator;

	public function __construct(
		string $appName,
		IRequest $request,
		DocumentStore $documentStore,
		URLDecoder $urlDecoder,
		IURLGenerator $urlGenerator
	) {
		parent::__construct($appName, $request);
		$this->documentStore = $documentStore;
		$this->urlDecoder = $urlDecoder;
		$this->urlGenerator = $urlGenerator;
	}


    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
	public function convert(bool $async, string $url, string $outputtype, string $filetype, string $title, string $key) {
		if ($outputtype === $filetype) {
			return new JSONResponse([
				'fileUrl' => $url,
				'percent' => 100,
				'endConvert' => true,
			]);
		} else {
			$documentId = (int)$key;
			$documentFile = $this->urlDecoder->getFileForUrl($url);

			// A failed conversion must come back as a structured {"error": <code>}
			// body, not as an uncaught exception: this is a plain HTTP endpoint
			// with no other handler, so without this catch the converter's
			// exception escapes as a 500 the caller can't interpret. This endpoint
			// answers the connector over the server-side conversion API, whose
			// error codes differ from the editor's c_oAscError space used in the
			// socket replies: -5 incorrect password, -3 conversion error.
			try {
				$this->documentStore->getDocumentForEditor($documentId, $documentFile, $filetype);
				$this->documentStore->convert($documentId, $outputtype);
			} catch (PasswordRequiredException $e) {
				return new JSONResponse(['error' => -5]);
			} catch (DocumentConversionException $e) {
				return new JSONResponse(['error' => -3]);
			}

			$url = $this->urlGenerator->linkToRouteAbsolute(
				'documentserver_community.Document.documentFile', [
					'path' => 'convert.' . $outputtype,
					'docId' => $documentId,
				]
			);

			return new JSONResponse([
				'fileUrl' => $url,
				'percent' => 100,
				'endConvert' => true,
			]);
		}
	}
}
