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

use OC\ForbiddenException;
use OC\Security\CSP\ContentSecurityPolicy;
use OC\Security\CSP\ContentSecurityPolicyNonceManager;
use OCA\DocumentServer\FileResponse;
use OCP\AppFramework\Controller;
use OCP\Files\IMimeTypeDetector;
use OCP\Files\NotFoundException;

class StaticController extends Controller {
	private $mimeTypeHelper;
	private $nonceManager;

	public function __construct(
		IMimeTypeDetector $mimeTypeHelper,
		ContentSecurityPolicyNonceManager $nonceManager
	) {
		$this->mimeTypeHelper = $mimeTypeHelper;
		$this->nonceManager = $nonceManager;
	}


	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 */
	public function webApps(?string $version, string $path) {
		if (strpos($path, '..') !== false) {
			throw new ForbiddenException();
		}

		$localPath = __DIR__ . '/../../3rdparty/onlyoffice/documentserver/web-apps/' . $path;

		return $this->createFileResponse($localPath);
	}

	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 */
	public function sdkJS(?string $version, string $path) {
		if (strpos($path, '..') !== false) {
			throw new ForbiddenException();
		}

		$localPath = __DIR__ . '/../../3rdparty/onlyoffice/documentserver/sdkjs/' . $path;

		return $this->createFileResponse($localPath);
	}

	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 */
	public function font(?string $version, string $fontId) {
		if (strpos($fontId, '..') !== false) {
			throw new ForbiddenException();
		}

		$localPath = __DIR__ . '/../../3rdparty/onlyoffice/documentserver/fonts/' . $fontId;

		return $this->createFileResponse($localPath);
	}

	private function createFileResponse($path) {

		if (!file_exists($path)) {
			throw new NotFoundException();
		}
		$content = file_get_contents($path);
		$isHTML = pathinfo($path, PATHINFO_EXTENSION) === 'html';
		if ($isHTML) {
			$content = $this->addScriptNonce($content, $this->nonceManager->getNonce());
		}

		$mime = $this->mimeTypeHelper->detectPath($path);
		if (pathinfo($path, PATHINFO_EXTENSION) === 'wasm') {
			$mime = 'application/wasm';
		}

		$response = new FileResponse(
			$content,
			strlen($content),
			filemtime($path),
			$mime
		);

		// we can't cache the html since the nonce might need to get updated
		if (!$isHTML) {
			$response->cacheFor(3600);
		}

		$csp = new ContentSecurityPolicy();
		$csp->addAllowedScriptDomain('\'strict-dynamic\'');
		$csp->addAllowedScriptDomain('\'unsafe-eval\'');
		$response->setContentSecurityPolicy($csp);

		return $response;
	}

	private function addScriptNonce(string $content, string $nonce): string {
		return str_replace('<script', "<script nonce=\"$nonce\"", $content);
	}

	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 */
	public function pluginsJSON() {
		return "asdasd";
	}
}

