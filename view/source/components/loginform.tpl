
<form id="c__loginform" class="c__loginform">

    <div class="loginform__user-id_block">
        <label for="loginform__user-id">USER ID*</label>
        <input id="loginform__user-id" name="loginform__user-id" maxlength="255" class="input__w-255" placeholder="USER ID" type="text" required>
        <small><span id="loginform__user-id_msg" class="loginform__user-id_msg"></span></small>
    </div>

    <div class="loginform__user-pass_block">
        <label for="loginform__user-pass">PASSWORD*</label>
        <input id="loginform__user-pass" name="loginform__user-pass" type="password" maxlength="64" class="input__w-255" placeholder="PASSWORD" minlength="6" required>
        <small><span id="loginform__user-pass_msg" class="loginform__user-pass_msg"></span>Please enter at least 6 alphanumeric characters up to 64 characters.</small>
    </div>

    <div id="loginform__submit-btn_block" class="loginform__submite-btn_block">
        <button type="button" id="loginform__submite-btn" class="loginform__submite">LOGIN</button>
        {include file='../components/loading-spinner-button.tpl'}
    </div>

</form>

