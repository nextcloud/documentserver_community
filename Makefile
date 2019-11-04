all: 3rdparty/onlyoffice/documentserver

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
