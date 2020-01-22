<?php
use OCA\DocumentServer\Document\FontManager;

// re-run font generation post update
/** @var FontManager $fontManager */
$fontManager = \OC::$server->query(FontManager::class);
$fontManager->rebuildFonts();
