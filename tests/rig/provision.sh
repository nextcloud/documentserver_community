#!/bin/bash
# Turn a freshly started rig into one the tests can run against: the connector
# installed, this app enabled, a second user to co-author with, and a document
# of each format to edit - shared with that second user, because without the
# share the second session gets "file not found" and a co-authoring run quietly
# degrades to one user.
#
# What it creates is written to .rig-env, which the tests read, so no file id
# ends up hardcoded in a test.
set -uo pipefail
HERE=$(cd "$(dirname "$0")" && pwd)

RIG_PROJECT=${RIG_PROJECT:-rig}
APP=${RIG_APP_CONTAINER:-${RIG_PROJECT}-app-1}
PORT=${RIG_PORT:-8099}
BASE=${RIG_BASE:-http://127.0.0.1:${PORT}}
ADMIN_USER=${RIG_ADMIN_USER:-admin}
ADMIN_PASSWORD=${RIG_ADMIN_PASSWORD:-rigadmin}
USER2_NAME=${RIG_USER2_NAME:-bob}
USER2_PASSWORD=${RIG_USER2_PASSWORD:-Kq7-rigTest-3910}
# Pinned rather than "whatever the app store has today": a connector upgrade
# changing how the editor is embedded is a thing to find out about deliberately.
CONNECTOR_VERSION=${RIG_CONNECTOR_VERSION:-10.1.2}

occ() { docker exec -u www-data "$APP" php occ "$@"; }
in_app() { docker exec "$APP" sh -c "$*"; }

echo "==> waiting for Nextcloud to finish installing"
for _ in $(seq 1 120); do
	if occ status 2>/dev/null | grep -q "installed: true"; then break; fi
	sleep 5
done
occ status | sed 's/^/   /'

echo "==> installing the ONLYOFFICE connector ${CONNECTOR_VERSION}"
if in_app "test -d /var/www/html/custom_apps/onlyoffice"; then
	echo "   already there"
else
	in_app "curl -fsSL -o /tmp/onlyoffice.tar.gz \
		https://github.com/ONLYOFFICE/onlyoffice-nextcloud/releases/download/v${CONNECTOR_VERSION}/onlyoffice.tar.gz \
		&& tar xzf /tmp/onlyoffice.tar.gz -C /var/www/html/custom_apps \
		&& chown -R www-data:www-data /var/www/html/custom_apps/onlyoffice \
		&& rm -f /tmp/onlyoffice.tar.gz" || exit 1
fi
occ app:enable onlyoffice | sed 's/^/   /'
occ app:enable documentserver_community | sed 's/^/   /'

# Point the connector at this app explicitly rather than leaving it to
# auto-configuration: run from occ, that picks the url up from
# overwrite.cli.url, which is not necessarily the host the browser uses - and
# an editor iframe on a different origin than the page cannot be framed by it,
# nor read by the tests.
occ config:app:set onlyoffice DocumentServerUrl --value "${BASE}/apps/documentserver_community/" | sed 's/^/   /'

echo "==> the app's own setup check"
in_app "php -r '
	\$x2t = \"/var/www/html/custom_apps/documentserver_community/3rdparty/onlyoffice/documentserver/server/FileConverter/bin/x2t\";
	echo file_exists(\$x2t) ? \"   x2t present\n\" : \"   x2t MISSING - run make in the app directory\n\";
'"

echo "==> creating ${USER2_NAME}"
if occ user:info "$USER2_NAME" >/dev/null 2>&1; then
	echo "   already there"
else
	docker exec -u www-data -e OC_PASS="$USER2_PASSWORD" "$APP" \
		php occ user:add --password-from-env "$USER2_NAME" | sed 's/^/   /'
fi

# The blank documents the bundled server ships for "new file" are the fixtures:
# every format, tiny, and by definition ones this document server can open.
TEMPLATES=/var/www/html/custom_apps/documentserver_community/3rdparty/onlyoffice/documentserver/document-templates/new/en-US
echo "==> uploading a document of each format"
: > "$HERE/.rig-env"
{
	echo "# written by provision.sh, read by tests/rig/lib/config.py"
	echo "RIG_BASE=$BASE"
	echo "RIG_PROJECT=$RIG_PROJECT"
	echo "RIG_ADMIN=${ADMIN_USER}:${ADMIN_PASSWORD}"
	echo "RIG_USER2=${USER2_NAME}:${USER2_PASSWORD}"
} >> "$HERE/.rig-env"

for kind in docx xlsx pptx; do
	name="sample.${kind}"
	in_app "cp ${TEMPLATES}/new.${kind} /tmp/${name}" || {
		echo "   FAILED to find ${TEMPLATES}/new.${kind}"; exit 1; }
	docker cp "$APP:/tmp/${name}" "/tmp/${name}" >/dev/null

	curl -s -u "${ADMIN_USER}:${ADMIN_PASSWORD}" -T "/tmp/${name}" \
		"${BASE}/remote.php/dav/files/${ADMIN_USER}/${name}" >/dev/null

	# share with the second user, so co-authoring runs have two real
	# participants
	curl -s -u "${ADMIN_USER}:${ADMIN_PASSWORD}" -X POST \
		-H "OCS-APIRequest: true" \
		-d "path=/${name}" -d "shareType=0" -d "shareWith=${USER2_NAME}" -d "permissions=31" \
		"${BASE}/ocs/v2.php/apps/files_sharing/api/v1/shares" >/dev/null

	fileid=$(curl -s -u "${ADMIN_USER}:${ADMIN_PASSWORD}" -X PROPFIND \
		-H "Depth: 0" -H "Content-Type: text/xml" \
		--data '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>' \
		"${BASE}/remote.php/dav/files/${ADMIN_USER}/${name}" \
		| grep -o '<oc:fileid>[0-9]*</oc:fileid>' | grep -o '[0-9]*' | head -1)

	if [ -z "$fileid" ]; then
		echo "   FAILED to upload ${name}"
		exit 1
	fi
	echo "   ${name} -> fileid ${fileid}"
	echo "RIG_FILEID_$(echo "$kind" | tr '[:lower:]' '[:upper:]')=${fileid}" >> "$HERE/.rig-env"
	echo "RIG_FILE_$(echo "$kind" | tr '[:lower:]' '[:upper:]')=${name}" >> "$HERE/.rig-env"
	rm -f "/tmp/${name}"
done

echo "==> document server url the connector will use"
occ config:app:get onlyoffice DocumentServerUrl | sed 's/^/   /'

echo
echo "provisioned; wrote $HERE/.rig-env"
cat "$HERE/.rig-env" | sed 's/^/   /'
