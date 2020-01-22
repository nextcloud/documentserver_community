<?php declare(strict_types=1);
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

namespace OCA\DocumentServer\Command;

use OC\Core\Command\Base;
use OCA\DocumentServer\Document\FontManager;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Fonts extends Base {
	private $fontManager;

	public function __construct(
		FontManager $fontManager
	) {
		parent::__construct();

		$this->fontManager = $fontManager;
	}

	protected function configure() {
		$this
			->setName('documentserver:fonts')
			->addOption('add', 'a', InputOption::VALUE_REQUIRED, 'Add a font from local file')
			->addOption('remove', 'r', InputOption::VALUE_REQUIRED, 'Remove a font by name')
			->setDescription('Manage custom fonts');
		parent::configure();
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		if ($add = $input->getOption('add')) {
			try {
				$this->fontManager->addFont($add);
				$this->fontManager->rebuildFonts();
			} catch (\Exception $e) {
				$error = $e->getMessage();
				$output->writeln("<error>$error</error>");
			}
		} else if ($remove = $input->getOption('remove')) {
			$this->fontManager->removeFont($remove);
			$this->fontManager->rebuildFonts();
		} else {
			$fonts = $this->fontManager->listFonts();
			if ($fonts) {
				foreach ($fonts as $font) {
					$output->writeln($font);
				}
			} else {
				$output->writeln("<info>No fonts added</info>");
			}
		}
	}
}
