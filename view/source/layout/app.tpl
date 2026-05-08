<!DOCTYPE html>
<html lang="ja">
    <head>
        <title>{$t_title}</title>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Sans+JP:wght@400;500;600;700&display=swap" rel="stylesheet">
        <link rel="shortcut icon" href="/favicon.ico" type="image/vnd.microsoft.icon">
        <link rel="icon" href="/favicon.ico" type="image/vnd.microsoft.icon">
{foreach from=$t_css item=css}        <link rel="stylesheet" type="text/css" href="{$css}">
{/foreach}
    </head>
    <body>
        <div class="contents {$t_controller_action}" id="contents">
            <div class="content">
{block name='content'}{/block}
            </div>
        </div>
        <footer>
            <small class="copyright">&copy; {if strlen($t_copyright) > 0}<a href="{$t_copyright_url}" target="_blank" rel="noopener noreferrer">{$t_copyright}</a>{else}{$t_copyright}{/if}</small>
        </footer>
{foreach from=$t_js item=js}        <script type="text/javascript" src="{$js}"></script>
{/foreach}
    </body>
</html>
