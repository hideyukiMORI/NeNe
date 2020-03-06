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
            }
        }
    });
});
