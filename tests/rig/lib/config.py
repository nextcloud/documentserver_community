"""Where the rig is and what is in it.

Everything is overridable from the environment, and `provision.sh` writes what
it created into `.rig-env` next to this package, so the tests do not carry a
particular instance's file ids around in their defaults.
"""
import os

HERE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
ENV_FILE = os.path.join(HERE, '.rig-env')


def _load_env_file():
    """Read .rig-env into the environment, without overriding what is set."""
    try:
        with open(ENV_FILE) as f:
            lines = f.readlines()
    except OSError:
        return
    for line in lines:
        line = line.strip()
        if not line or line.startswith('#') or '=' not in line:
            continue
        key, value = line.split('=', 1)
        os.environ.setdefault(key.strip(), value.strip().strip('"\''))


_load_env_file()


def _env(name, default):
    value = os.environ.get(name)
    return value if value else default


BASE = _env('RIG_BASE', 'http://127.0.0.1:8099')
PROJECT = _env('RIG_PROJECT', 'rig')
APP_CONTAINER = _env('RIG_APP_CONTAINER', f'{PROJECT}-app-1')
DB_CONTAINER = _env('RIG_DB_CONTAINER', f'{PROJECT}-db-1')

DB_NAME = _env('RIG_DB_NAME', 'nextcloud')
DB_USER = _env('RIG_DB_USER', 'nextcloud')
DB_PASSWORD = _env('RIG_DB_PASSWORD', 'rigpass')

ADMIN = _env('RIG_ADMIN', 'admin:rigadmin')
USER2 = _env('RIG_USER2', 'bob:Kq7-rigTest-3910')

CHROMIUM = _env('RIG_CHROMIUM', '/usr/bin/chromium')

# The documents provisioning uploaded, and where the text typed into each of
# them ends up inside the saved file.
DOCUMENTS = {
    'docx': {
        'name': _env('RIG_FILE_DOCX', 'sample.docx'),
        'fileid': _env('RIG_FILEID_DOCX', ''),
        'member': 'word/document.xml',
    },
    'xlsx': {
        'name': _env('RIG_FILE_XLSX', 'sample.xlsx'),
        'fileid': _env('RIG_FILEID_XLSX', ''),
        'member': 'xl/sharedStrings.xml',
    },
    'pptx': {
        'name': _env('RIG_FILE_PPTX', 'sample.pptx'),
        'fileid': _env('RIG_FILEID_PPTX', ''),
        'member': 'ppt/slides/slide1.xml',
    },
}


def document(kind='docx'):
    doc = DOCUMENTS[kind]
    if not doc['fileid']:
        raise SystemExit(
            f"no file id for the {kind} document: run ./rig.sh provision first, "
            f"or set RIG_FILEID_{kind.upper()}")
    return doc


def credentials(pair):
    user, password = pair.split(':', 1)
    return user, password
