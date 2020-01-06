// suppress "ONLYOFFICE cannot be reached"
DocsAPI = {};
DocsAPI.DocEditor = function() {};

document.addEventListener("DOMContentLoaded", function() {
	OC.Notification.show(t("documentserver", "The community document server only supports up to 20 concurrent sessions"), {
		type: "error"
	});
});
