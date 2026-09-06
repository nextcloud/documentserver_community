app_name=documentserver_community
project_dir=$(CURDIR)/../$(app_name)
build_dir=$(project_dir)/build
appstore_dir=$(build_dir)/appstore
appstore_build_directory=$(CURDIR)/build/artifacts/appstore
appstore_package_name=$(appstore_build_directory)/$(app_name)
package_name=$(app_name)
cert_dir=$(HOME)/.nextcloud/certificates

# Document server release to bundle. Euro-Office is a fork of ONLYOFFICE
# DocumentServer and packages the same tree in the same shape, so one build
# serves either; what differs is the vendor directory inside the package, which
# the steps below locate instead of hardcoding. Override to test another
# release, or to go back to upstream:
#   make ds_version=v9.3.3 3rdparty/onlyoffice/documentserver
#   make ds_repo=ONLYOFFICE/DocumentServer ds_version=v9.4.0 3rdparty/onlyoffice/documentserver
#
# The local directory keeps its name: it is part of the URLs sdkjs requests
# (see appinfo/routes.php and JSSettingsHelper), so renaming it is its own
# change.
ds_repo=Euro-Office/DocumentServer
ds_version=v9.3.4-hotfix.1
oo_dir=$(CURDIR)/3rdparty/onlyoffice/documentserver

all: 3rdparty/onlyoffice/documentserver version

clean:
	rm -rf 3rdparty/onlyoffice
	rm -rf build

appstore:
	make clean
	make all
	rm -rf $(appstore_build_directory)
	mkdir -p $(appstore_build_directory)
	tar czf $(appstore_package_name).tar.gz \
	--exclude-vcs \
	--exclude="../$(app_name)/3rdparty/onlyoffice/documentserver/fonts" \
	--exclude="../$(app_name)/build" \
	--exclude="../$(app_name)/tests" \
	--exclude="../$(app_name)/vendor" \
	--exclude="../$(app_name)/composer.json" \
	--exclude="../$(app_name)/composer.lock" \
	--exclude="../$(app_name)/Makefile" \
	--exclude="../$(app_name)/screenshots" \
	--exclude="../$(app_name)/.*" \
	--exclude="../$(app_name)/krankerl.toml" \
	../$(app_name) \

3rdparty/onlyoffice/documentserver:
	mkdir -p 3rdparty/onlyoffice
	mkdir -p oo-extract
	# Resolve the asset rather than composing its name: ONLYOFFICE publishes
	# one fixed filename per release, while Euro-Office puts the version in the
	# filename and it does not always match the tag (the v9.3.2 release ships a
	# 9.3.1-dev.1 rpm).
	url=$$(curl -sfL $${GITHUB_TOKEN:+-H "Authorization: Bearer $$GITHUB_TOKEN"} \
		https://api.github.com/repos/$(ds_repo)/releases/tags/$(ds_version) \
		| grep -o 'https://[^"]*x86_64[.]rpm' | head -1); \
	if [ -z "$$url" ]; then echo "no x86_64 rpm asset for $(ds_repo) $(ds_version)" >&2; exit 1; fi; \
	echo "fetching $$url"; \
	curl -sL -o documentserver.x86_64.rpm "$$url"
	cd oo-extract && rpm2cpio ../documentserver.x86_64.rpm | cpio -idm
	chmod -R 777 oo-extract/
	# var/www/<vendor>/documentserver: "onlyoffice" upstream, "euro-office"
	# on the fork.
	src=$$(find oo-extract/var/www -mindepth 2 -maxdepth 2 -type d -name documentserver | head -1); \
	if [ -z "$$src" ]; then echo 'no documentserver tree in the package' >&2; exit 1; fi; \
	cp -r "$$src" 3rdparty/onlyoffice
	# Up to 8.x the package kept the converter's shared libraries in
	# /usr/lib64 and expected the distro to place them on the library path;
	# since 9.x they ship inside FileConverter/bin instead, so only copy them
	# when that directory is actually there.
	bash -c 'if [ -d oo-extract/usr/lib64 ]; then \
		cp oo-extract/usr/lib64/* 3rdparty/onlyoffice/documentserver/server/FileConverter/bin/; \
		cp oo-extract/usr/lib64/* 3rdparty/onlyoffice/documentserver/server/tools/; fi'
	rm -f documentserver.x86_64.rpm
	bash -c 'rm -rf 3rdparty/onlyoffice/documentserver/server/{Common/config/*,DocService,Metrics}'
	# npm and Metrics belong to the Node DocService, which we replace with
	# the PHP implementation and never ship, so they are dead weight.
	rm -rf 3rdparty/onlyoffice/documentserver/npm
	# Every language's help images are served from the english set (see
	# StaticController::thirdparty), so ship only those. The hardcoded
	# language list this replaces had gone stale as upstream added pt, tr
	# and sr-Latn, leaving 200+ MB of screenshots nothing can request.
	bash -c 'for d in 3rdparty/onlyoffice/documentserver/web-apps/apps/*/main/resources/help/*/images; do \
		case "$$d" in */help/en/images) ;; *) rm -rf "$$d" ;; esac; done'
	# Same story as the tree above: etc/<vendor>/documentserver/default.json.
	cfg=$$(find oo-extract/etc -mindepth 3 -maxdepth 3 -path '*/documentserver/default.json' | head -1); \
	if [ -z "$$cfg" ]; then echo 'no default.json in the package' >&2; exit 1; fi; \
	cp "$$cfg" 3rdparty/onlyoffice/documentserver/server/Common/config/
	# Since 8.x the package ships web-apps api.js as an empty file plus an
	# api.js.tpl that DocService normally renders at start-up. We do not ship
	# DocService, so render it here. {{HASH_POSTFIX}} is left in place on
	# purpose: extendAppPath() only inserts the "/<version>-<hash>" cache
	# busting path segment once that placeholder is substituted, and we serve
	# the web-apps tree unversioned.
	bash -c 'if [ ! -s 3rdparty/onlyoffice/documentserver/web-apps/apps/api/documents/api.js ] && \
		[ -f 3rdparty/onlyoffice/documentserver/web-apps/apps/api/documents/api.js.tpl ]; then \
		cp 3rdparty/onlyoffice/documentserver/web-apps/apps/api/documents/api.js.tpl \
		   3rdparty/onlyoffice/documentserver/web-apps/apps/api/documents/api.js; fi'
	rm -rf oo-extract
	jq '.services.CoAuthoring.autoAssembly.enable = "true"' 3rdparty/onlyoffice/documentserver/server/Common/config/default.json > tmp.$$.json
	mv tmp.$$.json 3rdparty/onlyoffice/documentserver/server/Common/config/default.json
	# Since 9.x the converter's shared libraries only ship in
	# FileConverter/bin (they used to come from /usr/lib64 and get copied next
	# to the binary), so allfontsgen needs them on its library path. Paths stay
	# relative to server/tools: 9.x resolves relative arguments against the
	# binary's own directory, not the working directory.
	cd 3rdparty/onlyoffice/documentserver/server/tools && \
		LD_LIBRARY_PATH=../FileConverter/bin ./allfontsgen \
		--input="../../core-fonts" \
		--allfonts-web="../../sdkjs/common/AllFonts.js" \
		--allfonts="../FileConverter/bin/AllFonts.js" \
		--images="../../sdkjs/common/Images" \
		--output-web="../../fonts" \
		--selection="../FileConverter/bin/font_selection.bin"
	# Pin the generated font paths to x2t's working directory
	# (server/FileConverter/bin). allfontsgen writes them out prefixed with its
	# own working directory, which is neither where x2t runs nor the same path
	# on the machine that installs the app; x2t then hands a null buffer to
	# CFontFileLoader.LoadFontFromData and any change replay that has to measure
	# text - saving a spreadsheet, for one - dies with "Cannot read property
	# 'length' of null". The paths in font_selection.bin alongside it are not
	# used for loading, so they can stay as generated.
	sed -i 's|"[^"]*/core-fonts/|"../../../core-fonts/|g' \
		3rdparty/onlyoffice/documentserver/server/FileConverter/bin/AllFonts.js
	# Build the presentation design themes. Another job the DocService we do not
	# ship normally does: the package only carries their sources under
	# sdkjs/slide/themes/src, so without this the presentation editor's design
	# gallery is empty and it 404s on sdkjs/slide/themes/themes.js. Runs from
	# FileConverter/bin, both for the converter libraries and so the font paths
	# pinned just above resolve while it renders the thumbnails.
	cd 3rdparty/onlyoffice/documentserver/server/FileConverter/bin && \
		LD_LIBRARY_PATH=. ../../tools/allthemesgen \
		--converter-dir="$(oo_dir)/server/FileConverter/bin" \
		--src="$(oo_dir)/sdkjs/slide/themes"
	# only input for the generator above
	rm -rf 3rdparty/onlyoffice/documentserver/sdkjs/slide/themes/src
	sed -i 's/if(yb===d\[a\].ka)/if(d[a]\&\&yb===d[a].ka)/' 3rdparty/onlyoffice/documentserver/sdkjs/*/sdk-all.js || true

version:
	VERSION=$$(grep -ozP "DocsAPI\.DocEditor\.version\s*=\s*function\(\) *\{\n\s+return\s\'\K(\d+.\d+.\d+)" 3rdparty/onlyoffice/documentserver/web-apps/apps/api/documents/api.js) ;\
		sed -i "s/return '[0-9.]*'/return '$$VERSION'/" lib/OnlyOffice/WebVersion.php
