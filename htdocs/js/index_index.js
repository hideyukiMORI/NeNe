document.addEventListener('DOMContentLoaded', function() {
    window.app = new Vue({
        el: '#app',
        data: {
            message: 'Hello NeNe!'
        },
        created() {
            document.getElementById('app').classList.remove('hidden');
        }
    });
});
