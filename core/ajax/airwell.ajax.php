<?php
try {
    require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
    require_once dirname(__FILE__) . '/../class/GreeProtocol.php';
    include_file('core', 'authentification', 'php');
    if (!isConnect('admin')) {
        throw new Exception('401 Unauthorized');
    }
    if (!init('action')) {
        throw new Exception('No action specified');
    }
    $eqLogic = airwell::byId(init('id'));
    if (!is_object($eqLogic)) {
        throw new Exception('Unknown equipment: ' . init('id'));
    }
    switch (init('action')) {
        case 'bindDevice':
            $ip  = $eqLogic->getConfiguration('ip');
            $mac = $eqLogic->getConfiguration('mac');
            if (!$ip || !$mac) {
                throw new Exception('Renseignez l\'IP et la MAC avant de lancer le binding');
            }
            $key = GreeProtocol::bind($ip, $mac);
            $eqLogic->setConfiguration('device_key', $key);
            $eqLogic->save();
            ajax::success($key);
            break;
        default:
            throw new Exception('Unknown action: ' . init('action'));
    }
    ajax::success();
} catch (Exception $e) {
    ajax::error(displayException($e), $e->getCode());
}
