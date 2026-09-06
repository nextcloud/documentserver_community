#!/usr/bin/env python3
"""Check what happens to the document when people leave it.

Two ways of leaving, both of which used to leave the file unwritten until the
Cleanup background job came round (#100):

  * closing the editor - destroyEditor() takes the iframe out of the DOM
  * closing the tab    - the whole page unloads

and one thing that must *not* happen: the first of two participants leaving
must not end the document for the one still typing into it.

  1. A and B open the document and both type
  2. A closes the editor: the document stays open, B keeps editing
  3. B types again - which must still reach the file
  4. B closes the tab: everything is in the file, the document is disposed of

No cron and no `documentserver:flush` anywhere in here.
"""
import argparse, asyncio, os, subprocess, sys, time

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'lib'))
import config
import harness
from driver import Session, DISMISS_JS, TEXT_JS

CLOSE_EDITOR_JS = """(() => {
  const ed = (window.OCA && OCA.Onlyoffice && OCA.Onlyoffice.docEditor) || window.docEditor;
  if (ed && ed.destroyEditor) { ed.destroyEditor(); return 'destroyEditor()'; }
  return 'no editor to close';
})()"""


async def main():
    p = argparse.ArgumentParser()
    p.add_argument('--base', default=config.BASE)
    p.add_argument('--a', default=config.ADMIN)
    p.add_argument('--b', default=config.USER2)
    p.add_argument('--kind', default='docx', choices=sorted(config.DOCUMENTS))
    p.add_argument('--fileid', default=None)
    p.add_argument('--file', default=None)
    p.add_argument('--member', default=None)
    p.add_argument('--chromium', default=config.CHROMIUM)
    p.add_argument('--tag', default='LEAVE')
    p.add_argument('--interval', type=int, default=25)
    args = p.parse_args()
    document = config.document(args.kind)
    args.fileid = args.fileid or document['fileid']
    args.file = args.file or document['name']
    args.member = args.member or document['member']

    ua, pa = args.a.split(':', 1)
    ub, pb = args.b.split(':', 1)
    # unique per run: the sample file keeps what earlier runs typed into it,
    # and a marker left over from last time looks exactly like one that was
    # just written
    t = args.tag + str(int(time.time()) % 100000)
    ok = True

    print('==> resetting rig state')
    harness.reset()
    harness.set_app_config('autosave_interval', args.interval)
    time.sleep(harness.APP_CONFIG_PROPAGATION)

    A = Session('A', 9222, ua, pa, args.base, args.fileid, args.chromium, (420, 400))
    B = Session('B', 9333, ub, pb, args.base, args.fileid, args.chromium, (420, 470))
    try:
        await A.start(); await B.start()
        await A.login_and_open(); await A.drain(25)
        await B.login_and_open(); await B.drain(25)
        await A.eval(DISMISS_JS); await B.eval(DISMISS_JS)

        print(f'\n==> [1] both type')
        await A.type(f'{t}-A1 '); await A.drain(10); await B.drain(10)
        await B.type(f'{t}-B1 '); await B.drain(10); await A.drain(10)
        print('   sessions:', harness.sessions())

        print(f'\n==> [2] A closes the editor while B is still in the document')
        print('   A closing:', await A.eval(CLOSE_EDITOR_JS))
        await B.drain(20)
        left = harness.sessions()
        print('   sessions:', left, '| doc folders:', harness.doc_folders(), '| changes:', harness.changes())
        if left != 1:
            ok = False
            print('   FAIL - A leaving should have left exactly B behind')
        if harness.doc_folders() == 0 or harness.changes() == 0:
            ok = False
            print('   FAIL - the document was disposed of while B is still editing it')

        print(f'\n==> [3] B keeps typing, and it still reaches the file')
        await B.drain(args.interval)
        await B.type(f'{t}-B2 ')
        await B.drain(15)
        got = harness.file_markers(args.file, args.member,
                         [f'{t}-A1', f'{t}-B1', f'{t}-B2'])
        print('   file:', got)
        if not all(got.values()):
            ok = False
            print('   FAIL - B lost the document server when A left')

        print(f'\n==> [4] B types once more and closes the tab')
        await B.type(f'{t}-B3 ')
        await B.drain(8)
        await B.send('Page.navigate', url=args.base + '/apps/files')
        await B.drain(22)
    finally:
        await A.stop(); await B.stop()

    got = harness.file_markers(args.file, args.member,
                         [f'{t}-A1', f'{t}-B1', f'{t}-B2', f'{t}-B3'])
    print('   file:', got)
    print('   doc folders:', harness.doc_folders(), '| changes:', harness.changes(), '| sessions:', harness.sessions())
    if not all(got.values()):
        ok = False
        print('   FAIL - closing the tab did not write everything to the file')
    if harness.doc_folders() or harness.changes():
        ok = False
        print('   FAIL - the last participant left but the document was not disposed of')

    print('\n==> [5] reopen the document: the editor must show what was typed')
    C = Session('C', 9444, ua, pa, args.base, args.fileid, args.chromium, (420, 400))
    try:
        await C.start()
        await C.login_and_open()
        await C.drain(25)
        text = await C.eval(TEXT_JS)
        missing = [m for m in (f'{t}-A1', f'{t}-B1', f'{t}-B2', f'{t}-B3')
                   if m not in str(text)]
        print('   markers missing from the reopened document:', missing or 'none')
        if missing:
            ok = False
            print('   FAIL - reopening the document did not show the saved work')
    finally:
        await C.stop()

    print('\n   VERDICT:', 'leaving a document saves it' if ok else 'FAIL')
    return 0 if ok else 1



if __name__ == '__main__':
    sys.exit(asyncio.run(main()))
