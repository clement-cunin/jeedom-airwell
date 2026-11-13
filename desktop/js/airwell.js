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
    $("#bt_diagnoseDevice").off("click").on("click", openDiagnosticModal);
});

// ── Diagnostic modal ──────────────────────────────────────────────────────────

const DIAG_STATUS = {
    ok:          { icon: "fas fa-check-circle text-success",        text: "✅" },
    warning:     { icon: "fas fa-exclamation-triangle text-warning", text: "⚠️" },
    error:       { icon: "fas fa-times-circle text-danger",          text: "❌" },
    unavailable: { icon: "fas fa-minus-circle text-muted",           text: "➖" },
};

function openDiagnosticModal() {
    const eqLogicId = $(".eqLogicAttr[data-l1key='id']").val();
    if (!eqLogicId) {
        $.fn.showAlert({ message: "{{Sauvegardez d'abord l'équipement}}", level: "warning" });
        return;
    }
    if ($("#modal_airwellDiagnostic").length === 0) {
        $("body").append('<div id="modal_airwellDiagnostic" style="display:none;"><div id="div_diagnosticResult"></div></div>');
    }

    let report = "";
    $("#modal_airwellDiagnostic").dialog({
        title: "{{Diagnostic de la connexion}}",
        width: 640,
        maxHeight: 700,
        modal: true,
        buttons: [
            {
                text: "{{Copier le rapport}}",
                id: "bt_diagnosticCopy",
                class: "btn btn-default",
                click: () => copyText(report),
            },
            {
                text: "{{Relancer}}",
                id: "bt_diagnosticRerun",
                class: "btn btn-default",
                click: () => run(),
            },
            {
                text: "{{Fermer}}",
                class: "btn btn-default",
                click: function () { $(this).dialog("close"); },
            },
        ],
        open: () => run(),
    });

    function run() {
        const result = $("#div_diagnosticResult");
        $("#bt_diagnosticCopy, #bt_diagnosticRerun").prop("disabled", true);
        result.html("<p><i class=\"fas fa-spinner fa-spin\"></i> {{Diagnostic en cours… (jusqu'à 30 secondes)}}</p>");

        $.ajax({
            type: "POST",
            url: "plugins/airwell/core/ajax/airwell.ajax.php",
            data: { action: "diagnose", id: eqLogicId },
            dataType: "json",
            timeout: 120000,
            success: (data) => {
                $("#bt_diagnosticRerun").prop("disabled", false);
                if (data.state !== "ok") {
                    result.html($('<p class="text-danger">').text(data.result));
                    return;
                }
                report = buildDiagnosticReport(data.result);
                renderDiagnostic(result, data.result);
                $("#bt_diagnosticCopy").prop("disabled", false);
            },
            error: (_req, _status, error) => {
                $("#bt_diagnosticRerun").prop("disabled", false);
                result.html($('<p class="text-danger">').text(error));
            },
        });
    }
}

function renderDiagnostic(container, res) {
    container.empty();
    res.steps.forEach((s) => {
        const status = DIAG_STATUS[s.status] || DIAG_STATUS.unavailable;
        const block = $('<div style="margin-bottom:10px;">')
            .append($("<i>").addClass(status.icon).css("margin-right", "6px"))
            .append($("<strong>").text(s.step))
            .append($("<div>").text(s.message));
        if (s.details) {
            block.append($('<small class="text-muted" style="white-space:pre-line;">').text(s.details));
        }
        container.append(block);
    });

    const conclusion = $('<div class="alert alert-info" style="margin-top:10px;">')
        .append($("<strong>").text("{{Conclusion}} : "))
        .append($("<span>").text(res.conclusion));

    if (res.suggestedIp) {
        conclusion.append("<br>").append(suggestionButton("{{Utiliser l'IP}} " + res.suggestedIp, "ip", res.suggestedIp));
    }
    if (res.suggestedMac) {
        conclusion.append("<br>").append(suggestionButton("{{Utiliser la MAC}} " + res.suggestedMac, "mac", res.suggestedMac));
    }
    container.append(conclusion);
}

function suggestionButton(label, field, value) {
    return $('<button class="btn btn-xs btn-warning" style="margin-top:6px;">')
        .text(label)
        .on("click", function () {
            $(".eqLogicAttr[data-l1key='configuration'][data-l2key='" + field + "']").val(value);
            $(this).prop("disabled", true);
            $.fn.showAlert({ message: "{{Valeur reportée dans l'équipement : sauvegardez puis relancez le binding}}", level: "warning" });
        });
}

function buildDiagnosticReport(res) {
    const lines = [
        "Diagnostic Airwell — " + new Date().toLocaleString(),
        "IP : " + res.ip + " · MAC : " + res.mac,
        "",
    ];
    res.steps.forEach((s) => {
        const status = DIAG_STATUS[s.status] || DIAG_STATUS.unavailable;
        lines.push(status.text + " " + s.step);
        lines.push("   " + s.message);
        if (s.details) {
            s.details.split("\n").forEach((d) => lines.push("   " + d));
        }
    });
    lines.push("", "{{Conclusion}} : " + res.conclusion);
    return lines.join("\n");
}

function copyText(text) {
    const done = () => $.fn.showAlert({ message: "{{Rapport copié dans le presse-papier}}", level: "success" });
    // navigator.clipboard only exists on HTTPS/localhost: Jeedom is often served over plain HTTP
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(done);
        return;
    }
    const area = $("<textarea>").val(text).css({ position: "fixed", top: 0, left: 0, opacity: 0 }).appendTo("body");
    area[0].select();
    document.execCommand("copy");
    area.remove();
    done();
}

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
