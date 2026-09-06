#!/usr/bin/env python3
"""Open one document in two live sessions and report what each session receives.

Drives two headless chromium instances over CDP, logs a different user into
each, opens the same file in both, types in each, and then reads the Engine.IO
long-poll response bodies to see which socket messages actually reached whom.
"""
import argparse, asyncio, os, re, sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'lib'))
import config
from driver import Session, COAUTH_JS, DISMISS_JS, PROBE_JS, TEXT_JS


async def main():
    p = argparse.ArgumentParser()
    p.add_argument('--base', default=config.BASE)
    p.add_argument('--a', default=config.ADMIN)
    p.add_argument('--b', default=config.USER2)
    p.add_argument('--kind', default='docx', choices=sorted(config.DOCUMENTS))
    p.add_argument('--fileid', default=None)
    p.add_argument('--out', default='/tmp/coedit')
    p.add_argument('--chromium', default=config.CHROMIUM)
    p.add_argument('--rounds', type=int, default=1)
    p.add_argument('--tag', default='',
                   help='prefix for the typed markers, so a saved file can be checked '
                        'for this run rather than a previous one')
    args = p.parse_args()
    os.makedirs(args.out, exist_ok=True)
    if not args.fileid:
        args.fileid = config.document(args.kind)['fileid']

    ua, pa = args.a.split(':', 1)
    ub, pb = args.b.split(':', 1)
    A = Session('A', 9222, ua, pa, args.base, args.fileid, args.chromium, (420, 400), (20, 60))
    B = Session('B', 9333, ub, pb, args.base, args.fileid, args.chromium, (420, 470), (20, 66))

    verdict = {'ok': False}
    try:
        await A.start(); await B.start()
        print(f'==> opening file {args.fileid} as {ua} (A) and {ub} (B)')
        await A.login_and_open(); await A.drain(25)
        await B.login_and_open(); await B.drain(25)
        print('   A where:', await A.where())
        print('   B where:', await B.where())
        print('   A state:', await A.eval(PROBE_JS))
        print('   B state:', await B.eval(PROBE_JS))

        print('   A overlays:', await A.eval(DISMISS_JS))
        print('   B overlays:', await B.eval(DISMISS_JS))
        await A.send('Input.dispatchKeyEvent', type='rawKeyDown', key='Escape',
                     code='Escape', windowsVirtualKeyCode=27, nativeVirtualKeyCode=27)
        await B.send('Input.dispatchKeyEvent', type='rawKeyDown', key='Escape',
                     code='Escape', windowsVirtualKeyCode=27, nativeVirtualKeyCode=27)
        await A.drain(2); await B.drain(2)

        markers = []
        for r in range(1, args.rounds + 1):
            print(f'==> round {r}: A types')
            await A.type(f'{args.tag}A{r} ', row_offset=r - 1)
            await A.drain(12); await B.drain(12)
            print(f'==> round {r}: B types')
            await B.type(f'{args.tag}B{r} ', row_offset=r - 1)
            await B.drain(12); await A.drain(12)
            markers += [f'{args.tag}A{r}', f'{args.tag}B{r}']
            print(f'   round {r} A co-auth:', await A.eval(COAUTH_JS))
            print(f'   round {r} B co-auth:', await B.eval(COAUTH_JS))

        print('\n===== LIVE PROPAGATION =====')
        text = {}
        for s_ in (A, B):
            text[s_.name] = await s_.eval(TEXT_JS)
            print(f'   {s_.name} sees: {(text[s_.name] or "")[:220]!r}')
        ok = True
        for s_ in (A, B):
            missing = [m for m in markers if m not in (text[s_.name] or '')]
            if missing:
                ok = False
            print(f'   {s_.name}: missing markers {missing}' if missing
                  else f'   {s_.name}: all {len(markers)} markers present')
        print('   VERDICT:', 'live co-authoring OK' if ok else 'FAIL - changes did not propagate')
        verdict['ok'] = ok

        print('   A state:', await A.eval(PROBE_JS))
        print('   B state:', await B.eval(PROBE_JS))
        for s in (A, B):
            types, raw = await s.messages()
            sids = sorted(set(re.findall(r'"type":"auth","result":1,"sessionId":"([0-9a-f]+)"', ' '.join(raw))))
            print(f'\n>>> session {s.name} ({s.user}): {len(sids)} socket session(s) established '
                  f'-> {sids}  (more than one means it reconnected mid-edit)')
            print(f'\n===== session {s.name} ({s.user}) received =====')
            for t, n in sorted(types.items(), key=lambda kv: -kv[1]):
                print(f'   {n:3d}  {t}')
            for r in raw:
                if any(k in r for k in ('connectState', 'saveChanges', 'savePartChanges', 'cursor', 'participant')):
                    print('   msg:', r[:200])
            sent = [p for p in s.posts if 'saveChanges' in p]
            print(f'   sent {len(sent)} saveChanges command(s)')
            for p in sent[:6]:
                m = re.search(r'"startSaveChanges":(\w+).*?"endSaveChanges":(\w+)', p)
                start, end = (m.group(1), m.group(2)) if m else ('?', '?')
                print(f'   sent: start={start} end={end} bytes={len(p)}')
            await s.shot(os.path.join(args.out, f'{s.name}.png'))
    finally:
        await A.stop(); await B.stop()

    return 0 if verdict['ok'] else 1


if __name__ == '__main__':
    sys.exit(asyncio.run(main()))
