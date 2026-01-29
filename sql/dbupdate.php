<#1>
<?php
global $DIC;
$db = $DIC->database();

if (!$db->tableExists('xmdq_config')) {
    $fields = [
        'name' => [
            'type'    => 'text',
            'length'  => 250,
            'notnull' => true
        ],
        'value' => [
            'type'    => 'text',
            'length'  => 4000,
            'notnull' => false
        ]
    ];

    $db->createTable('xmdq_config', $fields);
    $db->addPrimaryKey('xmdq_config', ['name']);
}

if (!$db->tableExists('rep_robj_xmdq_data')) {
    $fields = [
        'id' => [
            'type'    => 'integer',
            'length'  => 8,
            'notnull' => true
        ],
        'is_online' => [
            'type'    => 'integer',
            'length'  => 4,
            'notnull' => false,
            'default' => 0
        ],
        'md_content' => [
            'type'    => 'clob',
            'notnull' => false
        ]
    ];

    $db->createTable('rep_robj_xmdq_data', $fields);
    $db->addPrimaryKey('rep_robj_xmdq_data', ['id']);
}
?>
