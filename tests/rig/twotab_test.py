#!/usr/bin/env python3
"""Two tabs of one browser, in one document: does each see the other typing?

coedit_test.py runs two separate browsers, which share nothing. Two tabs share
a cookie jar - so one Nextcloud PHP session - a connection pool, and any service
worker, and that is what someone opening a second tab on the same document
actually has.

Each tab types a marker, and then both document models are read back: the test
is whether each tab *shows* what the other typed, not whether the messages were
sent.
"""
import argparse, asyncio, os, subprocess, sys, time

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'lib'))
import config
import harness
from driver import Session, Tab, COAUTH_JS, DISMISS_JS, TEXT_JS


async def main():
    p = argparse.ArgumentParser()
    p.add_argument('--base', default=config.BASE)
    p.add_argument('--user', default=config.ADMIN)
    p.add_argument('--kind', default='docx', choices=sorted(config.DOCUMENTS))
    p.add_argument('--fileid', default=None)
    p.add_argument('--file', default=None)
    p.add_argument('--member', default=None)
    p.add_argument('--chromium', default=config.CHROMIUM)
    p.add_argument('--tag', default='TAB')
    p.add_argument('--rounds', type=int, default=2)
    p.add_argument('--interval', type=int, default=20)
    p.add_argument('--no-reset', action='store_true')
    args = p.parse_args()
    document = config.document(args.kind)
    args.fileid = args.fileid or document['fileid']
    args.file = args.file or document['name']
    args.member = args.member or document['member']

    u, pw = args.user.split(':', 1)
    t = args.tag + str(int(time.time()) % 100000)
    ok = True

    if not args.no_reset:
        print('==> resetting rig state')
        harness.reset()
        harness.set_app_config('autosave_interval', args.interval)
        time.sleep(harness.APP_CONFIG_PROPAGATION)

    A = Session('A', 9222, u, pw, args.base, args.fileid, args.chromium, (420, 400))
    B = Tab('B', 9222, u, pw, args.base, args.fileid, args.chromium, (420, 470))
    try:
        await A.start()
        await A.login_and_open()
        await A.drain(25)
        await A.eval(DISMISS_JS)
        print('   tab A:', await A.where())

        await B.start()
        await B.login_and_open()
        await B.drain(30)
        await B.eval(DISMISS_JS)
        print('   tab B:', await B.where())
        print('   sessions in the document:', harness.sessions())

        for r in range(1, args.rounds + 1):
            print(f'\n==> round {r}')
            await A.type(f'{t}-A{r} ', row_offset=r * 2)
            await A.drain(10); await B.drain(10)
            await B.type(f'{t}-B{r} ', row_offset=r * 2 + 1)
            await B.drain(10); await A.drain(10)
            print('   A co-auth:', await A.eval(COAUTH_JS))
            print('   B co-auth:', await B.eval(COAUTH_JS))

        await A.drain(8); await B.drain(8)
        atext, btext = str(await A.eval(TEXT_JS)), str(await B.eval(TEXT_JS))
        wanted = [f'{t}-{who}{r}' for r in range(1, args.rounds + 1) for who in ('A', 'B')]
        missA = [m for m in wanted if m not in atext]
        missB = [m for m in wanted if m not in btext]
        print('\n   tab A is missing:', missA or 'nothing')
        print('   tab B is missing:', missB or 'nothing')
        if missA or missB:
            ok = False
            print('   FAIL - a tab does not show what the other one typed')

        # From here on B is not a participant any more, so what B's model holds
        # is sdkjs's business - going to view mode can roll back changes it had
        # applied. What matters is what happens to the document and to A.
        print('\n==> tab B is dropped to view mode (what sdkjs does on a licence'
              ' verdict, rights change or disconnectEveryone)')
        await B.eval("document.querySelector('iframe').contentWindow"
                     ".Asc.editor.asc_coAuthoringDisconnect()")
        await B.drain(15); await A.drain(15)
        print('   doc folders:', harness.doc_folders(), '| changes:', harness.changes(),
              '| sessions:', harness.sessions())
        if harness.doc_folders() == 0 or harness.changes() == 0:
            ok = False
            print('   FAIL - the document was written off while a page was still open')

        print('   A types on, and it must still reach the file')
        await A.type(f'{t}-A9 ', row_offset=20)
        await A.drain(args.interval)
        await A.type(f'{t}-A9x ', row_offset=21)   # a save past the interval
        await A.drain(15)
        got = harness.file_markers(args.file, args.member,
                         [f'{t}-A9'])
        print('   file:', got)
        if not all(got.values()):
            ok = False
            print('   FAIL - the session lost its document when the other one went to view mode')

    finally:
        await B.stop()
        await A.stop()

    print('\n   VERDICT:', 'two tabs co-author' if ok else 'FAIL')
    return 0 if ok else 1


if __name__ == '__main__':
    sys.exit(asyncio.run(main()))
