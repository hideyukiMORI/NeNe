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
    // PhanTypeArraySuspicious : 1 occurrence
    // PhanTypeMagicVoidWithReturn : 1 occurrence
    // PhanTypeMismatchArgumentInternal : 1 occurrence
    // PhanTypeMismatchArgumentNullableInternal : 1 occurrence
    // PhanTypeMismatchForeach : 1 occurrence
    // PhanUndeclaredConstantOfClass : 1 occurrence

    'file_suppressions' => [
        'class/xion/DataModelBase.php' => [
            'PhanTypeArraySuspicious' => ['\\Nene\\Xion\\DataModelBase::validate'],
            'PhanTypeMagicVoidWithReturn' => ['\\Nene\\Xion\\DataModelBase::__set'],
            'PhanTypeMismatchArgumentInternal' => ['\\Nene\\Xion\\DataModelBase::validate'],
            'PhanTypeMismatchArgumentNullableInternal' => ['\\Nene\\Xion\\DataModelBase::setNow'],
            'PhanTypeMismatchForeach' => ['\\Nene\\Xion\\DataModelBase::validate']
        ],
        'class/xion/PdoConnection.php' => [
            'PhanUndeclaredConstantOfClass' => ['\\Nene\\Xion\\PdoConnection::__construct']
        ],
    ],
    // 'directory_suppressions' => ['src/directory_name' => ['PhanIssueName1', 'PhanIssueName2']] can be manually added if needed.
    // (directory_suppressions will currently be ignored by subsequent calls to --save-baseline, but may be preserved in future Phan releases)
];
