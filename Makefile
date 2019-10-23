all: 3rdparty/onlyoffice/documentserver

3rdparty/onlyoffice/documentserver:
	mkdir -p 3rdparty/onlyoffice
	docker create --name oo-extract onlyoffice/documentserver
	docker cp oo-extract:/var/www/onlyoffice/documentserver 3rdparty/onlyoffice
	docker rm oo-extract
	rm -r 3rdparty/onlyoffice/documentserver/server/{SpellChecker,Common,DocService}
	sed -i 's#/var/www/onlyoffice/documentserver/#../../../#g' 3rdparty/onlyoffice/documentserver/server/FileConverter/bin/AllFonts.js
	sed -i 's#/usr/share/fonts/truetype/#../../../core-fonts/#g' 3rdparty/onlyoffice/documentserver/server/FileConverter/bin/AllFonts.js
