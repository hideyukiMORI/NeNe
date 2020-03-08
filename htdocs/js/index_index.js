document.addEventListener('DOMContentLoaded', function() {
    window.app = new Vue({
        el: '#app',
        data: {
            message: 'Hello NeNe!',
            formUserId:     '',
            formUserIdMsg:  '',
            formUserPass:   '',
            formUserPassMsg:'',
            isConnect:      false,
        },
        created() {
            document.getElementById('app').classList.remove('hidden');
        },
        methods: {
            onSubmit: function() {
                console.log('submit!!');
                console.log(this.formUserId);
                if (this.formUserId.length == 0) {
                    this.formUserIdMsg = 'USER ID is required.';
                    return;
                }
                if (this.formUserPass.length == 0) {
                    this.formUserPassMsg = 'PASSWORD is required.';
                    return;
                }
            }
        }
    });
});
