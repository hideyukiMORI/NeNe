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
                this.formUserIdMsg = '';
                if (this.formUserPass.length == 0) {
                    this.formUserPassMsg = 'PASSWORD is required.';
                    return;
                } else if (this.formUserPass.length > 64) {
                    this.formUserPassMsg = 'Please enter a password within 64 characters.';
                    return;
                } else if (this.formUserPass.length < 5) {
                    this.formUserPassMsg = 'Password must be at least 6 characters';
                    return;
                }
                this.formUserPassMsg = '';
                this.isConnect = true;
                let params = new FormData();
                params.append('user_id', this.formUserId);
                params.append('user_pass', this.formUserPass);
                fetch('/session/login', {
                    method: 'POST',
                    credentials:'same-origin',
                    body: params
                }).then(res => res.json())
                    .then(response => {
                        this.isConnect = false;
                        console.log(response);
                    }).catch(error => {
                        this.isConnect = false;
                        console.log(error);
                    });
            }
        }
    });
});
