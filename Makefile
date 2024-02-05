app_name=documentserver_community
project_dir=$(CURDIR)/../$(app_name)
build_dir=$(project_dir)/build
appstore_build_directory=$(CURDIR)/build/artifacts/appstore
appstore_package_name=$(appstore_build_directory)/$(app_name)
sign_dir=$(build_dir)/sign
package_name=$(app_name)
cert_dir=$(HOME)/.nextcloud/certificates

all: 3rdparty/onlyoffice/documentserver version

clean:
	rm -rf 3rdparty/onlyoffice
	rm -rf build

appstore:
	make clean
	make all
	rm -rf $(appstore_build_directory)
	mkdir -p $(appstore_build_directory)
	tar cvzf $(appstore_package_name).tar.gz \
	--exclude-vcs \
	--exclude="../$(app_name)/build" \
	--exclude="../$(app_name)/tests" \
	--exclude="../$(app_name)/Makefile" \
	--exclude="../$(app_name)/screenshots" \
	--exclude="../$(app_name)/.*" \
	--exclude="../$(app_name)/krankerl.toml" \

3rdparty/onlyoffice/documentserver:
	mkdir -p 3rdparty/onlyoffice
	mkdir -p oo-extract
	curl -sLO https://github.com/ONLYOFFICE/DocumentServer/releases/download/v7.2.2/onlyoffice-documentserver.x86_64.rpm
	cd oo-extract && rpm2cpio ../onlyoffice-documentserver.x86_64.rpm | cpio -idm
	chmod -R 777 oo-extract/
	cp -r oo-extract/var/www/onlyoffice/documentserver 3rdparty/onlyoffice
	cp oo-extract/usr/lib64/* 3rdparty/onlyoffice/documentserver/server/FileConverter/bin/
	cp oo-extract/usr/lib64/* 3rdparty/onlyoffice/documentserver/server/tools/
	rm -rf oo-extract
	rm -f onlyoffice-documentserver.x86_64.rpm
	bash -c 'rm -rf 3rdparty/onlyoffice/documentserver/server/{Common,DocService}'
	bash -c 'rm -rf 3rdparty/onlyoffice/documentserver/web-apps/apps/*/main/resources/help/{de,es,fr,it,ru}/images'
	cd 3rdparty/onlyoffice/documentserver/server/tools && \
		./allfontsgen \
		--input="../../core-fonts" \
		--allfonts-web="../../sdkjs/common/AllFonts.js" \
		--allfonts="../FileConverter/bin/AllFonts.js" \
		--images="../../sdkjs/common/Images" \
		--output-web="../../fonts" \
		--selection="../FileConverter/bin/font_selection.bin"
	sed -i 's/if(yb===d\[a\].ka)/if(d[a]\&\&yb===d[a].ka)/' 3rdparty/onlyoffice/documentserver/sdkjs/*/sdk-all.js

version:
	VERSION=$$(grep -ozP "DocsAPI\.DocEditor\.version\s*=\s*function\(\) *\{\n\s+return\s\'\K(\d+.\d+.\d+)" 3rdparty/onlyoffice/documentserver/web-apps/apps/api/documents/api.js) ;\
		sed -i "s/return '[0-9.]*'/return '$$VERSION'/" lib/OnlyOffice/WebVersion.php
