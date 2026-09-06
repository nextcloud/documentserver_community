#!/usr/bin/env python3
"""Flushing a document somebody is still editing must not empty its change list.

`occ documentserver:flush` (and the Cleanup job, and the Save button) used to
run the same code whether or not anybody was in the document: apply the changes
to the file and then delete them. But the change list is the only record of
what was typed - Editor.bin stays at the version the document was opened at - so
consuming it mid-session means the next flush writes the file from what is left,
losing everything from before. A session opened afterwards gets the same empty
list and shows the document as it was before the edits.

  1. type, then flush while the session is live
  2. the file has the text, and the change list and document are still there
  3. type again, flush again with everybody gone
  4. the file must hold both edits - the second flush must not have dropped the
     first one
"""
import argparse, asyncio, os, subprocess, sys, time

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'lib'))
import config
import harness
from driver import Session, DISMISS_JS, TEXT_JS


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
    p.add_argument('--tag', default='FLUSH')
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
    # off, so that what the file holds is the flush's doing and nothing else
    harness.set_app_config('autosave_interval', 0)
    time.sleep(harness.APP_CONFIG_PROPAGATION)

    A = Session('A', 9222, ua, pa, args.base, args.fileid, args.chromium, (420, 400))
    try:
        await A.start()
        await A.login_and_open(); await A.drain(25)
        await A.eval(DISMISS_JS)

        print(f'\n==> [1] type {t}1, then flush with the session still open')
        await A.type(f'{t}1 ')
        await A.drain(10)
        before = harness.changes()
        print('   file before the flush:',
              harness.file_markers(args.file, args.member,
                         [f'{t}1']))
        print('   flush: exit=%d %s' % harness.flush())
        await A.drain(5)

        got = harness.file_markers(args.file, args.member,
                         [f'{t}1'])
        print('   file:', got, '| changes:', harness.changes(), '(was', str(before) + ')',
              '| doc folders:', harness.doc_folders(), '| sessions:', harness.sessions())
        if not got[f'{t}1']:
            ok = False
            print('   FAIL - the flush did not write the document')
        if harness.changes() < before:
            ok = False
            print('   FAIL - the flush consumed the change list of a live session')
        if harness.doc_folders() == 0:
            ok = False
            print('   FAIL - the flush disposed of a document that is still open')

        print('\n==> [2] a second session joins: it must see the first one\'s work')
        B = Session('B', 9333, ub, pb, args.base, args.fileid, args.chromium, (420, 470))
        try:
            await B.start()
            await B.login_and_open()
            await B.drain(25)
            text = str(await B.eval(TEXT_JS))
            print('   joiner sees the marker:', f'{t}1' in text)
            if f'{t}1' not in text:
                ok = False
                print('   FAIL - the joiner replayed an empty change list against the '
                      'baseline and got the document as it was before the edits')
        finally:
            await B.stop()

        print(f'\n==> [3] the session is still usable: type {t}2')
        await A.type(f'{t}2 ')
        await A.drain(10)
        print('   changes:', harness.changes(), '| sessions:', harness.sessions())
    finally:
        await A.stop()

    print('\n==> [4] everybody gone, flush again')
    harness.drop_sessions()
    print('   flush: exit=%d %s' % harness.flush())
    got = harness.file_markers(args.file, args.member,
                         [f'{t}1', f'{t}2'])
    print('   file:', got, '| doc folders:', harness.doc_folders(), '| changes:', harness.changes())
    if not all(got.values()):
        ok = False
        print('   FAIL - the final flush lost an edit')
    if harness.doc_folders() or harness.changes():
        ok = False
        print('   FAIL - the document was not disposed of once everybody had left')

    print('\n   VERDICT:', 'flushing a live document is safe' if ok else 'FAIL')
    return 0 if ok else 1


if __name__ == '__main__':
    sys.exit(asyncio.run(main()))
