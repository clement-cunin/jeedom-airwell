<?php
if (!isConnect('admin')) {
    throw new Exception('401 Unauthorized');
}
$plugin = plugin::byId('airwell');
sendVarToJS('eqType', $plugin->getId());
$eqlogics = eqLogic::byType('airwell');
?>

<div class="row row-overflow">
    <!-- Sidebar: equipment list -->
    <div class="col-xs-12 col-sm-3 eqLogicThumbnailDisplay">
        <legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
        <div class="eqLogicThumbnailContainer">
            <div class="cursor eqLogicAction logNewGetInfo" data-action="add">
                <i class="fas fa-plus-circle"></i>
                <br>
                <span>{{Ajouter}}</span>
            </div>
            <?php foreach ($eqlogics as $eqLogic) { ?>
                <div class="eqLogicDisplayCard cursor" data-eqLogic_id="<?php echo $eqLogic->getId(); ?>">
                    <img src="<?php echo $eqLogic->getImage(); ?>" />
                    <br>
                    <span class="name"><?php echo $eqLogic->getHumanName(true, true); ?></span>
                    <span class="label label-<?php echo ($eqLogic->getIsEnable()) ? 'success' : 'danger'; ?>">
                        <?php echo ($eqLogic->getIsEnable()) ? '{{Actif}}' : '{{Inactif}}'; ?>
                    </span>
                </div>
            <?php } ?>
        </div>
    </div>

    <!-- Main panel: equipment form -->
    <div class="col-xs-12 col-sm-9 eqLogic" style="display: none;">
        <div class="input-group pull-right" style="display:inline-flex">
            <a class="btn btn-default btn-sm eqLogicAction roundedLeft" data-action="configure">
                <i class="fas fa-cogs"></i> {{Configuration avancée}}
            </a>
            <a class="btn btn-default btn-sm eqLogicAction" data-action="copy">
                <i class="fas fa-copy"></i> {{Dupliquer}}
            </a>
            <a class="btn btn-sm btn-success eqLogicAction roundedRight" data-action="save">
                <i class="fas fa-check-circle"></i> {{Sauvegarder}}
            </a>
        </div>
        <ul class="nav nav-tabs" role="tablist">
            <li role="presentation" class="active">
                <a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab">{{Equipement}}</a>
            </li>
            <li role="presentation">
                <a href="#commandtab" aria-controls="profile" role="tab" data-toggle="tab">{{Commandes}}</a>
            </li>
        </ul>
        <div class="tab-content">
            <!-- Equipment tab -->
            <div role="tabpanel" class="tab-pane active" id="eqlogictab">
                <br>
                <div class="form-horizontal">
                    <fieldset>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Nom de l'équipement}}</label>
                            <div class="col-sm-3">
                                <input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none;" />
                                <input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Nom de l'équipement}}" />
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Objet parent}}</label>
                            <div class="col-sm-3">
                                <select class="eqLogicAttr form-control" data-l1key="object_id">
                                    <option value="">{{Aucun}}</option>
                                    <?php foreach (jeeObject::all() as $_object) { ?>
                                        <option value="<?php echo $_object->getId(); ?>"><?php echo $_object->getHumanName(); ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Catégorie}}</label>
                            <div class="col-sm-6">
                                <?php foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) { ?>
                                    <label class="checkbox-inline">
                                        <input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="<?php echo $key; ?>" />
                                        {{<?php echo $value['name']; ?>}}
                                    </label>
                                <?php } ?>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label"></label>
                            <div class="col-sm-6">
                                <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" /> {{Activer}}</label>
                                <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" /> {{Visible}}</label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Adresse IP}}</label>
                            <div class="col-sm-3">
                                <input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="ip" placeholder="192.168.1.x" />
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Adresse MAC}}</label>
                            <div class="col-sm-3">
                                <input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="mac" placeholder="AA:BB:CC:DD:EE:FF" />
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Clé device}}</label>
                            <div class="col-sm-3">
                                <input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="device_key" placeholder="{{Obtenue lors du binding}}" readonly />
                            </div>
                            <div class="col-sm-1">
                                <a class="btn btn-default btn-sm" id="bt_bindDevice" title="{{Lancer le binding}}">
                                    <i class="fas fa-link"></i>
                                </a>
                            </div>
                        </div>
                    </fieldset>
                </div>
            </div>

            <!-- Commands tab -->
            <div role="tabpanel" class="tab-pane" id="commandtab">
                <br>
                <table id="table_cmd" class="table table-bordered table-condensed">
                    <thead>
                        <tr>
                            <th>{{Nom}}</th>
                            <th>{{Type}}</th>
                            <th>{{Options}}</th>
                            <th>{{Action}}</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include_file('desktop', 'airwell', 'js', 'airwell'); ?>
<?php include_file('core', 'plugin.template', 'js'); ?>
