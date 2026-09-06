#!/usr/bin/env python3
"""Open one document in one live session and evaluate arbitrary JS in it.

The other harnesses here are batch runs; this one exists to poke at a live
editor while working out which sdkjs call reports what. Expressions come from
--js (repeatable) or a file, and run in the top-level page, so reach into the
editor through document.querySelector('iframe').contentWindow.
"""
import argparse, asyncio, json, os, sys
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'lib'))
import config
import harness
from driver import Session, DISMISS_JS

async def main():
    p = argparse.ArgumentParser()
    p.add_argument('--base', default=config.BASE)
    p.add_argument('--user', default=config.ADMIN)
    p.add_argument('--kind', default='docx', choices=sorted(config.DOCUMENTS))
    p.add_argument('--fileid', default=None)
    p.add_argument('--chromium', default=config.CHROMIUM)
    p.add_argument('--type-text', default='')
    p.add_argument('--js', action='append', default=[])
    p.add_argument('--js-file')
    p.add_argument('--settle', type=float, default=25)
    p.add_argument('--pause', type=float, default=0,
                   help='seconds to let the page run between expressions, for when one '
                        'expression triggers a round trip the next one checks')
    args = p.parse_args()
    args.fileid = args.fileid or config.document(args.kind)['fileid']

    exprs = list(args.js)
    if args.js_file:
        exprs += [b for b in open(args.js_file).read().split('\n%%\n') if b.strip()]

    u, pw = args.user.split(':', 1)
    s = Session('P', 9444, u, pw, args.base, args.fileid, args.chromium)
    try:
        await s.start()
        await s.login_and_open()
        await s.drain(args.settle)
        print('   where:', await s.where())
        await s.eval(DISMISS_JS)
        if args.type_text:
            await s.type(args.type_text)
            await s.drain(8)
        for i, e in enumerate(exprs):
            if i and args.pause:
                await s.drain(args.pause)
            print('\n>>>', e.strip().replace('\n', ' ')[:120])
            print(json.dumps(await s.eval(e), ensure_ascii=False)[:4000])
    finally:
        await s.stop()

asyncio.run(main())
