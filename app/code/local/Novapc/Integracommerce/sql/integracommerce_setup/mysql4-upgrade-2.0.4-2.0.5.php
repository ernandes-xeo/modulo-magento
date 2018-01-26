<?php
/**
 * PHP version 5
 * Novapc Integracommerce
 *
 * @category  Magento
 * @package   Novapc_Integracommerce
 * @author    Novapc <novapc@novapc.com.br>
 * @copyright 2017 Integracommerce
 * @license   https://opensource.org/licenses/osl-3.0.php PHP License 3.0
 * @version   GIT: 1.0
 * @link      https://github.com/integracommerce/modulo-magento
 */

$installer = $this;
$installer->startSetup();

$tablePrefix = Mage::getConfig()->getTablePrefix();
if (!empty($tablePrefix)) {
    $queueTable = $tablePrefix . 'npcintegra_product_queue';
    $attrTable = $tablePrefix . 'npcintegra_sku_attributes';
} else {
    $queueTable = 'npcintegra_product_queue';
    $attrTable = 'npcintegra_sku_attributes';
}

try {
    if ($installer->getConnection()->tableColumnExists($queueTable, 'product_id')) {
        $installer->run("ALTER TABLE `" . $queueTable . "` ADD UNIQUE INDEX `product_id_UNIQUE` (`product_id` ASC);");
    }

    if ($installer->getConnection()->tableColumnExists($attrTable, 'category')) {
        $installer->run("ALTER TABLE `" . $attrTable . "` ADD UNIQUE INDEX `category_UNIQUE` (`category` ASC);");
    }
} catch (Exception $e) {
    Mage::log($e->getMessage(), null, 'Integracommerce_InstallError.log');
}

$installer->endSetup();