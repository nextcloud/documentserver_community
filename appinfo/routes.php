<?php

return [
	'routes' => [
		['name' => 'Document#info', 'url' => '/3rdparty/onlyoffice/documentserver/doc/{documentId}/c/info', 'verb' => 'GET'],
		['name' => 'Document#xhr', 'url' => '/3rdparty/onlyoffice/documentserver/doc/{documentId}/c/{serverId}/{sessionId}/xhr', 'verb' => 'POST'],
		['name' => 'Document#xhrSend', 'url' => '/3rdparty/onlyoffice/documentserver/doc/{documentId}/c/{serverId}/{sessionId}/xhr_send', 'verb' => 'POST'],
		['name' => 'Document#healthCheck', 'url' => '/healthcheck', 'verb' => 'GET'],
		['name' => 'Document#documentFile', 'url' => '/open/{docId}/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+']],
		['name' => 'Document#upload', 'url' => '/3rdparty/onlyoffice/documentserver/upload/{docId}/{user}/{index}', 'verb' => 'POST'],
		['name' => 'Document#download', 'url' => '/3rdparty/onlyoffice/documentserver/downloadas/{docId}', 'verb' => 'POST'],

		['name' => 'CoAuthoring#command', 'url' => '/coauthoring/CommandService.ashx', 'verb' => 'POST'],

		['name' => 'Convert#convert', 'url' => '/converter', 'verb' => 'POST'],

		['name' => 'Static#webApps', 'url' => '/web-apps/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+']],
		['name' => 'Static#pluginsJSON', 'url' => '/plugins.json', 'verb' => 'GET'],
		['name' => 'Static#pluginsJSON', 'url' => '/3rdparty/onlyoffice/documentserver/plugins.json', 'verb' => 'GET'],
		['name' => 'Static#thirdparty', 'url' => '/3rdparty/onlyoffice/documentserver/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+']],
	]
];
