"""Talking to the rig from outside the browser: occ, the database, the files.

The tests use these to set the app up, to read the state the app keeps, and to
look at what actually landed in the file - which is the only thing that counts
for the saving tests, since the editor saying "all changes are saved" only ever
meant they reached the server.
"""
import os
import subprocess
import sys
import tempfile

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import config

APP_ID = 'documentserver_community'


def occ(*args, check=False):
    r = subprocess.run(
        ['docker', 'exec', '-u', 'www-data', config.APP_CONTAINER, 'php', 'occ', *args],
        capture_output=True, text=True)
    out = (r.stdout + r.stderr).strip()
    if check and r.returncode != 0:
        raise RuntimeError(f'occ {" ".join(args)} failed: {out}')
    return out


def app_exec(command):
    r = subprocess.run(['docker', 'exec', config.APP_CONTAINER, 'sh', '-c', command],
                       capture_output=True, text=True)
    return r.stdout.strip()


def sql(query):
    r = subprocess.run(
        ['docker', 'exec', config.DB_CONTAINER, 'mariadb',
         f'-u{config.DB_USER}', f'-p{config.DB_PASSWORD}', config.DB_NAME,
         '-N', '-B', '-e', query],
        capture_output=True, text=True)
    return r.stdout.strip()


def set_app_config(key, value):
    """An app config value, and the wait for it to be visible to web requests.

    occ writes it through the local cache the web server does not share, so a
    test that reads it back straight away sees the old one and concludes the
    setting does nothing.
    """
    occ('config:app:set', APP_ID, key, '--value', str(value))


def delete_app_config(key):
    occ('config:app:delete', APP_ID, key)


APP_CONFIG_PROPAGATION = 14  # seconds; see set_app_config


def reset():
    """Drop per-document state so the next run starts from the file on disk."""
    sql('truncate oc_documentserver_sess; truncate oc_documentserver_changes; '
        'truncate oc_documentserver_locks; truncate oc_documentserver_ipc;')
    app_exec('rm -rf /var/www/html/data/appdata_*/documentserver_community/doc_*')
    # appdata was touched behind Nextcloud's back, so resync the file cache or
    # the next conversion fails with 'Could not create path .../Editor.bin'
    occ('files:scan-app-data')
    app_exec(': > /var/www/html/data/nextcloud.log')


def doc_folders():
    """Open documents, ignoring doc_0 - the scratch folder the connector's
    preview conversions go through, which has nothing to do with editing."""
    out = app_exec('ls -d /var/www/html/data/appdata_*/documentserver_community/doc_* '
                   '2>/dev/null')
    return len([line for line in out.split() if not line.endswith('/doc_0')])


def snapshot_state():
    """The {index, time} each open document was last written out at."""
    out = app_exec(
        'for f in /var/www/html/data/appdata_*/documentserver_community/doc_*/snapshot; '
        'do echo "$(dirname $f | sed s#.*/##)=$(cat $f)"; done')
    return out.replace('\n', ' ') or 'none'


def changes():
    return int(sql('select count(*) from oc_documentserver_changes;') or 0)


def sessions():
    return int(sql('select count(*) from oc_documentserver_sess;') or 0)


def drop_sessions():
    sql('delete from oc_documentserver_sess;')


def job_last_run():
    """When the Cleanup background job last ran.

    A rig running background jobs in AJAX mode runs them whenever a browser
    makes a request, and the job writes documents that are being edited too, so
    a test asserting that a file did *not* change has to allow for it.
    """
    return sql("select last_run from oc_jobs where class like "
               "'%DocumentServer%Cleanup';") or '0'


def flush(*options):
    r = subprocess.run(
        ['docker', 'exec', '-u', 'www-data', config.APP_CONTAINER, 'php', 'occ',
         'documentserver:flush', *options],
        capture_output=True, text=True)
    return r.returncode, (r.stdout + r.stderr).strip()


def run_cleanup_job():
    """Run the Cleanup background job once, whatever the cron mode is."""
    job = sql("select id from oc_jobs where class like '%DocumentServer%Cleanup';")
    if not job:
        return 'no cleanup job registered'
    r = subprocess.run(
        ['docker', 'exec', '-u', 'www-data', config.APP_CONTAINER, 'php', 'occ',
         'background-job:execute', job.split()[0], '--force-execute'],
        capture_output=True, text=True)
    return f'exit={r.returncode}'


def download(name, user_pair=None):
    """Fetch a file over WebDAV, returning the local path."""
    user, password = config.credentials(user_pair or config.ADMIN)
    target = os.path.join(tempfile.gettempdir(), f'rig-{user}-{os.path.basename(name)}')
    subprocess.run(['curl', '-s', '-u', f'{user}:{password}',
                    f'{config.BASE}/remote.php/dav/files/{user}/{name}',
                    '-o', target], capture_output=True)
    return target


def file_markers(name, member, markers, user_pair=None):
    """Which of these markers are in the saved file right now."""
    path = download(name, user_pair)
    found = {}
    for marker in markers:
        r = subprocess.run(f'unzip -p {path} {member} 2>/dev/null | grep -c {marker}',
                           shell=True, capture_output=True, text=True)
        found[marker] = (r.stdout.strip() or '0') != '0'
    return found


def app_log(pattern):
    out = app_exec(f"grep -c '{pattern}' /var/www/html/data/nextcloud.log || true")
    return int(out or 0)


def app_log_errors():
    """Error-level log lines from this app, as one string per line."""
    out = app_exec(
        "grep -o '\"level\":[34][^\\n]*documentserver[^\\n]*' "
        "/var/www/html/data/nextcloud.log | tail -20 || true")
    return [line for line in out.split('\n') if line.strip()]
