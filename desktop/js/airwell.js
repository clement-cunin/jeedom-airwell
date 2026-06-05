"use strict";

$(() => {
    $("#bt_bindDevice").off("click").on("click", () => {
        const eqLogicId = $(".eqLogicAttr[data-l1key='id']").val();
        if (!eqLogicId) {
            $.fn.showAlert({ message: "{{Sauvegardez d'abord l'équipement}}", level: "warning" });
            return;
        }
        $.ajax({
            type: "POST",
            url: "plugins/airwell/core/ajax/airwell.ajax.php",
            data: { action: "bindDevice", id: eqLogicId },
            dataType: "json",
            error: (request, status, error) => { handleAjaxError(request, status, error); },
            success: (data) => {
                if (data.state !== "ok") {
                    $.fn.showAlert({ message: data.result, level: "danger" });
                    return;
                }
                $.fn.showAlert({ message: "{{Binding réussi}}", level: "success" });
                $(".eqLogicAction[data-action='getInfo']").trigger("click");
            },
        });
    });

    $("#bt_scanDevices").off("click").on("click", () => {
        const panel = $("#div_scanResults");
        if (panel.is(":visible")) {
            panel.hide();
        } else {
            panel.show();
            launchScan();
        }
    });

    $("#bt_scanLaunch").off("click").on("click", launchScan);
});

function launchScan() {
    const broadcastIp = $("#in_broadcastIp").val() || "255.255.255.255";
    const list = $("#div_scanDeviceList");
    list.html('<i class="fas fa-spinner fa-spin"></i> {{Scan en cours…}}');
    $.ajax({
        type: "POST",
        url: "plugins/airwell/core/ajax/airwell.ajax.php",
        data: { action: "scanDevices", broadcastIp: broadcastIp },
        dataType: "json",
        success: (data) => {
            if (data.state !== "ok") {
                list.html('<span class="text-danger">' + data.result + '</span>');
                return;
            }
            const devices = data.result;
            if (!devices || !devices.length) {
                list.html('<em class="text-muted">{{Aucun appareil trouvé}}</em>');
                return;
            }
            list.empty();
            devices.forEach((d) => {
                const label = d.name ? d.name : d.mac;
                const sub = d.ip + (d.brand ? " · " + d.brand : "") + (d.ver ? " " + d.ver : "");
                const card = $(
                    '<div style="border:1px solid #ddd; border-radius:4px; padding:6px 8px; margin-bottom:6px;">' +
                    '<strong>' + label + '</strong><br>' +
                    '<small class="text-muted">' + sub + '</small><br>' +
                    '<button class="btn btn-xs btn-success bt_import" style="margin-top:4px;">' +
                    '<i class="fas fa-plus"></i> {{Importer}}</button>' +
                    '</div>'
                );
                card.find(".bt_import").on("click", function () {
                    importDevice(d, $(this));
                });
                list.append(card);
            });
        },
        error: (request, status, error) => {
            list.html('<span class="text-danger">' + error + '</span>');
        },
    });
}

function importDevice(device, btn) {
    btn.prop("disabled", true).html('<i class="fas fa-spinner fa-spin"></i>');
    const name = device.name || ("Airwell " + device.mac.toUpperCase());
    $.ajax({
        type: "POST",
        url: "plugins/airwell/core/ajax/airwell.ajax.php",
        data: { action: "importDevice", ip: device.ip, mac: device.mac, name: name },
        dataType: "json",
        success: (data) => {
            if (data.state !== "ok") {
                btn.prop("disabled", false).html('<i class="fas fa-plus"></i> {{Importer}}');
                $.fn.showAlert({ message: data.result, level: "danger" });
                return;
            }
            const res = data.result;
            const msg = res.bindOk
                ? "{{Importé et bindé avec succès}}"
                : "{{Importé — binding à faire manuellement (appareil injoignable)}}";
            btn.closest("div").html('<span class="text-success"><i class="fas fa-check"></i> ' + msg + '</span>');
            // Reload the page to show the new equipment in the sidebar
            setTimeout(() => { window.location.reload(); }, 1200);
        },
        error: (request, status, error) => {
            btn.prop("disabled", false).html('<i class="fas fa-plus"></i> {{Importer}}');
            $.fn.showAlert({ message: error, level: "danger" });
        },
    });
}

// Must be global — Jeedom core calls this to render each command row
function addCmdToTable(_cmd) {
    if (!isset(_cmd)) _cmd = {};
    if (!isset(_cmd.configuration)) _cmd.configuration = {};

    var tr = '<tr class="cmd" data-cmd_id="' + init(_cmd.id) + '">';
    tr += '<td class="hidden-xs"><span class="cmdAttr" data-l1key="id"></span></td>';
    tr += '<td>';
    tr += '<input class="cmdAttr form-control input-sm" data-l1key="name" placeholder="{{Nom de la commande}}">';
    tr += '</td>';
    tr += '<td>';
    tr += '<span class="type" type="' + init(_cmd.type) + '">' + jeedom.cmd.availableType() + '</span>';
    tr += '<span class="subType" subType="' + init(_cmd.subType) + '"></span>';
    tr += '</td>';
    tr += '<td>';
    tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked/>{{Afficher}}</label>';
    tr += '</td>';
    tr += '<td>';
    if (is_numeric(_cmd.id)) {
        tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> ';
        tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a>';
    }
    tr += '</td>';
    tr += '</tr>';

    var $tr = $(tr);
    $('#table_cmd tbody').append($tr);
    $tr.setValues(_cmd, '.cmdAttr');
    jeedom.cmd.changeType($tr, init(_cmd.subType));
}
