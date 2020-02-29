<!DOCTYPE html>
<html lang="ja">
    <head>
        <title>{$t_siteTitle} | {$t_title}</title>
        <meta charset="UTF-8">
{foreach from=$t_css item=css}        <link rel="stylesheet" type="text/css" href="{$t_root}{$css.filename}.css?{$css.filetime}">
{/foreach}
