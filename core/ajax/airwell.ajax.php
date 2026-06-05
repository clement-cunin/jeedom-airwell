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

    switch (init('action')) {

        case 'bindDevice':
            $eqLogic = airwell::byId(init('id'));
            if (!is_object($eqLogic)) {
                throw new Exception('Unknown equipment: ' . init('id'));
            }
            $ip  = $eqLogic->getConfiguration('ip');
            $mac = $eqLogic->getConfiguration('mac');
            if (!$ip || !$mac) {
                throw new Exception('Renseignez l\'IP et la MAC avant de lancer le binding');
            }
            $result = GreeProtocol::bind($ip, $mac);
            $eqLogic->setConfiguration('device_key', $result['key']);
            $eqLogic->setConfiguration('cipher', $result['cipher']);
            $eqLogic->save();
            ajax::success($result);
            break;

        case 'scanDevices':
            $broadcastIp = init('broadcastIp', '255.255.255.255');
            $devices = GreeProtocol::discover($broadcastIp);
            $configuredMacs = [];
            foreach (eqLogic::byType('airwell') as $eq) {
                $mac = $eq->getConfiguration('mac');
                if ($mac) {
                    $configuredMacs[] = strtolower($mac);
                }
            }
            $devices = array_values(array_filter($devices, function ($d) use ($configuredMacs) {
                return !in_array(strtolower($d['mac']), $configuredMacs);
            }));
            ajax::success($devices);
            break;

        case 'importDevice':
            $ip   = init('ip');
            $mac  = init('mac');
            $name = init('name') ?: ('Airwell ' . strtoupper($mac));
            if (!$ip || !$mac) {
                throw new Exception('IP et MAC requis');
            }
            $eq = new airwell();
            $eq->setName($name);
            $eq->setEqType_name('airwell');
            $eq->setIsEnable(1);
            $eq->setIsVisible(1);
            $eq->setConfiguration('ip', $ip);
            $eq->setConfiguration('mac', $mac);
            $eq->save();
            $bindOk = false;
            try {
                $result = GreeProtocol::bind($ip, $mac);
                $eq->setConfiguration('device_key', $result['key']);
                $eq->setConfiguration('cipher', $result['cipher']);
                $eq->save();
                $bindOk = true;
            } catch (Exception $e) {
                log::add('airwell', 'warning', "importDevice bind failed [{$name}]: " . $e->getMessage());
            }
            ajax::success(['id' => $eq->getId(), 'name' => $eq->getName(), 'bindOk' => $bindOk]);
            break;

        default:
            throw new Exception('Unknown action: ' . init('action'));
    }
} catch (Exception $e) {
    ajax::error(displayException($e), $e->getCode());
}
