<?php
if (!isConnect('admin')) {
    throw new Exception('401 Unauthorized');
}
?>
<form class="form-horizontal">
    <fieldset>
        <div class="form-group">
            <label class="col-sm-4 control-label"></label>
            <div class="col-sm-4">
            </div>
        </div>
    </fieldset>
</form>
