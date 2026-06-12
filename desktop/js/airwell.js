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

    $("#bt_scanDevices").off("click").on("click", openScanModal);
});

// ── Scan modal ────────────────────────────────────────────────────────────────

function openScanModal() {
    if ($("#modal_airwellScan").length === 0) {
        $("body").append(
            '<div id="modal_airwellScan" style="display:none;">' +
            '  <div style="margin-bottom:10px;">' +
            '    <div class="input-group">' +
            '      <input type="text" id="in_broadcastIp" class="form-control" value="255.255.255.255">' +
            '      <span class="input-group-btn">' +
            '        <button class="btn btn-default" id="bt_scanLaunch" type="button">' +
            '          <i class="fas fa-sync-alt"></i> {{Rescanner}}' +
            '        </button>' +
            '      </span>' +
            '    </div>' +
            '  </div>' +
            '  <div id="div_scanDeviceList"></div>' +
            '</div>'
        );
    }

    $("#modal_airwellScan").dialog({
        title: "{{Import automatique — appareils Airwell}}",
        width: 560,
        maxHeight: 600,
        modal: true,
        buttons: [
            {
                text: "{{Importer tout}}",
                id: "bt_importAll",
                class: "btn btn-success",
                click: importAll,
            },
            {
                text: "{{Fermer}}",
                class: "btn btn-default",
                click: function () { $(this).dialog("close"); },
            },
        ],
        open: function () {
            $("#bt_importAll").prop("disabled", true);
            $("#bt_scanLaunch").off("click").on("click", launchScan);
            launchScan();
        },
    });
}

function launchScan() {
    const broadcastIp = $("#in_broadcastIp").val() || "255.255.255.255";
    const list = $("#div_scanDeviceList");
    $("#bt_importAll").prop("disabled", true);
    list.html('<p><i class="fas fa-spinner fa-spin"></i> {{Scan en cours…}}</p>');

    $.ajax({
        type: "POST",
        url: "plugins/airwell/core/ajax/airwell.ajax.php",
        data: { action: "scanDevices", broadcastIp: broadcastIp },
        dataType: "json",
        success: (data) => {
            if (data.state !== "ok") {
                list.html('<p class="text-danger">' + data.result + "</p>");
                return;
            }
            const devices = data.result;
            if (!devices || !devices.length) {
                list.html(
                    '<p class="text-muted">{{Aucun appareil trouvé}}</p>' +
                    '<p><a href="' + airwellDocUrl + '" target="_blank">{{Que faire si mes appareils ne sont pas trouvés ?}}</a></p>'
                );
                return;
            }
            renderDevices(devices);
            $("#bt_importAll").prop("disabled", false);
        },
        error: (_req, _status, error) => {
            list.html('<p class="text-danger">' + error + "</p>");
        },
    });
}

function renderDevices(devices) {
    const list = $("#div_scanDeviceList");
    list.empty();

    devices.forEach((d, i) => {
        const label = d.name || d.mac;
        const sub = d.ip + (d.brand ? " · " + d.brand : "") + (d.ver ? " " + d.ver : "");
        list.append(
            $('<div class="airwell-device-card" style="border:1px solid #ddd;border-radius:4px;padding:8px 10px;margin-bottom:8px;">')
                .attr("data-index", i)
                .append($("<strong>").text(label))
                .append("<br>")
                .append($('<small class="text-muted">').text(sub))
                .append("<br>")
                .append(
                    $('<button class="btn btn-xs btn-success bt_importOne" style="margin-top:6px;">')
                        .html('<i class="fas fa-plus"></i> {{Importer}}')
                        .on("click", function () { importDevice(d, $(this)); })
                )
                .data("device", d)
        );
    });
}

// ── Import helpers ─────────────────────────────────────────────────────────────

function importDevice(device, btn, onDone) {
    btn.prop("disabled", true).html('<i class="fas fa-spinner fa-spin"></i>');
    const name = device.name || ("Airwell " + device.mac.toUpperCase());

    return $.ajax({
        type: "POST",
        url: "plugins/airwell/core/ajax/airwell.ajax.php",
        data: { action: "importDevice", ip: device.ip, mac: device.mac, name: name, modelType: device.modelType || '', hid: device.hid || '' },
        dataType: "json",
        success: (data) => {
            if (data.state !== "ok") {
                btn.prop("disabled", false).html('<i class="fas fa-plus"></i> {{Importer}}');
                $.fn.showAlert({
                    message: data.result + ' — <a href="' + airwellDocUrl + '" target="_blank">{{Voir la documentation}}</a>',
                    level: "danger",
                });
                if (onDone) onDone(false);
                return;
            }
            const res = data.result;
            const msg = res.bindOk
                ? '<i class="fas fa-check"></i> {{Importé}}'
                : '<i class="fas fa-exclamation-triangle"></i> {{Importé (binding manuel requis)}}';
            btn.closest(".airwell-device-card").html('<span class="text-success">' + msg + "</span>");
            if (onDone) onDone(true);
        },
        error: (_req, _status, error) => {
            btn.prop("disabled", false).html('<i class="fas fa-plus"></i> {{Importer}}');
            $.fn.showAlert({ message: error, level: "danger" });
            if (onDone) onDone(false);
        },
    });
}

function importAll() {
    const btn = $("#bt_importAll").prop("disabled", true).html('<i class="fas fa-spinner fa-spin"></i> {{Import en cours…}}');
    const cards = $(".airwell-device-card");
    let pending = 0;

    cards.each(function () {
        const device = $(this).data("device");
        if (!device) return;
        const importBtn = $(this).find(".bt_importOne");
        if (!importBtn.length) return; // already imported
        pending++;
        importDevice(device, importBtn, () => {
            pending--;
            if (pending === 0) {
                btn.html('<i class="fas fa-check"></i> {{Terminé}}');
                setTimeout(() => { window.location.reload(); }, 1500);
            }
        });
    });

    if (pending === 0) {
        window.location.reload();
    }
}

// ── addCmdToTable (global — appelé par le core Jeedom) ───────────────────────

function addCmdToTable(_cmd) {
    if (!isset(_cmd)) _cmd = {};
    if (!isset(_cmd.configuration)) _cmd.configuration = {};

    var tr = '<tr class="cmd" data-cmd_id="' + init(_cmd.id) + '">';
    tr += '<td class="hidden-xs"><span class="cmdAttr" data-l1key="id"></span></td>';
    tr += '<td><input class="cmdAttr form-control input-sm" data-l1key="name" placeholder="{{Nom de la commande}}"></td>';
    tr += '<td>';
    tr += '<span class="type" type="' + init(_cmd.type) + '">' + jeedom.cmd.availableType() + '</span>';
    tr += '<span class="subType" subType="' + init(_cmd.subType) + '"></span>';
    tr += '</td>';
    tr += '<td><label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked/>{{Afficher}}</label></td>';
    tr += '<td>';
    if (is_numeric(_cmd.id)) {
        tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> ';
        tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a>';
    }
    tr += '</td></tr>';

    var $tr = $(tr);
    $('#table_cmd tbody').append($tr);
    $tr.setValues(_cmd, '.cmdAttr');
    jeedom.cmd.changeType($tr, init(_cmd.subType));
}
