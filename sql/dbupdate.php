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
<#2>
<?php
global $DIC;
$db = $DIC->database();

if ($db->tableExists('rep_robj_xmdq_data') && !$db->tableColumnExists('rep_robj_xmdq_data', 'last_prompt')) {
    $db->addTableColumn('rep_robj_xmdq_data', 'last_prompt', [
        'type'    => 'text',
        'length'  => 4000,
        'notnull' => false
    ]);
}
?>
<#3>
<?php
global $DIC;
$db = $DIC->database();

if ($db->tableExists('rep_robj_xmdq_data')) {
    if (!$db->tableColumnExists('rep_robj_xmdq_data', 'last_difficulty')) {
        $db->addTableColumn('rep_robj_xmdq_data', 'last_difficulty', [
            'type'    => 'text',
            'length'  => 50,
            'notnull' => false,
            'default' => 'medium'
        ]);
    }
    
    if (!$db->tableColumnExists('rep_robj_xmdq_data', 'last_question_count')) {
        $db->addTableColumn('rep_robj_xmdq_data', 'last_question_count', [
            'type'    => 'integer',
            'length'  => 4,
            'notnull' => false,
            'default' => 5
        ]);
    }
    
    if (!$db->tableColumnExists('rep_robj_xmdq_data', 'last_context')) {
        $db->addTableColumn('rep_robj_xmdq_data', 'last_context', [
            'type'    => 'clob',
            'notnull' => false
        ]);
    }
    
    if (!$db->tableColumnExists('rep_robj_xmdq_data', 'last_file_ref_id')) {
        $db->addTableColumn('rep_robj_xmdq_data', 'last_file_ref_id', [
            'type'    => 'integer',
            'length'  => 8,
            'notnull' => false,
            'default' => 0
        ]);
    }
}
?>
