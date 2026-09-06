#!/usr/bin/env python3
"""Check the editor's chat panel across two live sessions, plus the backlog.

Sends a message from each of two sessions and reports what each one was told,
then asks for the history again to see whether someone opening the document
later would find the conversation. The panel does not echo what you type, so a
session that does not get its own message back has broken chat even if the
other side sees it.
"""
import argparse, asyncio, json, os, sys
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'lib'))
import config
import harness
from driver import Session, DISMISS_JS

# Chat arrives through CoAuthoringApi.onMessage(messages, clear); record it
# rather than reading the Backbone collection the panel renders from.
RECORD_JS = r"""(() => {
  try {
    const w = document.querySelector('iframe').contentWindow;
    const inner = w.Asc.editor.CoAuthoringApi._CoAuthoringApi;
    w.__rigChat = [];
    const orig = inner.onMessage;
    inner.onMessage = function (messages, clear) {
      (messages || []).forEach(m => w.__rigChat.push(
        {from: m.useridoriginal, name: m.username, text: m.message, clear: !!clear}));
      if (orig) orig.call(this, messages, clear);
    };
    return 'recording';
  } catch (e) { return 'err: ' + e; }
})()"""

SEND_JS = r"""(() => {
  try {
    const w = document.querySelector('iframe').contentWindow;
    w.Asc.editor.asc_coAuthoringChatSendMessage(%s);
    return 'sent';
  } catch (e) { return 'err: ' + e; }
})()"""

READ_JS = r"""(() => {
  const w = document.querySelector('iframe').contentWindow;
  return JSON.stringify(w.__rigChat || 'no recorder');
})()"""

# What the panel itself holds. A session that opened after the conversation has
# no recorder in place, so this is the only way to see whether its backlog
# request was answered.
PANEL_JS = r"""(() => {
  try {
    const w = document.querySelector('iframe').contentWindow;
    for (const n of ['DE', 'SSE', 'PE', 'PDFE']) {
      const app = w[n];
      if (app && app.getCollection) {
        const c = app.getCollection('Common.Collections.ChatMessages');
        if (c) return JSON.stringify(c.map(m => m.get('message')));
      }
    }
    return JSON.stringify('no chat collection');
  } catch (e) { return JSON.stringify('err: ' + e); }
})()"""

ASK_HISTORY_JS = r"""(() => {
  try {
    const w = document.querySelector('iframe').contentWindow;
    w.__rigChat = [];
    w.Asc.editor.CoAuthoringApi.getMessages();
    return 'asked';
  } catch (e) { return 'err: ' + e; }
})()"""


async def main():
    p = argparse.ArgumentParser()
    p.add_argument('--base', default=config.BASE)
    p.add_argument('--a', default=config.ADMIN)
    p.add_argument('--b', default=config.USER2)
    p.add_argument('--kind', default='docx', choices=sorted(config.DOCUMENTS))
    p.add_argument('--fileid', default=None)
    p.add_argument('--chromium', default=config.CHROMIUM)
    p.add_argument('--tag', default='')
    args = p.parse_args()
    args.fileid = args.fileid or config.document(args.kind)['fileid']

    ua, pa = args.a.split(':', 1)
    ub, pb = args.b.split(':', 1)
    A = Session('A', 9222, ua, pa, args.base, args.fileid, args.chromium)
    B = Session('B', 9333, ub, pb, args.base, args.fileid, args.chromium)

    msg_a = f'{args.tag}hello-from-A'
    msg_b = f'{args.tag}hi-from-B'

    verdict = {'ok': False}
    try:
        await A.start(); await B.start()
        print(f'==> opening file {args.fileid} as {ua} (A) and {ub} (B)')
        await A.login_and_open(); await A.drain(25)
        await B.login_and_open(); await B.drain(25)
        await A.eval(DISMISS_JS); await B.eval(DISMISS_JS)
        print('   A recorder:', await A.eval(RECORD_JS))
        print('   B recorder:', await B.eval(RECORD_JS))

        print(f'==> A sends {msg_a!r}:', await A.eval(SEND_JS % json.dumps(msg_a)))
        await A.drain(10); await B.drain(10)
        print(f'==> B sends {msg_b!r}:', await B.eval(SEND_JS % json.dumps(msg_b)))
        await B.drain(10); await A.drain(10)

        got = {}
        for s in (A, B):
            got[s.name] = json.loads(await s.eval(READ_JS))
            print(f'\n   {s.name} received: {got[s.name]}')

        ok = True
        for s in (A, B):
            texts = [m['text'] for m in got[s.name]]
            missing = [m for m in (msg_a, msg_b) if m not in texts]
            if missing:
                ok = False
                print(f'   {s.name}: MISSING {missing}')
            else:
                print(f'   {s.name}: both messages present')

        print('\n==> asking for the backlog again, as a late joiner would')
        await A.eval(ASK_HISTORY_JS)
        await A.drain(10)
        history = json.loads(await A.eval(READ_JS))
        print('   backlog:', [m['text'] for m in history])
        if not all(m in [h['text'] for h in history] for m in (msg_a, msg_b)):
            ok = False
            print('   backlog: INCOMPLETE')
        else:
            print('   backlog: complete')

        print('\n==> a third session opens the document now')
        C = Session('C', 9555, ua, pa, args.base, args.fileid, args.chromium)
        try:
            await C.start()
            await C.login_and_open(); await C.drain(25)
            panel = json.loads(await C.eval(PANEL_JS))
            print('   C chat panel holds:', panel)
            if not isinstance(panel, list) or not all(m in panel for m in (msg_a, msg_b)):
                ok = False
                print('   C: INCOMPLETE - a late joiner does not see the conversation')
            else:
                print('   C: sees the whole conversation')
        finally:
            await C.stop()

        print('\n   VERDICT:', 'chat OK' if ok else 'FAIL')
        verdict['ok'] = ok
    finally:
        await A.stop(); await B.stop()

    return 0 if verdict['ok'] else 1


if __name__ == '__main__':
    sys.exit(asyncio.run(main()))
