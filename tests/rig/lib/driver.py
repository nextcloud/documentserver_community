"""Driving the editor: a headless chromium per session, over CDP.

The editor renders to a canvas, so nothing about what a session displays can be
read out of the DOM. Every question about what a user actually sees is asked of
the editor's own document model instead, which is what TEXT_JS and COAUTH_JS are
for.
"""
import asyncio, base64, json, os, re, shutil, subprocess, sys, tempfile, time
import urllib.request
import websockets

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import config


LOGIN_JS = r"""(() => {
  const u = document.querySelector('input[name=user], input#user, input[autocomplete="username"]');
  const p = document.querySelector('input[name=password], input#password, input[type=password]');
  if (!u || !p) return 'form not found';
  const set = (el, v) => { el.focus(); el.value = v;
    el.dispatchEvent(new Event('input', {bubbles: true}));
    el.dispatchEvent(new Event('change', {bubbles: true})); };
  set(u, %s); set(p, %s);
  const form = u.closest('form');
  const btn = form && (form.querySelector('button[type=submit]') || form.querySelector('input[type=submit]'));
  if (btn) { btn.click(); return 'submitted'; }
  if (form) { form.requestSubmit ? form.requestSubmit() : form.submit(); return 'submitted'; }
  return 'no submit control';
})()"""

DISMISS_JS = r"""(() => {
  // Nextcloud can put a modal (first-run wizard, "what's new") over the page,
  // which would swallow the clicks meant for the document.
  let closed = 0;
  document.querySelectorAll('.modal-mask, .modal-container, [role="dialog"]').forEach(d => {
    d.querySelectorAll('button, a').forEach(b => {
      const label = ((b.getAttribute('aria-label') || '') + ' ' + (b.className || '')).toLowerCase();
      if (/close|fermer/.test(label)) { b.click(); closed++; }
    });
  });
  return closed + ' closed, ' + document.querySelectorAll('.modal-mask').length + ' overlay(s) left';
})()"""

COAUTH_JS = r"""(() => {
  // What the co-authoring client thinks its state is. lastOtherSaveTime is only
  // set once a change from another user has been applied, so it is the signal
  // that live co-editing works; a non-empty chunk buffer is the signal that
  // remote changes arrived but are being held back (missing endSaveChanges).
  try {
    const w = document.querySelector('iframe').contentWindow;
    // Asc.editor.CoAuthoringApi is the CDocsCoApi facade; the state lives on
    // the DocsCoApi it wraps, and reading the facade silently yields undefined.
    const outer = w.Asc && w.Asc.editor && w.Asc.editor.CoAuthoringApi;
    const co = outer && outer._CoAuthoringApi;
    if (!co) return JSON.stringify({error: 'no CoAuthoringApi'});
    return JSON.stringify({
      isCoAuthoring: co.isCoAuthoring,
      countEditUsers: co._countEditUsers,
      participants: Object.keys(co._participants || {}),
      lastOtherSaveTime: co.lastOtherSaveTime,
      lastOwnSaveTime: co.lastOwnSaveTime,
      bufferedChunks: (co._saveChangesChunks || []).length,
      bufferedOther: (co._authOtherChanges || []).length,
      changesIndex: co.changesIndex,
      serverChangesSize: outer.get_serverChangesSize(),
      state: co._state,
    });
  } catch (e) { return JSON.stringify({error: String(e)}); }
})()"""

TEXT_JS = r"""(() => {
  // The page renders to a canvas, so the only way to see what a session
  // currently displays is to ask its document model. Each editor keeps a
  // different one, and select-all is no use: GetSelectedText returns null
  // unless the document has focus, which a headless tab does not reliably get.
  try {
    const w = document.querySelector('iframe').contentWindow;
    const api = w.Asc && w.Asc.editor;
    if (!api) return 'no editor';

    if (api.wbModel) {                       // spreadsheet
      const out = [];
      api.wbModel.aWorksheets.forEach(ws => {
        ws.getRange3(0, 0, 200, 30)._foreachNoEmpty(c => {
          const v = c.getValueWithoutFormat();
          // Addresses, not just values: when two sessions edit one document
          // the interesting question is usually which cell a value landed in.
          if (v) out.push(c.getName() + '=' + v);
        });
      });
      return out.join(' | ');
    }

    const ld = api.WordControl && api.WordControl.m_oLogicDocument;
    if (!ld) return 'no logic document';

    const paras = content => content.GetAllParagraphs({All: true}).map(p => p.GetText()).join(' ');

    if (ld.Slides) {                         // presentation
      return ld.Slides.map(sl => (sl.cSld.spTree || []).map(sp =>
        (sp.txBody && sp.txBody.content) ? paras(sp.txBody.content) : ''
      ).filter(Boolean).join(' ')).join(' | ');
    }

    return paras(ld);                        // text document
  } catch (e) { return 'err: ' + e; }
})()"""

PLACE_CELL_JS = r"""(() => {
  // Spreadsheets need the caret placed by address, not by pixel: this sheet
  // covers its top-left cells with charts and slicers, so a click there
  // selects a drawing and the keystrokes go into it (or nowhere) instead of
  // into a cell. Returns false for the other editors, which take the click.
  try {
    const w = document.querySelector('iframe').contentWindow;
    const api = w.Asc && w.Asc.editor;
    if (!api || !api.wb) return false;
    api.wb.getWorksheet().setSelection(new w.Asc.Range(%d, %d, %d, %d));
    return true;
  } catch (e) { return 'err: ' + e; }
})()"""

PROBE_JS = r"""(() => {
  const frame = document.querySelector('iframe');
  let inner = null;
  try {
    const d = frame && frame.contentDocument;
    if (d) {
      const err = d.querySelector('#id-error-mask-title, .error-mask-title');
      inner = {
        errorTitle: err && err.innerText,
        hasLoadMask: !!d.querySelector('.asc-loadmask, #loadmask'),
        // the co-editing users button shows the participant count
        users: (d.querySelector('#tlb-box-users, .btn-users, #left-btn-support') || {}).innerText || null,
        status: (d.querySelector('#status-label-zoom, .status-label') || {}).innerText || null,
      };
    }
  } catch (e) { inner = {crossOrigin: String(e)}; }
  return JSON.stringify(inner);
})()"""


class Session:
    def __init__(self, name, port, user, password, base, fileid, chromium,
                 click=(420, 400), cell=(20, 60)):
        self.name, self.port, self.user, self.password = name, port, user, password
        self.base, self.fileid, self.chromium = base, fileid, chromium
        # Where this session puts its caret. The two sessions must not land on
        # the same spot: in a spreadsheet that means both typing into one cell,
        # and the second value simply replaces the first, which looks exactly
        # like the first one never propagated.
        self.click = click
        # Target cell (col, row), zero-based, for the spreadsheet editor. Far
        # enough out to be empty in the sample workbook.
        self.cell = cell
        self.events, self.id, self.polls, self.posts = [], 0, {}, []

    async def send(self, method, **params):
        self.id += 1
        await self.ws.send(json.dumps({'id': self.id, 'method': method, 'params': params}))
        while True:
            msg = json.loads(await self.ws.recv())
            if msg.get('id') == self.id:
                if 'error' in msg:
                    raise RuntimeError(f"{self.name} {method}: {msg['error']}")
                return msg.get('result', {})
            self.note(msg)

    def note(self, msg):
        self.events.append(msg)
        if msg.get('method') == 'Network.requestWillBeSent':
            req = msg['params'].get('request', {})
            if 'EIO=4' in req.get('url', '') and req.get('postData'):
                self.posts.append(req['postData'])
        if msg.get('method') == 'Network.responseReceived':
            url = msg['params'].get('response', {}).get('url', '')
            if 'EIO=4' in url and 'transport=polling' in url:
                self.polls[msg['params']['requestId']] = url

    async def drain(self, seconds):
        deadline = time.time() + seconds
        while time.time() < deadline:
            try:
                msg = json.loads(await asyncio.wait_for(self.ws.recv(),
                                                        timeout=max(0.2, deadline - time.time())))
                self.note(msg)
            except (asyncio.TimeoutError, TimeoutError):
                break
            except Exception:
                break

    async def start(self):
        self.profile = tempfile.mkdtemp(prefix=f'cdp-{self.name}-')
        self.proc = subprocess.Popen([
            self.chromium, '--headless=new', '--no-sandbox', '--disable-gpu',
            '--disable-dev-shm-usage', '--window-size=1400,900',
            f'--remote-debugging-port={self.port}', f'--user-data-dir={self.profile}',
            '--no-first-run', '--no-default-browser-check', 'about:blank',
        ], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        ws_url = None
        for _ in range(60):
            try:
                targets = json.load(urllib.request.urlopen(f'http://127.0.0.1:{self.port}/json/list'))
                pages = [t for t in targets if t['type'] == 'page']
                if pages:
                    ws_url = pages[0]['webSocketDebuggerUrl']
                    break
            except Exception:
                await asyncio.sleep(0.5)
        if not ws_url:
            raise RuntimeError(f'{self.name}: chromium gave no debugging target')
        self.conn = await websockets.connect(ws_url, max_size=64 * 1024 * 1024, ping_interval=None)
        self.ws = self.conn
        for m in ('Runtime.enable', 'Log.enable', 'Network.enable', 'Page.enable'):
            await self.send(m)

    async def login_and_open(self):
        await self.send('Page.navigate', url=self.base + '/login')
        await self.drain(5)
        print(f'   {self.name} login:', await self.eval(LOGIN_JS % (json.dumps(self.user), json.dumps(self.password))))
        await self.drain(8)
        who = await self.eval("document.location.pathname + '|' + ((window.OC && OC.currentUser) || '?')")
        print(f'   {self.name} after login:', who)
        await self.send('Page.navigate', url=f'{self.base}/apps/onlyoffice/{self.fileid}')

    async def where(self):
        return await self.eval(
            "document.location.pathname + ' | iframe=' + !!document.querySelector('iframe')"
            r" + ' | ' + (document.body ? document.body.innerText.slice(0,160).replace(/[\n\t]+/g,' ') : '')")

    async def eval(self, expr):
        res = await self.send('Runtime.evaluate', expression=expr, returnByValue=True)
        if res.get('exceptionDetails'):
            return f"JS error: {res['exceptionDetails'].get('text')}"
        return res.get('result', {}).get('value')

    async def type(self, text, enter=True, row_offset=0, attempts=2):
        """Type text into the document, and make sure it went in.

        A click sometimes lands where the caret does not follow - below the
        text in a nearly empty document, on a drawing, on an overlay that was
        still up - and the keystrokes then go nowhere. That is indistinguishable
        from the change never reaching the server, which is what most of these
        tests are about, so typing checks the editor's own model and tries once
        more rather than leaving a test to fail for the wrong reason.
        """
        for attempt in range(1, attempts + 1):
            await self._type_once(text, enter=enter, row_offset=row_offset)
            await self.drain(3)
            if text.strip() in str(await self.eval(TEXT_JS)):
                return True
            if attempt < attempts:
                print(f'   {self.name}: the text did not land, typing it again')
        return False

    async def _type_once(self, text, enter=True, row_offset=0):
        for ev in ('mousePressed', 'mouseReleased'):
            await self.send('Input.dispatchMouseEvent', type=ev,
                            x=self.click[0], y=self.click[1],
                            button='left', clickCount=1)
        await self.drain(1.5)
        # One cell per round: writing the same cell twice just replaces the
        # value, which would make an earlier round's marker look lost.
        cell = (self.cell[0], self.cell[1] + row_offset)
        placed = await self.eval(PLACE_CELL_JS % (cell + cell))
        if placed is True:
            # A click may have grabbed a chart; drop that selection before
            # typing, then re-place the caret on the target cell.
            await self.send('Input.dispatchKeyEvent', type='rawKeyDown', key='Escape',
                            code='Escape', windowsVirtualKeyCode=27, nativeVirtualKeyCode=27)
            await self.send('Input.dispatchKeyEvent', type='keyUp', key='Escape', code='Escape')
            await self.eval(PLACE_CELL_JS % (cell + cell))
        for ch in text:
            await self.send('Input.dispatchKeyEvent', type='keyDown', text=ch,
                            unmodifiedText=ch, key=ch)
            await self.send('Input.dispatchKeyEvent', type='keyUp', key=ch)
        if enter:
            # A spreadsheet keeps what was typed in the cell editor until the
            # edit is committed, so nothing is sent to the server without this.
            for t in ('rawKeyDown', 'char', 'keyUp'):
                await self.send('Input.dispatchKeyEvent', type=t, key='Enter',
                                code='Enter', text='\r', unmodifiedText='\r',
                                windowsVirtualKeyCode=13, nativeVirtualKeyCode=13)

    async def messages(self):
        """socket message types this session received, from the poll bodies."""
        types, raw = {}, []
        for rid in list(self.polls):
            try:
                body = await self.send('Network.getResponseBody', requestId=rid)
            except Exception:
                continue
            text = body.get('body', '')
            if body.get('base64Encoded'):
                try:
                    text = base64.b64decode(text).decode('utf-8', 'replace')
                except Exception:
                    continue
            for m in re.finditer(r'42\[(.*)\]', text):
                raw.append(m.group(1)[:200])
                for t in re.findall(r'"type"\s*:\s*"([a-zA-Z]+)"', m.group(1)):
                    types[t] = types.get(t, 0) + 1
        return types, raw

    async def shot(self, path):
        img = await self.send('Page.captureScreenshot')
        with open(path, 'wb') as f:
            f.write(base64.b64decode(img['data']))

    async def stop(self):
        try:
            await self.conn.close()
        except Exception:
            pass
        self.proc.terminate()
        try:
            self.proc.wait(timeout=10)
        except Exception:
            self.proc.kill()
        shutil.rmtree(self.profile, ignore_errors=True)

class Tab(Session):
    """A second tab in a browser that is already running.

    Two tabs share a cookie jar - so one Nextcloud session - a connection pool
    and any service worker, which two separate browsers do not. That is what
    somebody opening the same document twice actually has, and it is a case the
    two-browser tests cannot reach.
    """

    async def start(self):
        req = urllib.request.Request(
            f'http://127.0.0.1:{self.port}/json/new?url=about:blank', method='PUT')
        info = json.load(urllib.request.urlopen(req))
        self.target_id = info['id']
        self.conn = await websockets.connect(info['webSocketDebuggerUrl'],
                                             max_size=64 * 1024 * 1024, ping_interval=None)
        self.ws = self.conn
        for m in ('Runtime.enable', 'Log.enable', 'Network.enable', 'Page.enable'):
            await self.send(m)

    async def login_and_open(self):
        # the cookie is already in this browser, so straight to the document
        await self.send('Page.navigate', url=f'{self.base}/apps/onlyoffice/{self.fileid}')

    async def stop(self):
        try:
            await self.conn.close()
        except Exception:
            pass
