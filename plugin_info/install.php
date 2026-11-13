<?php
if (!isConnect('admin')) {
    throw new Exception('401 Unauthorized');
}
require_once __DIR__ . '/../core/class/GreeProtocol.php';

function airwell_install() {
}

function airwell_update() {
    // Normalize MAC addresses entered before preSave() normalization existed
    foreach (eqLogic::byType('airwell') as $eqLogic) {
        $mac = (string)$eqLogic->getConfiguration('mac');
        if ($mac !== '' && GreeProtocol::isValidMac($mac) && GreeProtocol::normalizeMac($mac) !== $mac) {
            $eqLogic->setConfiguration('mac', GreeProtocol::normalizeMac($mac));
            $eqLogic->save();
        }
    }
}

function airwell_remove() {
}
