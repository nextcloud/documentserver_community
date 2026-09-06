#!/usr/bin/env python3
"""Check that edits reach the Nextcloud file without cron and without a flush.

Issue #100: the file was only written once the last participant had left *and*
the Cleanup background job had run. Everything typed before that lived in this
server's database, so a browser closed at the wrong moment - or an instance
whose cron is slow or misconfigured - lost the work, silently.

This drives one real editor and checks the file on disk at each step:

  1. type, and the first save writes the file straight away
  2. type again inside the autosave interval - the file must not change, or the
     converter is being run on every keystroke batch
  3. type again after the interval - both markers must be in the file now
  4. close the editor - everything must be in the file, the document folder
     gone and the change list empty, with no cron and no `documentserver:flush`

Nothing here runs occ except to read state and to set the interval.
"""
import argparse, asyncio, os, subprocess, sys, time

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'lib'))
import config
import harness
from driver import Session, DISMISS_JS, TEXT_JS


CLOSE_JS = r"""(() => {
  try {
    // what the editor's own close button goes through in the Nextcloud
    // connector: OCA.Onlyoffice.onRequestClose
    const ed = (window.OCA && OCA.Onlyoffice && OCA.Onlyoffice.docEditor) || window.docEditor;
    if (ed && ed.destroyEditor) {
      ed.destroyEditor();
      return 'destroyEditor()';
    }
    const f = document.querySelector('iframe');
    const w = f && f.contentWindow;
    const api = w && (w.Asc && w.Asc.editor);
    if (api && api.asc_coAuthoringDisconnect) {
      api.asc_coAuthoringDisconnect();
      return 'asc_coAuthoringDisconnect()';
    }
    return 'no way to close found';
  } catch (e) { return 'error: ' + e; }
})()"""


async def main():
    p = argparse.ArgumentParser()
    p.add_argument('--base', default=config.BASE)
    p.add_argument('--a', default=config.ADMIN)
    p.add_argument('--kind', default='docx', choices=sorted(config.DOCUMENTS))
    p.add_argument('--fileid', default=None)
    p.add_argument('--file', default=None)
    p.add_argument('--member', default=None)
    p.add_argument('--chromium', default=config.CHROMIUM)
    p.add_argument('--tag', default='AUTO')
    p.add_argument('--interval', type=int, default=45)
    args = p.parse_args()
    document = config.document(args.kind)
    args.fileid = args.fileid or document['fileid']
    args.file = args.file or document['name']
    args.member = args.member or document['member']

    ua, pa = args.a.split(':', 1)
    # unique per run: the sample file keeps what earlier runs typed into it,
    # and a marker left over from last time looks exactly like one that was
    # just written
    t = args.tag + str(int(time.time()) % 100000)
    ok = True

    print('==> resetting rig state')
    harness.reset()
    print('   autosave interval ->', args.interval, ':',
          harness.set_app_config('autosave_interval', args.interval) or 'set')
    # an app config value written through occ takes a moment to be visible to
    # web requests (local APCu)
    time.sleep(harness.APP_CONFIG_PROPAGATION)

    A = Session('A', 9222, ua, pa, args.base, args.fileid, args.chromium, (420, 400))
    try:
        await A.start()
        await A.login_and_open()
        await A.drain(25)
        await A.eval(DISMISS_JS)

        print(f'\n==> [1] type {t}1, nothing else')
        await A.type(f'{t}1 ', row_offset=0)
        await A.drain(12)
        got = harness.file_markers(args.file, args.member,
                         [f'{t}1'])
        print('   file:', got, '| doc folders:', harness.doc_folders(), '| changes:', harness.changes(),
              '\n   snapshot state:', harness.snapshot_state(), '| now:', int(time.time()))
        if not got[f'{t}1']:
            ok = False
            print('   FAIL - the first save did not reach the file')

        # Well inside the interval: the editor keeps saving on its own while a
        # session is open, and a check too close to the far end of the interval
        # catches one of those rather than the one this step typed.
        print(f'\n==> [2] type {t}2 straight away, inside the {args.interval}s interval')
        job_before = harness.job_last_run()
        await A.type(f'{t}2 ', row_offset=1)
        await A.drain(6)
        got = harness.file_markers(args.file, args.member,
                         [f'{t}1', f'{t}2'])
        print('   file:', got, '\n   snapshot state:', harness.snapshot_state(), '| now:', int(time.time()))
        if got[f'{t}2']:
            if harness.job_last_run() != job_before:
                print('   (the Cleanup job ran in this window and wrote it; '
                      'the interval was not exercised this run)')
            else:
                ok = False
                print('   FAIL - the interval is not being respected, every save assembles the document')

        print(f'\n==> [3] wait out the interval, then type {t}3')
        await A.drain(args.interval)
        await A.type(f'{t}3 ', row_offset=2)
        await A.drain(15)
        got = harness.file_markers(args.file, args.member,
                         [f'{t}1', f'{t}2', f'{t}3'])
        print('   file:', got)
        if not all(got.values()):
            ok = False
            print('   FAIL - the autosave did not write the newer edits')

        print(f'\n==> [4] type {t}4 and close the editor')
        await A.type(f'{t}4 ', row_offset=3)
        await A.drain(8)
        print('   closing:', await A.eval(CLOSE_JS))
        # the beacon is only acted on once the session's poll has provably
        # stopped, which takes Channel::SEEN_INTERVAL + 3 seconds
        await A.drain(20)
    finally:
        await A.stop()

    got = harness.file_markers(args.file, args.member,
                         [f'{t}1', f'{t}2', f'{t}3', f'{t}4'])
    print('   close command seen by the server:', harness.app_log('type=close') > 0)
    print('   file:', got)
    print('   doc folders:', harness.doc_folders(), '| changes:', harness.changes(), '| sessions:', harness.sessions())
    if not all(got.values()):
        ok = False
        print('   FAIL - closing the editor did not write everything to the file')
    if harness.doc_folders() or harness.changes():
        ok = False
        print('   FAIL - the document was written but its session was not ended')

    errors = harness.app_log_errors()
    if errors:
        print('\n===== errors the app logged =====')
        for line in errors[:5]:
            print('  ', line[:200])

    print('\n   VERDICT:', 'saving during editing works' if ok else 'FAIL')
    return 0 if ok else 1


if __name__ == '__main__':
    sys.exit(asyncio.run(main()))
