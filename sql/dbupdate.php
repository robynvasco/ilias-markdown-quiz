<?php
/** @var $db \ilDBInterface */
global $DIC;
$db = $DIC->database();

/*
 * Schritt 1: Erstellen der Haupttabelle für die Quiz-Daten
 * <#1> steht für den ersten Installationsschritt in ILIAS
 */
?>
<#1>
<?php
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
            'type'    => 'text',
            'length'  => 4000, // Platz für viel Markdown-Code
            'notnull' => false
        ]
    ];

    $db->createTable('rep_robj_xmdq_data', $fields);
    $db->addPrimaryKey('rep_robj_xmdq_data', ['id']);
}
?>

<#2>
<?php
if (!$db->tableExists('xmdq_config')) {
    $fields = [
        'name' => [
            'type' => 'text',
            'length' => 250,
            'notnull' => true
        ],
        'value' => [
            'type' => 'text',
            'length' => 4000,
            'notnull' => false
        ]
    ];

    $db->createTable('xmdq_config', $fields);
    $db->addPrimaryKey('xmdq_config', ['name']);
}
?>