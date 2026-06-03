"use strict";

$(() => {
    // Load equipment list on page ready
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
                // Refresh page to show the new key
                $(".eqLogicAction[data-action='getInfo']").trigger("click");
            },
        });
    });

    // Commands table rendering
    function addCmdToTable(_cmd) {
        if (!isset(_cmd)) _cmd = {};
        if (!isset(_cmd.configuration)) _cmd.configuration = {};
        const tr = $("<tr>")
            .attr({ "data-cmd_id": init(_cmd.id) })
            .addClass("cmd");
        tr.append(
            $("<td>").append(
                $("<input>")
                    .addClass("cmdAttr form-control input-sm")
                    .attr({ "data-l1key": "name" })
                    .val(init(_cmd.name))
            )
        );
        tr.append(
            $("<td>").text(init(_cmd.type) + " / " + init(_cmd.subType))
        );
        tr.append($("<td>"));
        tr.append(
            $("<td>").append(
                $("<a>")
                    .addClass("btn btn-default btn-xs cmdAction")
                    .attr({ "data-action": "configure" })
                    .html('<i class="fas fa-cogs"></i>')
            ).append(" ").append(
                $("<a>")
                    .addClass("btn btn-default btn-xs cmdAction")
                    .attr({ "data-action": "test" })
                    .html('<i class="fas fa-rss"></i> {{Tester}}')
            )
        );
        $("#table_cmd tbody").append(tr);
        tr.setValues(_cmd, ".cmdAttr");
    }

    jeedom.eqLogic.builSelectCmd = addCmdToTable;
});
