#!/usr/bin/env python3
"""Run the regression suite and say what passed.

Each test is a script that exits non-zero when its verdict is FAIL, so this is
mostly bookkeeping: run them in a deliberate order - the cheap ones that fail
loudest first - keep the output, and report.

    ./run_all.py                 the whole suite
    ./run_all.py autosave leave  only those
    ./run_all.py --list          what there is
"""
import argparse
import os
import subprocess
import sys
import time

HERE = os.path.dirname(os.path.abspath(__file__))

# name -> (script, args, what it covers)
SUITE = [
    ('smoke', 'smoke_test.py', [],
     'every format opens, edits and reaches the file'),
    ('flush-live', 'flush_live_test.py', [],
     'flushing a document somebody is still editing must not empty its change list'),
    ('autosave', 'autosave_test.py', [],
     'edits reach the file while editing, with no cron and no flush'),
    ('leave', 'leave_test.py', [],
     'closing the editor, and closing the tab, save the document'),
    ('twotab', 'twotab_test.py', [],
     'two tabs of one browser, and one of them dropped to view mode'),
    ('coedit', 'coedit_test.py', ['--rounds', '2'],
     'two users see each other typing'),
    ('coedit-xlsx', 'coedit_test.py', ['--kind', 'xlsx', '--rounds', '1', '--tag', 'X'],
     'the same, in a spreadsheet, where changes carry recalc indexes'),
    ('chat', 'chat_test.py', [],
     'the chat panel, including a late joiner reading the backlog'),
]


def main():
    p = argparse.ArgumentParser()
    p.add_argument('names', nargs='*', help='tests to run (default: all)')
    p.add_argument('--list', action='store_true')
    p.add_argument('--keep-going', action='store_true',
                   help='run the rest of the suite after a failure')
    args = p.parse_args()

    if args.list:
        for name, script, _, what in SUITE:
            print(f'{name:12s} {script:22s} {what}')
        return 0

    selected = [t for t in SUITE if not args.names or t[0] in args.names]
    unknown = set(args.names) - {t[0] for t in SUITE}
    if unknown:
        print(f'unknown test(s): {", ".join(sorted(unknown))}')
        return 2

    results = []
    for name, script, extra, what in selected:
        print(f'\n{"=" * 72}\n== {name}: {what}\n{"=" * 72}', flush=True)
        started = time.time()
        code = subprocess.run([sys.executable, os.path.join(HERE, script), *extra]).returncode
        took = time.time() - started
        results.append((name, code, took))
        print(f'-- {name}: {"PASS" if code == 0 else f"FAIL (exit {code})"} in {took:.0f}s',
              flush=True)
        if code != 0 and not args.keep_going:
            print('-- stopping; pass --keep-going to run the rest')
            break

    print(f'\n{"=" * 72}')
    for name, code, took in results:
        print(f'   {"PASS" if code == 0 else "FAIL"}  {name:12s} {took:5.0f}s')
    failed = [name for name, code, _ in results if code != 0]
    skipped = len(selected) - len(results)
    if skipped:
        print(f'   ....  {skipped} not run')
    print('   SUITE:', 'all green' if not failed else 'FAILED: ' + ', '.join(failed))
    return 0 if not failed else 1


if __name__ == '__main__':
    sys.exit(main())
