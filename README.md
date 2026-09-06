# Community Documentserver

This is a easy way to get ONLYOFFICE integrated in Nextcloud. It is developed purely as a way for home users to not have to deal with docker images, reverse proxies and other things. It is not aimed at anything beyond that - if you need it to scale, use the docker image, packages or other methods, those will always be faster and more scalable.

The work on this was sponsored by Nextcloud GmbH for the private home user community. There is no commercial support available and there will not be.

## Requirements
The community document server only supports running on x86-64 Linux servers using glibc based distributions.
To get it running, you also need to install the [ONLYOFFICE](https://apps.nextcloud.com/apps/onlyoffice) app.

We'd like to also support ARM devices like the Raspberry Pi in the future.

## Configuring OnlyOffice

The community documentserver will automatically configure itself if no other document server is configured in the onlyoffice settings ("Document Editing Service address" is empty).
All other "Server settings" should be left empty.

If autoconfiguration fails for any reason, you may manually enter the url. Log in as the admin and go to Settings > ONLYOFFICE. For the ONLYOFFICE Docs address, enter the value in the format of `https://<nextcloud_server>/apps/documentserver_community/`.

## Update

After community document server update and any related app (OnlyOffice app for example), you should clear your browser cache to get newer version running.

## Saving documents

Documents are written back to Nextcloud:

- while they are being edited, at most once a minute;
- as soon as the last person closes the document;
- and by the background job, which catches anything the first two missed - a
  browser that crashed, a server restarted mid-edit - so a working cron is still
  worth having.

The periodic write is what bounds how much can be lost when a browser or the
server goes away. It costs one document assembly per interval per document being
edited, however much is typed in between, and none at all while nobody is
typing. To change the interval:

    occ config:app:set documentserver_community autosave_interval --value=120

`0` turns it off, leaving the document to be written when everybody has left.
`occ documentserver:flush` writes out everything that is open, whether or not
anybody is still editing it.

## Adding fonts

You can add custom fonts to the document server using the following occ commands

- Add font by path `occ documentserver:fonts --add /usr/share/fonts/myfont.ttf`
- List added fonts `occ documentserver:fonts`
- Remove an added font `occ documentserver:fonts --remove myfont.ttf`

## Self signed certificates

If your nextcloud is using a self signed certificate for https, you'll need to import the certificate into nextcloud's certificate store to make the documentserver work.

    occ security:certificates:import /path/to/certificate.crt

## SELinux

If you're using SELinux you'll need to configure it to allow executing binaries from the `documentserver_community/3rdparty` directory, for example:

```
semanage fcontext -a -t httpd_sys_script_exec_t '/var/www/html/nextcloud/apps/documentserver_community/3rdparty/onlyoffice/documentserver(/.*)?'
restorecon -R -v /var/www/html/nextcloud
```

Specific commands and paths will differ based on your specific setup.

## Tests

`tests/rig` is a disposable Nextcloud with this app in it and a set of
regression tests that drive real editors in headless chromium — they open
documents, type into them, and then ask the editor what it displays and the
saved file what it kept. See its README; the short version is:

    make                     # once: the document server this all runs against
    cd tests/rig
    ./rig.sh up && ./rig.sh provision
    ./rig.sh test

`tests/` also holds the unit tests, which run against a Nextcloud checkout:

    vendor-bin/phpunit/vendor/bin/phpunit -c apps/documentserver_community/tests/phpunit.xml

Both run in CI on every push, along with php, javascript and shell linting.

## Setup from git

When installing from git `make`, `curl`, `rpm2cpio`, and `cpio` are required.

- clone the repo into the Nextcloud app directory 
- run `make` in the app folder to download the 3rdparty components
- Enable the app

# OnlyOffice components

This app includes components from OnlyOffice to do a large part of the work.
While building the app, these components are copied over from the official OnlyOffice documentserver docker image (see `Makefile`).
The source for this can be found at the [OnlyOffice](https://github.com/ONLYOFFICE) github,
primarily the [web-apps](https://github.com/ONLYOFFICE/web-apps), [sdkjs](https://github.com/ONLYOFFICE/sdkjs) and [core](https://github.com/ONLYOFFICE/core) repositories.

These components are licenced under AGPL-3.0 with their copyright belonging to the OnlyOffice team.
