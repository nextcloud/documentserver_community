/**
 * The host page's half of telling the server when the editor goes away.
 *
 * Appended to the api.js the integration loads, so it runs in the page that
 * embeds the editor rather than in the editor's own frame. That matters for the
 * way an editor is usually closed: DocsAPI's destroyEditor() takes the iframe
 * out of the DOM, and a request started by a document that is being detached is
 * cancelled with it - the frame's own goodbye never leaves the browser. This
 * page is still there, and can send it.
 *
 * The session id is published here by the editor frame (close-beacon.js).
 */
(function () {
	'use strict';

	// the id we have already said goodbye for, rather than a flag: a page can
	// close one document and open another without ever reloading
	var goodbyeSent = null;

	function sessionId() {
		return window.__documentServerSessionId;
	}

	function send() {
		if (!sessionId() || goodbyeSent === sessionId() ||
			!window.__documentServerCloseUrl || !navigator.sendBeacon) {
			return;
		}
		goodbyeSent = sessionId();
		try {
			navigator.sendBeacon(
				window.__documentServerCloseUrl + '?sid=' + encodeURIComponent(sessionId())
			);
		} catch (e) {
			/* nothing to fall back to */
		}
	}

	function editorStillThere() {
		var frames = document.getElementsByTagName('iframe');
		for (var i = 0; i < frames.length; i++) {
			try {
				if (frames[i].contentWindow &&
					frames[i].contentWindow.__documentServerSessionId === sessionId()) {
					return true;
				}
			} catch (e) {
				/* a frame we may not look into is not ours to judge */
			}
		}
		return false;
	}

	window.addEventListener('pagehide', function (event) {
		// a page going into the back/forward cache can come back, with its
		// socket, so it has not left
		if (!event.persisted) {
			send();
		}
	});
	window.addEventListener('unload', send);

	if (typeof MutationObserver === 'function') {
		new MutationObserver(function (records) {
			for (var i = 0; i < records.length; i++) {
				var removed = records[i].removedNodes;
				for (var j = 0; j < removed.length; j++) {
					var node = removed[j];
					if (node.nodeType !== 1) {
						continue;
					}
					if (node.tagName === 'IFRAME' ||
						(node.querySelector && node.querySelector('iframe'))) {
						if (sessionId() && !editorStillThere()) {
							send();
							return;
						}
					}
				}
			}
		}).observe(document.documentElement, {childList: true, subtree: true});
	}
})();
