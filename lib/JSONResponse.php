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

namespace OCA\DocumentServer;

use OC\AppFramework\Http;
use OCP\AppFramework\Http\Response;


class JSONResponse extends Response {

    /**
     * response data
     *
     * @var array|object
     */
    protected $data;

    public function __construct($data = [], $statusCode = Http::STATUS_OK) {
        parent::__construct();

        $this->data = $data;
        $this->setStatus($statusCode);
        $this->addHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public function render() {
        // Convert the data to JSON format and return it
        $data = array_flip($this->data);
        return json_encode($this->data);
    }

    public function setData($data) {
        $this->data = $data;

        return $this;
    }

    public function getData() {
        return $this->data;
    }
}
