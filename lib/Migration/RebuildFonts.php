<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2020 Robin Appelman <robin@icewind.nl>
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

namespace OCA\DocumentServer\Migration;

use OCA\DocumentServer\Document\FontManager;
use Psr\Log\LoggerInterface;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

class RebuildFonts implements IRepairStep {
	private $fontManager;
	private $logger;

	public function __construct(FontManager $fontManager, LoggerInterface $logger) {
		$this->fontManager = $fontManager;
		$this->logger = $logger;
	}

	/**
	 * @inheritdoc
	 */
	public function getName() {
		return 'Rebuild font library';
	}

	/**
	 * @inheritdoc
	 */
	public function run(IOutput $output) {
		try {
			$this->fontManager->rebuildFonts();
		} catch (\Exception $e) {
			$this->logger->warning('An exception occurred trying to rebuild fonts', ['exception' => $e]);
			$output->warning("Error while trying to rebuild fonts, if you had any custom fonts configured you'll need to run `occ documentserver:fonts --rebuild`");
		}
	}
}
