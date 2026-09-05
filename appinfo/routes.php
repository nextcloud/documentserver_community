<?php

return [
	'routes' => [
		// Engine.IO / socket.io 4.x long-polling
		// Socket.IO client always appends a trailing slash to its path, so both variants are needed.
		['name' => 'Document#socketIOPoll', 'url' => '/3rdparty/onlyoffice/documentserver/doc/{documentId}/c', 'verb' => 'GET'],
		['name' => 'Document#socketIOPoll', 'url' => '/3rdparty/onlyoffice/documentserver/doc/{documentId}/c/', 'verb' => 'GET'],
		['name' => 'Document#socketIOMessage', 'url' => '/3rdparty/onlyoffice/documentserver/doc/{documentId}/c', 'verb' => 'POST'],
		['name' => 'Document#socketIOMessage', 'url' => '/3rdparty/onlyoffice/documentserver/doc/{documentId}/c/', 'verb' => 'POST'],
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

		// Euro-Office's editor HTML pulls in sdkjs with a relative path written
		// for a document server that owns the origin root
		// ("../../../../../../sdkjs/common/device_scale.js"). Ours sits three
		// directories deeper, so the browser runs out of parents and asks for
		// /3rdparty/sdkjs/... instead. Keep this narrow: a catch-all
		// /3rdparty/{path} is matched ahead of the specific route above and
		// takes the whole static tree down with it.
		['name' => 'Static#sdkjsRoot', 'url' => '/3rdparty/sdkjs/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+']],
	]
];
