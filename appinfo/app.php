<?php
use OCA\DocumentServer\AppInfo\Application;

(\OC::$server->query(Application::class))->register();
