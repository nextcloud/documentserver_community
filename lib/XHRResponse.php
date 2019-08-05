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

namespace OCA\Documents;

use OC\AppFramework\Http;
use OCP\AppFramework\Http\ICallbackResponse;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\Response;

class XHRResponse extends Response implements ICallbackResponse {

	private $type;
	private $data;

	public function __construct(
		string $type,
		array $data = null,
		int $statusCode = Http::STATUS_OK,
		$headers = []
	) {
		$this->type = $type;
		$this->data = $data;
		$this->setStatus($statusCode);
		$this->setHeaders(array_merge($this->getHeaders(), $headers));

		$this->addHeader('Content-Type', 'application/javascript; charset=UTF-8');
	}

	public function callback(IOutput $output) {
		if ($output->getHttpResponseCode() !== Http::STATUS_NOT_MODIFIED) {


			if ($this->data) {
				$encodedData = json_encode($this->data);
				$escapedData = \GuzzleHttp\json_encode($encodedData);
				$body = $this->type . '[' . $escapedData . ']';
			} else {
				$body = $this->type;
			}
			$body .= "\n";

			$this->addHeader('Content-Length', strlen($body));

			print $body;
		}
	}
}
