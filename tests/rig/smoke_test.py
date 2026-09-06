#!/usr/bin/env python3
"""Every format opens, edits, and reaches the file.

The one test that would have caught most of what broke while this app was being
brought up to a current document server: an editor that never finishes loading
because api.js is a template nobody rendered, one that renders unstyled because
a CSP nonce killed the inline onload that loads its stylesheets, a converter
that cannot find its fonts, appdata on local storage the file cache never heard
about. All of those show up here as an editor that does not open, a JS
exception, a failed request, or text that never reaches the file.

Per format: open it in a real browser, check the page for exceptions and failed
requests, type a marker, and look for it in the saved file.
"""
import argparse, asyncio, os, sys, time

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'lib'))
import config
import harness
from driver import Session, DISMISS_JS, PROBE_JS, TEXT_JS

# The websocket attempt is expected to fail: PHP cannot upgrade, and the 400 is
# what makes the client fall back to long polling. Nextcloud has no preview for
# a document nobody has generated one for yet, which is a Files app 404 and
# nothing to do with the editor.
EXPECTED_FAILURES = ('transport=websocket', 'ws://', 'wss://', '/core/preview')


def interesting_failures(session):
    out = []
    for event in session.events:
        method, params = event.get('method'), event.get('params', {})
        if method == 'Network.loadingFailed':
            text = f"{params.get('type')} {params.get('errorText')} {params.get('requestId')}"
        elif method == 'Network.responseReceived':
            response = params.get('response', {})
            if response.get('status', 0) < 400:
                continue
            text = f"HTTP {response.get('status')} {response.get('url')}"
        else:
            continue
        if not any(skip in text for skip in EXPECTED_FAILURES):
            out.append(text)
    return out


def exceptions(session):
    out = []
    for event in session.events:
        if event.get('method') == 'Runtime.exceptionThrown':
            details = event['params'].get('exceptionDetails', {})
            out.append(f"{details.get('text')} @ {details.get('url')}:{details.get('lineNumber')}")
    return out


async def check_format(kind, args):
    document = config.DOCUMENTS[kind]
    fileid = document['fileid']
    if not fileid:
        raise SystemExit(f'no file id for {kind}: run ./rig.sh provision')

    marker = f"SMOKE{kind.upper()}{int(time.time()) % 100000}"
    user, password = config.credentials(args.a)
    ok = True

    print(f'\n==> {document["name"]} (fileid {fileid})')
    harness.reset()

    session = Session('A', 9222, user, password, args.base, fileid, args.chromium, (420, 400))
    try:
        await session.start()
        await session.login_and_open()
        await session.drain(args.load_wait)
        await session.eval(DISMISS_JS)

        state = await session.eval(PROBE_JS)
        print('   editor:', state)
        if state and 'errorTitle' in str(state) and 'null' not in str(state).split('errorTitle')[1][:20]:
            ok = False
            print('   FAIL - the editor is showing an error')

        broken = interesting_failures(session)
        thrown = exceptions(session)
        print(f'   failed requests: {len(broken)} | JS exceptions: {len(thrown)}')
        for line in (broken + thrown)[:8]:
            print('     ', line)
        if broken or thrown:
            ok = False
            print('   FAIL - opening the document was not clean')

        await session.type(f'{marker} ')
        await session.drain(args.save_wait)

        typed = str(await session.eval(TEXT_JS))
        if marker not in typed:
            ok = False
            print(f'   FAIL - the marker never reached the editor model: {typed[:120]!r}')
        stored = harness.changes()
        print(f'   changes stored: {stored}')
        if stored == 0:
            ok = False
            print('   FAIL - nothing was sent to the server')
    finally:
        await session.stop()

    # The browser is gone but its session has not expired yet, and a flush only
    # disposes of a document nobody is in - so tell the server what killing the
    # browser could not.
    harness.drop_sessions()

    code, out = harness.flush()
    print(f'   flush: exit={code} {out[:60]}')
    if code != 0:
        ok = False
        print('   FAIL - documentserver:flush reported an error')

    found = harness.file_markers(document['name'], document['member'], [marker], args.a)
    print('   file:', found)
    if not all(found.values()):
        ok = False
        print('   FAIL - the edit did not reach the saved file')

    left = harness.doc_folders()
    if left:
        ok = False
        print(f'   FAIL - {left} document folder(s) left behind after the flush')

    errors = harness.app_log_errors()
    if errors:
        ok = False
        print('   FAIL - the app logged errors:')
        for line in errors[:5]:
            print('     ', line[:200])

    return ok


async def main():
    p = argparse.ArgumentParser()
    p.add_argument('--base', default=config.BASE)
    p.add_argument('--a', default=config.ADMIN)
    p.add_argument('--chromium', default=config.CHROMIUM)
    p.add_argument('--kind', action='append', choices=sorted(config.DOCUMENTS),
                   help='only this format (repeatable); default is all three')
    p.add_argument('--load-wait', type=float, default=30)
    p.add_argument('--save-wait', type=float, default=15)
    args = p.parse_args()

    kinds = args.kind or ['docx', 'xlsx', 'pptx']
    results = {}
    for kind in kinds:
        results[kind] = await check_format(kind, args)

    print('\n   VERDICT:', ' '.join(f'{k}={"ok" if v else "FAIL"}' for k, v in results.items()))
    return 0 if all(results.values()) else 1


if __name__ == '__main__':
    sys.exit(asyncio.run(main()))
