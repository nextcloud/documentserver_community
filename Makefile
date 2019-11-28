all: 3rdparty/onlyoffice/documentserver version

clean:
	rm -r 3rdparty/onlyoffice

3rdparty/onlyoffice/documentserver:
	mkdir -p 3rdparty/onlyoffice
	docker create --name oo-extract onlyoffice/documentserver
	docker cp oo-extract:/var/www/onlyoffice/documentserver 3rdparty/onlyoffice
	docker rm oo-extract
	rm -r 3rdparty/onlyoffice/documentserver/server/{SpellChecker,Common,DocService}
	cd 3rdparty/onlyoffice/documentserver/server/FileConverter/bin && \
		../../tools/allfontsgen \
		--input="../../../core-fonts" \
		--allfonts-web="../../../sdkjs/common/AllFonts.js" \
		--allfonts="AllFonts.js" \
		--images="../../../sdkjs/common/Images" \
		--selection="font_selection.bin"
	sed -i 's#../../../../doc/#../../../../../../../doc/#g' 3rdparty/onlyoffice/documentserver/sdkjs/{cell,word,slide}/sdk-all-min.js

version:
	VERSION=$$(grep -ozP "DocsAPI\.DocEditor\.version\s*=\s*function\(\) *\{\n\s+return\s\'\K(\d+.\d+.\d+)" 3rdparty/onlyoffice/documentserver/web-apps/apps/api/documents/api.js) ;\
		sed -i "s/return '[0-9.]*'/return '$$VERSION'/" lib/OnlyOffice/WebVersion.php
