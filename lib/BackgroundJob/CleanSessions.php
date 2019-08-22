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

namespace OCA\DocumentServer\BackgroundJob;

use OCA\DocumentServer\Channel\SessionManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\Job;

class CleanSessions extends Job {
	/** @var SessionManager */
	private $sessionManager;

	public function __construct(ITimeFactory $time, SessionManager $sessionManager) {
		parent::__construct($time);
		$this->sessionManager = $sessionManager;
	}

	protected function run($argument) {
		$this->sessionManager->cleanSessions();
	}
}
