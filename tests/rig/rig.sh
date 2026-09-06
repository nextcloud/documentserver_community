#!/bin/bash
# The one entry point: bring the rig up, provision it, run the tests, tear it
# down. Everything it does is also doable by hand with docker compose and the
# python scripts; this exists so CI and a laptop run the same commands.
#
#   ./rig.sh up            start the containers and wait for Nextcloud
#   ./rig.sh provision     connector, users, documents (writes .rig-env)
#   ./rig.sh reset         drop per-document state between runs
#   ./rig.sh test [names]  run the regression suite, or the named tests
#   ./rig.sh logs          this app's lines from nextcloud.log
#   ./rig.sh down          throw it all away, volumes included
set -uo pipefail
HERE=$(cd "$(dirname "$0")" && pwd)
cd "$HERE"

export RIG_PROJECT=${RIG_PROJECT:-rig}
export RIG_PORT=${RIG_PORT:-8099}
export APP_SOURCE=${APP_SOURCE:-$(cd "$HERE/../.." && pwd)}
APP=${RIG_APP_CONTAINER:-${RIG_PROJECT}-app-1}
BASE=${RIG_BASE:-http://127.0.0.1:${RIG_PORT}}

case "${1:-}" in
up)
	if [ ! -d "$APP_SOURCE/3rdparty/onlyoffice/documentserver" ]; then
		echo "!! $APP_SOURCE/3rdparty/onlyoffice/documentserver is missing."
		echo "   Run 'make' in the app directory first; without the document"
		echo "   server there is nothing to test."
		exit 1
	fi
	docker compose up -d
	echo "==> waiting for $BASE"
	for _ in $(seq 1 120); do
		code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/status.php" || true)
		if [ "$code" = "200" ]; then
			echo "   up: $(curl -s "$BASE/status.php")"
			exit 0
		fi
		sleep 5
	done
	echo "!! Nextcloud did not come up"
	docker compose logs --tail 40 app
	exit 1
	;;
provision)
	exec "$HERE/provision.sh"
	;;
reset)
	exec python3 -c "
import sys; sys.path.insert(0, '$HERE/lib')
import harness; harness.reset(); print('rig state reset')"
	;;
test)
	shift
	exec python3 "$HERE/run_all.py" "$@"
	;;
logs)
	docker exec "$APP" sh -c 'cat /var/www/html/data/nextcloud.log' | python3 -c "
import sys, json
for line in sys.stdin:
    try:
        d = json.loads(line)
    except Exception:
        continue
    app = str(d.get('app')); msg = str(d.get('message'))
    if 'documentserver' in app or 'onlyoffice' in app or 'documentserver' in msg.lower():
        lvl = {0:'DEBUG',1:'INFO',2:'WARN',3:'ERROR',4:'FATAL'}.get(d.get('level'), d.get('level'))
        print(f'[{lvl}] {app}: {msg[:400]}')
        ex = d.get('exception') or {}
        if isinstance(ex, dict) and ex.get('Message'):
            print(f\"        {ex.get('Exception')}: {str(ex.get('Message'))[:300]}\")
" | tail -60
	;;
down)
	docker compose down -v
	rm -f "$HERE/.rig-env"
	;;
*)
	sed -n '2,12p' "$0" | sed 's/^# \?//'
	exit 1
	;;
esac
