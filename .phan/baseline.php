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
    // PhanTypeMagicVoidWithReturn : 1 occurrence
    // PhanTypeMismatchArgument : 1 occurrence
    // PhanTypeMismatchArgumentInternal : 1 occurrence
    // PhanTypeMismatchDimFetch : 1 occurrence
    // PhanTypeMismatchForeach : 1 occurrence
    // PhanUndeclaredConstantOfClass : 1 occurrence

    'file_suppressions' => [
        'class/xion/DataModelBase.php' => [
            'PhanTypeMagicVoidWithReturn' => ['\\Nene\\Xion\\DataModelBase::__set'],
            'PhanTypeMismatchArgument' => ['\\Nene\\Xion\\DataModelBase::validate'],
            'PhanTypeMismatchArgumentInternal' => ['\\Nene\\Xion\\DataModelBase::validate'],
            'PhanTypeMismatchDimFetch' => ['\\Nene\\Xion\\DataModelBase::validate'],
            'PhanTypeMismatchForeach' => ['\\Nene\\Xion\\DataModelBase::validate']
        ],
        'class/xion/PdoConnection.php' => [
            'PhanUndeclaredConstantOfClass' => ['\\Nene\\Xion\\PdoConnection::__construct']
        ],
    ],
    // 'directory_suppressions' => ['src/directory_name' => ['PhanIssueName1', 'PhanIssueName2']] can be manually added if needed.
    // (directory_suppressions will currently be ignored by subsequent calls to --save-baseline, but may be preserved in future Phan releases)
];
