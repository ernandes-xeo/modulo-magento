<?php
/**
 * Novapc Integracommerce
 * 
 * @category     Novapc
 * @package      Novapc_Integracommerce 
 * @copyright    Copyright (c) 2016 Novapc (http://www.novapc.com.br/)
 * @author       Novapc
 * @version      Release: 0.1.0 
 */

$installer = $this; 
$installer->startSetup();

$setup = new Mage_Eav_Model_Entity_Setup('core_setup');
$attributesSets = Mage::getResourceModel('eav/entity_attribute_set_collection')->setEntityTypeFilter(4);
$entityTypeId = $setup->getEntityTypeId('catalog_product');
// adding attribute group
foreach ($attributesSets as $attrSet) {
    $setup->addAttributeGroup('catalog_product', $attrSet->getAttributeSetName(), 'Integracommerce', 1000); 
}

// Add attribute to product attribute set
$codigo = 'integracommerce_active';
$config = array(
    'group'    => 'Integracommerce',
    'position' => 1,
    'label'    => 'Sincronizado',
    'user_defined' => true,
    'type'     => 'int',
    'input'    => 'boolean',
    'apply_to' => 'simple,bundle,grouped,configurable',
    'default'  => 0,
    'required' => 1,
    'note'     => 'Se este produto não foi sincronizado com o Integracommerce, marque Não.'
);

$setup->addAttribute('catalog_product', $codigo, $config);

$attributeId = $setup->getAttributeId($entityTypeId, 'integracommerce_active');

$setup->run("
INSERT IGNORE INTO `{$installer->getTable('catalog_product_entity_int')}`
(`entity_type_id`, `attribute_id`, `entity_id`, `value`)
    SELECT '{$entityTypeId}', '{$attributeId}', `entity_id`, '0'
        FROM `{$installer->getTable('catalog_product_entity')}`;
");

$installer->endSetup();