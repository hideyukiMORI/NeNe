

        <footer>
        <hr>
            <small class="copyright">&copy; {$t_copyright}</small>
        </footer>
{foreach from=$t_js item=js}        <script type="text/javascript" src="{$t_root}{$js.filename}.js?{$js.filetime}"></script>
{/foreach}



</body>
</html>
