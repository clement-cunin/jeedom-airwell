<?php
require_once __DIR__ . '/GreeProtocol.php';

class airwell extends eqLogic {

    public static function cron5() {
        foreach (eqLogic::byType('airwell', true) as $eqLogic) {
            $eqLogic->refreshStatus();
        }
    }

    public function refreshStatus() {
        $ip  = $this->getConfiguration('ip');
        $mac = $this->getConfiguration('mac');
        $key = $this->getConfiguration('device_key');
        if (!$ip || !$mac || !$key) {
            return;
        }
        try {
            $cipher  = $this->getConfiguration('cipher', 'v1');
            $status  = GreeProtocol::getStatus($ip, $mac, $key, $cipher);
            $modeStr = GreeProtocol::MODES_INV[$status['Mod'] ?? 0] ?? 'auto';
            $this->checkAndUpdateCmd('power',         $status['Pow']    ?? 0);
            $this->checkAndUpdateCmd('mode',          $modeStr);
            $this->checkAndUpdateCmd('setpoint',      $status['SetTem'] ?? 0);
            $this->checkAndUpdateCmd('fanspeed',      $status['WdSpd']  ?? 0);
            $this->checkAndUpdateCmd('display',       $status['Lig']    ?? 0);
            if (isset($status['TemSen'])) {
                $this->checkAndUpdateCmd('internal_temp', $status['TemSen'] - 40);
            }
        } catch (Exception $e) {
            log::add('airwell', 'error', "refreshStatus [{$this->getName()}]: " . $e->getMessage());
        }
    }

    public function postSave() {
        $power = $this->getCmd('info', 'power');
        if (!is_object($power)) {
            $power = new airwellCmd();
            $power->setLogicalId('power');
            $power->setIsVisible(1);
            $power->setName(__('Alimentation', __FILE__));
        }
        $power->setType('info');
        $power->setSubType('binary');
        $power->setEqLogic_id($this->getId());
        $power->save();

        $mode = $this->getCmd('info', 'mode');
        if (!is_object($mode)) {
            $mode = new airwellCmd();
            $mode->setLogicalId('mode');
            $mode->setIsVisible(1);
            $mode->setName(__('Mode', __FILE__));
        }
        $mode->setType('info');
        $mode->setSubType('string');
        $mode->setEqLogic_id($this->getId());
        $mode->save();

        $setpoint = $this->getCmd('info', 'setpoint');
        if (!is_object($setpoint)) {
            $setpoint = new airwellCmd();
            $setpoint->setLogicalId('setpoint');
            $setpoint->setIsVisible(1);
            $setpoint->setName(__('Température consigne', __FILE__));
        }
        $setpoint->setType('info');
        $setpoint->setSubType('numeric');
        $setpoint->setUnite('°C');
        $setpoint->setEqLogic_id($this->getId());
        $setpoint->save();

        $fanspeed = $this->getCmd('info', 'fanspeed');
        if (!is_object($fanspeed)) {
            $fanspeed = new airwellCmd();
            $fanspeed->setLogicalId('fanspeed');
            $fanspeed->setIsVisible(1);
            $fanspeed->setName(__('Vitesse ventilateur', __FILE__));
        }
        $fanspeed->setType('info');
        $fanspeed->setSubType('numeric');
        $fanspeed->setEqLogic_id($this->getId());
        $fanspeed->save();

        $cmdSetFanspeed = $this->getCmd('action', 'set_fanspeed');
        if (!is_object($cmdSetFanspeed)) {
            $cmdSetFanspeed = new airwellCmd();
            $cmdSetFanspeed->setLogicalId('set_fanspeed');
            $cmdSetFanspeed->setIsVisible(1);
            $cmdSetFanspeed->setName(__('Régler vitesse ventilateur', __FILE__));
        }
        $cmdSetFanspeed->setType('action');
        $cmdSetFanspeed->setSubType('slider');
        $cmdSetFanspeed->setConfiguration('minValue', 0);
        $cmdSetFanspeed->setConfiguration('maxValue', 5);
        $cmdSetFanspeed->setEqLogic_id($this->getId());
        $cmdSetFanspeed->save();

        $display = $this->getCmd('info', 'display');
        if (!is_object($display)) {
            $display = new airwellCmd();
            $display->setLogicalId('display');
            $display->setIsVisible(1);
            $display->setName(__('Affichage', __FILE__));
        }
        $display->setType('info');
        $display->setSubType('binary');
        $display->setEqLogic_id($this->getId());
        $display->save();

        $cmdSetDisplay = $this->getCmd('action', 'set_display');
        if (!is_object($cmdSetDisplay)) {
            $cmdSetDisplay = new airwellCmd();
            $cmdSetDisplay->setLogicalId('set_display');
            $cmdSetDisplay->setIsVisible(1);
            $cmdSetDisplay->setName(__('Allumer/éteindre affichage', __FILE__));
        }
        $cmdSetDisplay->setType('action');
        $cmdSetDisplay->setSubType('select');
        $cmdSetDisplay->setConfiguration('listValue', '1|Allumé;0|Éteint');
        $cmdSetDisplay->setEqLogic_id($this->getId());
        $cmdSetDisplay->save();

        $internalTemp = $this->getCmd('info', 'internal_temp');
        if (!is_object($internalTemp)) {
            $internalTemp = new airwellCmd();
            $internalTemp->setLogicalId('internal_temp');
            $internalTemp->setIsVisible(1);
            $internalTemp->setName(__('Température interne', __FILE__));
        }
        $internalTemp->setType('info');
        $internalTemp->setSubType('numeric');
        $internalTemp->setUnite('°C');
        $internalTemp->setEqLogic_id($this->getId());
        $internalTemp->save();

        $cmdOn = $this->getCmd('action', 'turn_on');
        if (!is_object($cmdOn)) {
            $cmdOn = new airwellCmd();
            $cmdOn->setLogicalId('turn_on');
            $cmdOn->setIsVisible(1);
            $cmdOn->setName(__('Allumer', __FILE__));
        }
        $cmdOn->setType('action');
        $cmdOn->setSubType('other');
        $cmdOn->setEqLogic_id($this->getId());
        $cmdOn->save();

        $cmdOff = $this->getCmd('action', 'turn_off');
        if (!is_object($cmdOff)) {
            $cmdOff = new airwellCmd();
            $cmdOff->setLogicalId('turn_off');
            $cmdOff->setIsVisible(1);
            $cmdOff->setName(__('Éteindre', __FILE__));
        }
        $cmdOff->setType('action');
        $cmdOff->setSubType('other');
        $cmdOff->setEqLogic_id($this->getId());
        $cmdOff->save();

        $cmdSetTemp = $this->getCmd('action', 'set_temperature');
        if (!is_object($cmdSetTemp)) {
            $cmdSetTemp = new airwellCmd();
            $cmdSetTemp->setLogicalId('set_temperature');
            $cmdSetTemp->setIsVisible(1);
            $cmdSetTemp->setName(__('Régler température', __FILE__));
        }
        $cmdSetTemp->setType('action');
        $cmdSetTemp->setSubType('slider');
        $cmdSetTemp->setConfiguration('minValue', 16);
        $cmdSetTemp->setConfiguration('maxValue', 30);
        $cmdSetTemp->setEqLogic_id($this->getId());
        $cmdSetTemp->save();

        $cmdSetMode = $this->getCmd('action', 'set_mode');
        if (!is_object($cmdSetMode)) {
            $cmdSetMode = new airwellCmd();
            $cmdSetMode->setLogicalId('set_mode');
            $cmdSetMode->setIsVisible(1);
            $cmdSetMode->setName(__('Régler mode', __FILE__));
        }
        $cmdSetMode->setType('action');
        $cmdSetMode->setSubType('select');
        $cmdSetMode->setConfiguration('listValue', 'auto|Auto;cool|Froid;heat|Chaud;dry|Déshumidification;fan_only|Ventilation');
        $cmdSetMode->setEqLogic_id($this->getId());
        $cmdSetMode->save();
    }
}

class airwellCmd extends cmd {

    public function execute($_options = []) {
        $eqLogic = $this->getEqLogic();
        $ip      = $eqLogic->getConfiguration('ip');
        $mac     = $eqLogic->getConfiguration('mac');
        $key     = $eqLogic->getConfiguration('device_key');
        if (!$ip || !$mac || !$key) {
            throw new Exception("Airwell [{$eqLogic->getName()}]: IP, MAC ou clé device non configurés");
        }

        $cipher = $eqLogic->getConfiguration('cipher', 'v1');
        switch ($this->getLogicalId()) {
            case 'turn_on':
                $params = ['Pow' => 1];
                break;
            case 'turn_off':
                $params = ['Pow' => 0];
                break;
            case 'set_temperature':
                $params = ['SetTem' => (int)($_options['slider'] ?? 20)];
                break;
            case 'set_mode':
                $params = ['Mod' => GreeProtocol::MODES[$_options['select'] ?? 'auto'] ?? 0];
                break;
            case 'set_fanspeed':
                $params = ['WdSpd' => (int)($_options['slider'] ?? 0)];
                break;
            case 'set_display':
                $params = ['Lig' => (int)($_options['select'] ?? 0)];
                break;
            default:
                throw new Exception("Commande inconnue: " . $this->getLogicalId());
        }

        if (!GreeProtocol::sendCommand($ip, $mac, $key, $params, $cipher)) {
            throw new Exception("Airwell [{$eqLogic->getName()}]: commande refusée par le device");
        }
    }
}
