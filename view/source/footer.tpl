            </div>
        </div>



        <footer>
            <small class="copyright">&copy; {if strlen($t_copyright) > 0}<a href="{$t_copyright_url}" target="_blank" rel="noopener noreferrer">{$t_copyright}</a>{else}{$t_copyright}{/if}</small>
        </footer>
{foreach from=$t_js item=js}        <script type="text/javascript" src="{$js}"></script>
{/foreach}



    </body>
</html>
