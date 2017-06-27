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

class Novapc_Integracommerce_Block_Adminhtml_Report_Renderer_Status extends Mage_Adminhtml_Block_Widget_Grid_Column_Renderer_Abstract
{
    
    public function render(Varien_Object $row)
    {
        $value =  $row->getData($this->getColumn()->getIndex());
        if (!$value || empty($value)) {
            return '<div style="background: green; width: 100%; padding: 1px; border-radius: 15px; text-align: center;"><span style="color: white;">A Sincronizar </span></div>';
        } else {
            return '<div style="background: red; width: 100%; padding: 1px; border-radius: 15px; text-align: center;"><span style="color: white;">Erro</span></div>';
        }
    }
   
}