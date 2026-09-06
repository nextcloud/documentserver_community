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

use OC\ForbiddenException;
use OC\Security\CSP\ContentSecurityPolicy;
use OC\Security\CSP\ContentSecurityPolicyNonceManager;
use OCA\DocumentServer\Channel\SessionManager;
use OCA\DocumentServer\FileResponse;
use OCA\DocumentServer\SetupCheck;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\Files\IMimeTypeDetector;
use OCP\IRequest;
use OCP\IURLGenerator;

class StaticController extends Controller {
	private $mimeTypeHelper;
	private $nonceManager;
	private $setupCheck;
	private $sessionManager;
	private $urlGenerator;

	public function __construct(
		$appName,
		IRequest $request,
		IMimeTypeDetector $mimeTypeHelper,
		ContentSecurityPolicyNonceManager $nonceManager,
		SetupCheck $setupCheck,
		SessionManager $sessionManager,
		IURLGenerator $urlGenerator
	) {
		parent::__construct($appName, $request);

		$this->mimeTypeHelper = $mimeTypeHelper;
		$this->nonceManager = $nonceManager;
		$this->setupCheck = $setupCheck;
		$this->sessionManager = $sessionManager;
		$this->urlGenerator = $urlGenerator;
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[PublicPage]
	public function thirdparty(string $path) {
		if (strpos($path, '..') !== false) {
			throw new ForbiddenException();
		}

		// use english images for all help pages to save space
		$path = preg_replace("|resources/help/\w+/images|", "resources/help/en/images", $path);

		// A couple of files are requested at the documentserver root while the
		// package ships them deeper in the tree; upstream nginx maps them.
		// Without this the editor logs a 404 for each on every document open.
		$rootAliases = [
			'document_editor_service_worker.js' => 'sdkjs/common/serviceworker/document_editor_service_worker.js',
			'themes.json' => 'web-apps/apps/common/main/resources/themes/themes.json',
		];
		if (isset($rootAliases[$path])) {
			$path = $rootAliases[$path];
		}

		$localPath = __DIR__ . '/../../3rdparty/onlyoffice/documentserver/' . $path;

		return $this->createFileResponse($localPath);
	}

	/**
	 * sdkjs, reachable one directory prefix up.
	 *
	 * The editor HTML is written for a document server that owns the origin
	 * root, so its script path for sdkjs walks up past ours - which lives
	 * three directories deeper - and the browser gives up at
	 * /3rdparty/sdkjs/... Serving the same files there costs nothing: they are
	 * already public under the full prefix.
	 */
	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[PublicPage]
	public function sdkjsRoot(string $path) {
		return $this->thirdparty('sdkjs/' . $path);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[PublicPage]
	public function webApps(string $path) {
		if (strpos($path, '..') !== false) {
			throw new ForbiddenException();
		}

		$localPath = __DIR__ . '/../../3rdparty/onlyoffice/documentserver/web-apps/' . $path;

		// onlyoffice will load this js file first
		// we use this as an opportunity to do some checks and present error messages
		// by serving a custom js file instead
		if ($path === 'apps/api/documents/api.js') {
			if (!$this->setupCheck->check()) {
				$hint = $this->setupCheck->getHint();
				$localPath = __DIR__ . '/../../js/binaryerror.js';
				$rawContent = file_get_contents($localPath);
				$content = str_replace('__HINT__', addcslashes($hint, "'"), $rawContent);
				return $this->createFileResponseWithContent($localPath, $content, false);
			}

			// the page that embeds the editor is the one that can still say
			// goodbye when the editor's own frame is being removed
			return $this->createFileResponseWithContent(
				$localPath,
				file_get_contents($localPath) . "\n" . $this->closeBeacon('close-beacon-host.js')
			);
		}

		return $this->createFileResponse($localPath);
	}

	private function createFileResponse($path) {
		if (!file_exists($path)) {
			return new NotFoundResponse();
		}
		$content = file_get_contents($path);
		return $this->createFileResponseWithContent($path, $content);
	}

	private function createFileResponseWithContent(string $path, string $content, $cache = true) {
		$isHTML = pathinfo($path, PATHINFO_EXTENSION) === 'html';
		if ($isHTML) {
			// before the nonce is applied, so the injected script gets one too
			$content = $this->addCloseBeacon($path, $content);
			$content = $this->addScriptNonce($content, $this->nonceManager->getNonce());
			$content = $this->makeStyleSheetsBlocking($content);
		}

		$mime = $this->mimeTypeHelper->detectPath($path);
		if (pathinfo($path, PATHINFO_EXTENSION) === 'wasm') {
			$mime = 'application/wasm';
		}

		$response = new FileResponse(
			$content,
			strlen($content),
			filemtime($path),
			$mime,
			basename($path)
		);

		// we can't cache the html since the nonce might need to get updated
		if ($cache && !$isHTML) {
			$response->cacheFor(3600);
		}

		$csp = new ContentSecurityPolicy();
		$csp->addAllowedScriptDomain($this->request->getServerHost());
		$csp->addAllowedScriptDomain('\'unsafe-eval\'');
		$csp->addAllowedScriptDomain('\'unsafe-inline\'');
		$csp->addAllowedFrameDomain($this->request->getServerHost());
		$response->setContentSecurityPolicy($csp);

		return $response;
	}

	/**
	 * Have the editor page tell us when it goes away.
	 *
	 * A document server that holds a WebSocket learns of a closed editor from
	 * the socket closing. Ours is polled over HTTP, so a closed tab - or
	 * DocsAPI's destroyEditor(), which takes the iframe out of the DOM without
	 * the code inside it getting a word in - leaves a poll that simply never
	 * comes back, with nothing running to notice. The document then waited for
	 * the Cleanup background job to be scheduled before it was written at all
	 * (#100).
	 *
	 * Injected rather than shipped as a file reference so it is covered by the
	 * nonce added below, and only into the editor entry points: the same tree
	 * serves help pages and loaders that never open a socket.
	 */
	private function addCloseBeacon(string $path, string $content): string {
		if (!preg_match('#/apps/\w+editor/main/index[\w.]*\.html$#', str_replace('\\', '/', $path))) {
			return $content;
		}

		$injected = '<script>' . $this->closeBeacon('close-beacon.js') . '</script>';

		$head = stripos($content, '</head>');
		if ($head === false) {
			return $content . $injected;
		}

		return substr($content, 0, $head) . $injected . substr($content, $head);
	}

	/**
	 * One of the goodbye scripts, with the address to send it to.
	 */
	private function closeBeacon(string $file): string {
		$script = @file_get_contents(__DIR__ . '/../../js/' . $file);
		if ($script === false) {
			return '';
		}

		$closeUrl = $this->urlGenerator->linkToRoute('documentserver_community.Document.sessionClosed');

		return 'window.__documentServerCloseUrl = window.__documentServerCloseUrl || '
			. json_encode($closeUrl) . ";\n" . $script;
	}

	private function addScriptNonce(string $content, string $nonce): string {
		return str_replace('<script', "<script nonce=\"$nonce\"", $content);
	}

	/**
	 * Load the editor's stylesheets normally instead of asynchronously.
	 *
	 * Since 8.0 the editor html pulls its CSS in with the "async CSS" trick:
	 *   <link rel="stylesheet" href="..." media="print" onload="this.media='all'">
	 * We serve that html under a CSP carrying a script nonce, and a nonce makes
	 * the browser ignore 'unsafe-inline', so the inline onload never runs, the
	 * sheet stays at media="print" and the editor renders unstyled. Rewriting
	 * these links into plain blocking stylesheets fixes the rendering without
	 * having to drop the nonce from the policy.
	 */
	private function makeStyleSheetsBlocking(string $content): string {
		return preg_replace_callback(
			'/<link\b[^>]*rel=(["\'])stylesheet\1[^>]*>/i',
			function (array $match): string {
				$tag = preg_replace('/\son(?:load|error)=(["\']).*?\1/i', '', $match[0]);
				return preg_replace('/\smedia=(["\'])print\1/i', ' media="all"', $tag);
			},
			$content
		);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[PublicPage]
	public function pluginsJSON() {
		return new DataResponse([]);
	}
}
