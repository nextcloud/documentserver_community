// suppress "ONLYOFFICE cannot be reached"
DocsAPI = {};
DocsAPI.DocEditor = function() {};
const hint = '__HINT__';

document.addEventListener("DOMContentLoaded", function() {
	if (hint) {
		OC.Notification.show(hint, {
			type: "error"
		});
	}
	OC.Notification.show(t("documentserver", "Community document server is not supported for this instance, please setup and configure an external document server"), {
		type: "error"
	});
});
