<?php
try {
    require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
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
            // Will be implemented in issue #2 (POC)
            throw new Exception('Not yet implemented');
        default:
            throw new Exception('Unknown action: ' . init('action'));
    }
    ajax::success();
} catch (Exception $e) {
    ajax::error(displayException($e), $e->getCode());
}
