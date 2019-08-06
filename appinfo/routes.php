<?php

return [
	'routes' => [
		['name' => 'Document#info', 'url' => '/doc/{documentId}/c/info', 'verb' => 'GET'],
		['name' => 'Document#xhr', 'url' => '/doc/{documentId}/c/{serverId}/{sessionId}/xhr', 'verb' => 'POST'],
		['name' => 'Document#xhrSend', 'url' => '/doc/{documentId}/c/{serverId}/{sessionId}/xhr_send', 'verb' => 'POST'],
		['name' => 'Document#healthCheck', 'url' => '/healthcheck', 'verb' => 'GET'],
		['name' => 'Document#openDocument', 'url' => '/open/{docId}/{format}/{url}', 'verb' => 'GET'],

		['name' => 'Spellcheck#info', 'url' => '/spellchecker/doc/{documentId}/c/info', 'verb' => 'GET'],
		['name' => 'Spellcheck#xhr', 'url' => '/spellchecker/doc/{documentId}/c/{serverId}/{sessionId}/xhr', 'verb' => 'POST'],
		['name' => 'Spellcheck#xhrSend', 'url' => '/spellchecker/doc/{documentId}/c/{serverId}/{sessionId}/xhr_send', 'verb' => 'POST'],

		['name' => 'CoAuthoring#command', 'url' => '/coauthoring/CommandService.ashx', 'verb' => 'POST'],

		['name' => 'Convert#convert', 'url' => '/ConvertService.ashx', 'verb' => 'POST'],

		['name' => 'Static#webApps', 'url' => '/{version}/web-apps/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+']],
		['name' => 'Static#webApps', 'url' => '/web-apps/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+']],
		['name' => 'Static#sdkJS', 'url' => '/{version}/sdkjs/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+']],
		['name' => 'Static#sdkJS', 'url' => '/sdkjs/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+']],
		['name' => 'Static#font', 'url' => '/fonts/{fontId}', 'verb' => 'GET'],
		['name' => 'Static#pluginsJSON', 'url' => '/plugins.json', 'verb' => 'GET'],
	]
];
