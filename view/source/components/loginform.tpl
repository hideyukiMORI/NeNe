
<form id="c__loginform" class="c__loginform" @submit.prevent="onSubmit">

    <div class="loginform__user-id_block">
        <label for="loginform__user-id">USER ID*</label>
        <input id="loginform__user-id" name="loginform__user-id" maxlength="255" class="input__w-100p" placeholder="USER ID" type="text" v-model="formUserId" required>
        <small><div id="loginform__user-id_msg" class="loginform__user-id_msg error" v-if="formUserIdMsg.length > 0">{{ formUserIdMsg }}</div></small>
    </div>

    <div class="loginform__user-pass_block">
        <label for="loginform__user-pass">PASSWORD*</label>
        <input id="loginform__user-pass" name="loginform__user-pass" type="password"  class="input__w-100p" placeholder="PASSWORD" v-model="formUserPass" required>
        <small><div id="loginform__user-pass_msg" class="loginform__user-pass_msg error" v-if="formUserPassMsg.length > 0">{{ formUserPassMsg }}</div>Please enter at least 5 alphanumeric characters up to 64 characters.</small>
    </div>

    <div id="loginform__submit-btn_block" class="loginform__submite-btn_block">
        {include file='../components/loading-spinner.tpl' vif='isConnect'}
        <button type="submit" id="loginform__submite-btn" class="loginform__submit input__w-100p" :disabled="isConnect">LOGIN</button>
    </div>
</form>

