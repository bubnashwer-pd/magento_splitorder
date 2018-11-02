<?php

$installer = $this;
$setup = new Mage_Eav_Model_Entity_Setup('core_setup');
$order_setup = new Mage_Sales_Model_Mysql4_Setup('core_setup');
$installer->startSetup();

// the attribute added will be displayed under the group/tab General in product edit page
$setup->addAttribute("catalog_product", "warehouse", array(
    'label' => 'Warehouse',
    'type' => 'varchar',
    'input' => 'select',
    'backend' => 'eav/entity_attribute_backend_array',
    'frontend' => '',
    'source' => 'eav/entity_attribute_source_table',
    'global' => Mage_Catalog_Model_Resource_Eav_Attribute::SCOPE_GLOBAL,
    'visible' => true,
    'required' => false,
    'user_defined' => true,
    'searchable' => false,
    'filterable' => false,
    'comparable' => false,
    'visible_on_front' => false,
    'visible_in_advanced_search' => false,
    'unique' => false
));

$order_setup->addAttribute('order', 'split_order_parent_id', array(
    'type' => 'varchar',
    'label' => 'Split Order Parent ID',
    'visible' => true,
    'required' => false,
    'visible_on_front' => true,
    'user_defined' => true
));


$installer->endSetup();
