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
 * The session id is published here by the editor frame (close-beacon.js), and
 * the frame it came from is remembered while it is still attached: "the editor
 * is gone" then means that frame having left the document, rather than no frame
 * currently answering to the session id, which is also what a frame that is
 * merely reloading looks like. The server disposes of a document on the
 * strength of this message, so it must not be sent for an editor that is still
 * there.
 */
(function () {
	'use strict';

	// the id we have already said goodbye for, rather than a flag: a page can
	// close one document and open another without ever reloading
	var goodbyeSent = null;
	var editorFrame = null;
	var editorSessionId = null;

	function sessionId() {
		return window.__documentServerSessionId;
	}

	function send(id) {
		if (!id || goodbyeSent === id || !window.__documentServerCloseUrl ||
			!navigator.sendBeacon) {
			return;
		}
		goodbyeSent = id;
		try {
			navigator.sendBeacon(
				window.__documentServerCloseUrl + '?sid=' + encodeURIComponent(id)
			);
		} catch (e) {
			/* nothing to fall back to */
		}
	}

	/** The frame that published the session id, while it is still attached. */
	function findEditorFrame() {
		var id = sessionId();
		if (!id) {
			return null;
		}
		var frames = document.getElementsByTagName('iframe');
		for (var i = 0; i < frames.length; i++) {
			try {
				if (frames[i].contentWindow &&
					frames[i].contentWindow.__documentServerSessionId === id) {
					return frames[i];
				}
			} catch (e) {
				/* a frame we may not look into is not ours */
			}
		}
		return null;
	}

	function check() {
		var found = findEditorFrame();
		if (found) {
			editorFrame = found;
			editorSessionId = sessionId();
			return;
		}
		// Only once the frame we were watching has actually left the document.
		// A frame that is still attached but not answering is loading, or
		// reloading, and will publish its id again.
		if (editorFrame && !document.contains(editorFrame)) {
			send(editorSessionId);
			editorFrame = null;
			editorSessionId = null;
		}
	}

	setInterval(check, 2000);

	window.addEventListener('pagehide', function (event) {
		// a page going into the back/forward cache can come back, with its
		// socket, so it has not left
		if (!event.persisted) {
			send(sessionId());
		}
	});
	window.addEventListener('unload', function () {
		send(sessionId());
	});
})();
