<?php
/**
 * This is an automatically generated baseline for Phan issues.
 * When Phan is invoked with --load-baseline=path/to/baseline.php,
 * The pre-existing issues listed in this file won't be emitted.
 *
 * This file can be updated by invoking Phan with --save-baseline=path/to/baseline.php
 * (can be combined with --load-baseline)
 */
return [
    // # Issue statistics:
    // PhanTypeMismatchReturn : 3 occurrences
    // PhanPossiblyUndeclaredVariable : 2 occurrences
    // PhanUndeclaredProperty : 2 occurrences
    // PhanUnreferencedUseNormal : 2 occurrences
    // PhanTypeMagicVoidWithReturn : 1 occurrence
    // PhanTypeMismatchArgument : 1 occurrence
    // PhanTypeMismatchArgumentInternal : 1 occurrence
    // PhanTypeMismatchDimFetch : 1 occurrence
    // PhanTypeMismatchForeach : 1 occurrence
    // PhanTypeMismatchPropertyProbablyReal : 1 occurrence
    // PhanTypeMissingReturn : 1 occurrence
    // PhanTypeSuspiciousStringExpression : 1 occurrence
    // PhanUndeclaredConstantOfClass : 1 occurrence
    // PhanUndeclaredTypeThrowsType : 1 occurrence
    // PhanUndeclaredVariableDim : 1 occurrence
    // PhanUnextractableAnnotation : 1 occurrence

    'file_suppressions' => [
        'class/xion/ControllerBase.php' => [
            'PhanTypeMissingReturn' => ['\\Nene\\Xion\\ControllerBase::preAction']
        ],
        'class/xion/DataMapperBase.php' => [
            'PhanPossiblyUndeclaredVariable' => ['\\Nene\\Xion\\DataMapperBase::insert', '\\Nene\\Xion\\DataMapperBase::update'],
            'PhanTypeMismatchReturn' => ['\\Nene\\Xion\\DataMapperBase::countAll', '\\Nene\\Xion\\DataMapperBase::countById', '\\Nene\\Xion\\DataMapperBase::insert'],
            'PhanTypeSuspiciousStringExpression' => ['\\Nene\\Xion\\DataMapperBase::update'],
            'PhanUndeclaredVariableDim' => ['\\Nene\\Xion\\DataMapperBase::update'],
            'PhanUnreferencedUseNormal' => ['class/xion/DataMapperBase.php']
        ],
        'class/xion/DataModelBase.php' => [
            'PhanTypeMagicVoidWithReturn' => ['\\Nene\\Xion\\DataModelBase::__set'],
            'PhanTypeMismatchArgument' => ['\\Nene\\Xion\\DataModelBase::validate'],
            'PhanTypeMismatchArgumentInternal' => ['\\Nene\\Xion\\DataModelBase::validate'],
            'PhanTypeMismatchDimFetch' => ['\\Nene\\Xion\\DataModelBase::validate'],
            'PhanTypeMismatchForeach' => ['\\Nene\\Xion\\DataModelBase::validate']
        ],
        'class/xion/ModelBase.php' => [
            'PhanUnreferencedUseNormal' => ['class/xion/ModelBase.php']
        ],
        'class/xion/PdoConnection.php' => [
            'PhanTypeMismatchPropertyProbablyReal' => ['\\Nene\\Xion\\PdoConnection::__destruct'],
            'PhanUndeclaredConstantOfClass' => ['\\Nene\\Xion\\PdoConnection::__construct']
        ],
        'class/xion/Post.php' => [
            'PhanUndeclaredProperty' => ['\\Nene\\Xion\\Post::setValues']
        ],
        'class/xion/QueryString.php' => [
            'PhanUndeclaredProperty' => ['\\Nene\\Xion\\QueryString::setValues']
        ],
        'class/xion/RequestVariables.php' => [
            'PhanUnextractableAnnotation' => ['\\Nene\\Xion\\RequestVariables']
        ],
        'class/xion/View.php' => [
            'PhanUndeclaredTypeThrowsType' => ['\\Nene\\Xion\\View::__clone']
        ],
    ],
    // 'directory_suppressions' => ['src/directory_name' => ['PhanIssueName1', 'PhanIssueName2']] can be manually added if needed.
    // (directory_suppressions will currently be ignored by subsequent calls to --save-baseline, but may be preserved in future Phan releases)
];
