app_name=documentserver_community
project_dir=$(CURDIR)/../$(app_name)
build_dir=$(project_dir)/build
appstore_dir=$(build_dir)/appstore
sign_dir=$(build_dir)/sign
package_name=$(app_name)
cert_dir=$(HOME)/.nextcloud/certificates

all: 3rdparty/onlyoffice/documentserver version

clean:
	rm -rf 3rdparty/onlyoffice
	rm -rf build

3rdparty/onlyoffice/documentserver:
	mkdir -p 3rdparty/onlyoffice
	docker create --name oo-extract onlyoffice/documentserver:7.2.1
	docker cp oo-extract:/var/www/onlyoffice/documentserver 3rdparty/onlyoffice
	docker rm oo-extract
	rm -rf 3rdparty/onlyoffice/documentserver/server/{Common,DocService}
	cd 3rdparty/onlyoffice/documentserver/server/FileConverter/bin && \
		../../tools/allfontsgen \
		--input="../../../core-fonts" \
		--allfonts-web="../../../sdkjs/common/AllFonts.js" \
		--allfonts="AllFonts.js" \
		--images="../../../sdkjs/common/Images" \
		--output-web="../../../fonts" \
		--selection="font_selection.bin"
	sed -i 's/if(yb===d\[a\].ka)/if(d[a]\&\&yb===d[a].ka)/' 3rdparty/onlyoffice/documentserver/sdkjs/*/sdk-all.js

version:
	VERSION=$$(grep -ozP "DocsAPI\.DocEditor\.version\s*=\s*function\(\) *\{\n\s+return\s\'\K(\d+.\d+.\d+)" 3rdparty/onlyoffice/documentserver/web-apps/apps/api/documents/api.js) ;\
		sed -i "s/return '[0-9.]*'/return '$$VERSION'/" lib/OnlyOffice/WebVersion.php
