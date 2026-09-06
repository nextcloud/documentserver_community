<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2026 Fabrice Meyer <meyer.fabrice@gmx.fr>
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

namespace OCA\DocumentServer\Tests\Document;

use OCA\DocumentServer\Channel\SessionManager;
use OCA\DocumentServer\Document\Change;
use OCA\DocumentServer\Document\ChangeStore;
use OCA\DocumentServer\Document\DocumentStore;
use OCA\DocumentServer\Document\SaveHandler;
use OCA\DocumentServer\DocumentConverter;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/**
 * When the document gets written, and - the part that cost a lot of documents -
 * when its change list may be thrown away.
 *
 * The change list is the whole record of what was typed: Editor.bin stays at the
 * version the document was opened at, and every write replays the list against
 * it. So consuming it is only ever safe once nobody is left in the document, and
 * these are the decisions that make sure of that.
 */
class SaveHandlerTest extends TestCase {
	private const DOCUMENT = 42;

	/** @var DocumentStore|MockObject */
	private $documentStore;
	/** @var ChangeStore|MockObject */
	private $changeStore;
	/** @var SessionManager|MockObject */
	private $sessionManager;
	/** @var IConfig|MockObject */
	private $config;
	private $now = 1000000;
	/** @var SaveHandler */
	private $saveHandler;

	protected function setUp(): void {
		parent::setUp();

		$this->documentStore = $this->createMock(DocumentStore::class);
		$this->changeStore = $this->createMock(ChangeStore::class);
		$this->sessionManager = $this->createMock(SessionManager::class);
		$this->config = $this->createMock(IConfig::class);

		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturnCallback(function () {
			return $this->now;
		});

		$this->config->method('getAppValue')
			->willReturnCallback(function (string $app, string $key, string $default) {
				return $default;
			});

		$this->saveHandler = new SaveHandler(
			$this->documentStore,
			$this->changeStore,
			$this->createMock(DocumentConverter::class),
			$this->createMock(ILockingProvider::class),
			$this->sessionManager,
			$this->config,
			$timeFactory
		);
	}

	private function change(): Change {
		return new Change(self::DOCUMENT, $this->now, 'a change', 'user', 'user', 0);
	}

	private function snapshotState(int $index, int $time): array {
		return ['index' => $index, 'time' => $time];
	}

	public function testFlushOfADocumentNobodyIsInConsumesItAndClosesIt() {
		$this->sessionManager->method('isDocumentActive')->willReturn(false);
		$this->changeStore->method('getMaxChangeIndexForDocument')->willReturn(3);
		$this->changeStore->method('getChangesAndMarkProcessingForDocument')
			->willReturn([$this->change()]);
		$this->documentStore->method('getSnapshotState')->willReturn($this->snapshotState(-1, 0));

		$this->documentStore->expects($this->once())->method('saveChanges');
		$this->changeStore->expects($this->once())->method('deleteProcessedChanges');
		$this->documentStore->expects($this->once())->method('closeDocument');

		$this->saveHandler->flushChanges(self::DOCUMENT);
	}

	public function testFlushOfADocumentSomebodyIsEditingWritesItWithoutConsumingIt() {
		$this->sessionManager->method('isDocumentActive')->willReturn(true);
		$this->changeStore->method('getMaxChangeIndexForDocument')->willReturn(3);
		$this->changeStore->method('getChangesForDocument')->willReturn([$this->change()]);
		$this->documentStore->method('getSnapshotState')->willReturn($this->snapshotState(-1, 0));

		$this->documentStore->expects($this->once())->method('saveChanges');
		$this->changeStore->expects($this->never())->method('getChangesAndMarkProcessingForDocument');
		$this->changeStore->expects($this->never())->method('deleteProcessedChanges');
		$this->documentStore->expects($this->never())->method('closeDocument');

		$this->saveHandler->flushChanges(self::DOCUMENT);
	}

	public function testASessionArrivingWhileTheDocumentIsAssembledKeepsItsChanges() {
		// nobody is in the document when the flush starts, somebody is by the
		// time the converter has finished
		$this->sessionManager->method('isDocumentActive')
			->willReturnOnConsecutiveCalls(false, true);
		$this->changeStore->method('getMaxChangeIndexForDocument')->willReturn(3);
		$this->changeStore->method('getChangesAndMarkProcessingForDocument')
			->willReturn([$this->change()]);
		$this->documentStore->method('getSnapshotState')->willReturn($this->snapshotState(-1, 0));

		$this->documentStore->expects($this->once())->method('saveChanges');
		$this->changeStore->expects($this->once())->method('unmarkProcessing');
		$this->changeStore->expects($this->never())->method('deleteProcessedChanges');
		$this->documentStore->expects($this->never())->method('closeDocument');

		$this->saveHandler->flushChanges(self::DOCUMENT);
	}

	public function testASnapshotOfADocumentNobodyHasTypedIntoDoesNotRunTheConverter() {
		$this->changeStore->method('getMaxChangeIndexForDocument')->willReturn(7);
		$this->documentStore->method('getSnapshotState')
			->willReturn($this->snapshotState(7, $this->now - 3600));

		$this->documentStore->expects($this->never())->method('saveChanges');

		$this->assertFalse($this->saveHandler->saveSnapshot(self::DOCUMENT));
	}

	public function testASnapshotWritesTheDocumentAndLeavesTheChangeListAlone() {
		$this->changeStore->method('getMaxChangeIndexForDocument')->willReturn(9);
		$this->changeStore->method('getChangesForDocument')->willReturn([$this->change()]);
		$this->documentStore->method('getSnapshotState')
			->willReturn($this->snapshotState(7, $this->now - 3600));

		$this->documentStore->expects($this->once())->method('saveChanges');
		$this->documentStore->expects($this->exactly(2))->method('setSnapshotState');
		$this->changeStore->expects($this->never())->method('deleteProcessedChanges');
		$this->documentStore->expects($this->never())->method('closeDocument');

		$this->assertTrue($this->saveHandler->saveSnapshot(self::DOCUMENT));
	}

	public function testTheDocumentIsNotWrittenAgainInsideTheAutosaveInterval() {
		$this->documentStore->method('getSnapshotState')->willReturn($this->snapshotState(
			1, $this->now - (SaveHandler::DEFAULT_AUTOSAVE_INTERVAL - 5)));

		$this->documentStore->expects($this->never())->method('saveChanges');

		$this->assertFalse($this->saveHandler->saveSnapshotIfDue(self::DOCUMENT));
	}

	public function testTheDocumentIsWrittenOnceTheAutosaveIntervalHasPassed() {
		$this->documentStore->method('getSnapshotState')->willReturn($this->snapshotState(
			1, $this->now - (SaveHandler::DEFAULT_AUTOSAVE_INTERVAL + 5)));
		$this->changeStore->method('getMaxChangeIndexForDocument')->willReturn(4);
		$this->changeStore->method('getChangesForDocument')->willReturn([$this->change()]);

		$this->documentStore->expects($this->once())->method('saveChanges');

		$this->assertTrue($this->saveHandler->saveSnapshotIfDue(self::DOCUMENT));
	}

	public function testAutosaveCanBeTurnedOff() {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('0');
		$saveHandler = new SaveHandler(
			$this->documentStore,
			$this->changeStore,
			$this->createMock(DocumentConverter::class),
			$this->createMock(ILockingProvider::class),
			$this->sessionManager,
			$config,
			$this->createMock(ITimeFactory::class)
		);

		$this->documentStore->expects($this->never())->method('getSnapshotState');
		$this->documentStore->expects($this->never())->method('saveChanges');

		$this->assertFalse($saveHandler->saveSnapshotIfDue(self::DOCUMENT));
	}
}
