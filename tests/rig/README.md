# The test rig

A disposable Nextcloud with this app mounted live, and a set of regression tests
that drive real editors in headless chromium.

They drive editors because that is where the bugs were. This app is one half of
a conversation with sdkjs: what matters is not whether a message was sent but
what the other end does with it, and every one of the tests here exists because
something looked right on the wire and was wrong on the screen — changes
buffered forever behind a missing flag, a document written to a file nobody
reads, a session ended under an editor that was still open. So each test asks
the editor's own document model what it displays, or reads the saved file, and
never settles for "the request returned 200".

## Running it

Needs docker, python3 with `websockets` and `requests`, chromium, and the
document server tree in place:

    make                        # in the app directory, once: downloads the document server
    pip install websockets requests

    cd tests/rig
    ./rig.sh up                 # containers, and wait for Nextcloud
    ./rig.sh provision          # connector, users, one document per format
    ./rig.sh test               # the whole suite
    ./rig.sh test autosave      # or just one, see ./run_all.py --list
    ./rig.sh logs               # this app's lines from nextcloud.log
    ./rig.sh down               # throw it all away

Knobs, all environment variables: `RIG_PROJECT` (compose project, so a second
rig can run alongside), `RIG_PORT`, `RIG_NC_IMAGE`, `RIG_CONNECTOR_VERSION`,
`APP_SOURCE` (which working copy to mount). `provision.sh` writes what it
created — users, documents, file ids — to `.rig-env`, which the tests read, so
nothing about a particular instance is hardcoded in a test.

## What each test covers

| test | covers |
| --- | --- |
| `smoke` | every format opens with no JS exception or failed request, takes an edit, and that edit is in the file after a flush. Catches the whole class of "the editor does not come up": an unrendered `api.js`, stylesheets killed by the CSP nonce, fonts the converter cannot find, appdata the file cache never heard about |
| `flush-live` | flushing a document somebody is still editing writes the file and leaves the change list and the document folder alone. The change list is the only record of what was typed — `Editor.bin` stays at the version the document was opened at — so consuming it mid-session strands the document |
| `autosave` | edits reach the file while the document is open, with no cron and no flush; a save inside the interval does not re-assemble the document; `autosave_interval 0` turns it off |
| `leave` | closing the editor and closing the tab both write the document out and dispose of it, one participant leaving does not end the document for the other, and reopening shows all of it |
| `twotab` | two tabs of *one* browser — which share a cookie jar, a Nextcloud session and a connection pool, as two separate browsers do not — see each other type; and one of them dropped to view mode (`asc_coAuthoringDisconnect`, which is what sdkjs does on a licence verdict or a rights change) does not take the document away from the other |
| `coedit` | two users in one document each see what the other types, in a text document and in a spreadsheet, with one socket session per browser — a second one means the transport reconnected mid-edit |
| `chat` | the chat panel carries messages both ways, and a late joiner reads the backlog |

## Things that will mislead you

- **The sample documents keep what earlier runs typed into them.** Every test
  suffixes its markers with a per-run number for that reason. A test that looks
  for a fixed marker will pass on a leftover from an hour ago.
- **A rig with background jobs in AJAX mode runs them whenever a browser makes a
  request**, and the Cleanup job writes documents that are being edited. Any
  assertion that a file did *not* change has to allow for that; `autosave_test`
  checks whether the job ran in the window it is measuring.
- **The `wss://…transport=websocket` 400 in the console is deliberate.** PHP
  cannot upgrade, and the 400 is what makes the client fall back to long
  polling.
- An app config value written with `occ` takes a few seconds to be visible to
  web requests, which is what `harness.APP_CONFIG_PROPAGATION` waits out.
- Deleting appdata behind Nextcloud's back leaves stale file cache rows and the
  next conversion fails with `Could not create path .../Editor.bin`;
  `harness.reset()` follows it with `files:scan-app-data` for that reason.

## Layout

    rig.sh              up / provision / reset / test / logs / down
    provision.sh        connector, second user, a document per format, .rig-env
    docker-compose.yml  Nextcloud + MariaDB, this working copy mounted as an app
    run_all.py          the suite runner, and what each test covers
    lib/config.py       where the rig is and what is in it
    lib/harness.py      occ, the database, the appdata, the saved files
    lib/driver.py       a headless chromium per session, over CDP
    probe.py            poke at one live editor by hand, for when a test fails
