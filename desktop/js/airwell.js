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
            data: {
                action: "bindDevice",
                id: eqLogicId,
            },
            dataType: "json",
            error: (request, status, error) => {
                handleAjaxError(request, status, error);
            },
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
});

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
