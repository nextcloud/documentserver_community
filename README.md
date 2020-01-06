# Community Documentserver

## Requirements

The community document server only supports running on x86-64 Linux servers.

When installing from git `make` and `docker` are required.

## Configuring OnlyOffice

The community documentserver will automatically configure itself if no other document server is configured in the onlyoffice settings ("Document Editing Service address" is empty).
All other "Server settings" should be left empty.

## Setup from git

- clone the repo into the Nextcloud app directory 
- run `make` in the app folder to download the 3rdparty components
- Enable the app
