<!DOCTYPE html>
<html lang="ja">
    <head>
        <title>{$t_title}</title>
        <meta charset="UTF-8">
        <link href="https://fonts.googleapis.com/css?family=Overpass&display=swap" rel="stylesheet">
        <link href="https://fonts.googleapis.com/css?family=Roboto&display=swap" rel="stylesheet">
        <link href="https://fonts.googleapis.com/css?family=Nunito&display=swap" rel="stylesheet">
{foreach from=$t_css item=css}        <link rel="stylesheet" type="text/css" href="{$t_root}{$css.filename}.css?{$css.filetime}">
{/foreach}
