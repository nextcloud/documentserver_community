// suppress "ONLYOFFICE cannot be reached"
DocsAPI = {};
DocsAPI.DocEditor = function() {};

document.addEventListener("DOMContentLoaded", function() {
	OC.Notification.show(t("documentserver", "3rdparty dependencies not setup, please run `make`"), {
		type: "error"
	});
});
