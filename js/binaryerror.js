// suppress "ONLYOFFICE cannot be reached"
DocsAPI = {};
DocsAPI.DocEditor = function() {};

document.addEventListener("DOMContentLoaded", function() {
	OC.Notification.show(t("documentserver", "Community document server is not supported for this instance, please setup and configure an external document server"), {
		type: "error"
	});
});
