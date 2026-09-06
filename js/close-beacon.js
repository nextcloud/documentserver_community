/**
 * The editor page's half of telling the server when the editor goes away.
 *
 * A real document server holds a WebSocket, so a browser leaving the page is a
 * closed socket and the document gets written straight away. This one is driven
 * by HTTP long polling: when the editor is closed the pending poll is simply
 * dropped, and nothing on the server runs to notice. Until the Cleanup
 * background job came round the document was not written at all, which is how
 * closing a document could lose everything typed into it (#100).
 *
 * All this side does is know which session it is. The session id is the one in
 * the polling urls, picked up as they go past rather than read out of sdkjs
 * internals, and it is published to the page that hosts the editor, because
 * that is the one that can still make a request when this frame is being taken
 * out of the DOM (see close-beacon-host.js).
 */
(function () {
	'use strict';

	var sessionId = null;

	function publish(id) {
		sessionId = id;
		window.__documentServerSessionId = id;
		try {
			// same origin whenever this server is the one serving the editor,
			// which is the only case where the host page can help
			window.parent.__documentServerSessionId = id;
			window.parent.__documentServerCloseUrl = window.__documentServerCloseUrl;
		} catch (e) {
			/* another origin: the host page cannot see us, and we are on our own */
		}
	}

	function noteUrl(url) {
		if (typeof url !== 'string' || url.indexOf('EIO=') === -1) {
			return;
		}
		var match = /[?&]sid=([^&#]+)/.exec(url);
		if (match) {
			publish(decodeURIComponent(match[1]));
		}
	}

	var open = XMLHttpRequest.prototype.open;
	XMLHttpRequest.prototype.open = function (method, url) {
		try {
			noteUrl(url);
		} catch (e) {
			/* never break the request over this */
		}
		return open.apply(this, arguments);
	};

	// A tab being closed while the editor is in it: this frame is unloading
	// along with its parent, and a beacon from an unloading document is still
	// sent. A frame taken out of the DOM is a different matter - the load is
	// cancelled with the document - which is what the host page covers.
	function sayGoodbye(event) {
		if (event && event.persisted) {
			// going into the back/forward cache, not leaving: the page can come
			// back, and saying goodbye for it would end a session still editing
			return;
		}
		if (!sessionId || !navigator.sendBeacon) {
			return;
		}
		try {
			navigator.sendBeacon(
				window.__documentServerCloseUrl + '?sid=' + encodeURIComponent(sessionId)
			);
		} catch (e) {
			/* the page is going away; there is nothing to fall back to */
		}
	}

	window.addEventListener('pagehide', sayGoodbye);
	window.addEventListener('unload', sayGoodbye);
})();
